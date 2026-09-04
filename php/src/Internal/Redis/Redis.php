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
 * Redis holds the Redis client and addresses of Redis instances.
 *
 * Port of Go `redis_db.Redis`. The Go version wraps a
 * `redis.UniversalClient` (standalone or cluster); the PHP port wraps a
 * phpredis `\Redis` client and supports standalone servers only (see
 * {@see RedisDb::newRedisClient()} for the documented cluster divergence).
 */
final class Redis
{
    /**
     * Redis server addresses.
     *
     * @var string[]
     */
    private array $addresses;

    /** The connected phpredis client. */
    private \Redis $client;

    /**
     * @param string[] $addresses
     */
    public function __construct(array $addresses, \Redis $client)
    {
        $this->addresses = $addresses;
        $this->client = $client;
    }

    /**
     * Client returns the Redis client.
     * It can be used directly for Redis operations like Get, Set, or Publish.
     */
    public function client(): \Redis
    {
        return $this->client;
    }

    /**
     * MakeRedisClient returns the Redis client, allowing compatibility with
     * other packages or tools (mirrors the Go `interface{}` return).
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
}
