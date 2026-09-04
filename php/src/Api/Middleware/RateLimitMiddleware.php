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
use Blnk\Config\Configuration;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\ApiError\ErrorResponse;
use Blnk\Internal\Log;
use Blnk\Internal\Redis\PoolConfig;
use Blnk\Internal\Redis\RedisDb;
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
 * Documented divergence: Tollbooth keeps one in-memory token bucket
 * (golang.org/x/time/rate) per client key inside the Go process. A PHP
 * process serves one request at a time, so the buckets live in Redis instead
 * and are updated atomically by a Lua script implementing the same token
 * bucket (rate = requests_per_second, capacity = burst, refill on demand). The
 * bucket key mirrors Tollbooth's default `BuildKeys`: remote address + request
 * path. Buckets expire after the configured cleanup interval
 * (Tollbooth's DefaultExpirationTTL). When Redis is unreachable the request is
 * allowed through (fail-open) and the failure is logged — Tollbooth cannot
 * fail, so blocking traffic on an infrastructure error would be new behavior.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /** Tollbooth's default message and status for a limited request. */
    public const LimitMessage = 'You have reached maximum request limit.';
    public const LimitStatusCode = 429;

    /** Redis key prefix of the per-client token buckets. */
    public const KeyPrefix = 'blnk:ratelimit:';

    /**
     * Token bucket, executed atomically on the Redis server.
     * KEYS[1] = bucket key; ARGV = rate (tokens/s), burst, now (seconds, float), ttl (seconds).
     * Returns 1 when a token was taken (request allowed), 0 otherwise.
     */
    private const TOKEN_BUCKET_SCRIPT = <<<'LUA'
local key = KEYS[1]
local rate = tonumber(ARGV[1])
local burst = tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local ttl = tonumber(ARGV[4])
local data = redis.call('HMGET', key, 'tokens', 'ts')
local tokens = tonumber(data[1])
local ts = tonumber(data[2])
if tokens == nil or ts == nil then
  tokens = burst
  ts = now
end
local elapsed = now - ts
if elapsed < 0 then elapsed = 0 end
tokens = tokens + elapsed * rate
if tokens > burst then tokens = burst end
local allowed = 0
if tokens >= 1 then
  tokens = tokens - 1
  allowed = 1
end
redis.call('HSET', key, 'tokens', tokens, 'ts', now)
redis.call('EXPIRE', key, ttl)
return allowed
LUA;

    private bool $enabled;
    private float $rps = 0.0;
    private int $burst = 0;
    private int $ttl = 0;
    private Configuration $conf;
    private ?\Redis $redis;

    /**
     * Parameters:
     * - $conf: The configuration object containing rate limit settings.
     * - $redis: Optional Redis client; when omitted one is opened lazily from
     *   the configured Redis DNS on the first limited request.
     */
    public function __construct(Configuration $conf, ?\Redis $redis = null)
    {
        $this->conf = $conf;
        $this->redis = $redis;

        if ($conf->rateLimit->requestsPerSecond === null || $conf->rateLimit->burst === null) {
            // Rate limiting is disabled if RequestsPerSecond or Burst are not set.
            $this->enabled = false;

            return;
        }

        $this->enabled = true;
        $this->rps = $conf->rateLimit->requestsPerSecond;
        $this->burst = $conf->rateLimit->burst;
        $cleanupSec = Configuration::DEFAULT_CLEANUP_SEC;
        if ($conf->rateLimit->cleanupIntervalSec !== null) {
            $cleanupSec = $conf->rateLimit->cleanupIntervalSec;
        }
        $this->ttl = $cleanupSec;
    }

    /**
     * RateLimitMiddleware — static factory mirroring the Go function name.
     */
    public static function rateLimitMiddleware(Configuration $conf, ?\Redis $redis = null): self
    {
        return new self($conf, $redis);
    }

    /**
     * Middleware function that applies rate limiting to requests.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        $remoteAddr = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $limited = !$this->allow($remoteAddr, $request->getUri()->getPath());

        if ($limited) {
            // Respond with an error if the request exceeds the rate limit.
            // Tollbooth's status code stays authoritative for the response.
            $resp = ErrorResponse::newErrorResponse(ErrorCode::ErrGenRateLimited, self::LimitMessage, null);
            $response = Json::write((new ResponseFactory())->createResponse(), self::LimitStatusCode, [
                'error' => self::LimitMessage,
                'error_detail' => ['code' => $resp->code, 'message' => $resp->message],
            ]);

            return $this->withTollboothHeaders($response, $request, $remoteAddr);
        }

        return $this->withTollboothHeaders($handler->handle($request), $request, $remoteAddr);
    }

    /**
     * withTollboothHeaders adds the informational headers Tollbooth sets on
     * every request it inspects.
     */
    private function withTollboothHeaders(ResponseInterface $response, ServerRequestInterface $request, string $remoteAddr): ResponseInterface
    {
        return $response
            ->withHeader('X-Rate-Limit-Limit', sprintf('%.2f', $this->rps))
            ->withHeader('X-Rate-Limit-Duration', '1')
            ->withHeader('X-Rate-Limit-Request-Forwarded-For', $request->getHeaderLine('X-Forwarded-For'))
            ->withHeader('X-Rate-Limit-Request-Remote-Addr', $remoteAddr);
    }

    /**
     * allow takes one token from the client's bucket, reporting whether the
     * request may proceed.
     */
    private function allow(string $remoteAddr, string $path): bool
    {
        $client = $this->client();
        if ($client === null) {
            return true;
        }

        $key = self::KeyPrefix . $remoteAddr . ':' . $path;
        try {
            $result = $client->eval(self::TOKEN_BUCKET_SCRIPT, [
                $key,
                (string) $this->rps,
                (string) $this->burst,
                sprintf('%.6F', microtime(true)),
                (string) max(1, $this->ttl),
            ], 1);
        } catch (\Throwable $e) {
            Log::get()->error('rate limiter: redis evaluation failed, allowing request', ['error' => $e->getMessage()]);

            return true;
        }

        return (int) $result === 1;
    }

    private function client(): ?\Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }
        try {
            $this->redis = RedisDb::newRedisClient(
                [$this->conf->redis->dns],
                $this->conf->redis->skipTLSVerify,
                new PoolConfig($this->conf->redis->poolSize, $this->conf->redis->minIdleConns)
            )->client();
        } catch (\Throwable $e) {
            Log::get()->error('rate limiter: unable to connect to redis, allowing request', ['error' => $e->getMessage()]);

            return null;
        }

        return $this->redis;
    }
}
