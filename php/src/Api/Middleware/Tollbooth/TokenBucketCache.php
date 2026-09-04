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

/**
 * TokenBucketCache stands in for github.com/go-pkgz/expirable-cache/v3 as
 * Tollbooth uses it: the `Cache[string, *rate.Limiter]` map of token buckets
 * with a TTL that lives in the memory of the Go process (`limiter.New`:
 * `cache.NewCache[string, *rate.Limiter]().WithTTL(DefaultExpirationTTL)`).
 *
 * A PHP process keeps nothing between requests, and one Go process
 * corresponds to a whole PHP server (a php-fpm master with its workers, a
 * `php -S` server), so the buckets live in small files under a directory of
 * the system temp dir that is private to this server:
 * `<tmp>/blnk-ratelimit-<hash>/<sha1(key)>`. Every worker of the server shares
 * them — exactly the scope of Tollbooth's in-process map — and nothing is
 * shared with other hosts or replicas, which keep their own limiters as
 * separate Go processes do. Each bucket file is read and written under an
 * exclusive flock, the counterpart of the limiter mutex Go holds around
 * Get/Set/Allow.
 *
 * Semantics mirrored from expirable-cache (LRC mode, unlimited keys, no
 * background goroutine):
 * - Set stamps expiresAt = now + ttl; Get never refreshes the TTL.
 * - Get treats an entry as absent once now is after expiresAt; the entry
 *   stays until a Set replaces it.
 * - When a new key is added the cache drops its oldest entry if that one
 *   has expired. The file store instead runs deleteExpired() at most once
 *   per half TTL (the period expirable-cache itself recommends) when a key is
 *   added. Both are invisible to callers: expired entries already read as
 *   absent.
 *
 * Documented divergences: the state survives a restart of the PHP server
 * (it still expires by TTL) where the Go process memory does not; and a temp
 * directory that cannot be written raises a RuntimeException — Tollbooth's
 * map cannot fail, and silently disabling the limiter would be a worse
 * surprise than a loud error.
 */
final class TokenBucketCache
{
    /** Prefix of the per-server bucket directory inside the system temp dir. */
    public const DirPrefix = 'blnk-ratelimit-';

    /** Marker file whose mtime throttles the opportunistic deleteExpired() runs. */
    private const SweepMarker = '.sweep';

    /** noEvictionTTL - very long ttl to prevent eviction (expirable-cache: 10 years). */
    public const noEvictionTTL = Clock::Hour * 24 * 365 * 10;

    private string $dir;
    private int $ttl = self::noEvictionTTL;

    /**
     * NewCache returns a new Cache.
     * Default MaxKeys is unlimited (0).
     * Default TTL is 10 years, sane value for expirable cache is 5 minutes.
     * Default eviction mode is LRC, appropriate option allow to change it to LRU.
     *
     * @param string $dir Directory holding the bucket files; created on first use.
     */
    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    /**
     * defaultDir returns the bucket directory of this server: a directory in
     * the system temp dir named after the installed code path, the effective
     * user and the given instance identity, so distinct Blnk deployments on
     * one host (different checkouts, users or configured ports) keep
     * separate limiters just as distinct Go processes would.
     */
    public static function defaultDir(string $instanceIdentity = ''): string
    {
        $identity = \dirname(__DIR__, 4) . "\0" . getmyuid() . "\0" . $instanceIdentity;

        return rtrim(sys_get_temp_dir(), '/\\') . '/' . self::DirPrefix . substr(sha1($identity), 0, 16);
    }

    /**
     * WithTTL functional option defines TTL for all cache entries.
     * By default, it is set to 10 years, meaning no TTL.
     */
    public function withTTL(int $ttl): self
    {
        $this->ttl = $ttl;

        return $this;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * locked runs $fn with the slot of $key while holding the slot's exclusive
     * lock, persists the slot when $fn changed it, and returns $fn's result.
     * This is the port of the sequence Go performs under `l.Lock()` in
     * `limitReachedWithTokenBucketTTL`: Get, Set when missing, Get, Allow.
     *
     * @template T
     * @param callable(TokenBucketSlot): T $fn
     * @return T
     *
     * @throws \RuntimeException when the bucket directory or file cannot be used.
     */
    public function locked(string $key, callable $fn): mixed
    {
        $path = $this->dir . '/' . sha1($key);
        $fh = $this->openLocked($path);

        $inserted = false;
        try {
            $raw = stream_get_contents($fh);
            $slot = new TokenBucketSlot($raw === false ? '' : $raw, $this->ttl);

            $result = $fn($slot);

            if ($slot->dirty()) {
                $encoded = $slot->encode();
                if (ftruncate($fh, 0) === false || rewind($fh) === false || fwrite($fh, $encoded) !== strlen($encoded) || fflush($fh) === false) {
                    throw new \RuntimeException(sprintf('rate limiter: cannot write token bucket file %s', $path));
                }
            }
            $inserted = $slot->inserted();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        if ($inserted) {
            // expirable-cache prunes its oldest expired entry whenever a key is
            // added; the file store sweeps the expired bucket files instead,
            // throttled to once per half TTL.
            $this->removeExpiredThrottled();
        }

        return $result;
    }

    /**
     * DeleteExpired clears cache of expired items
     *
     * Bucket files are removed under their exclusive lock; a file another
     * worker holds locked at that moment is in use and left alone. Empty or
     * unreadable files hold no entry and are removed too.
     */
    public function deleteExpired(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $dh = @opendir($this->dir);
        if ($dh === false) {
            return;
        }
        try {
            while (($name = readdir($dh)) !== false) {
                if ($name[0] === '.') {
                    continue;
                }
                $path = $this->dir . '/' . $name;
                $fh = @fopen($path, 'r+');
                if ($fh === false) {
                    continue;
                }
                try {
                    if (!flock($fh, LOCK_EX | LOCK_NB)) {
                        continue;
                    }
                    try {
                        $raw = stream_get_contents($fh);
                        $entry = TokenBucketSlot::decode($raw === false ? '' : $raw);
                        if ($entry === null || Clock::after(Clock::now(), $entry['expires_at'])) {
                            @unlink($path);
                        }
                    } finally {
                        flock($fh, LOCK_UN);
                    }
                } finally {
                    fclose($fh);
                }
            }
        } finally {
            closedir($dh);
        }
    }

    /**
     * Purge clears the cache completely.
     */
    public function purge(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (scandir($this->dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            @unlink($this->dir . '/' . $name);
        }
    }

    /**
     * removeExpiredThrottled runs deleteExpired() at most once per half TTL,
     * tracked by the mtime of a marker file in the bucket directory.
     */
    private function removeExpiredThrottled(): void
    {
        $marker = $this->dir . '/' . self::SweepMarker;
        clearstatcache(true, $marker);
        $last = @filemtime($marker);
        $interval = max(1, intdiv($this->ttl, 2 * Clock::Second)); // seconds
        if ($last !== false && time() - $last < $interval) {
            return;
        }
        if (!@touch($marker)) {
            return;
        }
        $this->deleteExpired();
    }

    /**
     * openLocked opens the bucket file (creating it when missing) and takes
     * its exclusive lock. The lock is re-taken on a fresh file when the path
     * was unlinked by deleteExpired() between the open and the lock.
     *
     * @return resource
     */
    private function openLocked(string $path)
    {
        for ($attempt = 0; ; $attempt++) {
            $fh = @fopen($path, 'c+');
            if ($fh === false) {
                $this->ensureDir();
                $fh = @fopen($path, 'c+');
                if ($fh === false) {
                    throw new \RuntimeException(sprintf('rate limiter: cannot open token bucket file %s', $path));
                }
            }
            if (!flock($fh, LOCK_EX)) {
                fclose($fh);
                throw new \RuntimeException(sprintf('rate limiter: cannot lock token bucket file %s', $path));
            }

            clearstatcache(true, $path);
            $onDisk = @stat($path);
            $opened = fstat($fh);
            $same = $onDisk !== false && $opened !== false
                && $onDisk['ino'] === $opened['ino'] && $onDisk['dev'] === $opened['dev'];
            if ($same || $attempt >= 3) {
                return $fh;
            }

            // The locked inode is no longer what the path refers to: retry.
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * ensureDir creates the bucket directory (private to the current user).
     */
    private function ensureDir(): void
    {
        if (is_dir($this->dir)) {
            return;
        }
        if (!@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(sprintf('rate limiter: cannot create token bucket directory %s', $this->dir));
        }
    }
}
