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

namespace Blnk\Api;

use Blnk\Internal\Cache\CacheInterface;
use Blnk\Internal\Cache\RedisCache;
use Blnk\Internal\Log;
use Blnk\Internal\Search\ReindexProgress;
use Blnk\Internal\Search\ReindexService;

/**
 * Port of the `reindexManager` struct and its `globalReindexManager`
 * singleton (api/reindex.go).
 *
 * Go keeps the running ReindexService — and therefore its live progress — in
 * process memory behind a sync.RWMutex. A PHP process serves one request at
 * a time and does not outlive it, so besides the in-process service (used
 * within the request that started the reindex) the progress is persisted in
 * Redis, under {@see ReindexManager::ProgressKey}, so that a later
 * GET /search/reindex — served by another process — can report it and a
 * concurrent POST is refused while a run is in progress. Only the start and
 * the terminal state are persisted (the Go progress is updated per batch);
 * an in-progress marker expires after {@see ReindexManager::InProgressTTL}
 * seconds so a crashed run cannot block reindexing forever.
 */
final class ReindexManager
{
    /** Redis key holding the persisted ReindexProgress JSON. */
    public const ProgressKey = 'blnk:reindex:progress';

    /** Lifetime of an in-progress marker (seconds). */
    public const InProgressTTL = 3600;

    /** Lifetime of a completed/failed progress record (seconds). */
    public const TerminalTTL = 86400;

    private static ?ReindexManager $global = null;

    /** Go: `service *search.ReindexService` — the in-process service. */
    private ?ReindexService $service = null;

    private ?CacheInterface $cache;

    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache;
    }

    /**
     * global is `globalReindexManager`.
     */
    public static function global(): self
    {
        if (self::$global === null) {
            self::$global = new self();
        }

        return self::$global;
    }

    /** Go: `globalReindexManager.service` (read). */
    public function service(): ?ReindexService
    {
        return $this->service;
    }

    /** Go: `globalReindexManager.service = reindexService`. */
    public function setService(ReindexService $service): void
    {
        $this->service = $service;
    }

    /**
     * getProgress returns the live progress of the in-process service, or the
     * persisted progress of a run started by another process; null when no
     * reindex operation has been started (Go: `service == nil`).
     */
    public function getProgress(): ?ReindexProgress
    {
        if ($this->service !== null) {
            return $this->service->getProgress();
        }

        $cache = $this->cache();
        if ($cache === null) {
            return null;
        }
        $data = null;
        try {
            $cache->get(self::ProgressKey, $data);
        } catch (\Throwable $e) {
            Log::get()->error('reindex manager: unable to read persisted progress', ['error' => $e->getMessage()]);

            return null;
        }
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (!is_array($data)) {
            return null;
        }

        return self::progressFromArray($data);
    }

    /**
     * persistProgress stores a progress snapshot for other processes.
     */
    public function persistProgress(ReindexProgress $progress): void
    {
        $cache = $this->cache();
        if ($cache === null) {
            return;
        }
        $ttl = $progress->status === 'in_progress' || $progress->status === 'pending' ? self::InProgressTTL : self::TerminalTTL;
        try {
            $cache->set(self::ProgressKey, json_encode($progress, JSON_THROW_ON_ERROR), $ttl);
        } catch (\Throwable $e) {
            Log::get()->error('reindex manager: unable to persist progress', ['error' => $e->getMessage()]);
        }
    }

    private function cache(): ?CacheInterface
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        try {
            $this->cache = RedisCache::newCache();
        } catch (\Throwable $e) {
            Log::get()->error('reindex manager: unable to connect to redis', ['error' => $e->getMessage()]);

            return null;
        }

        return $this->cache;
    }

    /**
     * progressFromArray rebuilds a ReindexProgress from its JSON form.
     *
     * @param array<string, mixed> $data
     */
    public static function progressFromArray(array $data): ReindexProgress
    {
        $p = new ReindexProgress();
        $p->status = (string) ($data['status'] ?? '');
        $p->phase = (string) ($data['phase'] ?? '');
        $p->totalRecords = (int) ($data['total_records'] ?? 0);
        $p->processedRecords = (int) ($data['processed_records'] ?? 0);
        $errors = $data['errors'] ?? [];
        $p->errors = is_array($errors) ? array_values(array_map('strval', $errors)) : [];
        $p->startedAt = self::time($data['started_at'] ?? null);
        $p->completedAt = self::time($data['completed_at'] ?? null);

        return $p;
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || $value === '0001-01-01T00:00:00Z') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
