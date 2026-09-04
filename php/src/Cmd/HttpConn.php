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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * HttpConn is the minimal HTTP/1.1 connection codec the in-process servers
 * of the PHP port use where Go relies on `net/http.Server`: the ACME HTTP-01
 * challenge listener ({@see \Blnk\Cmd\CertMagic\Solvers\HttpSolver}) and the
 * TLS server of `blnk start`'s `serveTLS` ({@see HttpsServer}).
 *
 * It reads one request (request line, headers, and a Content-Length or
 * chunked body) from a stream socket into a PSR-7 ServerRequest, and writes a
 * PSR-7 response back. Every response closes the connection (no keep-alive;
 * `Connection: close` is announced), which HTTP/1.1 clients handle by
 * reconnecting.
 */
final class HttpConn
{
    /** Go `http.DefaultMaxHeaderBytes`. */
    public const MaxHeaderBytes = 1 << 20;

    /** HttpError status codes the reader reports. */
    public const StatusBadRequest = 400;
    public const StatusRequestTimeout = 408;
    public const StatusRequestEntityTooLarge = 413;
    public const StatusRequestHeaderFieldsTooLarge = 431;

    private function __construct()
    {
    }

    /**
     * readRequest reads one HTTP/1.1 request from the (accepted, optionally
     * TLS-enabled) connection.
     *
     * @param resource $conn
     * @param string $scheme "http" or "https" — the scheme of the served URL
     * @param float $headerTimeoutSec how long the request head may take to arrive (Go: ReadHeaderTimeout)
     * @param float $bodyTimeoutSec how long the body may take to arrive (Go: ReadTimeout)
     * @param int $maxBodyBytes 0 for unlimited
     * @return ServerRequestInterface|null null when the peer closed without sending anything
     * @throws \RuntimeException with the HTTP status to answer as code (400/408/413/431)
     */
    public static function readRequest($conn, string $scheme, float $headerTimeoutSec, float $bodyTimeoutSec, int $maxBodyBytes = 0): ?ServerRequestInterface
    {
        stream_set_blocking($conn, false);
        $buffer = '';
        $deadline = microtime(true) + $headerTimeoutSec;

        // request head
        while (($end = self::findHeadEnd($buffer)) === null) {
            if (\strlen($buffer) > self::MaxHeaderBytes) {
                throw new \RuntimeException('request header fields too large', self::StatusRequestHeaderFieldsTooLarge);
            }
            $chunk = self::readChunk($conn, $deadline, $buffer === '' ? 'request timeout' : 'request header timeout');
            if ($chunk === null) {
                if ($buffer === '') {
                    return null; // EOF before any byte: the client went away
                }
                throw new \RuntimeException('unexpected EOF in request head', self::StatusBadRequest);
            }
            $buffer .= $chunk;
        }
        [$headEnd, $sepLen] = $end;
        $head = substr($buffer, 0, $headEnd);
        $buffer = substr($buffer, $headEnd + $sepLen);

        $lines = preg_split('/\r?\n/', $head) ?: [];
        $requestLine = trim((string) array_shift($lines));
        if (!preg_match('#^([A-Za-z]+)\s+(\S+)\s+HTTP/(\d)\.(\d)$#', $requestLine, $m)) {
            throw new \RuntimeException('malformed HTTP request', self::StatusBadRequest);
        }
        $method = strtoupper($m[1]);
        $target = $m[2];
        $protocolVersion = $m[3] . '.' . $m[4];

        /** @var array<string, string[]> $headers name (as sent) → values; lower-cased lookup in $lookup */
        $headers = [];
        $lookup = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                throw new \RuntimeException('malformed HTTP header', self::StatusBadRequest);
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            $lower = strtolower($name);
            $key = $lookup[$lower] ?? $name;
            $lookup[$lower] = $key;
            $headers[$key][] = $value;
        }

        // body
        $body = '';
        $bodyDeadline = microtime(true) + $bodyTimeoutSec;
        $transferEncoding = strtolower(implode(',', $headers[$lookup['transfer-encoding'] ?? ''] ?? []));
        if (str_contains($transferEncoding, 'chunked')) {
            $body = self::readChunkedBody($conn, $buffer, $bodyDeadline, $maxBodyBytes);
        } elseif (isset($lookup['content-length'])) {
            $lengthValue = trim((string) ($headers[$lookup['content-length']][0] ?? ''));
            if (!preg_match('/^\d+$/', $lengthValue)) {
                throw new \RuntimeException('bad Content-Length', self::StatusBadRequest);
            }
            $length = (int) $lengthValue;
            if ($maxBodyBytes > 0 && $length > $maxBodyBytes) {
                throw new \RuntimeException('http: request body too large', self::StatusRequestEntityTooLarge);
            }
            $body = self::readExact($conn, $buffer, $length, $bodyDeadline);
        }

        // PSR-7 request
        $hostHeader = trim((string) ($headers[$lookup['host'] ?? ''][0] ?? ''));
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $target)) {
            $uri = $target; // absolute-form
        } else {
            $uri = $scheme . '://' . ($hostHeader !== '' ? $hostHeader : 'localhost') . ($target === '*' ? '/' : $target);
        }

        $peer = (string) @stream_socket_get_name($conn, true);
        $local = (string) @stream_socket_get_name($conn, false);
        [$remoteAddr, $remotePort] = self::splitHostPort($peer);
        [$localAddr, $localPort] = self::splitHostPort($local);
        $serverParams = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $target,
            'SERVER_PROTOCOL' => 'HTTP/' . $protocolVersion,
            'REMOTE_ADDR' => $remoteAddr,
            'REMOTE_PORT' => $remotePort,
            'SERVER_ADDR' => $localAddr,
            'SERVER_PORT' => $localPort,
            'SERVER_NAME' => $hostHeader !== '' ? (string) preg_replace('/:\d+$/', '', $hostHeader) : $localAddr,
            'HTTP_HOST' => $hostHeader,
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];
        if ($scheme === 'https') {
            $serverParams['HTTPS'] = 'on';
        }

        $request = (new ServerRequestFactory())->createServerRequest($method, $uri, $serverParams);
        foreach ($headers as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                $request = $first ? $request->withHeader($name, $value) : $request->withAddedHeader($name, $value);
                $first = false;
            }
        }
        $request = $request
            ->withProtocolVersion($protocolVersion)
            ->withBody((new StreamFactory())->createStream($body));

        return $request;
    }

    /**
     * writeResponse sends a PSR-7 response and announces the connection close.
     *
     * @param resource $conn
     * @throws \RuntimeException when the peer stops reading
     */
    public static function writeResponse($conn, ResponseInterface $response, string $protocolVersion = '1.1', bool $headOnly = false, float $timeoutSec = 30.0): void
    {
        $status = $response->getStatusCode();
        $reason = $response->getReasonPhrase();
        if ($reason === '') {
            $reason = 'Status ' . $status;
        }

        $body = '';
        if (!$headOnly && $status >= 200 && $status !== 204 && $status !== 304) {
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = (string) $stream;
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }
        $lower = array_change_key_case($headers, \CASE_LOWER);
        if (!isset($lower['date'])) {
            $headers['Date'] = gmdate('D, d M Y H:i:s') . ' GMT';
        }
        if (!isset($lower['content-length']) && !isset($lower['transfer-encoding']) && $status >= 200 && $status !== 204 && $status !== 304) {
            $headers['Content-Length'] = (string) ($headOnly ? $response->getBody()->getSize() ?? \strlen($body) : \strlen($body));
        }
        $headers['Connection'] = 'close';

        $out = sprintf("HTTP/%s %d %s\r\n", $protocolVersion === '1.0' ? '1.0' : '1.1', $status, $reason);
        foreach ($headers as $name => $value) {
            $out .= sprintf("%s: %s\r\n", $name, $value);
        }
        $out .= "\r\n" . $body;

        self::writeAll($conn, $out, microtime(true) + $timeoutSec);
    }

    /**
     * errorResponse builds Go's `http.Error` reply: text/plain, nosniff, the
     * message and a trailing newline.
     */
    public static function errorResponse(int $status, string $message): ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $response->getBody()->write($message . "\n");
        return $response;
    }

    /**
     * @return array{0: int, 1: int}|null offset of the head end and separator length
     */
    private static function findHeadEnd(string $buffer): ?array
    {
        $crlf = strpos($buffer, "\r\n\r\n");
        $lf = strpos($buffer, "\n\n");
        if ($crlf === false && $lf === false) {
            return null;
        }
        if ($crlf !== false && ($lf === false || $crlf <= $lf)) {
            return [$crlf, 4];
        }
        return [(int) $lf, 2];
    }

    /**
     * readChunk waits for data until the deadline; null on EOF.
     *
     * @param resource $conn
     * @throws \RuntimeException on timeout (408)
     */
    private static function readChunk($conn, float $deadline, string $timeoutMessage): ?string
    {
        while (true) {
            $data = @fread($conn, 65536);
            if ($data !== false && $data !== '') {
                return $data;
            }
            if (feof($conn)) {
                return null;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new \RuntimeException($timeoutMessage, self::StatusRequestTimeout);
            }
            $read = [$conn];
            $write = null;
            $except = null;
            $slice = min($remaining, 1.0);
            $sec = (int) floor($slice);
            $usec = (int) (($slice - $sec) * 1_000_000);
            if (@stream_select($read, $write, $except, $sec, $usec) === false) {
                usleep(5_000); // interrupted by a signal
            }
        }
    }

    /**
     * readExact reads exactly `$length` body bytes, using `$buffer` first.
     *
     * @param resource $conn
     * @throws \RuntimeException
     */
    private static function readExact($conn, string &$buffer, int $length, float $deadline): string
    {
        while (\strlen($buffer) < $length) {
            $chunk = self::readChunk($conn, $deadline, 'request body timeout');
            if ($chunk === null) {
                throw new \RuntimeException('unexpected EOF in request body', self::StatusBadRequest);
            }
            $buffer .= $chunk;
        }
        $body = substr($buffer, 0, $length);
        $buffer = substr($buffer, $length);
        return $body;
    }

    /**
     * readLine reads one CRLF-terminated line (without the terminator).
     *
     * @param resource $conn
     * @throws \RuntimeException
     */
    private static function readLine($conn, string &$buffer, float $deadline): string
    {
        while (($pos = strpos($buffer, "\n")) === false) {
            if (\strlen($buffer) > self::MaxHeaderBytes) {
                throw new \RuntimeException('malformed chunked encoding', self::StatusBadRequest);
            }
            $chunk = self::readChunk($conn, $deadline, 'request body timeout');
            if ($chunk === null) {
                throw new \RuntimeException('unexpected EOF in request body', self::StatusBadRequest);
            }
            $buffer .= $chunk;
        }
        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);
        return rtrim($line, "\r");
    }

    /**
     * readChunkedBody decodes a Transfer-Encoding: chunked body.
     *
     * @param resource $conn
     * @throws \RuntimeException
     */
    private static function readChunkedBody($conn, string &$buffer, float $deadline, int $maxBodyBytes): string
    {
        $body = '';
        while (true) {
            $sizeLine = self::readLine($conn, $buffer, $deadline);
            $sizeHex = trim(explode(';', $sizeLine, 2)[0]);
            if ($sizeHex === '' || !ctype_xdigit($sizeHex)) {
                throw new \RuntimeException('malformed chunked encoding', self::StatusBadRequest);
            }
            $size = (int) hexdec($sizeHex);
            if ($size === 0) {
                // trailers until an empty line
                while (self::readLine($conn, $buffer, $deadline) !== '') {
                }
                return $body;
            }
            if ($maxBodyBytes > 0 && \strlen($body) + $size > $maxBodyBytes) {
                throw new \RuntimeException('http: request body too large', self::StatusRequestEntityTooLarge);
            }
            $body .= self::readExact($conn, $buffer, $size, $deadline);
            self::readLine($conn, $buffer, $deadline); // the CRLF after the chunk data
        }
    }

    /**
     * @param resource $conn
     * @throws \RuntimeException
     */
    private static function writeAll($conn, string $payload, float $deadline): void
    {
        stream_set_blocking($conn, false);
        $written = 0;
        $len = \strlen($payload);
        while ($written < $len) {
            $n = @fwrite($conn, substr($payload, $written, 65536));
            if ($n === false) {
                throw new \RuntimeException('write: connection closed by peer');
            }
            if ($n > 0) {
                $written += $n;
                continue;
            }
            if (feof($conn)) {
                throw new \RuntimeException('write: connection closed by peer');
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new \RuntimeException('write: i/o timeout');
            }
            $read = null;
            $write = [$conn];
            $except = null;
            $slice = min($remaining, 1.0);
            $sec = (int) floor($slice);
            $usec = (int) (($slice - $sec) * 1_000_000);
            if (@stream_select($read, $write, $except, $sec, $usec) === false) {
                usleep(5_000);
            }
        }
    }

    /** @return array{0: string, 1: string} */
    private static function splitHostPort(string $hostport): array
    {
        if ($hostport === '') {
            return ['', ''];
        }
        $pos = strrpos($hostport, ':');
        if ($pos === false) {
            return [$hostport, ''];
        }
        $host = substr($hostport, 0, $pos);
        $port = substr($hostport, $pos + 1);
        return [trim($host, '[]'), $port];
    }
}
