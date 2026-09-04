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

/**
 * Cache interface provides the basic operations for a cache system.
 * It includes methods for setting, getting, and deleting cached data.
 *
 * Port of Go `cache.Cache`. `context.Context` parameters are dropped (PHP is
 * synchronous); `time.Duration` TTLs become seconds (int|float).
 */
interface CacheInterface
{
    /**
     * Set stores a value in the cache with a specified time-to-live (TTL).
     *
     * Parameters:
     * - $key: The cache key under which the value will be stored.
     * - $value: The value to be stored in the cache.
     * - $ttl: The duration (seconds) the value should be retained in the cache.
     *
     * @throws \RuntimeException if the operation fails.
     */
    public function set(string $key, mixed $value, int|float $ttl): void;

    /**
     * Get retrieves a value from the cache using a given key.
     *
     * Parameters:
     * - $key: The cache key to fetch the value.
     * - $data: The variable to store the fetched data. Left untouched on a
     *   cache miss (mirroring the Go implementation, which swallows
     *   cache.ErrCacheMiss and returns nil).
     *
     * @throws \RuntimeException if retrieval fails (not on cache miss).
     */
    public function get(string $key, mixed &$data): void;

    /**
     * Delete removes a value from the cache based on the provided key.
     *
     * Parameters:
     * - $key: The cache key to be deleted.
     *
     * @throws \RuntimeException if the key cannot be deleted.
     */
    public function delete(string $key): void;
}
