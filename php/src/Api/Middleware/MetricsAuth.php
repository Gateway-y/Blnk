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

use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\ApiError\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Port of `MetricsAuth`, `MetricsAuthHandler` and `writeMetricsAuthError`
 * (api/middleware/middleware.go).
 *
 * MetricsAuth returns a middleware that controls access to the /metrics endpoint.
 *
 * Behavior based on secure mode and token configuration:
 *   - Secure mode OFF, no token: open access (no auth required)
 *   - Secure mode OFF, token set: require bearer token
 *   - Secure mode ON, token set: require bearer token
 *   - Secure mode ON, no token: block all access (misconfiguration)
 *
 * When authentication is required, requests must include "Authorization: Bearer <token>".
 * This uses the standard Authorization header that Prometheus natively supports via
 * its scrape_configs authorization block.
 */
final class MetricsAuth implements MiddlewareInterface
{
    private const BearerPrefix = 'Bearer ';

    private bool $secure;
    private string $token;

    public function __construct(bool $secure, string $token)
    {
        $this->secure = $secure;
        $this->token = $token;
    }

    /**
     * MetricsAuth — static factory mirroring the Go function name.
     */
    public static function metricsAuth(bool $secure, string $token): self
    {
        return new self($secure, $token);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $failure = self::check($this->secure, $this->token, $request);
        if ($failure !== null) {
            return AuthMiddleware::abortWithCode($failure[0], $failure[1]);
        }

        return $handler->handle($request);
    }

    /**
     * MetricsAuthHandler wraps a plain request handler with bearer token authentication.
     * This is the non-Gin equivalent of MetricsAuth, used for the worker monitoring server
     * which uses a standard http.ServeMux instead of Gin.
     * Same secure mode logic as MetricsAuth: blocks access when secure=true and token is empty.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $next
     * @return callable(ServerRequestInterface): ResponseInterface
     */
    public static function metricsAuthHandler(bool $secure, string $token, callable $next): callable
    {
        if ($secure && $token === '') {
            return static fn (ServerRequestInterface $request): ResponseInterface => self::writeMetricsAuthError(ErrorCode::ErrAuthMetricsDisabled, 'Metrics endpoint unavailable: metrics_bearer_token must be configured when secure mode is enabled');
        }

        if ($token === '') {
            return $next;
        }

        return static function (ServerRequestInterface $request) use ($secure, $token, $next): ResponseInterface {
            $failure = self::check($secure, $token, $request);
            if ($failure !== null) {
                return self::writeMetricsAuthError($failure[0], $failure[1]);
            }

            return $next($request);
        };
    }

    /**
     * check evaluates the shared secure-mode / bearer-token rules.
     *
     * @return array{0: string, 1: string}|null `[code, message]` of the rejection, or null to allow
     */
    private static function check(bool $secure, string $token, ServerRequestInterface $request): ?array
    {
        if ($secure && $token === '') {
            return [ErrorCode::ErrAuthMetricsDisabled, 'Metrics endpoint unavailable: metrics_bearer_token must be configured when secure mode is enabled'];
        }

        if ($token === '') {
            return null;
        }

        $auth = $request->getHeaderLine('Authorization');
        if ($auth === '') {
            return [ErrorCode::ErrAuthMetricsTokenRequired, 'Authorization required for metrics endpoint'];
        }

        if (!str_starts_with($auth, self::BearerPrefix)) {
            return [ErrorCode::ErrAuthMetricsTokenRequired, 'Authorization header must use Bearer scheme'];
        }

        $provided = substr($auth, strlen(self::BearerPrefix));
        if (!hash_equals($token, $provided)) {
            return [ErrorCode::ErrAuthInvalidBearerToken, 'Invalid bearer token'];
        }

        return null;
    }

    /**
     * writeMetricsAuthError is the plain net/http counterpart of abortWithCode.
     * It also fixes the previous handler, which sent JSON bodies through
     * http.Error and therefore with a text/plain content type.
     */
    public static function writeMetricsAuthError(string $code, string $message): ResponseInterface
    {
        $resp = ErrorResponse::newErrorResponse($code, $message, null);
        $payload = [
            'error' => $message,
            'error_detail' => ['code' => $resp->code, 'message' => $resp->message],
        ];
        $response = (new ResponseFactory())->createResponse(ErrorCode::statusForCode($code));
        // Go: json.NewEncoder(w).Encode(payload) — appends a trailing newline.
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . "\n");

        return $response->withHeader('Content-Type', 'application/json');
    }
}
