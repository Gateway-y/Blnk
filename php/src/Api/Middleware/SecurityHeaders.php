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

namespace Blnk\Api\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Port of `SecurityHeaders` (api/middleware/middleware.go).
 *
 * SecurityHeaders sets security headers to the response.
 * It sets the following headers:
 * - X-Content-Type-Options: nosniff
 * - X-Frame-Options: DENY
 * - Referrer-Policy: strict-origin-when-cross-origin
 * - Content-Security-Policy: default-src 'none'; frame-ancestors 'none'
 * - Cache-Control: no-store
 * - Strict-Transport-Security: max-age=31536000; includeSubDomains
 */
final class SecurityHeaders implements MiddlewareInterface
{
    /**
     * SecurityHeaders — static factory mirroring the Go function name.
     */
    public static function securityHeaders(): self
    {
        return new self();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return self::apply($handler->handle($request), $request);
    }

    /**
     * apply sets the headers on a response. Gin sets them before running the
     * handler, so they are also present on responses the recovery middleware
     * writes for a panicking handler; {@see \Blnk\Api\LogrusRecovery} calls
     * this for that case.
     */
    public static function apply(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->withHeader('Cache-Control', 'no-store');

        $isTLS = $request->getUri()->getScheme() === 'https';
        if (!$isTLS) {
            $xfp = strtolower($request->getHeaderLine('X-Forwarded-Proto'));
            if ($xfp === 'https') {
                $isTLS = true;
            }
        }
        if ($isTLS) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
