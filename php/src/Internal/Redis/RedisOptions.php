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
 * RedisOptions is the PHP counterpart of go-redis `redis.Options` as produced
 * by `redis_db.ParseRedisURL`: the connection parameters extracted from a
 * Redis DNS/URL string.
 */
final class RedisOptions
{
    /** host:port address of the Redis server (go-redis `Options.Addr`). */
    public string $addr = '';

    /** Optional ACL username. */
    public string $username = '';

    /** Optional password. */
    public string $password = '';

    /** Database number (go-redis `Options.DB`). */
    public int $db = 0;

    /** Whether to use TLS for the connection (go-redis `Options.TLSConfig != nil`). */
    public bool $useTLS = false;

    /** Whether to skip TLS certificate verification (`tls.Config.InsecureSkipVerify`). */
    public bool $skipTLSVerify = false;

    /** Pool size (applied for parity only; see {@see PoolConfig}). */
    public int $poolSize = 0;

    /** Minimum idle connections (parity only; see {@see PoolConfig}). */
    public int $minIdleConns = 0;

    /** The host part of {@see RedisOptions::$addr}. */
    public function host(): string
    {
        $pos = strrpos($this->addr, ':');
        if ($pos === false) {
            return $this->addr;
        }
        return substr($this->addr, 0, $pos);
    }

    /** The port part of {@see RedisOptions::$addr} (defaults to 6379). */
    public function port(): int
    {
        $pos = strrpos($this->addr, ':');
        if ($pos === false) {
            return 6379;
        }
        $port = substr($this->addr, $pos + 1);
        return $port === '' ? 6379 : (int) $port;
    }
}
