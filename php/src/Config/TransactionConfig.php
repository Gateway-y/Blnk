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

namespace Blnk\Config;

/**
 * Port of Go `config.TransactionConfig`; `time.Duration` fields are integer seconds.
 */
final class TransactionConfig
{
    /** JSON: "batch_size"; env: BLNK_TRANSACTION_BATCH_SIZE */
    public int $batchSize = 0;

    /** JSON: "max_queue_size"; env: BLNK_TRANSACTION_MAX_QUEUE_SIZE */
    public int $maxQueueSize = 0;

    /** JSON: "max_workers"; env: BLNK_TRANSACTION_MAX_WORKERS */
    public int $maxWorkers = 0;

    /** Seconds. JSON: "lock_duration"; env: BLNK_TRANSACTION_LOCK_DURATION */
    public int $lockDuration = 0;

    /** Seconds. JSON: "lock_wait_timeout"; env: BLNK_TRANSACTION_LOCK_WAIT_TIMEOUT */
    public int $lockWaitTimeout = 0;

    /** JSON: "index_queue_prefix"; env: BLNK_TRANSACTION_INDEX_QUEUE_PREFIX */
    public string $indexQueuePrefix = '';

    /** JSON: "enable_coalescing"; env: BLNK_TRANSACTION_ENABLE_COALESCING */
    public bool $enableCoalescing = false;

    /** JSON: "enable_queued_checks"; env: BLNK_TRANSACTION_ENABLE_QUEUED_CHECKS */
    public bool $enableQueuedChecks = false;

    /** JSON: "disable_batch_reference_check"; env: BLNK_TRANSACTION_DISABLE_BATCH_REFERENCE_CHECK */
    public bool $disableBatchReferenceCheck = false;

    /** JSON: "hash_chain" */
    public HashChainConfig $hashChain;

    public function __construct()
    {
        $this->hashChain = new HashChainConfig();
    }

    /**
     * Default values (Go `defaultTransaction`):
     * BatchSize 1000, MaxQueueSize 1000, MaxWorkers 10, LockDuration 5m,
     * LockWaitTimeout 3s, IndexQueuePrefix "transactions",
     * EnableCoalescing true, EnableQueuedChecks false,
     * DisableBatchReferenceCheck false.
     */
    public static function defaults(): self
    {
        $c = new self();
        $c->batchSize = 1000;
        $c->maxQueueSize = 1000;
        $c->maxWorkers = 10;
        $c->lockDuration = 5 * 60; // 5 * time.Minute
        $c->lockWaitTimeout = 3; // 3 * time.Second
        $c->indexQueuePrefix = 'transactions';
        $c->enableCoalescing = true;
        $c->enableQueuedChecks = false;
        $c->disableBatchReferenceCheck = false;
        return $c;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->batchSize = (int) ($data['batch_size'] ?? 0);
        $c->maxQueueSize = (int) ($data['max_queue_size'] ?? 0);
        $c->maxWorkers = (int) ($data['max_workers'] ?? 0);
        $c->lockDuration = (int) ($data['lock_duration'] ?? 0);
        $c->lockWaitTimeout = (int) ($data['lock_wait_timeout'] ?? 0);
        $c->indexQueuePrefix = (string) ($data['index_queue_prefix'] ?? '');
        $c->enableCoalescing = (bool) ($data['enable_coalescing'] ?? false);
        $c->enableQueuedChecks = (bool) ($data['enable_queued_checks'] ?? false);
        $c->disableBatchReferenceCheck = (bool) ($data['disable_batch_reference_check'] ?? false);
        $hashChain = $data['hash_chain'] ?? [];
        if (is_array($hashChain)) {
            $c->hashChain = HashChainConfig::fromArray($hashChain);
        }
        return $c;
    }

    /** Applies the BLNK_* environment overrides (envconfig equivalents). */
    public function applyEnvOverrides(): void
    {
        $this->batchSize = Env::getInt('BLNK_TRANSACTION_BATCH_SIZE') ?? $this->batchSize;
        $this->maxQueueSize = Env::getInt('BLNK_TRANSACTION_MAX_QUEUE_SIZE') ?? $this->maxQueueSize;
        $this->maxWorkers = Env::getInt('BLNK_TRANSACTION_MAX_WORKERS') ?? $this->maxWorkers;
        $this->lockDuration = Env::getDurationSeconds('BLNK_TRANSACTION_LOCK_DURATION') ?? $this->lockDuration;
        $this->lockWaitTimeout = Env::getDurationSeconds('BLNK_TRANSACTION_LOCK_WAIT_TIMEOUT') ?? $this->lockWaitTimeout;
        $this->indexQueuePrefix = Env::getString('BLNK_TRANSACTION_INDEX_QUEUE_PREFIX') ?? $this->indexQueuePrefix;
        $this->enableCoalescing = Env::getBool('BLNK_TRANSACTION_ENABLE_COALESCING') ?? $this->enableCoalescing;
        $this->enableQueuedChecks = Env::getBool('BLNK_TRANSACTION_ENABLE_QUEUED_CHECKS') ?? $this->enableQueuedChecks;
        $this->disableBatchReferenceCheck = Env::getBool('BLNK_TRANSACTION_DISABLE_BATCH_REFERENCE_CHECK') ?? $this->disableBatchReferenceCheck;
        $this->hashChain->applyEnvOverrides();
    }
}
