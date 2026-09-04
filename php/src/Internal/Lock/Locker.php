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
 * Locker represents a distributed lock using Redis.
 * The lock is identified by a unique key and value, where the value is used
 * to ensure that only the lock holder can release or renew the lock.
 *
 * Port of Go `redlock.Locker` (internal/lock). `context.Context` parameters
 * are dropped (synchronous PHP); `time.Duration` parameters become seconds
 * (int|float). Errors are thrown: {@see LockHeldException} when the lock is
 * already held, {@see LockWaitTimeoutException} on wait timeout, and
 * \RuntimeException for other failures.
 */
class Locker
{
    /** lockBackoffBase (Go: 5 * time.Millisecond), in seconds. */
    public const LOCK_BACKOFF_BASE = 0.005;

    /** lockBackoffMax (Go: 100 * time.Millisecond), in seconds. */
    public const LOCK_BACKOFF_MAX = 0.1;

    /** Redis client for interacting with Redis. */
    protected \Redis $client;

    /** The unique key for the lock in Redis. */
    protected string $key;

    /** A unique value to ensure only the holder can release/extend the lock. */
    protected string $value;

    /**
     * NewLocker initializes a new Locker instance with a Redis client, a key, and a value.
     *
     * Parameters:
     * - $client: A Redis client to interact with Redis.
     * - $key: The unique identifier for the lock.
     * - $value: A unique value to associate with the lock (ensures lock ownership).
     */
    public function __construct(\Redis $client, string $key, string $value)
    {
        $this->client = $client;
        $this->key = $key;
        $this->value = $value;
    }

    /** The lock key (Go accesses `locker.key` within the package). */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Lock attempts to acquire the lock for the specified key with a timeout.
     * If the lock is already held, it throws a LockHeldException.
     *
     * Parameters:
     * - $timeout: The time-to-live (TTL, seconds) for the lock.
     *
     * @throws LockHeldException if the lock is already held.
     * @throws \RuntimeException on Redis errors.
     */
    public function lock(int|float $timeout): void
    {
        try {
            // SET key value NX PX <ms> — the SetNX(+TTL) of the Go client.
            $success = $this->client->set($this->key, $this->value, ['nx', 'px' => (int) round($timeout * 1000)]);
        } catch (\RedisException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if ($success === false) {
            throw new LockHeldException(sprintf('lock for key %s is already held', $this->key));
        }
    }

    /**
     * Unlock releases the lock if the calling instance is the lock holder (based on the value).
     * The operation is atomic, ensuring only the holder of the lock can release it.
     *
     * @throws \RuntimeException if the unlock operation fails, either because the
     *                           lock expired or the caller is not the lock holder.
     */
    public function unlock(): void
    {
        // Lua script ensures atomicity: checks the value and deletes the key if the value matches.
        $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
        try {
            $result = $this->client->eval($script, [$this->key, $this->value], 1);
        } catch (\RedisException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if ((int) $result === 0) {
            throw new \RuntimeException(sprintf("unlock failed, either lock expired or you're not the lock holder for key %s", $this->key));
        }
    }

    /**
     * ExtendLock extends the TTL of the lock if the calling instance is the lock holder.
     * This method ensures that the lock is renewed only by the lock holder.
     *
     * Parameters:
     * - $extension: The additional time (seconds) to extend the lock by.
     *
     * @throws \RuntimeException if the extension fails, either because the lock
     *                           expired or the caller is not the lock holder.
     */
    public function extendLock(int|float $extension): void
    {
        // Lua script ensures atomicity: checks the value and extends the TTL if the value matches.
        $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('pexpire', KEYS[1], ARGV[2]) else return 0 end";
        try {
            $result = $this->client->eval($script, [$this->key, $this->value, (string) ((int) round($extension * 1000))], 1);
        } catch (\RedisException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if ((int) $result === 0) {
            throw new \RuntimeException(sprintf("lock extension failed for key %s, either lock expired or you're not the holder", $this->key));
        }
    }

    /**
     * WaitLock tries to acquire the lock within a specified waiting period.
     * It will attempt to acquire the lock with exponential backoff if the lock
     * is held by another process.
     *
     * Parameters:
     * - $lockTimeout: The TTL (seconds) to set for the lock when acquired.
     * - $waitTimeout: The maximum time (seconds) to wait for the lock to become available.
     *
     * @throws LockWaitTimeoutException if the lock could not be acquired within the wait timeout.
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
                self::sleepWithJitter($deadline, $attempt);
            }
        }
        throw new LockWaitTimeoutException(sprintf('failed to acquire lock for key %s within the wait timeout', $this->key));
    }

    /**
     * sleepWithJitter sleeps for an exponentially growing, jittered backoff period:
     * the cap starts at LOCK_BACKOFF_BASE and doubles per attempt up to
     * LOCK_BACKOFF_MAX, with the actual delay drawn uniformly from (0, cap]
     * (full jitter). Short initial delays let waiters re-acquire quickly after
     * brief critical sections, while the jittered growth avoids thundering
     * herds under contention.
     *
     * @param float $deadline Absolute deadline (microtime(true)-based).
     */
    public static function sleepWithJitter(float $deadline, int $attempt): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return;
        }

        $backoff = self::LOCK_BACKOFF_BASE;
        for ($i = 0; $i < $attempt && $backoff < self::LOCK_BACKOFF_MAX; $i++) {
            $backoff *= 2;
        }
        if ($backoff > self::LOCK_BACKOFF_MAX) {
            $backoff = self::LOCK_BACKOFF_MAX;
        }

        $delay = random_int(0, (int) ($backoff * 1_000_000)) / 1_000_000;
        if ($delay <= 0) {
            $delay = 0.001; // time.Millisecond
        }
        if ($delay > $remaining) {
            $delay = $remaining;
        }

        usleep((int) ($delay * 1_000_000));
    }
}
