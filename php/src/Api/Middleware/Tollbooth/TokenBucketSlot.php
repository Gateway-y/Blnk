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

use Blnk\Api\Middleware\Tollbooth\Rate\Limiter;

/**
 * TokenBucketSlot is one key's entry of a {@see TokenBucketCache}, handed to
 * the callback of {@see TokenBucketCache::locked()} while the entry's file is
 * exclusively locked. Its get()/set() carry the semantics of expirable-cache's
 * `Get` and `Set` for that single key; the cache persists the slot when the
 * callback returns and something changed.
 */
final class TokenBucketSlot
{
    private ?int $expiresAt = null;
    private ?Limiter $limiter = null;
    private string $original;
    private int $defaultTTL;
    private bool $inserted = false;

    /**
     * @param string $raw        The stored file content ("" when the entry does not exist yet).
     * @param int    $defaultTTL The cache-wide TTL (nanoseconds) used for a Set with ttl 0.
     */
    public function __construct(string $raw, int $defaultTTL)
    {
        $this->original = $raw;
        $this->defaultTTL = $defaultTTL;

        $entry = self::decode($raw);
        if ($entry !== null) {
            $this->expiresAt = $entry['expires_at'];
            $this->limiter = Limiter::fromState($entry['limiter']);
        }
    }

    /**
     * Get returns the key value if it's not expired
     *
     * (expirable-cache: `if time.Now().After(ent.expiresAt) { return value, false }` —
     * the expired entry stays in place until a Set replaces it.)
     */
    public function get(): ?Limiter
    {
        if ($this->limiter === null || $this->expiresAt === null) {
            return null;
        }
        // Expired item check
        if (Clock::after(Clock::now(), $this->expiresAt)) {
            return null;
        }

        return $this->limiter;
    }

    /**
     * Set key, ttl of 0 would use cache-wide TTL
     *
     * (expirable-cache `addWithTTL`: an existing entry — expired or not — is
     * updated in place with a fresh expiresAt = now + ttl; otherwise a new
     * entry is added.)
     */
    public function set(Limiter $value, int $ttl): void
    {
        $now = Clock::now();
        if ($ttl === 0) {
            $ttl = $this->defaultTTL;
        }

        if ($this->limiter === null) {
            // Add new item
            $this->inserted = true;
        }
        $this->limiter = $value;
        $this->expiresAt = Clock::add($now, $ttl);
    }

    /**
     * expiration mirrors `GetExpiration` for the slot: the stored expiresAt or
     * null when there is no entry.
     */
    public function expiration(): ?int
    {
        return $this->limiter === null ? null : $this->expiresAt;
    }

    /**
     * inserted reports whether set() added a new key (rather than updating an
     * existing entry) — the moment expirable-cache prunes its oldest expired
     * entry.
     */
    public function inserted(): bool
    {
        return $this->inserted;
    }

    /**
     * dirty reports whether the persisted form differs from what was loaded:
     * a Set, or an Allow that changed the bucket's tokens/last.
     */
    public function dirty(): bool
    {
        return $this->limiter !== null && $this->encode() !== $this->original;
    }

    /**
     * encode serializes the entry for the bucket file.
     */
    public function encode(): string
    {
        if ($this->limiter === null) {
            return '';
        }

        return json_encode([
            'expires_at' => $this->expiresAt,
            'limiter' => $this->limiter->state(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * decode parses a bucket file; a missing, empty or corrupt file reads as
     * "no entry".
     *
     * @return array{expires_at: int, limiter: array<string, mixed>}|null
     */
    public static function decode(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        try {
            $entry = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($entry) || !isset($entry['expires_at']) || !is_int($entry['expires_at']) || !isset($entry['limiter']) || !is_array($entry['limiter'])) {
            return null;
        }

        return ['expires_at' => $entry['expires_at'], 'limiter' => $entry['limiter']];
    }
}
