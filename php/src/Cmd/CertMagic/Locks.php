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
 * Locks holds the package-level lock bookkeeping of certmagic storage.go:
 * `acquireLock`, `releaseLock`, `CleanUpOwnLocks` and the `locks` map that
 * stores a reference to all the current locks obtained by this process.
 */
final class Locks
{
    /** @var array<string, Storage> lock key → storage holding it */
    private static array $locks = [];

    private function __construct()
    {
    }

    /**
     * acquireLock obtains the named lock from storage and remembers it.
     *
     * @throws \RuntimeException
     */
    public static function acquireLock(Storage $storage, string $lockKey): void
    {
        $storage->lock($lockKey);
        self::$locks[$lockKey] = $storage;
    }

    /**
     * releaseLock releases the named lock and forgets it.
     *
     * @throws \RuntimeException
     */
    public static function releaseLock(Storage $storage, string $lockKey): void
    {
        $storage->unlock($lockKey);
        unset(self::$locks[$lockKey]);
    }

    /**
     * CleanUpOwnLocks immediately cleans up all
     * current locks obtained by this process. Since
     * this does not cancel the operations that
     * the locks are synchronizing, this should be
     * called only immediately before process exit.
     * Errors are only logged.
     */
    public static function cleanUpOwnLocks(): void
    {
        foreach (self::$locks as $lockKey => $storage) {
            try {
                $storage->unlock($lockKey);
            } catch (\Throwable $err) {
                Log::get()->error('unable to clean up lock in storage backend', [
                    'storage' => ($storage instanceof \Stringable ? (string) $storage : $storage::class),
                    'lock_key' => $lockKey,
                    'error' => $err->getMessage(),
                ]);
                continue;
            }
            unset(self::$locks[$lockKey]);
        }
    }

    /**
     * keepFresh refreshes the timestamps of every lock this process holds.
     *
     * PHP port: Go keeps each lock file fresh from a goroutine
     * (`keepLockfileFresh`); the single-threaded port refreshes them from the
     * waits of the ACME flow instead (see {@see \Blnk\Cmd\CertMagic\Acme\Client::wait()}).
     */
    public static function keepFresh(): void
    {
        foreach (self::$locks as $storage) {
            if ($storage instanceof FileStorage) {
                $storage->keepLockfilesFresh();
            }
        }
    }

    /** held lists the lock keys currently held by this process. @return string[] */
    public static function held(): array
    {
        return array_keys(self::$locks);
    }
}
