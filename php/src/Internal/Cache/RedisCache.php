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

namespace Blnk\Internal\Cache;

use Blnk\Config\Configuration;
use Blnk\Internal\Redis\PoolConfig;
use Blnk\Internal\Redis\RedisDb;

/**
 * RedisCache implements the Cache interface, using Redis as the underlying
 * cache store. It leverages both Redis and local in-memory caching for
 * efficient lookups.
 *
 * Port of Go `cache.RedisCache` (go-redis/cache/v9).
 *
 * DIVERGENCES (documented per PORTING.md):
 * - Go serializes values with msgpack; the PHP port uses PHP `serialize()` —
 *   the cache is written and read only by this PHP codebase, so the wire
 *   format is internal.
 * - Go's local cache is a TinyLFU with 128000 entries and a 1-minute TTL,
 *   shared across goroutines; PHP's request model is single-threaded, so the
 *   local cache is a plain bounded per-process array with the same entry cap
 *   and 1-minute TTL (FIFO eviction instead of TinyLFU).
 */
final class RedisCache implements CacheInterface
{
    /** cacheSize defines the size of the local cache (in number of entries) used alongside Redis. */
    public const CACHE_SIZE = 128000;

    /** Local cache entry TTL, mirroring the Go TinyLFU 1-minute TTL. */
    private const LOCAL_TTL_SEC = 60;

    /** The standalone or cluster client of {@see RedisDb::newRedisClient()}. */
    private \Redis|\RedisCluster $client;

    /**
     * Local in-process cache: key => [expiresAt (float unix ts), serialized value].
     *
     * @var array<string, array{0: float, 1: string}>
     */
    private array $local = [];

    private function __construct(\Redis|\RedisCluster $client)
    {
        $this->client = $client;
    }

    /**
     * NewCache creates a new instance of RedisCache by establishing a connection
     * to Redis. It fetches the configuration, initializes Redis, and returns a
     * Cache instance.
     *
     * @throws \RuntimeException if the configuration or Redis initialization fails.
     */
    public static function newCache(): CacheInterface
    {
        // Fetch configuration settings
        $cfg = Configuration::fetch();

        // Initialize Redis cache with the configured Redis DNS and pool settings
        return self::newRedisCache([$cfg->redis->dns], $cfg->redis->skipTLSVerify, $cfg->redis->poolSize, $cfg->redis->minIdleConns);
    }

    /**
     * NewCacheWithClient creates a new RedisCache using an existing Redis client.
     * No exception is thrown because no I/O occurs — the client is already validated.
     */
    public static function newCacheWithClient(\Redis|\RedisCluster $client): CacheInterface
    {
        return new self($client);
    }

    /**
     * newRedisCache sets up a Redis-backed cache with local caching.
     *
     * Parameters:
     * - $addresses: The Redis server addresses to connect to.
     *
     * @param string[] $addresses
     * @throws \RuntimeException if the connection fails.
     */
    private static function newRedisCache(array $addresses, bool $skipTLSVerify, int $poolSize, int $minIdleConns): self
    {
        // Initialize the Redis client using the provided addresses
        $client = RedisDb::newRedisClient($addresses, $skipTLSVerify, new PoolConfig($poolSize, $minIdleConns));

        return new self($client->client());
    }

    /**
     * Set adds a new entry to the cache with a specified key and TTL.
     *
     * Parameters:
     * - $key: The cache key under which to store the value.
     * - $data: The value to be cached.
     * - $ttl: The time-to-live (seconds) for the cached value.
     *
     * @throws \RuntimeException if the caching operation fails.
     */
    public function set(string $key, mixed $data, int|float $ttl): void
    {
        $payload = serialize($data);

        try {
            if ($ttl > 0) {
                $ok = $this->client->set($key, $payload, ['px' => (int) round($ttl * 1000)]);
            } else {
                $ok = $this->client->set($key, $payload);
            }
        } catch (\RedisException|\RedisClusterException $e) {
            throw new \RuntimeException(sprintf('cache set failed for key %s: %s', $key, $e->getMessage()), 0, $e);
        }
        if ($ok === false) {
            throw new \RuntimeException(sprintf('cache set failed for key %s', $key));
        }

        $this->localSet($key, $payload);
    }

    /**
     * Get retrieves an entry from the cache based on the provided key.
     *
     * Parameters:
     * - $key: The cache key to retrieve.
     * - $data: The variable to store the retrieved data. Left untouched on a
     *   cache miss (Go returns nil on cache.ErrCacheMiss).
     *
     * @throws \RuntimeException if the retrieval fails (not on cache miss).
     */
    public function get(string $key, mixed &$data): void
    {
        $payload = $this->localGet($key);
        if ($payload === null) {
            try {
                $raw = $this->client->get($key);
            } catch (\RedisException|\RedisClusterException $e) {
                throw new \RuntimeException(sprintf('cache get failed for key %s: %s', $key, $e->getMessage()), 0, $e);
            }
            if ($raw === false || !is_string($raw)) {
                return; // cache miss
            }
            $payload = $raw;
            $this->localSet($key, $payload);
        }

        $value = @unserialize($payload);
        if ($value === false && $payload !== serialize(false)) {
            throw new \RuntimeException(sprintf('cache get failed for key %s: unable to unserialize cached value', $key));
        }
        $data = $value;
    }

    /**
     * Delete removes an entry from the cache based on the provided key.
     *
     * Parameters:
     * - $key: The cache key to delete.
     *
     * @throws \RuntimeException if the deletion fails.
     */
    public function delete(string $key): void
    {
        unset($this->local[$key]);
        try {
            $this->client->del($key);
        } catch (\RedisException|\RedisClusterException $e) {
            throw new \RuntimeException(sprintf('cache delete failed for key %s: %s', $key, $e->getMessage()), 0, $e);
        }
    }

    private function localSet(string $key, string $payload): void
    {
        if (count($this->local) >= self::CACHE_SIZE) {
            // Evict the oldest entry (FIFO); Go uses TinyLFU — see class PHPDoc.
            $oldest = array_key_first($this->local);
            if ($oldest !== null) {
                unset($this->local[$oldest]);
            }
        }
        $this->local[$key] = [microtime(true) + self::LOCAL_TTL_SEC, $payload];
    }

    private function localGet(string $key): ?string
    {
        if (!isset($this->local[$key])) {
            return null;
        }
        [$expiresAt, $payload] = $this->local[$key];
        if ($expiresAt < microtime(true)) {
            unset($this->local[$key]);
            return null;
        }
        return $payload;
    }
}
