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

use Blnk\Api\Middleware\SecurityHeaders;
use Blnk\Internal\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Port of `logrusRecovery` (api/api.go): the `gin.CustomRecovery` handler that
 * logs a recovered panic with the request method, path and client IP and
 * aborts with an empty 500 response.
 *
 * Uncaught PHP exceptions are the counterpart of Go panics. Slim's routing
 * exceptions are also translated here to Gin's defaults (`404 page not found`
 * for unknown routes; a method mismatch is a 404 too because Gin's
 * HandleMethodNotAllowed is off), although the catch-all NoRoute handler
 * registered in {@see Api::router()} normally answers those first.
 */
final class LogrusRecovery implements MiddlewareInterface
{
    /** Gin's default NoRoute body. */
    public const NotFoundBody = '404 page not found';

    /**
     * logrusRecovery — static factory mirroring the Go function name.
     */
    public static function logrusRecovery(): self
    {
        return new self();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpNotFoundException | HttpMethodNotAllowedException) {
            return Json::text((new ResponseFactory())->createResponse(), 404, self::NotFoundBody);
        } catch (\Throwable $recovered) {
            Log::get()->error('panic recovered', [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'client_ip' => Query::clientIP($request),
                'panic' => $recovered->getMessage(),
                'exception' => $recovered,
            ]);

            // Go: c.AbortWithStatus(http.StatusInternalServerError) — headers
            // already set by the security middleware stay on the response.
            return SecurityHeaders::apply((new ResponseFactory())->createResponse(500), $request);
        }
    }
}
