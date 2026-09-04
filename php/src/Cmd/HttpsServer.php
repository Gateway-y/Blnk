<?php

/*
Copyright 2024 Blnk Finance Authors.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

declare(strict_types=1);

namespace Blnk\Cmd;

use Blnk\Cmd\CertMagic\Certificate;
use Blnk\Cmd\CertMagic\ClientHelloInfo;
use Blnk\Cmd\CertMagic\Solvers\SolverRegistry;
use Blnk\Cmd\CertMagic\TlsConfig;
use Blnk\Internal\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HttpsServer is the PHP counterpart of the `*http.Server` that
 * cmd/server.go's `serveTLS` builds (`&http.Server{Addr, Handler, TLSConfig:
 * cfg.TLSConfig()}` + `ListenAndServeTLS("", "")`): an in-process HTTP/1.1
 * server that terminates TLS itself with the certificates CertMagic manages.
 *
 * PHP's TLS streams have no per-handshake certificate callback, so for every
 * accepted connection the server peeks at the TLS ClientHello record, parses
 * its SNI and ALPN extensions ({@see ClientHelloInfo::parse()}), asks the
 * TLS config's `getCertificate` ({@see \Blnk\Cmd\CertMagic\Config::getCertificate()})
 * for the certificate to serve — exact/wildcard cache lookup, TLS-ALPN
 * challenge certificates — installs it on the stream context and then
 * completes the handshake. Requests are then decoded by {@see HttpConn} and
 * dispatched to the handler (the Slim application).
 *
 * Divergences from Go's net/http (documented):
 *  - single-threaded: connections are handled one after another, without
 *    keep-alive (`Connection: close` on every response), and a handshake or
 *    request that stalls holds the loop up to the configured timeouts;
 *  - HTTP/1.1 only (no h2);
 *  - while a certificate renewal is in progress the server keeps answering
 *    requests through {@see SolverRegistry}'s idle callbacks.
 */
final class HttpsServer
{
    /** Go `http.Server.Addr`, e.g. ":443". */
    private string $addr;

    /**
     * Go `http.Server.Handler`: a PSR-15 handler or `callable(ServerRequestInterface): ResponseInterface`.
     *
     * @var RequestHandlerInterface|callable|null
     */
    public $handler;

    /** Go `http.Server.TLSConfig`; null serves plain HTTP. */
    public ?TlsConfig $tlsConfig;

    /**
     * Timeouts in seconds (Go: ReadHeaderTimeout, ReadTimeout, WriteTimeout,
     * IdleTimeout). Go's zero value means "no timeout"; the single-threaded
     * port needs bounds, so the defaults are the ones certmagic's HTTPS()
     * applies to its HTTPS server.
     */
    public float $readHeaderTimeout = 10.0;

    public float $readTimeout = 30.0;

    public float $writeTimeout = 120.0;

    public float $idleTimeout = 300.0;

    /** MaxHeaderBytes (Go: http.DefaultMaxHeaderBytes) and the request body cap (0 = unlimited). */
    public int $maxHeaderBytes = HttpConn::MaxHeaderBytes;

    public int $maxBodyBytes = 0;

    /** @var resource|null the listening socket */
    private $listener = null;

    /** Directory holding the PEM files handed to OpenSSL (one per certificate hash). */
    private string $certDir = '';

    /** @var array<string, string> certificate hash → PEM file */
    private array $certFiles = [];

    private bool $polling = false;

    private bool $closed = false;

    /**
     * @param RequestHandlerInterface|callable|null $handler
     */
    public function __construct(string $addr, $handler, ?TlsConfig $tlsConfig)
    {
        $this->addr = $addr;
        $this->handler = $handler;
        $this->tlsConfig = $tlsConfig;
    }

    /** addr returns the listen address (Go `Addr`). */
    public function addr(): string
    {
        return $this->addr;
    }

    /** isTLS reports whether the server terminates TLS. */
    public function isTLS(): bool
    {
        return $this->tlsConfig !== null;
    }

    /**
     * ListenAndServe listens on the TCP network address srv.Addr and then
     * calls Serve to handle requests on incoming connections.
     *
     * @param callable(): bool|null $quit stops serving when it returns true (Go: `Shutdown` from another goroutine)
     * @param callable(): void|null $onIdle PHP-only: work to run on every loop iteration
     * @throws \RuntimeException
     */
    public function listenAndServe(?callable $quit = null, ?callable $onIdle = null): void
    {
        $this->listen();
        $this->serve($quit, $onIdle);
    }

    /**
     * ListenAndServeTLS listens on the TCP network address srv.Addr and
     * then calls ServeTLS to handle requests on incoming TLS connections.
     * The certificates come from the TLS config's GetCertificate (Go:
     * `ListenAndServeTLS("", "")`).
     *
     * @param callable(): bool|null $quit
     * @param callable(): void|null $onIdle
     * @throws \RuntimeException
     */
    public function listenAndServeTLS(?callable $quit = null, ?callable $onIdle = null): void
    {
        if ($this->tlsConfig === null) {
            throw new \RuntimeException('http: Server has no TLSConfig; ListenAndServeTLS requires a certificate source');
        }
        $this->listen();
        $this->serve($quit, $onIdle);
    }

    /**
     * listen binds the listening socket (":port" binds every interface,
     * dual-stack when IPv6 is available, like Go's net.Listen).
     *
     * @throws \RuntimeException "listen tcp :port: ..."
     */
    public function listen(): void
    {
        if ($this->listener !== null) {
            return;
        }
        [$host, $port] = self::hostPort($this->addr);
        $candidates = $host === '' ? ['[::]', '0.0.0.0'] : [self::bracket($host)];
        $errstr = '';
        $ln = null;
        foreach ($candidates as $bind) {
            $errno = 0;
            $err = '';
            $context = stream_context_create();
            $ln = @stream_socket_server('tcp://' . $bind . ':' . $port, $errno, $err, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);
            if ($ln !== false) {
                break;
            }
            $ln = null;
            $errstr = $err !== '' ? $err : 'error ' . $errno;
        }
        if ($ln === null) {
            throw new \RuntimeException(sprintf('listen tcp %s: %s', $this->addr, $errstr));
        }
        stream_set_blocking($ln, false);
        $this->listener = $ln;
        $this->closed = false;

        if ($this->tlsConfig !== null) {
            // http.Server.ServeTLS adds "h2" and "http/1.1" to NextProtos; the port speaks HTTP/1.1
            if (!\in_array('http/1.1', $this->tlsConfig->nextProtos, true)) {
                $this->tlsConfig->nextProtos = array_merge(['http/1.1'], $this->tlsConfig->nextProtos);
            }
            $this->tlsConfig->nextProtos = array_values(array_filter($this->tlsConfig->nextProtos, static fn (string $p): bool => $p !== 'h2'));
            foreach ($this->tlsConfig->sslContextOptions() as $name => $value) {
                stream_context_set_option($ln, 'ssl', (string) $name, $value);
            }
            $this->certDir = rtrim(sys_get_temp_dir(), '/') . '/blnk-https-' . getmypid() . '-' . bin2hex(random_bytes(4));
            if (!@mkdir($this->certDir, 0700, true) && !is_dir($this->certDir)) {
                throw new \RuntimeException(sprintf('cannot create certificate directory %s', $this->certDir));
            }
        }

        // keep answering requests while the ACME flow waits (renewals)
        SolverRegistry::registerIdle('https-server:' . spl_object_id($this), function (): void {
            $this->poll();
        });
    }

    /**
     * Serve accepts incoming connections on the listener, handling each in
     * turn, until `$quit` reports true (Go: until Shutdown/Close).
     *
     * @param callable(): bool|null $quit
     * @param callable(): void|null $onIdle
     * @throws \RuntimeException
     */
    public function serve(?callable $quit = null, ?callable $onIdle = null): void
    {
        self::serveAll([$this], $quit, $onIdle);
    }

    /**
     * serveAll multiplexes several servers on one loop (Go runs one goroutine
     * per server; certmagic's HTTPS() serves the HTTP and HTTPS listeners).
     *
     * @param HttpsServer[] $servers
     * @param callable(): bool|null $quit
     * @param callable(): void|null $onIdle
     * @throws \RuntimeException
     */
    public static function serveAll(array $servers, ?callable $quit = null, ?callable $onIdle = null, float $slice = 0.1): void
    {
        foreach ($servers as $server) {
            if ($server->listener === null) {
                throw new \RuntimeException(sprintf('http: Server on %s is not listening', $server->addr));
            }
        }

        while ($quit === null || !$quit()) {
            $read = [];
            $byId = [];
            foreach ($servers as $server) {
                if ($server->listener !== null && \is_resource($server->listener)) {
                    $read[] = $server->listener;
                    $byId[(int) $server->listener] = $server;
                }
            }
            if ($read === []) {
                return; // every listener was closed
            }
            $write = null;
            $except = null;
            $sec = (int) floor($slice);
            $usec = (int) (($slice - $sec) * 1_000_000);
            $n = @stream_select($read, $write, $except, $sec, $usec);
            if ($n !== false && $n > 0) {
                foreach ($read as $ln) {
                    $server = $byId[(int) $ln] ?? null;
                    if ($server !== null) {
                        $server->poll();
                    }
                }
            }
            if ($onIdle !== null) {
                $onIdle();
            }
        }
    }

    /**
     * poll answers the connections waiting on the listening socket (at most
     * `$maxAccepts`) without blocking, and returns how many were served.
     */
    public function poll(int $maxAccepts = 16): int
    {
        if ($this->listener === null || $this->polling) {
            return 0;
        }
        $this->polling = true;
        $served = 0;
        try {
            while ($served < $maxAccepts) {
                $read = [$this->listener];
                $write = null;
                $except = null;
                $n = @stream_select($read, $write, $except, 0, 0);
                if ($n === false || $n === 0) {
                    break;
                }
                $conn = @stream_socket_accept($this->listener, 0);
                if ($conn === false) {
                    break;
                }
                try {
                    $this->handleConn($conn);
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('http: %s', $err->getMessage()), ['addr' => $this->addr]);
                } finally {
                    if (\is_resource($conn)) {
                        @fclose($conn);
                    }
                }
                $served++;
            }
        } finally {
            $this->polling = false;
        }
        return $served;
    }

    /**
     * Shutdown gracefully shuts down the server without interrupting any
     * active connections (there are none in flight between two loop
     * iterations of the single-threaded port), then releases the listener.
     */
    public function shutdown(float $timeoutSec = 0.0): void
    {
        $this->close();
    }

    /** Close immediately closes the listener and forgets the certificate files. */
    public function close(): void
    {
        $this->closed = true;
        SolverRegistry::unregisterIdle('https-server:' . spl_object_id($this));
        if ($this->listener !== null) {
            if (\is_resource($this->listener)) {
                @fclose($this->listener);
            }
            $this->listener = null;
        }
        foreach ($this->certFiles as $file) {
            @unlink($file);
        }
        $this->certFiles = [];
        if ($this->certDir !== '' && is_dir($this->certDir)) {
            @rmdir($this->certDir);
        }
    }

    public function isListening(): bool
    {
        return $this->listener !== null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * handleConn serves one connection: TLS handshake (if configured), one
     * request, one response.
     *
     * @param resource $conn
     * @throws \RuntimeException
     */
    private function handleConn($conn): void
    {
        stream_set_blocking($conn, false);
        $scheme = 'http';
        if ($this->tlsConfig !== null) {
            if (!$this->handshake($conn)) {
                return;
            }
            $scheme = 'https';
        }

        try {
            $request = HttpConn::readRequest($conn, $scheme, $this->readHeaderTimeout, $this->readTimeout, $this->maxBodyBytes);
        } catch (\RuntimeException $err) {
            $status = $err->getCode() >= 400 && $err->getCode() < 600 ? $err->getCode() : 400;
            HttpConn::writeResponse($conn, HttpConn::errorResponse($status, $err->getMessage()), '1.1', false, $this->writeTimeout);
            return;
        }
        if ($request === null) {
            return; // the peer went away (or a TLS-ALPN validator that only wanted the handshake)
        }

        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('http: panic serving %s: %s', (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''), $err->getMessage()));
            $response = HttpConn::errorResponse(500, 'Internal Server Error');
        }
        HttpConn::writeResponse($conn, $response, $request->getProtocolVersion(), $request->getMethod() === 'HEAD', $this->writeTimeout);
    }

    /**
     * dispatch hands the request to the handler.
     *
     * @throws \Throwable
     */
    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->handler instanceof RequestHandlerInterface) {
            return $this->handler->handle($request);
        }
        if (\is_callable($this->handler)) {
            $response = ($this->handler)($request);
            if ($response instanceof ResponseInterface) {
                return $response;
            }
            throw new \RuntimeException('handler did not return a response');
        }
        return HttpConn::errorResponse(404, '404 page not found');
    }

    /**
     * handshake performs the server side of the TLS handshake with the
     * certificate GetCertificate selects for the connection's ClientHello.
     * Returns false (after logging) when the handshake failed.
     *
     * @param resource $conn
     */
    private function handshake($conn): bool
    {
        $remote = (string) @stream_socket_get_name($conn, true);
        $deadline = microtime(true) + $this->readHeaderTimeout;

        // peek at the ClientHello (tls.Config.GetCertificate receives the parsed hello)
        $hello = null;
        $peeked = '';
        while ($hello === null) {
            $data = @stream_socket_recvfrom($conn, 65536, \STREAM_PEEK);
            if (\is_string($data) && \strlen($data) > \strlen($peeked)) {
                $peeked = $data;
                try {
                    $hello = ClientHelloInfo::parse($peeked, $conn);
                } catch (\Throwable $err) {
                    Log::get()->debug(sprintf('http: TLS handshake error from %s: %s', $remote, $err->getMessage()));
                    return false;
                }
                if ($hello !== null) {
                    break;
                }
            }
            if (feof($conn)) {
                return false; // EOF before the ClientHello
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                Log::get()->debug(sprintf('http: TLS handshake error from %s: i/o timeout', $remote));
                return false;
            }
            $read = [$conn];
            $write = null;
            $except = null;
            $wait = min($remaining, 1.0);
            $sec = (int) floor($wait);
            $usec = (int) (($wait - $sec) * 1_000_000);
            if (@stream_select($read, $write, $except, $sec, $usec) === false) {
                usleep(5_000);
            }
        }

        // choose the certificate
        try {
            /** @var Certificate $cert */
            $cert = ($this->tlsConfig->getCertificate)($hello);
        } catch (\Throwable $err) {
            Log::get()->debug(sprintf('http: TLS handshake error from %s: %s', $remote, $err->getMessage()));
            return false;
        }
        try {
            $file = $this->certFile($cert);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('http: TLS handshake error from %s: %s', $remote, $err->getMessage()));
            return false;
        }
        stream_context_set_option($conn, 'ssl', 'local_cert', $file);
        // a TLS-ALPN validator negotiates acme-tls/1 only; regular clients get http/1.1
        stream_context_set_option($conn, 'ssl', 'alpn_protocols', implode(',', $this->tlsConfig->nextProtos));

        // complete the handshake
        while (true) {
            $result = @stream_socket_enable_crypto($conn, true, $this->tlsConfig->cryptoMethod);
            if ($result === true) {
                return true;
            }
            if ($result === false) {
                $reason = '';
                while (($msg = openssl_error_string()) !== false) {
                    $reason = $msg;
                }
                Log::get()->debug(sprintf('http: TLS handshake error from %s: %s', $remote, $reason !== '' ? $reason : 'handshake failed'));
                return false;
            }
            // 0: more data needed
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || feof($conn)) {
                Log::get()->debug(sprintf('http: TLS handshake error from %s: %s', $remote, feof($conn) ? 'EOF' : 'i/o timeout'));
                return false;
            }
            $read = [$conn];
            $write = null;
            $except = null;
            $wait = min($remaining, 1.0);
            $sec = (int) floor($wait);
            $usec = (int) (($wait - $sec) * 1_000_000);
            if (@stream_select($read, $write, $except, $sec, $usec) === false) {
                usleep(5_000);
            }
        }
    }

    /**
     * certFile writes the certificate chain and its private key to a PEM file
     * OpenSSL can load as `local_cert` (cached by certificate hash).
     *
     * @throws \RuntimeException
     */
    private function certFile(Certificate $cert): string
    {
        $hash = $cert->hash !== '' ? $cert->hash : hash('sha256', $cert->certificatePEM . $cert->privateKeyPEM);
        $file = $this->certFiles[$hash] ?? null;
        if ($file !== null && is_file($file)) {
            return $file;
        }
        if ($cert->certificatePEM === '' || $cert->privateKeyPEM === '') {
            throw new \RuntimeException('certificate has no PEM encoding to serve');
        }
        $file = $this->certDir . '/' . preg_replace('/[^0-9a-f]/', '', $hash) . '.pem';
        if (@file_put_contents($file, rtrim($cert->certificatePEM) . "\n" . rtrim($cert->privateKeyPEM) . "\n") === false) {
            throw new \RuntimeException(sprintf('cannot write certificate file %s', $file));
        }
        @chmod($file, 0600);
        $this->certFiles[$hash] = $file;

        // forget files of certificates that are gone (renewed/evicted)
        if (\count($this->certFiles) > 64) {
            $live = [];
            if ($this->tlsConfig !== null) {
                foreach (($this->tlsConfig->certificates)() as $c) {
                    $live[$c->hash] = true;
                }
            }
            foreach ($this->certFiles as $h => $f) {
                if ($h !== $hash && !isset($live[$h])) {
                    @unlink($f);
                    unset($this->certFiles[$h]);
                }
            }
        }
        return $file;
    }

    /** @return array{0: string, 1: string} */
    private static function hostPort(string $addr): array
    {
        $pos = strrpos($addr, ':');
        if ($pos === false) {
            return ['', $addr];
        }
        return [trim(substr($addr, 0, $pos), '[]'), substr($addr, $pos + 1)];
    }

    private static function bracket(string $host): string
    {
        return str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
    }
}
