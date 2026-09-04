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

use Blnk\Api\Middleware\MetricsAuth;
use Blnk\Config\Configuration;
use Blnk\Internal\Log;
use Blnk\Internal\Redis\PoolConfig;
use Blnk\Internal\Redis\RedisDb;
use Blnk\Internal\Traces\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * MonitoringServer is the worker monitoring HTTP server of cmd/workers.go
 * (`startMonitoringServer`): the `http.ServeMux` on `:<queue.monitoring_port>`
 * serving
 *
 *   /health       {"status": "UP", "service": "worker"}
 *   /monitoring/  the asynqmon dashboard, behind MetricsAuthHandler
 *   /metrics      the Prometheus handler when trace.MetricsHandler() is set, behind MetricsAuthHandler
 *
 * Divergences (documented):
 *  - Go serves it from a goroutine; the PHP port runs it inside the worker
 *    loop: a non-blocking listening socket whose pending connections are
 *    answered by {@see poll()} between task batches. Responses are therefore
 *    delayed by at most one loop iteration (bounded by the idle poll interval
 *    and the duration of the tasks in flight).
 *  - asynqmon (a React UI over asynq's Redis layout) has no PHP counterpart;
 *    /monitoring/ answers a JSON snapshot of the configured queues
 *    (pending/processing/scheduled/dead counts from {@see WorkerQueue::queueStats()}).
 *  - Requests are minimal HTTP/1.1 (headers only are parsed; bodies are ignored;
 *    every response closes the connection).
 */
final class MonitoringServer
{
    /** asynqmon `RootPath`. */
    public const RootPath = '/monitoring';

    /** Longest wait for a client to send its request head, in seconds. */
    private const ClientTimeoutSec = 1.0;

    /** Maximum request-head size accepted. */
    private const MaxHeadBytes = 16384;

    private Configuration $conf;

    /** Go `http.Server.Addr`, e.g. ":5004". */
    private string $addr;

    /** @var string[] queues shown on the dashboard */
    private array $queueNames;

    /** @var resource|null */
    private $socket = null;

    private ?WorkerQueue $queue = null;

    /** @var callable(ServerRequestInterface): ResponseInterface|null */
    private $monitoringHandler = null;

    /** @var callable(ServerRequestInterface): ResponseInterface|null */
    private $metricsHandler = null;

    /**
     * @param string[] $queueNames
     */
    public function __construct(Configuration $conf, string $addr, array $queueNames)
    {
        $this->conf = $conf;
        $this->addr = $addr;
        $this->queueNames = array_values(array_unique($queueNames));

        $secure = $conf->server->secure;
        $token = $conf->server->metricsBearerToken;

        // monitoringMux.Handle("/monitoring/", middleware.MetricsAuthHandler(secure, token, asynqmonHandler))
        $this->monitoringHandler = MetricsAuth::metricsAuthHandler($secure, $token, function (ServerRequestInterface $request): ResponseInterface {
            return $this->dashboard($request);
        });

        // if h := trace.MetricsHandler(); h != nil { monitoringMux.Handle("/metrics", MetricsAuthHandler(secure, token, h)) }
        $h = Tracer::metricsHandler();
        if (\is_callable($h)) {
            $this->metricsHandler = MetricsAuth::metricsAuthHandler($secure, $token, static function (ServerRequestInterface $request) use ($h): ResponseInterface {
                $response = (new ResponseFactory())->createResponse(200);
                $result = $h($request, $response);
                return $result instanceof ResponseInterface ? $result : $response;
            });
        }
    }

    public function addr(): string
    {
        return $this->addr;
    }

    /**
     * ListenAndServe binds the listening socket (Go: `srv.ListenAndServe()`).
     *
     * @throws \RuntimeException when the port cannot be bound ("could not start monitoring server")
     */
    public function listenAndServe(): void
    {
        if ($this->socket !== null) {
            return;
        }
        $host = str_starts_with($this->addr, ':') ? '0.0.0.0' . $this->addr : $this->addr;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_server('tcp://' . $host, $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN);
        if ($socket === false) {
            throw new \RuntimeException(sprintf('could not start monitoring server: listen tcp %s: %s', $this->addr, $errstr !== '' ? $errstr : 'error ' . $errno));
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
    }

    /**
     * poll answers every connection waiting on the listening socket (at most
     * `$maxAccepts` per call) and returns how many were served.
     */
    public function poll(int $maxAccepts = 32): int
    {
        if ($this->socket === null) {
            return 0;
        }
        $served = 0;
        while ($served < $maxAccepts) {
            $read = [$this->socket];
            $write = null;
            $except = null;
            $n = @stream_select($read, $write, $except, 0, 0);
            if ($n === false || $n === 0) {
                break;
            }
            $client = @stream_socket_accept($this->socket, 0);
            if ($client === false) {
                break;
            }
            try {
                $this->serve($client);
            } catch (\Throwable $err) {
                Log::get()->error('monitoring request failed', ['error' => $err->getMessage()]);
            } finally {
                if (\is_resource($client)) {
                    fclose($client);
                }
            }
            $served++;
        }
        return $served;
    }

    /**
     * Shutdown closes the listening socket (Go: `srv.Shutdown(ctx)`); pending
     * connections are answered first.
     */
    public function shutdown(): void
    {
        if ($this->socket === null) {
            return;
        }
        $this->poll();
        fclose($this->socket);
        $this->socket = null;
    }

    public function isListening(): bool
    {
        return $this->socket !== null;
    }

    /**
     * serve reads one request head from the client and writes the response.
     *
     * @param resource $client
     */
    private function serve($client): void
    {
        stream_set_blocking($client, false);
        $head = '';
        $deadline = microtime(true) + self::ClientTimeoutSec;
        while (!str_contains($head, "\r\n\r\n") && !str_contains($head, "\n\n")) {
            $chunk = fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($client) || microtime(true) >= $deadline) {
                    break;
                }
                usleep(5_000);
                continue;
            }
            $head .= $chunk;
            if (\strlen($head) > self::MaxHeadBytes) {
                $this->write($client, 431, ['Content-Type' => 'text/plain; charset=utf-8'], "431 Request Header Fields Too Large\n");
                return;
            }
        }

        $lines = preg_split('/\r?\n/', $head) ?: [];
        $requestLine = trim((string) ($lines[0] ?? ''));
        if ($requestLine === '' || !preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/\d\.\d$/', $requestLine, $m)) {
            $this->write($client, 400, ['Content-Type' => 'text/plain; charset=utf-8'], "400 Bad Request\n");
            return;
        }
        $method = $m[1];
        $target = $m[2];
        $headers = [];
        foreach (\array_slice($lines, 1) as $line) {
            $line = trim($line);
            if ($line === '') {
                break;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }

        [$status, $respHeaders, $body] = $this->route($method, $target, $headers);
        $this->write($client, $status, $respHeaders, $body);
    }

    /**
     * route dispatches like Go's `http.ServeMux` registration in startMonitoringServer.
     *
     * @param array<string, string> $headers lower-cased header name → value
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function route(string $method, string $target, array $headers): array
    {
        $path = (string) (parse_url($target, \PHP_URL_PATH) ?? $target);

        if ($path === '/health') {
            return [200, ['Content-Type' => 'application/json'], '{"status": "UP", "service": "worker"}'];
        }

        if ($path === self::RootPath) {
            // http.ServeMux redirects "/monitoring" to the registered subtree "/monitoring/".
            return [301, ['Location' => self::RootPath . '/', 'Content-Type' => 'text/html; charset=utf-8'], "<a href=\"/monitoring/\">Moved Permanently</a>.\n\n"];
        }

        if (str_starts_with($path, self::RootPath . '/') && $this->monitoringHandler !== null) {
            return $this->callPsr($this->monitoringHandler, $method, $target, $headers);
        }

        if ($path === '/metrics' && $this->metricsHandler !== null) {
            return $this->callPsr($this->metricsHandler, $method, $target, $headers);
        }

        return [404, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff'], "404 page not found\n"];
    }

    /**
     * callPsr runs a PSR-7 handler (the MetricsAuthHandler chain) on the raw request.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function callPsr(callable $handler, string $method, string $target, array $headers): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://' . ($headers['host'] ?? 'localhost') . $target);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $response = $handler($request);

        $out = [];
        foreach ($response->getHeaders() as $name => $values) {
            $out[(string) $name] = implode(', ', $values);
        }
        return [$response->getStatusCode(), $out, (string) $response->getBody()];
    }

    /**
     * dashboard is the asynqmon replacement: a JSON snapshot of the queues.
     */
    private function dashboard(ServerRequestInterface $request): ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse(200)->withHeader('Content-Type', 'application/json');
        try {
            $queue = $this->queue();
            $queues = [];
            foreach ($this->queueNames as $name) {
                $queues[$name] = $queue->queueStats($name);
            }
            $payload = [
                'service' => 'worker',
                'root_path' => self::RootPath,
                'queues' => $queues,
                'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::RFC3339),
                'note' => 'asynqmon dashboard is replaced by this queue snapshot in the PHP port',
            ];
        } catch (\Throwable $err) {
            $response = $response->withStatus(503);
            $payload = ['service' => 'worker', 'error' => sprintf('queue statistics unavailable: %s', $err->getMessage())];
        }
        $response->getBody()->write(json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT) . "\n");
        return $response;
    }

    /**
     * queue lazily opens the Redis connection of the dashboard (asynqmon opens
     * its own redis client from the parsed Redis URL).
     *
     * @throws \Throwable when Redis cannot be reached
     */
    private function queue(): WorkerQueue
    {
        if ($this->queue === null) {
            $client = RedisDb::newRedisClient([$this->conf->redis->dns], $this->conf->redis->skipTLSVerify, new PoolConfig(
                $this->conf->redis->poolSize,
                $this->conf->redis->minIdleConns
            ))->client();
            $this->queue = WorkerQueue::newQueue($this->conf, $client);
        }
        return $this->queue;
    }

    /**
     * write sends a complete HTTP/1.1 response and closes the connection.
     *
     * @param resource $client
     * @param array<string, string> $headers
     */
    private function write($client, int $status, array $headers, string $body): void
    {
        $reason = self::reasonPhrase($status);
        $head = sprintf("HTTP/1.1 %d %s\r\n", $status, $reason);
        $headers['Content-Length'] = (string) \strlen($body);
        $headers['Connection'] = 'close';
        $headers['Date'] = gmdate('D, d M Y H:i:s') . ' GMT';
        foreach ($headers as $name => $value) {
            $head .= sprintf("%s: %s\r\n", $name, $value);
        }
        $head .= "\r\n";

        stream_set_blocking($client, true);
        stream_set_timeout($client, 1);
        $payload = $head . $body;
        $written = 0;
        $len = \strlen($payload);
        while ($written < $len) {
            $n = @fwrite($client, substr($payload, $written));
            if ($n === false || $n === 0) {
                break;
            }
            $written += $n;
        }
    }

    private static function reasonPhrase(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            301 => 'Moved Permanently',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            431 => 'Request Header Fields Too Large',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Status ' . $status,
        };
    }
}
