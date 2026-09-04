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

/**
 * Storage is a type that implements a key-value store with
 * basic file system (folder path) semantics. Keys use the
 * forward slash '/' to separate path components and have no
 * leading or trailing slashes.
 *
 * A "prefix" of a key is defined on a component basis,
 * e.g. "a" is a prefix of "a/b" but not "ab/c".
 *
 * A "file" is a key with a value associated with it.
 *
 * A "directory" is a key with no value, but which may be
 * the prefix of other keys.
 *
 * Keys passed into Load and Store always have "file" semantics,
 * whereas "directories" are only implicit by leading up to the
 * file.
 *
 * The Load, Delete, List, and Stat methods should throw
 * {@see ErrNotExist} if the key does not exist.
 *
 * Processes running in a cluster should use the same Storage
 * value (with the same configuration) in order to share
 * certificates and other TLS resources with the cluster.
 *
 * (certmagic storage.go `Storage` + `Locker` interfaces. Locker facilitates
 * synchronization across machines and networks: a distributed named-mutex
 * service so that multiple consumers can coordinate tasks and share
 * resources. The default FileStorage writes a timestamp to the lock file
 * every few seconds, and if another node acquiring the lock sees that
 * timestamp is too old, it may assume the lock is stale.)
 */
interface Storage
{
    /**
     * Lock acquires the lock for name, blocking until the lock
     * can be obtained or an error is thrown. Only one lock
     * for the given name can exist at a time. A call to Lock for
     * a name which already exists blocks until the named lock
     * is released or becomes stale.
     *
     * If the named lock represents an idempotent operation, callers
     * should always check to make sure the work still needs to be
     * completed after acquiring the lock. You never know if another
     * process already completed the task while you were waiting to
     * acquire it.
     *
     * @throws \RuntimeException
     */
    public function lock(string $name): void;

    /**
     * Unlock releases named lock. This method must ONLY be called
     * after a successful call to Lock, and only after the critical
     * section is finished, even if it errored or timed out. Unlock
     * cleans up any resources allocated during Lock. Unlock should
     * only throw if the lock was unable to be released.
     *
     * @throws \RuntimeException
     */
    public function unlock(string $name): void;

    /**
     * Store puts value at key. It creates the key if it does
     * not exist and overwrites any existing value at this key.
     *
     * @throws \RuntimeException
     */
    public function store(string $key, string $value): void;

    /**
     * Load retrieves the value at key.
     *
     * @throws ErrNotExist when the key does not exist
     * @throws \RuntimeException
     */
    public function load(string $key): string;

    /**
     * Delete deletes the named key. If the name is a
     * directory (i.e. prefix of other keys), all keys
     * prefixed by this key should be deleted. An error
     * should be thrown only if the key still exists
     * when the method returns.
     *
     * @throws \RuntimeException
     */
    public function delete(string $key): void;

    /**
     * Exists returns true if the key exists either as
     * a directory (prefix to other keys) or a file,
     * and there was no error checking.
     */
    public function exists(string $key): bool;

    /**
     * List returns all keys in the given path.
     *
     * If recursive is true, non-terminal keys
     * will be enumerated (i.e. "directories"
     * should be walked); otherwise, only keys
     * prefixed exactly by prefix will be listed.
     *
     * @return string[]
     * @throws ErrNotExist when the prefix does not exist
     * @throws \RuntimeException
     */
    public function list(string $path, bool $recursive): array;

    /**
     * Stat returns information about key.
     *
     * @throws ErrNotExist when the key does not exist
     * @throws \RuntimeException
     */
    public function stat(string $key): KeyInfo;
}
