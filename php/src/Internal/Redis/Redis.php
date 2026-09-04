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

namespace Blnk\Internal\Redis;

/**
 * Redis struct holds the Redis client and addresses of Redis instances.
 * It supports both single-instance Redis connections and Redis Cluster setups.
 *
 * Port of Go `redis_db.Redis`. The Go `redis.UniversalClient` (standalone or
 * cluster) maps onto phpredis' `\Redis` (single address) or `\RedisCluster`
 * (several addresses) — see {@see RedisDb::newRedisClient()}.
 */
final class Redis
{
    /**
     * Redis server addresses.
     *
     * @var string[]
     */
    private array $addresses;

    /** Redis universal client (works for both single and clustered Redis). */
    private \Redis|\RedisCluster $client;

    /**
     * @param string[] $addresses
     */
    public function __construct(array $addresses, \Redis|\RedisCluster $client)
    {
        $this->addresses = $addresses;
        $this->client = $client;
    }

    /**
     * Client returns the Redis universal client.
     * It can be used directly for Redis operations like Get, Set, or Publish.
     *
     * Returns:
     * - The universal Redis client, which supports both standalone and clustered Redis instances.
     */
    public function client(): \Redis|\RedisCluster
    {
        return $this->client;
    }

    /**
     * MakeRedisClient returns the Redis client interface, allowing compatibility
     * with other packages or tools (mirrors the Go `interface{}` return).
     */
    public function makeRedisClient(): mixed
    {
        return $this->client;
    }

    /**
     * The addresses this wrapper was created with.
     *
     * @return string[]
     */
    public function addresses(): array
    {
        return $this->addresses;
    }

    /** Whether the wrapped client is a Redis Cluster client. */
    public function isCluster(): bool
    {
        return $this->client instanceof \RedisCluster;
    }
}
