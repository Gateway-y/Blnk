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

namespace Blnk\Api;

use Blnk\Internal\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Port of `logrusAccessLogger` (api/api.go): logs one "http request" line per
 * request with method, path, status, latency, client IP, error count and
 * response length.
 */
final class LogrusAccessLogger implements MiddlewareInterface
{
    /**
     * logrusAccessLogger — static factory mirroring the Go function name.
     */
    public static function logrusAccessLogger(): self
    {
        return new self();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = hrtime(true);
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $clientIP = Query::clientIP($request);
        RequestErrors::reset();

        $response = $handler->handle($request);

        Log::get()->info('http request', [
            'method' => $method,
            'path' => $path,
            'status' => $response->getStatusCode(),
            'latency_ms' => intdiv(hrtime(true) - $start, 1_000_000),
            'client_ip' => $clientIP,
            'error_count' => RequestErrors::count(),
            'response_len' => $response->getBody()->getSize() ?? 0,
        ]);

        return $response;
    }
}
