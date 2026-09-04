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

namespace Blnk\Internal\Lock;

/**
 * MultiLocker manages multiple distributed locks with deterministic ordering.
 * It acquires locks in lexicographic order to prevent deadlocks when multiple
 * transactions need to lock the same set of keys in different orders.
 *
 * Port of Go `redlock.MultiLocker`.
 */
class MultiLocker
{
    protected \Redis|\RedisCluster $client;

    /** @var Locker[] */
    protected array $lockers;

    /** @var string[] */
    protected array $keys;

    protected string $value;

    /**
     * NewMultiLocker creates a new MultiLocker instance that manages locks for
     * multiple keys. Keys are deduplicated and sorted lexicographically to
     * ensure consistent lock ordering across all callers, preventing deadlocks.
     *
     * Parameters:
     * - $client: A Redis client to interact with Redis.
     * - $keys: The keys to lock (duplicates are removed, order is normalized).
     * - $value: A unique value to associate with all locks (ensures lock ownership).
     *
     * @param string[] $keys
     */
    public function __construct(\Redis|\RedisCluster $client, array $keys, string $value)
    {
        // Deduplicate keys
        $seen = [];
        $uniqueKeys = [];
        foreach ($keys as $key) {
            if ($key !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueKeys[] = $key;
            }
        }

        // Sort keys lexicographically to ensure consistent ordering
        sort($uniqueKeys, SORT_STRING);

        // Create lockers for each key
        $lockers = [];
        foreach ($uniqueKeys as $key) {
            $lockers[] = new Locker($client, $key, $value);
        }

        $this->client = $client;
        $this->lockers = $lockers;
        $this->keys = $uniqueKeys;
        $this->value = $value;
    }

    /**
     * Lock attempts to acquire all locks in deterministic order.
     * If any lock acquisition fails, all previously acquired locks are released (rollback).
     *
     * Parameters:
     * - $timeout: The time-to-live (TTL, seconds) for each lock.
     *
     * @throws LockHeldException if any lock is already held (previous locks rolled back).
     * @throws \RuntimeException if any lock could not be acquired for another reason.
     */
    public function lock(int|float $timeout): void
    {
        $acquiredCount = 0;

        foreach ($this->lockers as $locker) {
            try {
                $locker->lock($timeout);
            } catch (\Throwable $e) {
                // Rollback: release all previously acquired locks in reverse order
                $this->rollback($acquiredCount);
                $message = sprintf('failed to acquire lock for key %s: %s', $locker->key(), $e->getMessage());
                if ($e instanceof LockHeldException) {
                    // Preserve the sentinel (Go wraps with %w so errors.Is(ErrLockHeld) holds).
                    throw new LockHeldException($message, $e);
                }
                throw new \RuntimeException($message, 0, $e);
            }
            $acquiredCount++;
        }
    }

    /**
     * WaitLock tries to acquire all locks within a specified waiting period.
     * It will attempt to acquire locks with exponential backoff if any lock is held.
     * If lock acquisition fails after the wait timeout, all acquired locks are released.
     *
     * Parameters:
     * - $lockTimeout: The TTL (seconds) to set for each lock when acquired.
     * - $waitTimeout: The maximum time (seconds) to wait for all locks to become available.
     *
     * @throws LockWaitTimeoutException if all locks could not be acquired within the wait timeout.
     * @throws \RuntimeException on other Redis errors.
     */
    public function waitLock(int|float $lockTimeout, int|float $waitTimeout): void
    {
        $deadline = microtime(true) + $waitTimeout;

        for ($attempt = 0; microtime(true) < $deadline; $attempt++) {
            try {
                $this->lock($lockTimeout);
                return;
            } catch (LockHeldException $e) {
                Locker::sleepWithJitter($deadline, $attempt);
            }
        }

        throw new LockWaitTimeoutException('failed to acquire all locks within the wait timeout');
    }

    /**
     * Unlock releases all locks in reverse order of acquisition.
     * Releasing in reverse order is a best practice for multi-lock scenarios.
     *
     * @throws \RuntimeException the last unlock failure, if any (all locks are
     *                           attempted regardless, mirroring the Go lastErr behavior).
     */
    public function unlock(): void
    {
        $lastErr = null;

        // Release locks in reverse order
        for ($i = count($this->lockers) - 1; $i >= 0; $i--) {
            try {
                $this->lockers[$i]->unlock();
            } catch (\Throwable $e) {
                $lastErr = $e;
            }
        }

        if ($lastErr !== null) {
            if ($lastErr instanceof \RuntimeException) {
                throw $lastErr;
            }
            throw new \RuntimeException($lastErr->getMessage(), 0, $lastErr);
        }
    }

    /**
     * rollback releases locks that were successfully acquired before a failure.
     *
     * Parameters:
     * - $count: The number of locks to release (from the beginning of the list).
     */
    protected function rollback(int $count): void
    {
        // Release in reverse order of acquisition
        for ($i = $count - 1; $i >= 0; $i--) {
            try {
                $this->lockers[$i]->unlock();
            } catch (\Throwable) {
                // Errors ignored (Go: `_ = m.lockers[i].Unlock(ctx)`).
            }
        }
    }

    /**
     * Keys returns the deduplicated and sorted keys that this MultiLocker manages.
     *
     * @return string[]
     */
    public function keys(): array
    {
        return $this->keys;
    }
}
