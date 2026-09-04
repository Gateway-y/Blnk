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

namespace Blnk\Cmd\CertMagic;

use Blnk\Internal\Log;

/**
 * FileStorage facilitates forming file paths derived from a root
 * directory. It is used to get file paths in a consistent,
 * cross-platform way or persisting ACME assets on the file system.
 * The presence of a lock file for a given key indicates a lock
 * is held and is thus unavailable.
 *
 * Locks are created atomically by relying on the file system to
 * enforce the O_EXCL flag. Acquirers that are forcefully terminated
 * will not have a chance to clean up their locks before they exit,
 * so locks may become stale. That is why, while a lock is actively
 * held, the contents of the lockfile are updated with the current
 * timestamp periodically. If another instance tries to acquire the
 * lock but fails, it can see if the timestamp within is still fresh.
 * If so, it patiently waits by polling occasionally. Otherwise,
 * the stale lockfile is deleted, essentially forcing an unlock.
 *
 * While locking is atomic, unlocking is not perfectly atomic. File
 * systems offer native atomic operations when creating files, but
 * not necessarily when deleting them. It is theoretically possible
 * for two instances to discover the same stale lock and both proceed
 * to delete it, but if one instance is able to delete the lockfile
 * and create a new one before the other one calls delete, then the
 * new lock file created by the first instance will get deleted by
 * mistake. This does mean that mutual exclusion is not guaranteed
 * to be perfectly enforced in the presence of stale locks. One
 * alternative is to lock the unlock operation by using ".unlock"
 * files; and we did this for some time, but those files themselves
 * may become stale, leading applications into infinite loops if
 * they always expect the unlock file to be deleted by the instance
 * that created it. We instead prefer the simpler solution that
 * implies imperfect mutual exclusion if locks become stale, but
 * that is probably less severe a consequence than infinite loops.
 *
 * See https://github.com/caddyserver/caddy/issues/4448 for discussion.
 *
 * PHP port: Go refreshes each held lock file from a goroutine
 * (`keepLockfileFresh`); here {@see keepLockfilesFresh()} is called from the
 * waits of the ACME flow ({@see Locks::keepFresh()}), which is where the
 * single-threaded process spends its time while holding a lock.
 */
final class FileStorage implements Storage
{
    /**
     * lockFreshnessInterval is how often to update
     * a lock's timestamp. Locks with a timestamp
     * more than this duration in the past (plus a
     * grace period for latency) can be considered
     * stale.
     */
    public const lockFreshnessInterval = 5;

    /** fileLockPollInterval is how frequently to check the existence of a lock file */
    public const fileLockPollInterval = 1;

    public string $path;

    /** @var array<string, float> lock files this instance holds → time of the last freshness update */
    private array $heldLockfiles = [];

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /** Exists returns true if key exists in s. */
    public function exists(string $key): bool
    {
        $filename = $this->filename($key);
        return file_exists($filename) || is_link($filename);
    }

    /** Store saves value at key. */
    public function store(string $key, string $value): void
    {
        $filename = $this->filename($key);
        $dir = \dirname($filename);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('mkdir %s: %s', $dir, self::lastError()));
        }
        // atomicfile.New(filename, 0o600): write a temporary file in the same
        // directory, then rename it over the target so readers never see a
        // partial write.
        $tmp = @tempnam($dir, '.' . basename($filename) . '.tmp');
        if ($tmp === false) {
            throw new \RuntimeException(sprintf('open %s: %s', $filename, self::lastError()));
        }
        if (@file_put_contents($tmp, $value) !== \strlen($value)) {
            @unlink($tmp); // cancel the write
            throw new \RuntimeException(sprintf('write %s: %s', $filename, self::lastError()));
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $filename)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('rename %s %s: %s', $tmp, $filename, self::lastError()));
        }
    }

    /** Load retrieves the value at key. */
    public function load(string $key): string
    {
        // i believe it's possible for the read call to error but still return bytes, in event of something like a shortread?
        // therefore, i think it's appropriate to not return any bytes to avoid downstream users of the package erroniously believing that
        // bytes read + error is a valid response (it should not be)
        $filename = $this->filename($key);
        if (!file_exists($filename)) {
            throw new ErrNotExist(sprintf('open %s: no such file or directory', $filename));
        }
        if (is_dir($filename)) {
            throw new \RuntimeException(sprintf('read %s: is a directory', $filename));
        }
        $xs = @file_get_contents($filename);
        if ($xs === false) {
            throw new \RuntimeException(sprintf('open %s: %s', $filename, self::lastError()));
        }
        return $xs;
    }

    /** Delete deletes the value at key (Go: os.RemoveAll — no error when it does not exist). */
    public function delete(string $key): void
    {
        self::removeAll($this->filename($key));
    }

    /** List returns all keys that match prefix. */
    public function list(string $prefix, bool $recursive): array
    {
        $keys = [];
        $walkPrefix = $this->filename($prefix);

        if (!file_exists($walkPrefix)) {
            throw new ErrNotExist(sprintf('lstat %s: no such file or directory', $walkPrefix));
        }
        if (!is_dir($walkPrefix)) {
            return $keys; // filepath.Walk visits only the root, which is skipped
        }
        $this->walk($walkPrefix, $walkPrefix, $prefix, $recursive, $keys);

        return $keys;
    }

    /** Stat returns information about key. */
    public function stat(string $key): KeyInfo
    {
        $filename = $this->filename($key);
        $fi = @stat($filename);
        if ($fi === false) {
            if (!file_exists($filename)) {
                throw new ErrNotExist(sprintf('stat %s: no such file or directory', $filename));
            }
            throw new \RuntimeException(sprintf('stat %s: %s', $filename, self::lastError()));
        }
        return new KeyInfo(
            $key,
            (new \DateTimeImmutable('@' . $fi['mtime']))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
            (int) $fi['size'],
            !is_dir($filename)
        );
    }

    /**
     * Filename returns the key as a path on the file
     * system prefixed by s.Path.
     */
    public function filename(string $key): string
    {
        $joined = rtrim($this->path, '/') . '/' . str_replace('/', \DIRECTORY_SEPARATOR, $key);
        return (string) preg_replace('#/+#', '/', $joined);
    }

    /**
     * Lock obtains a lock named by the given name. It blocks
     * until the lock can be obtained or an error is thrown.
     */
    public function lock(string $name): void
    {
        $filename = $this->lockFilename($name);

        // sometimes the lockfiles read as empty (size 0) - this is either a stale lock or it
        // is currently being written; we can retry a few times in this case, as it has been
        // shown to help (issue #232)
        $emptyCount = 0;

        while (true) {
            if ($this->createLockfile($filename)) {
                // got the lock, yay
                return;
            }

            // lock file already exists

            $meta = null;
            $contents = @file_get_contents($filename);
            $readErr = $contents === false ? self::lastError() : '';
            if ($contents !== false) {
                if (trim($contents) === '') { // io.EOF
                    $emptyCount++;
                    if ($emptyCount < 8) {
                        // wait for brief time and retry; could be that the file is in the process
                        // of being written or updated (which involves truncating) - see issue #232
                        usleep(250_000);
                        continue;
                    }
                    // lockfile is empty or truncated multiple times; I *think* we can assume
                    // the previous acquirer either crashed or had some sort of failure that
                    // caused them to be unable to fully acquire or retain the lock, therefore
                    // we should treat it as if the lockfile did not exist
                    Log::get()->info(sprintf('[INFO][%s] %s: Empty lockfile (EOF) - likely previous process crashed or storage medium failure; treating as stale', (string) $this, $filename));
                    $meta = [];
                } else {
                    $decoded = json_decode($contents, true);
                    if (!\is_array($decoded)) {
                        throw new \RuntimeException(sprintf('decoding lockfile contents: %s', json_last_error_msg()));
                    }
                    $meta = $decoded;
                }
            }

            switch (true) {
                case $contents === false && !file_exists($filename):
                    // must have just been removed; try again to create it
                    continue 2;

                case $contents === false:
                    // unexpected error
                    throw new \RuntimeException(sprintf('accessing lock file: %s', $readErr));

                case self::fileLockIsStale($meta ?? []):
                    // lock file is stale - delete it and try again to obtain lock
                    // (NOTE: locking becomes imperfect if lock files are stale; known solutions
                    // either have potential to cause infinite loops, as in caddyserver/caddy#4448,
                    // or must give up on perfect mutual exclusivity; however, these cases are rare,
                    // so we prefer the simpler solution that avoids infinite loops)
                    Log::get()->info(sprintf(
                        "[INFO][%s] Lock for '%s' is stale (created: %s, last update: %s); removing then retrying: %s",
                        (string) $this,
                        $name,
                        (string) ($meta['created'] ?? Rfc3339::Zero),
                        (string) ($meta['updated'] ?? Rfc3339::Zero),
                        $filename
                    ));
                    if (!@unlink($filename) && file_exists($filename)) { // hopefully we can replace the lock file quickly!
                        throw new \RuntimeException(sprintf('unable to delete stale lockfile; deadlocked: %s', self::lastError()));
                    }
                    continue 2;

                default:
                    // lockfile exists and is not stale;
                    // just wait a moment and try again
                    usleep(self::fileLockPollInterval * 1_000_000);
            }
        }
    }

    /** Unlock releases the lock for name. */
    public function unlock(string $name): void
    {
        $filename = $this->lockFilename($name);
        unset($this->heldLockfiles[$filename]);
        if (!@unlink($filename)) {
            if (!file_exists($filename)) {
                throw new \RuntimeException(sprintf('remove %s: no such file or directory', $filename));
            }
            throw new \RuntimeException(sprintf('remove %s: %s', $filename, self::lastError()));
        }
    }

    public function __toString(): string
    {
        return 'FileStorage:' . $this->path;
    }

    /**
     * keepLockfilesFresh updates every lock file this instance holds with the
     * current timestamp once lockFreshnessInterval has elapsed since its last
     * update. It stops tracking a file when it disappears (happy path = lock
     * released), or when there is an error at any point.
     *
     * (Go: `keepLockfileFresh`, one goroutine per lock.)
     */
    public function keepLockfilesFresh(): void
    {
        $now = microtime(true);
        foreach ($this->heldLockfiles as $filename => $lastUpdate) {
            if ($now - $lastUpdate < self::lockFreshnessInterval) {
                continue;
            }
            try {
                $done = $this->updateLockfileFreshness($filename);
            } catch (\Throwable $err) {
                Log::get()->error(sprintf('[ERROR] Keeping lock file fresh: %s - terminating lock maintenance (lockfile: %s)', $err->getMessage(), $filename));
                unset($this->heldLockfiles[$filename]);
                continue;
            }
            if ($done) {
                unset($this->heldLockfiles[$filename]);
                continue;
            }
            $this->heldLockfiles[$filename] = $now;
        }
    }

    private function lockFilename(string $name): string
    {
        return $this->lockDir() . '/' . StorageKeys::safe($name) . '.lock';
    }

    private function lockDir(): string
    {
        return rtrim($this->path, '/') . '/locks';
    }

    /**
     * fileLockIsStale reports whether the lock metadata is too old.
     *
     * @param array<string, mixed> $meta
     */
    public static function fileLockIsStale(array $meta): bool
    {
        $ref = null;
        try {
            $ref = Rfc3339::decode(isset($meta['updated']) ? (string) $meta['updated'] : null);
            if ($ref === null) {
                $ref = Rfc3339::decode(isset($meta['created']) ? (string) $meta['created'] : null);
            }
        } catch (\Throwable) {
            $ref = null;
        }
        if ($ref === null) {
            return true; // time.Since(zero time) is enormous
        }
        // since updates are exactly every lockFreshnessInterval,
        // add a grace period for the actual file read+write to
        // take place
        return microtime(true) - Rfc3339::seconds($ref) > self::lockFreshnessInterval * 2;
    }

    /**
     * createLockfile atomically creates the lockfile
     * identified by filename. A successfully created
     * lockfile should be removed with unlock. Returns
     * false when the lock file already exists.
     *
     * @throws \RuntimeException on any other error ("creating lock file: ...")
     */
    private function createLockfile(string $filename): bool
    {
        if (!self::atomicallyCreateFile($filename, true)) {
            return false;
        }

        // go keepLockfileFresh(filename)
        $this->heldLockfiles[$filename] = microtime(true);

        return true;
    }

    /**
     * updateLockfileFreshness updates the lock file at filename
     * with the current timestamp. It returns true if the parent
     * loop can terminate (i.e. no more need to update the lock).
     *
     * @throws \RuntimeException
     */
    private function updateLockfileFreshness(string $filename): bool
    {
        if (!file_exists($filename)) {
            return true; // lock released
        }
        $f = @fopen($filename, 'r+');
        if ($f === false) {
            throw new \RuntimeException(sprintf('open %s: %s', $filename, self::lastError()));
        }
        try {
            // read contents
            $metaBytes = (string) stream_get_contents($f, 2048);
            $meta = json_decode($metaBytes, true);
            if (!\is_array($meta)) {
                // see issue #232: this can error if the file is empty,
                // which happens sometimes when the disk is REALLY slow
                throw new \RuntimeException(sprintf('unexpected end of JSON input (%s)', json_last_error_msg()));
            }

            // truncate file and reset I/O offset to beginning
            if (!ftruncate($f, 0) || !rewind($f)) {
                throw new \RuntimeException(sprintf('truncate %s: %s', $filename, self::lastError()));
            }

            // write updated timestamp
            $meta['updated'] = Rfc3339::encode(Rfc3339::now());
            if (fwrite($f, JsonUtil::marshal($meta) . "\n") === false) {
                return false;
            }

            // sync to device; we suspect that sometimes file systems
            // (particularly AWS EFS) don't do this on their own,
            // leaving the file empty when we close it; see
            // https://github.com/caddyserver/caddy/issues/3954
            fflush($f);
            if (\function_exists('fsync')) {
                fsync($f);
            }
            return false;
        } finally {
            fclose($f);
        }
    }

    /**
     * atomicallyCreateFile atomically creates the file
     * identified by filename if it doesn't already exist.
     * Returns false if it already exists.
     *
     * @throws \RuntimeException on any other error
     */
    private static function atomicallyCreateFile(string $filename, bool $writeLockInfo): bool
    {
        // no need to check this error, we only really care about the file creation error
        $dir = \dirname($filename);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $f = @fopen($filename, 'x'); // O_CREATE|O_WRONLY|O_EXCL
        if ($f === false) {
            if (file_exists($filename)) {
                return false; // os.IsExist(err)
            }
            throw new \RuntimeException(sprintf('creating lock file: open %s: %s', $filename, self::lastError()));
        }
        try {
            @chmod($filename, 0644);
            if ($writeLockInfo) {
                $now = Rfc3339::encode(Rfc3339::now());
                $meta = ['created' => $now, 'updated' => $now];
                if (fwrite($f, JsonUtil::marshal($meta) . "\n") === false) {
                    throw new \RuntimeException(sprintf('creating lock file: write %s: %s', $filename, self::lastError()));
                }
                // see https://github.com/caddyserver/caddy/issues/3954
                fflush($f);
                if (\function_exists('fsync')) {
                    fsync($f);
                }
            }
        } finally {
            fclose($f);
        }
        return true;
    }

    /**
     * walk is `filepath.Walk` over `$dir`, appending storage keys to `$keys`
     * (lexical order; directories are descended only when recursive).
     *
     * @param string[] $keys
     */
    private function walk(string $dir, string $walkPrefix, string $prefix, bool $recursive, array &$keys): void
    {
        $entries = @scandir($dir, \SCANDIR_SORT_ASCENDING);
        if ($entries === false) {
            throw new \RuntimeException(sprintf('open %s: %s', $dir, self::lastError()));
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fpath = $dir . '/' . $entry;
            $suffix = ltrim(substr($fpath, \strlen($walkPrefix)), '/');
            $keys[] = StorageKeys::join($prefix, $suffix);
            if (is_dir($fpath) && !is_link($fpath) && $recursive) {
                $this->walk($fpath, $walkPrefix, $prefix, $recursive, $keys);
            }
        }
    }

    /** removeAll is `os.RemoveAll`: deletes a file or a directory tree; missing paths are not an error. */
    private static function removeAll(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path) && file_exists($path)) {
                throw new \RuntimeException(sprintf('remove %s: %s', $path, self::lastError()));
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = @scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                self::removeAll($path . '/' . $entry);
            }
        }
        if (!@rmdir($path) && is_dir($path)) {
            throw new \RuntimeException(sprintf('remove %s: %s', $path, self::lastError()));
        }
    }

    private static function lastError(): string
    {
        $err = error_get_last();
        if ($err === null) {
            return 'unknown error';
        }
        error_clear_last();
        return (string) preg_replace('/^[a-z_]+\([^)]*\): /', '', (string) $err['message']);
    }
}
