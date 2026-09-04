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

use Blnk\Api\Json;
use Blnk\Api\Middleware\Tollbooth\Clock;
use Blnk\Api\Middleware\Tollbooth\ExpirableOptions;
use Blnk\Api\Middleware\Tollbooth\Limiter;
use Blnk\Api\Middleware\Tollbooth\TokenBucketCache;
use Blnk\Api\Middleware\Tollbooth\Tollbooth;
use Blnk\Config\Configuration;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\ApiError\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Port of `RateLimitMiddleware` (api/middleware/middleware.go).
 *
 * RateLimitMiddleware creates a middleware for rate limiting using Tollbooth.
 * It sets up rate limiting based on the configuration parameters and applies it to incoming requests.
 *
 * Tollbooth (github.com/didip/tollbooth/v7 v7.0.2) is ported 1:1 into
 * {@see \Blnk\Api\Middleware\Tollbooth}: the request is keyed by
 * "<client IP>|<path>|" where the IP comes from X-Forwarded-For (last entry),
 * then X-Real-IP, then the connection's remote address, canonicalized to the
 * /64 prefix for IPv6; one token bucket per key (x/time/rate semantics: rate
 * = requests_per_second, capacity = burst, initially full) that expires
 * cleanup_interval_sec after its creation; a limited request is answered
 * with Tollbooth's 429 and message; every inspected request carries the
 * X-Rate-Limit-* and RateLimit-* headers. The only thing PHP cannot mirror
 * literally — the buckets living in the memory of the Go process — becomes
 * a file store private to this PHP server ({@see TokenBucketCache}), shared
 * by its worker processes and by nothing else, so the limiter stays
 * per-instance rather than cluster-wide.
 *
 * Gin sets Tollbooth's headers on the writer before running the handler, so
 * they also appear on the responses written after the chain — the 500 its
 * recovery writes for a panicking handler and the NoRoute 404. Here Slim
 * raises those conditions as exceptions from the innermost kernel; they
 * unwind past this middleware to {@see \Blnk\Api\LogrusRecovery}, whose
 * synthesized 500/404 therefore carries no rate-limit headers (the limiter
 * itself has still counted the request).
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /** The Tollbooth limiter; null when rate limiting is disabled. */
    private ?Limiter $lmt = null;

    /**
     * Parameters:
     * - $conf: The configuration object containing rate limit settings.
     * - $bucketDir: Directory of the token bucket files; the server's default
     *   directory ({@see TokenBucketCache::defaultDir()}) when null.
     */
    public function __construct(Configuration $conf, ?string $bucketDir = null)
    {
        if ($conf->rateLimit->requestsPerSecond === null || $conf->rateLimit->burst === null) {
            // Rate limiting is disabled if RequestsPerSecond or Burst are not set.
            return;
        }

        $rps = $conf->rateLimit->requestsPerSecond;
        $burst = $conf->rateLimit->burst;
        $cleanupSec = Configuration::DEFAULT_CLEANUP_SEC;
        if ($conf->rateLimit->cleanupIntervalSec !== null) {
            $cleanupSec = $conf->rateLimit->cleanupIntervalSec;
        }
        $ttl = $cleanupSec * Clock::Second;

        // Create a new Tollbooth limiter with the specified rate and expiration time.
        $lmt = Tollbooth::newLimiter($rps, new ExpirableOptions(
            defaultExpirationTTL: $ttl,
        ), $bucketDir ?? TokenBucketCache::defaultDir(self::instanceIdentity($conf)));
        $lmt->setBurst($burst);

        $this->lmt = $lmt;
    }

    /**
     * RateLimitMiddleware — static factory mirroring the Go function name.
     *
     * Parameters:
     * - $conf: The configuration object containing rate limit settings.
     *
     * Returns a middleware that applies rate limiting to requests.
     */
    public static function rateLimitMiddleware(Configuration $conf, ?string $bucketDir = null): self
    {
        return new self($conf, $bucketDir);
    }

    /**
     * limiter exposes the Tollbooth limiter (null when disabled).
     */
    public function limiter(): ?Limiter
    {
        return $this->lmt;
    }

    /**
     * Middleware function that applies rate limiting to requests.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->lmt === null) {
            return $handler->handle($request);
        }

        $headers = [];
        $httpError = Tollbooth::limitByRequest($this->lmt, $headers, $request);
        if ($httpError !== null) {
            // Respond with an error if the request exceeds the rate limit.
            // Tollbooth's status code stays authoritative for the response.
            $response = Json::write((new ResponseFactory())->createResponse(), $httpError->statusCode, [
                'error' => $httpError->message,
                'error_detail' => ErrorResponse::newErrorResponse(ErrorCode::ErrGenRateLimited, $httpError->message, null)->jsonSerialize()['error'],
            ]);

            return Tollbooth::applyHeaders($response, $headers);
        }

        return Tollbooth::applyHeaders($handler->handle($request), $headers);
    }

    /**
     * instanceIdentity distinguishes this server's bucket store from that of
     * another Blnk deployment on the same host (the code path and user are
     * added by TokenBucketCache::defaultDir): the configured server port.
     */
    private static function instanceIdentity(Configuration $conf): string
    {
        return 'port=' . $conf->server->port;
    }
}
