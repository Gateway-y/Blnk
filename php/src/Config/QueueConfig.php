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
 * Port of Go `config.QueueConfig`; `time.Duration` fields are integer seconds.
 */
final class QueueConfig
{
    /** JSON: "transaction_queue"; env: BLNK_QUEUE_TRANSACTION */
    public string $transactionQueue = '';

    /** JSON: "webhook_queue"; env: BLNK_QUEUE_WEBHOOK */
    public string $webhookQueue = '';

    /** JSON: "index_queue"; env: BLNK_QUEUE_INDEX */
    public string $indexQueue = '';

    /** JSON: "inflight_expiry_queue"; env: BLNK_QUEUE_INFLIGHT_EXPIRY */
    public string $inflightExpiryQueue = '';

    /** JSON: "inflight_commit_queue"; env: BLNK_QUEUE_INFLIGHT_COMMIT */
    public string $inflightCommitQueue = '';

    /** JSON: "number_of_queues"; env: BLNK_QUEUE_NUMBER_OF_QUEUES */
    public int $numberOfQueues = 0;

    /** JSON: "enable_hot_lane"; env: BLNK_QUEUE_ENABLE_HOT_LANE */
    public bool $enableHotLane = false;

    /** JSON: "hot_queue_name"; env: BLNK_QUEUE_HOT_QUEUE_NAME */
    public string $hotQueueName = '';

    /** JSON: "hot_queue_concurrency"; env: BLNK_QUEUE_HOT_QUEUE_CONCURRENCY */
    public int $hotQueueConcurrency = 0;

    /** Seconds. JSON: "hot_pair_ttl"; env: BLNK_QUEUE_HOT_PAIR_TTL */
    public int $hotPairTTL = 0;

    /** JSON: "hot_pair_lock_contention_threshold"; env: BLNK_QUEUE_HOT_PAIR_LOCK_CONTENTION_THRESHOLD */
    public int $hotPairLockContentionThreshold = 0;

    /** JSON: "reject_lock_contention_immediately"; env: BLNK_QUEUE_REJECT_LOCK_CONTENTION_IMMEDIATELY */
    public bool $rejectLockContentionImmediately = false;

    /** JSON: "insufficient_fund_retries"; env: BLNK_QUEUE_INSUFFICIENT_FUND_RETRIES */
    public bool $insufficientFundRetries = false;

    /** JSON: "max_retry_attempts"; env: BLNK_QUEUE_MAX_RETRY_ATTEMPTS */
    public int $maxRetryAttempts = 0;

    /** JSON: "monitoring_port"; env: BLNK_QUEUE_MONITORING_PORT */
    public string $monitoringPort = '';

    /** JSON: "webhook_concurrency"; env: BLNK_QUEUE_WEBHOOK_CONCURRENCY */
    public int $webhookConcurrency = 0;

    /** JSON: "transaction_worker_concurrency"; env: BLNK_QUEUE_TRANSACTION_WORKER_CONCURRENCY */
    public int $transactionWorkerConcurrency = 0;

    /**
     * Default values (Go `defaultQueue`):
     * TransactionQueue "new:transaction", WebhookQueue "new:webhook",
     * IndexQueue "new:index", InflightExpiryQueue "new:inflight-expiry",
     * InflightCommitQueue "new:inflight-commit", NumberOfQueues 20,
     * EnableHotLane false, HotQueueName "hot_transactions",
     * HotQueueConcurrency 1, HotPairTTL 5m, HotPairLockContentionThreshold 3,
     * RejectLockContentionImmediately false, MaxRetryAttempts 5,
     * MonitoringPort DEFAULT_MONITORING_PORT, WebhookConcurrency 20,
     * TransactionWorkerConcurrency 4.
     */
    public static function defaults(): self
    {
        $c = new self();
        $c->transactionQueue = 'new:transaction';
        $c->webhookQueue = 'new:webhook';
        $c->indexQueue = 'new:index';
        $c->inflightExpiryQueue = 'new:inflight-expiry';
        $c->inflightCommitQueue = 'new:inflight-commit';
        $c->numberOfQueues = 20;
        $c->enableHotLane = false;
        $c->hotQueueName = 'hot_transactions';
        $c->hotQueueConcurrency = 1;
        $c->hotPairTTL = 5 * 60; // 5 * time.Minute
        $c->hotPairLockContentionThreshold = 3;
        $c->rejectLockContentionImmediately = false;
        $c->maxRetryAttempts = 5;
        $c->monitoringPort = Configuration::DEFAULT_MONITORING_PORT;
        $c->webhookConcurrency = 20;
        $c->transactionWorkerConcurrency = 4;
        return $c;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->transactionQueue = (string) ($data['transaction_queue'] ?? '');
        $c->webhookQueue = (string) ($data['webhook_queue'] ?? '');
        $c->indexQueue = (string) ($data['index_queue'] ?? '');
        $c->inflightExpiryQueue = (string) ($data['inflight_expiry_queue'] ?? '');
        $c->inflightCommitQueue = (string) ($data['inflight_commit_queue'] ?? '');
        $c->numberOfQueues = (int) ($data['number_of_queues'] ?? 0);
        $c->enableHotLane = (bool) ($data['enable_hot_lane'] ?? false);
        $c->hotQueueName = (string) ($data['hot_queue_name'] ?? '');
        $c->hotQueueConcurrency = (int) ($data['hot_queue_concurrency'] ?? 0);
        $c->hotPairTTL = (int) ($data['hot_pair_ttl'] ?? 0);
        $c->hotPairLockContentionThreshold = (int) ($data['hot_pair_lock_contention_threshold'] ?? 0);
        $c->rejectLockContentionImmediately = (bool) ($data['reject_lock_contention_immediately'] ?? false);
        $c->insufficientFundRetries = (bool) ($data['insufficient_fund_retries'] ?? false);
        $c->maxRetryAttempts = (int) ($data['max_retry_attempts'] ?? 0);
        $c->monitoringPort = (string) ($data['monitoring_port'] ?? '');
        $c->webhookConcurrency = (int) ($data['webhook_concurrency'] ?? 0);
        $c->transactionWorkerConcurrency = (int) ($data['transaction_worker_concurrency'] ?? 0);
        return $c;
    }

    /** Applies the BLNK_* environment overrides (envconfig equivalents). */
    public function applyEnvOverrides(): void
    {
        $this->transactionQueue = Env::getString('BLNK_QUEUE_TRANSACTION') ?? $this->transactionQueue;
        $this->webhookQueue = Env::getString('BLNK_QUEUE_WEBHOOK') ?? $this->webhookQueue;
        $this->indexQueue = Env::getString('BLNK_QUEUE_INDEX') ?? $this->indexQueue;
        $this->inflightExpiryQueue = Env::getString('BLNK_QUEUE_INFLIGHT_EXPIRY') ?? $this->inflightExpiryQueue;
        $this->inflightCommitQueue = Env::getString('BLNK_QUEUE_INFLIGHT_COMMIT') ?? $this->inflightCommitQueue;
        $this->numberOfQueues = Env::getInt('BLNK_QUEUE_NUMBER_OF_QUEUES') ?? $this->numberOfQueues;
        $this->enableHotLane = Env::getBool('BLNK_QUEUE_ENABLE_HOT_LANE') ?? $this->enableHotLane;
        $this->hotQueueName = Env::getString('BLNK_QUEUE_HOT_QUEUE_NAME') ?? $this->hotQueueName;
        $this->hotQueueConcurrency = Env::getInt('BLNK_QUEUE_HOT_QUEUE_CONCURRENCY') ?? $this->hotQueueConcurrency;
        $this->hotPairTTL = Env::getDurationSeconds('BLNK_QUEUE_HOT_PAIR_TTL') ?? $this->hotPairTTL;
        $this->hotPairLockContentionThreshold = Env::getInt('BLNK_QUEUE_HOT_PAIR_LOCK_CONTENTION_THRESHOLD') ?? $this->hotPairLockContentionThreshold;
        $this->rejectLockContentionImmediately = Env::getBool('BLNK_QUEUE_REJECT_LOCK_CONTENTION_IMMEDIATELY') ?? $this->rejectLockContentionImmediately;
        $this->insufficientFundRetries = Env::getBool('BLNK_QUEUE_INSUFFICIENT_FUND_RETRIES') ?? $this->insufficientFundRetries;
        $this->maxRetryAttempts = Env::getInt('BLNK_QUEUE_MAX_RETRY_ATTEMPTS') ?? $this->maxRetryAttempts;
        $this->monitoringPort = Env::getString('BLNK_QUEUE_MONITORING_PORT') ?? $this->monitoringPort;
        $this->webhookConcurrency = Env::getInt('BLNK_QUEUE_WEBHOOK_CONCURRENCY') ?? $this->webhookConcurrency;
        $this->transactionWorkerConcurrency = Env::getInt('BLNK_QUEUE_TRANSACTION_WORKER_CONCURRENCY') ?? $this->transactionWorkerConcurrency;
    }
}
