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

namespace Blnk\Api\Middleware\Tollbooth;

use Blnk\Api\Middleware\Tollbooth\Rate\Limiter as RateLimiter;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of `limiter.Limiter` (github.com/didip/tollbooth/v7/limiter/limiter.go),
 * the config struct to limit a particular request handler.
 *
 * Go guards every field with an RWMutex; a PHP request is single-threaded so
 * the getters/setters are plain. The token buckets are kept in a
 * {@see TokenBucketCache} (expirable-cache stand-in) whose per-key file lock
 * plays the role of the mutex LimitReached holds around Get/Set/Allow.
 *
 * Subset notes: the basic-auth user, header and context-value entry lists
 * are plain arrays here where Go keeps them in expirable caches (their
 * entries do not expire in this port; Blnk registers none), and the
 * OnLimitReached/OverrideDefaultResponseWriter hooks of LimitHandler — which
 * Gin/Slim never use — are not ported.
 */
final class Limiter
{
    /** Maximum number of requests to limit per second. */
    private float $max = 0.0;

    /** Limiter burst size */
    private int $burst = 0;

    /** HTTP message when limit is reached. */
    private string $message = '';

    /** Content-Type for Message */
    private string $messageContentType = '';

    /** HTTP status code when limit is reached. */
    private int $statusCode = 0;

    /**
     * List of places to look up IP address.
     * Default is "RemoteAddr", "X-Forwarded-For", "X-Real-IP".
     * You can rearrange the order as you like.
     *
     * @var string[]
     */
    private array $ipLookups = [];

    private int $forwardedForIndex = 0;

    /**
     * List of HTTP Methods to limit (GET, POST, PUT, etc.).
     * Empty means limit all methods.
     *
     * @var string[]
     */
    private array $methods = [];

    /** Able to configure token bucket expirations. */
    private ExpirableOptions $generalExpirableOptions;

    /**
     * List of basic auth usernames to limit.
     *
     * @var string[]
     */
    private array $basicAuthUsers = [];

    /**
     * Map of HTTP headers to limit.
     * Empty means skip headers checking.
     *
     * @var array<string, string[]>
     */
    private array $headers = [];

    /**
     * Map of Context values to limit.
     *
     * @var array<string, string[]>
     */
    private array $contextValues = [];

    /** Map of limiters with TTL */
    private TokenBucketCache $tokenBuckets;

    /** Ignore URL on the rate limiter keys */
    private bool $ignoreURL = false;

    private int $tokenBucketExpirationTTL = 0;
    private int $basicAuthExpirationTTL = 0;
    private int $headerEntryExpirationTTL = 0;
    private int $contextEntryExpirationTTL = 0;

    private function __construct()
    {
    }

    /**
     * New is a constructor for Limiter.
     *
     * @param string|null $bucketDir Directory of the token bucket files
     *                               ({@see TokenBucketCache}); the server's
     *                               default directory when null.
     */
    public static function new(?ExpirableOptions $generalExpirableOptions, ?string $bucketDir = null): self
    {
        $lmt = new self();

        $lmt->setMessageContentType('text/plain; charset=utf-8')
            ->setMessage('You have reached maximum request limit.')
            ->setStatusCode(429)
            ->setIPLookups(['RemoteAddr', 'X-Forwarded-For', 'X-Real-IP'])
            ->setForwardedForIndexFromBehind(0)
            ->setHeaders([])
            ->setContextValues([])
            ->setIgnoreURL(false);

        if ($generalExpirableOptions !== null) {
            $lmt->generalExpirableOptions = $generalExpirableOptions;
        } else {
            $lmt->generalExpirableOptions = new ExpirableOptions();
        }

        // Default for DefaultExpirationTTL is 10 years.
        if ($lmt->generalExpirableOptions->defaultExpirationTTL <= 0) {
            $lmt->generalExpirableOptions->defaultExpirationTTL = 87600 * Clock::Hour;
        }

        $lmt->tokenBuckets = (new TokenBucketCache($bucketDir ?? TokenBucketCache::defaultDir()))
            ->withTTL($lmt->generalExpirableOptions->defaultExpirationTTL);

        return $lmt;
    }

    /**
     * SetTokenBucketExpirationTTL is thread-safe way of setting custom token bucket expiration TTL.
     */
    public function setTokenBucketExpirationTTL(int $ttl): self
    {
        $this->tokenBucketExpirationTTL = $ttl;

        return $this;
    }

    /**
     * GetTokenBucketExpirationTTL is thread-safe way of getting custom token bucket expiration TTL.
     */
    public function getTokenBucketExpirationTTL(): int
    {
        return $this->tokenBucketExpirationTTL;
    }

    /**
     * SetBasicAuthExpirationTTL is thread-safe way of setting custom basic auth expiration TTL.
     */
    public function setBasicAuthExpirationTTL(int $ttl): self
    {
        $this->basicAuthExpirationTTL = $ttl;

        return $this;
    }

    /**
     * GetBasicAuthExpirationTTL is thread-safe way of getting custom basic auth expiration TTL.
     */
    public function getBasicAuthExpirationTTL(): int
    {
        return $this->basicAuthExpirationTTL;
    }

    /**
     * SetHeaderEntryExpirationTTL is thread-safe way of setting custom basic auth expiration TTL.
     */
    public function setHeaderEntryExpirationTTL(int $ttl): self
    {
        $this->headerEntryExpirationTTL = $ttl;

        return $this;
    }

    /**
     * GetHeaderEntryExpirationTTL is thread-safe way of getting custom basic auth expiration TTL.
     */
    public function getHeaderEntryExpirationTTL(): int
    {
        return $this->headerEntryExpirationTTL;
    }

    /**
     * SetContextValueEntryExpirationTTL is thread-safe way of setting custom Context value expiration TTL.
     */
    public function setContextValueEntryExpirationTTL(int $ttl): self
    {
        $this->contextEntryExpirationTTL = $ttl;

        return $this;
    }

    /**
     * GetContextValueEntryExpirationTTL is thread-safe way of getting custom Context value expiration TTL.
     */
    public function getContextValueEntryExpirationTTL(): int
    {
        return $this->contextEntryExpirationTTL;
    }

    /**
     * SetMax is thread-safe way of setting maximum number of requests to limit per second.
     */
    public function setMax(float $max): self
    {
        $this->max = $max;

        return $this;
    }

    /**
     * GetMax is thread-safe way of getting maximum number of requests to limit per second.
     */
    public function getMax(): float
    {
        return $this->max;
    }

    /**
     * SetBurst is thread-safe way of setting maximum burst size.
     */
    public function setBurst(int $burst): self
    {
        $this->burst = $burst;

        return $this;
    }

    /**
     * GetBurst is thread-safe way of setting maximum burst size.
     */
    public function getBurst(): int
    {
        return $this->burst;
    }

    /**
     * SetMessage is thread-safe way of setting HTTP message when limit is reached.
     */
    public function setMessage(string $msg): self
    {
        $this->message = $msg;

        return $this;
    }

    /**
     * GetMessage is thread-safe way of getting HTTP message when limit is reached.
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * SetMessageContentType is thread-safe way of setting HTTP message Content-Type when limit is reached.
     */
    public function setMessageContentType(string $contentType): self
    {
        $this->messageContentType = $contentType;

        return $this;
    }

    /**
     * GetMessageContentType is thread-safe way of getting HTTP message Content-Type when limit is reached.
     */
    public function getMessageContentType(): string
    {
        return $this->messageContentType;
    }

    /**
     * SetStatusCode is thread-safe way of setting HTTP status code when limit is reached.
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    /**
     * GetStatusCode is thread-safe way of getting HTTP status code when limit is reached.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * SetIPLookups is thread-safe way of setting list of places to look up IP address.
     *
     * @param string[] $ipLookups
     */
    public function setIPLookups(array $ipLookups): self
    {
        $this->ipLookups = $ipLookups;

        return $this;
    }

    /**
     * GetIPLookups is thread-safe way of getting list of places to look up IP address.
     *
     * @return string[]
     */
    public function getIPLookups(): array
    {
        return $this->ipLookups;
    }

    /**
     * SetIgnoreURL is thread-safe way of setting whenever ignore the URL on rate limit keys
     */
    public function setIgnoreURL(bool $enabled): self
    {
        $this->ignoreURL = $enabled;

        return $this;
    }

    /**
     * GetIgnoreURL returns whether the URL is ignored in the rate limit key set
     */
    public function getIgnoreURL(): bool
    {
        return $this->ignoreURL;
    }

    /**
     * SetForwardedForIndexFromBehind is thread-safe way of setting which X-Forwarded-For index to choose.
     */
    public function setForwardedForIndexFromBehind(int $forwardedForIndex): self
    {
        $this->forwardedForIndex = $forwardedForIndex;

        return $this;
    }

    /**
     * GetForwardedForIndexFromBehind is thread-safe way of getting which X-Forwarded-For index to choose.
     */
    public function getForwardedForIndexFromBehind(): int
    {
        return $this->forwardedForIndex;
    }

    /**
     * SetMethods is thread-safe way of setting list of HTTP Methods to limit (GET, POST, PUT, etc.).
     *
     * @param string[] $methods
     */
    public function setMethods(array $methods): self
    {
        $this->methods = $methods;

        return $this;
    }

    /**
     * GetMethods is thread-safe way of getting list of HTTP Methods to limit (GET, POST, PUT, etc.).
     *
     * @return string[]
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * SetBasicAuthUsers is thread-safe way of setting list of basic auth usernames to limit.
     *
     * @param string[] $basicAuthUsers
     */
    public function setBasicAuthUsers(array $basicAuthUsers): self
    {
        foreach ($basicAuthUsers as $basicAuthUser) {
            if (!Libstring::stringInSlice($this->basicAuthUsers, $basicAuthUser)) {
                $this->basicAuthUsers[] = $basicAuthUser;
            }
        }

        return $this;
    }

    /**
     * GetBasicAuthUsers is thread-safe way of getting list of basic auth usernames to limit.
     *
     * @return string[]
     */
    public function getBasicAuthUsers(): array
    {
        return $this->basicAuthUsers;
    }

    /**
     * RemoveBasicAuthUsers is thread-safe way of removing basic auth usernames from existing list.
     *
     * @param string[] $basicAuthUsers
     */
    public function removeBasicAuthUsers(array $basicAuthUsers): self
    {
        foreach ($basicAuthUsers as $toBeRemoved) {
            $this->basicAuthUsers = array_values(array_filter(
                $this->basicAuthUsers,
                static fn (string $user): bool => $user !== $toBeRemoved
            ));
        }

        return $this;
    }

    /**
     * DeleteExpiredTokenBuckets is thread-safe way of deleting expired token buckets
     */
    public function deleteExpiredTokenBuckets(): void
    {
        $this->tokenBuckets->deleteExpired();
    }

    /**
     * SetHeaders is thread-safe way of setting map of HTTP headers to limit.
     *
     * @param array<string, string[]> $headers
     */
    public function setHeaders(array $headers): self
    {
        foreach ($headers as $header => $entries) {
            $this->setHeader($header, $entries);
        }

        return $this;
    }

    /**
     * GetHeaders is thread-safe way of getting map of HTTP headers to limit.
     *
     * @return array<string, string[]>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * SetHeader is thread-safe way of setting entries of 1 HTTP header.
     *
     * @param string[] $entries
     */
    public function setHeader(string $header, array $entries): self
    {
        $existing = $this->headers[$header] ?? [];
        foreach ($entries as $entry) {
            if (!Libstring::stringInSlice($existing, $entry)) {
                $existing[] = $entry;
            }
        }
        $this->headers[$header] = $existing;

        return $this;
    }

    /**
     * GetHeader is thread-safe way of getting entries of 1 HTTP header.
     *
     * @return string[]
     */
    public function getHeader(string $header): array
    {
        return $this->headers[$header] ?? [];
    }

    /**
     * RemoveHeader is thread-safe way of removing entries of 1 HTTP header.
     */
    public function removeHeader(string $header): self
    {
        $this->headers[$header] = [];

        return $this;
    }

    /**
     * RemoveHeaderEntries is thread-safe way of removing new entries to 1 HTTP header rule.
     *
     * @param string[] $entriesForRemoval
     */
    public function removeHeaderEntries(string $header, array $entriesForRemoval): self
    {
        if (!array_key_exists($header, $this->headers)) {
            return $this;
        }

        $this->headers[$header] = array_values(array_filter(
            $this->headers[$header],
            static fn (string $entry): bool => !Libstring::stringInSlice($entriesForRemoval, $entry)
        ));

        return $this;
    }

    /**
     * SetContextValues is thread-safe way of setting map of HTTP headers to limit.
     *
     * @param array<string, string[]> $contextValues
     */
    public function setContextValues(array $contextValues): self
    {
        foreach ($contextValues as $contextValue => $entries) {
            $this->setContextValue($contextValue, $entries);
        }

        return $this;
    }

    /**
     * GetContextValues is thread-safe way of getting a map of Context values to limit.
     *
     * @return array<string, string[]>
     */
    public function getContextValues(): array
    {
        return $this->contextValues;
    }

    /**
     * SetContextValue is thread-safe way of setting entries of 1 Context value.
     *
     * @param string[] $entries
     */
    public function setContextValue(string $contextValue, array $entries): self
    {
        $existing = $this->contextValues[$contextValue] ?? [];
        foreach ($entries as $entry) {
            if (!Libstring::stringInSlice($existing, $entry)) {
                $existing[] = $entry;
            }
        }
        $this->contextValues[$contextValue] = $existing;

        return $this;
    }

    /**
     * GetContextValue is thread-safe way of getting 1 Context value entry.
     *
     * @return string[]
     */
    public function getContextValue(string $contextValue): array
    {
        return $this->contextValues[$contextValue] ?? [];
    }

    /**
     * RemoveContextValue is thread-safe way of removing entries of 1 Context value.
     */
    public function removeContextValue(string $contextValue): self
    {
        $this->contextValues[$contextValue] = [];

        return $this;
    }

    /**
     * RemoveContextValuesEntries is thread-safe way of removing entries to a ContextValue.
     *
     * @param string[] $entriesForRemoval
     */
    public function removeContextValuesEntries(string $contextValue, array $entriesForRemoval): self
    {
        if (!array_key_exists($contextValue, $this->contextValues)) {
            return $this;
        }

        $this->contextValues[$contextValue] = array_values(array_filter(
            $this->contextValues[$contextValue],
            static fn (string $entry): bool => !Libstring::stringInSlice($entriesForRemoval, $entry)
        ));

        return $this;
    }

    private function limitReachedWithTokenBucketTTL(string $key, int $tokenBucketTTL): bool
    {
        $lmtMax = $this->getMax();
        $lmtBurst = $this->getBurst();

        // Go: l.Lock() … defer l.Unlock() — the bucket's file lock.
        return $this->tokenBuckets->locked($key, static function (TokenBucketSlot $slot) use ($lmtMax, $lmtBurst, $tokenBucketTTL): bool {
            if ($slot->get() === null) {
                $slot->set(
                    RateLimiter::newLimiter($lmtMax, $lmtBurst),
                    $tokenBucketTTL
                );
            }

            $expiringMap = $slot->get();
            if ($expiringMap === null) {
                return false;
            }

            return !$expiringMap->allow();
        });
    }

    /**
     * LimitReached returns a bool indicating if the Bucket identified by key ran out of tokens.
     */
    public function limitReached(string $key): bool
    {
        $ttl = $this->getTokenBucketExpirationTTL();

        if ($ttl <= 0) {
            $ttl = $this->generalExpirableOptions->defaultExpirationTTL;
        }

        return $this->limitReachedWithTokenBucketTTL($key, $ttl);
    }

    /**
     * Tokens returns current amount of tokens left in the Bucket identified by key.
     */
    public function tokens(string $key): int
    {
        return $this->tokenBuckets->locked($key, static function (TokenBucketSlot $slot): int {
            $expiringMap = $slot->get();
            if ($expiringMap === null) {
                return 0;
            }

            return Clock::intFromFloat($expiringMap->tokensAt(Clock::now()));
        });
    }

    /**
     * tokenBuckets exposes the bucket store (for the worker CLI / tests).
     */
    public function tokenBuckets(): TokenBucketCache
    {
        return $this->tokenBuckets;
    }

    /**
     * clientIP is a convenience for callers that need the limiter's view of
     * the request's IP (RemoteIP over the configured lookups, canonicalized).
     */
    public function clientIP(ServerRequestInterface $r): string
    {
        return Libstring::canonicalizeIP(Libstring::remoteIP($this->getIPLookups(), $this->getForwardedForIndexFromBehind(), $r));
    }
}
