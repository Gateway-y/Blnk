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
 * Port of Go `internal/redis-db` (`redisdb.go`): the package-level
 * `ParseRedisURL` and `NewRedisClient` functions, backed by phpredis.
 *
 * DIVERGENCE (documented per PORTING.md): the Go implementation returns a
 * go-redis `UniversalClient` and transparently supports Redis Cluster when
 * multiple addresses are supplied. The PHP port fully supports standalone
 * Redis (single address, TLS, auth, db selection); Redis Cluster/Sentinel
 * are NOT supported — passing multiple addresses throws a \RuntimeException.
 */
final class RedisDb
{
    /** Connection/ping validation budget, mirroring the Go 500ms ping context. */
    private const CONNECT_TIMEOUT_SEC = 0.5;

    private function __construct()
    {
    }

    /**
     * ParseRedisURL parses a Redis URL into Redis options, handling various URL
     * formats including Azure Redis Cache URLs with special characters in passwords.
     *
     * Mirrors Go `redis_db.ParseRedisURL`.
     */
    public static function parseRedisURL(string $rawURL, bool $skipTLSVerify): RedisOptions
    {
        // Don't modify docker-style addresses (e.g. redis:6379)
        if (substr_count($rawURL, ':') === 1 && !str_contains($rawURL, '@') && !str_contains($rawURL, '//')) {
            $opts = new RedisOptions();
            $opts->addr = $rawURL;
            return $opts;
        }

        // Handle URLs that have redis:// prefix with password but no colon
        if (str_starts_with($rawURL, 'redis://') && str_contains($rawURL, '@')) {
            $parts = explode('@', substr($rawURL, strlen('redis://')));
            if (count($parts) === 2) {
                $authParts = explode(':', $parts[0]);
                if (count($authParts) === 1) {
                    // No username, just password - keep original logic
                    $rawURL = sprintf('redis://:%s@%s', $parts[0], $parts[1]);
                }
            }
        }

        // Parse the URL (equivalent of go-redis redis.ParseURL).
        $opts = self::parseURL($rawURL);
        if ($opts === null) {
            // If ParseURL fails, try manual parsing
            $host = $rawURL;
            $password = '';

            // Extract password if present
            if (str_contains($rawURL, '@')) {
                $parts = explode('@', $rawURL);
                if (count($parts) === 2) {
                    $password = $parts[0];
                    if (str_starts_with($password, 'redis://')) {
                        $password = substr($password, strlen('redis://'));
                    }
                    $host = $parts[1];
                }
            }

            $opts = new RedisOptions();
            $opts->addr = $host;
            $opts->password = $password;
            $opts->db = 0;

            // Enable TLS for Azure Redis
            if (str_contains($host, 'redis.cache.windows.net')) {
                $opts->useTLS = true;
            }
        }

        // Apply TLS skip verify if configured and TLS is enabled
        if ($opts->useTLS && $skipTLSVerify) {
            $opts->skipTLSVerify = true;
        }

        return $opts;
    }

    /**
     * NewRedisClient creates a new Redis client connection based on the provided
     * list of addresses.
     *
     * Parameters:
     * - $addresses: A list of Redis addresses. Only a single address (standalone
     *   Redis) is supported by the PHP port — see the class-level divergence note.
     * - $skipTLSVerify: Whether to skip TLS certificate verification.
     * - $pool: Optional pool settings (kept for parity; phpredis has no
     *   client-side pool, so the values are recorded but not applied).
     *
     * @param string[] $addresses
     * @throws \RuntimeException if the address list is empty, contains more than
     *                           one address (cluster is unsupported), or the
     *                           connection/ping fails.
     */
    public static function newRedisClient(array $addresses, bool $skipTLSVerify = false, ?PoolConfig $pool = null): Redis
    {
        // Ensure at least one address is provided
        if (count($addresses) === 0) {
            throw new \RuntimeException('redis addresses list cannot be empty');
        }

        // Resolve pool config (use provided or defaults)
        $pc = $pool ?? new PoolConfig();
        if ($pc->poolSize === 0) {
            $pc->poolSize = 100;
        }
        if ($pc->minIdleConns === 0) {
            $pc->minIdleConns = 20;
        }

        if (count($addresses) > 1) {
            // Go builds a Redis Cluster client here; the PHP port does not
            // support cluster mode (documented divergence).
            throw new \RuntimeException('redis cluster mode (multiple addresses) is not supported by the PHP port; provide a single Redis address');
        }

        $opts = self::parseRedisURL($addresses[0], $skipTLSVerify);
        $opts->poolSize = $pc->poolSize;
        $opts->minIdleConns = $pc->minIdleConns;

        $client = self::connect($opts);

        return new Redis($addresses, $client);
    }

    /**
     * Establishes and validates a phpredis connection from parsed options.
     *
     * @throws \RuntimeException on connect/auth/select/ping failure.
     */
    private static function connect(RedisOptions $opts): \Redis
    {
        $client = new \Redis();
        $host = $opts->host();
        if ($opts->useTLS) {
            $host = 'tls://' . $host;
        }

        $context = [];
        if ($opts->useTLS) {
            $context['stream'] = [
                'verify_peer' => !$opts->skipTLSVerify,
                'verify_peer_name' => !$opts->skipTLSVerify,
            ];
        }

        try {
            $connected = $client->connect($host, $opts->port(), self::CONNECT_TIMEOUT_SEC, null, 0, 0.0, $context);
            if ($connected === false) {
                throw new \RuntimeException(sprintf('failed to connect to redis at %s', $opts->addr));
            }

            if ($opts->password !== '') {
                $auth = $opts->username !== '' ? [$opts->username, $opts->password] : $opts->password;
                if ($client->auth($auth) === false) {
                    throw new \RuntimeException(sprintf('redis authentication failed for %s', $opts->addr));
                }
            }

            if ($opts->db !== 0 && $client->select($opts->db) === false) {
                throw new \RuntimeException(sprintf('failed to select redis db %d on %s', $opts->db, $opts->addr));
            }

            // Verify the connection, mirroring the Go 500ms Ping.
            $client->ping();
        } catch (\RedisException $e) {
            throw new \RuntimeException(sprintf('redis connection to %s failed: %s', $opts->addr, $e->getMessage()), 0, $e);
        }

        return $client;
    }

    /**
     * Equivalent of go-redis `redis.ParseURL`: accepts redis:// and rediss://
     * URLs with optional user:password, database in the path (or `db` query
     * parameter). Returns null on any parse failure (the caller then applies
     * the Go manual-parsing fallback).
     */
    private static function parseURL(string $rawURL): ?RedisOptions
    {
        $parsed = parse_url($rawURL);
        if ($parsed === false || !isset($parsed['scheme'])) {
            return null;
        }
        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'redis' && $scheme !== 'rediss') {
            return null;
        }

        $opts = new RedisOptions();
        $opts->useTLS = ($scheme === 'rediss');

        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? 6379;
        $opts->addr = sprintf('%s:%d', $host, $port);

        if (isset($parsed['user'])) {
            $opts->username = rawurldecode($parsed['user']);
        }
        if (isset($parsed['pass'])) {
            $opts->password = rawurldecode($parsed['pass']);
        }

        $path = trim($parsed['path'] ?? '', '/');
        if ($path !== '') {
            if (preg_match('/^\d+$/', $path) !== 1) {
                return null; // go-redis: "invalid redis database number"
            }
            $opts->db = (int) $path;
        }

        if (isset($parsed['query']) && $parsed['query'] !== '') {
            parse_str($parsed['query'], $query);
            if (isset($query['db']) && is_string($query['db']) && preg_match('/^\d+$/', $query['db']) === 1) {
                $opts->db = (int) $query['db'];
            }
        }

        return $opts;
    }
}
