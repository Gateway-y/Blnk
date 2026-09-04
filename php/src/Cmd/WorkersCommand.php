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

namespace Blnk\Cmd;

use Blnk\Config\Configuration;
use Blnk\Core\Blnk;
use Blnk\Core\InflightActionPayload;
use Blnk\Core\NewWebhook;
use Blnk\Core\Queue;
use Blnk\Core\QueuedTransactionRecoveryProcessor;
use Blnk\Core\QueueTask;
use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\HotPairs\Router as HotPairsRouter;
use Blnk\Internal\Log;
use Blnk\Internal\Metrics\Metrics;
use Blnk\Internal\Notification\Notification;
use Blnk\Internal\Redis\PoolConfig;
use Blnk\Internal\Redis\RedisDb;
use Blnk\Internal\Search\IndexBatch;
use Blnk\Internal\Search\TypesenseClient;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;

/**
 * WorkersCommand is the port of cmd/workers.go: the `blnk workers` command,
 * the queue task handlers (Go: methods on `*blnkInstance`) and the worker
 * server setup.
 *
 * Worker model (documented divergence — PORTING.md "Queue"/"Concurrency"):
 * Go runs three asynq servers (transaction queues, optional hot lane,
 * webhook/index queues), each with `Concurrency` handler goroutines, plus the
 * monitoring HTTP server and the recovery processor, all in one process. The
 * PHP port runs the same three servers ({@see WorkerServer}) with the same
 * queues, handlers ({@see ServeMux}) and concurrency settings from ONE
 * sequential loop ({@see runWorkers()}):
 *
 *   loop until SIGINT/SIGTERM:
 *     transaction server: process up to transaction_worker_concurrency tasks
 *     hot-lane server:    process up to hot_queue_concurrency tasks (when enabled)
 *     webhook server:     process up to webhook_concurrency tasks
 *     recovery processor tick, monitoring server poll, cron jobs, heartbeat
 *     sleep 100ms when every queue was empty
 *
 * so concurrency numbers bound the batch a server group takes per turn rather
 * than parallelism; scale out by running several `blnk workers` processes
 * (they share the Redis queues safely thanks to the processing lists and
 * leases of {@see Queue}). A failed handler is retried with asynq's backoff
 * (`retry_count` in the envelope replaces `asynq.GetRetryCount`) and
 * dead-lettered after `max_retry`. Graceful shutdown finishes the task in
 * flight, then stops the servers in Go's order.
 *
 * cmd/workers.go registers no periodic/cron jobs; {@see registerCronJob()}
 * (dragonmantank/cron-expression) is available for deployments that need them.
 */
final class WorkersCommand
{
    public const Use = 'workers';
    public const Short = 'start blnk workers';

    /** asynq `ShutdownTimeout: 30 * time.Second` of every worker server. */
    public const ShutdownTimeoutSec = 30;

    /** Monitoring server shutdown budget (Go: 5 * time.Second). */
    public const MonitoringShutdownTimeoutSec = 5;

    /** Tracing shutdown budget (Go: 10 * time.Second). */
    public const TracingShutdownTimeoutSec = 10;

    /** Task type of queued hook executions (hooks.RedisHookManager). */
    public const HookExecutionTaskType = 'new:hook_execution';

    /** Task type of dependency-ordered index batches (Queue::queueIndexBatch). */
    public const IndexBatchTaskType = 'new:index:batch';

    /** Sleep between loop iterations when every queue is empty, in microseconds. */
    private const IdlePollIntervalUs = 100_000;

    /** Sleep after a queue (Redis) failure before the loop retries, in seconds. */
    private const QueueErrorBackoffSec = 1;

    private static ?CronScheduler $cron = null;

    /** Go: the `*blnkInstance` receiver of the handlers. */
    private BlnkInstance $b;

    public function __construct(BlnkInstance $b)
    {
        $this->b = $b;
    }

    /** instance returns the wrapped blnkInstance. */
    public function instance(): BlnkInstance
    {
        return $this->b;
    }

    // ------------------------------------------------------------------
    // Task handlers (Go: methods on *blnkInstance)
    // ------------------------------------------------------------------

    /**
     * processTransaction processes a transaction received from the Redis queue.
     * If a transaction fails due to "insufficient funds", it is rejected, and a webhook is sent.
     * Otherwise, it retries the transaction in case of other failures.
     *
     * @throws \Throwable to trigger a retry (Go: returning the error)
     */
    public function processTransaction(QueueTask $t): void
    {
        $span = Tracer::get('blnk.transactions.worker')->startSpan('Process Transaction From Redis Queue');
        try {
            $startTime = microtime(true);

            try {
                $txn = self::unmarshalTransaction($t);
            } catch (\Throwable $err) {
                Log::get()->error($err->getMessage());
                throw $err;
            }

            $cnf = $this->cnf();
            $hotLane = $cnf->queue->enableHotLane && $t->type === $cnf->queue->hotQueueName;

            $handled = false;
            $err = null;
            try {
                $handled = $this->blnk()->tryRecordQueuedTransactionBatch($txn);
            } catch (\Throwable $e) {
                $err = $e;
            }
            if ($hotLane) {
                $err = null;
                try {
                    $handled = $this->blnk()->tryRecordQueuedTransactionBatchForHotLane($txn);
                } catch (\Throwable $e) {
                    $handled = false;
                    $err = $e;
                }
            }
            if ($err !== null) {
                Log::get()->warning(sprintf('coalesced processing attempt failed for transaction %s', $txn->transactionID), ['error' => self::goErrorString($err)]);
            }
            if ($handled) {
                Metrics::queueProcessingDuration()->record(microtime(true) - $startTime, ['result' => 'success']);
                return;
            }

            try {
                $this->blnk()->processQueuedTransaction($txn, $hotLane);
            } catch (\Throwable $err) {
                // Handle reference already used error
                if (Blnk::isDuplicateReferenceError($err)) {
                    Notification::notifyError($err);
                    return;
                }

                $message = strtolower(self::goErrorString($err));

                if (str_contains($message, 'insufficient funds')) {
                    if (!$cnf->queue->insufficientFundRetries) {
                        $this->handleTransactionRejection($txn, $err);
                        return;
                    }

                    $retryCount = $t->retryCount; // asynq.GetRetryCount(ctx)
                    if (self::hasReachedMaxRetryAttempt($cnf, $retryCount)) {
                        Log::get()->warning('Transaction reached max retry attempts; rejecting with final processing error', [
                            'transaction_id' => $txn->transactionID,
                            'retry_count' => $retryCount,
                            'max_retries' => $cnf->queue->maxRetryAttempts,
                        ]);
                        $this->handleTransactionRejection($txn, $err);
                        return;
                    }

                    Log::get()->info(sprintf(
                        'Insufficient funds for transaction %s, retry attempt %d/%d',
                        $txn->transactionID,
                        $retryCount,
                        $cnf->queue->maxRetryAttempts
                    ));
                    Metrics::workerRetriesTotal()->add(1, ['reason' => 'insufficient_funds']);
                    throw $err; // This will trigger a retry
                }

                if (str_contains($message, 'transaction exceeds overdraft limit')) {
                    $this->handleTransactionRejection($txn, $err);
                    return;
                }

                if (self::shouldRejectLockContentionImmediately($cnf, $err)) {
                    Log::get()->warning('Rejecting transaction immediately due to lock contention policy', [
                        'transaction_id' => $txn->transactionID,
                        'error' => self::goErrorString($err),
                    ]);
                    $this->handleTransactionRejection($txn, $err);
                    return;
                }

                $retryCount = $t->retryCount;
                if (self::hasReachedMaxRetryAttempt($cnf, $retryCount)) {
                    Log::get()->warning('Transaction reached max retry attempts; rejecting with final processing error', [
                        'transaction_id' => $txn->transactionID,
                        'retry_count' => $retryCount,
                        'max_retries' => $cnf->queue->maxRetryAttempts,
                        'error' => self::goErrorString($err),
                    ]);
                    $this->handleTransactionRejection($txn, $err);
                    return;
                }

                Log::get()->info(sprintf('Transaction %s pushed back for retry due to error: %s', $txn->transactionID, self::goErrorString($err)));
                Metrics::workerRetriesTotal()->add(1, ['reason' => 'other']);
                throw $err;
            }

            Log::get()->info(sprintf('Transaction %s processed successfully', $txn->transactionID));
            Metrics::queueProcessingDuration()->record(microtime(true) - $startTime, ['result' => 'success']);
        } finally {
            $span->end();
        }
    }

    /**
     * handleTransactionRejection rejects the transaction with the processing
     * error and emits the `transaction.rejected` webhook.
     *
     * @throws \Throwable the rejection or webhook error
     */
    private function handleTransactionRejection(Transaction $txn, \Throwable $err): void
    {
        $this->blnk()->rejectTransaction($txn, self::goErrorString($err));

        $this->blnk()->sendWebhook(new NewWebhook('transaction.rejected', clone $txn)); // Payload: *txn (a copy)
    }

    public static function hasReachedMaxRetryAttempt(?Configuration $cfg, int $retryCount): bool
    {
        if ($cfg === null || $cfg->queue->maxRetryAttempts <= 0) {
            return false;
        }
        return $retryCount >= $cfg->queue->maxRetryAttempts;
    }

    public static function shouldRejectLockContentionImmediately(?Configuration $cfg, ?\Throwable $err): bool
    {
        if ($cfg === null || !$cfg->queue->rejectLockContentionImmediately) {
            return false;
        }
        return HotPairsRouter::isLockContentionError($err);
    }

    /**
     * indexData indexes data into TypeSense for searchability.
     * It fetches the collection name and payload from the task, ensures the collections exist,
     * and sends the payload to the appropriate TypeSense collection for indexing.
     *
     * @throws \Throwable
     */
    public function indexData(QueueTask $t): void
    {
        $cnf = $this->cnf();
        if ($cnf->typeSense->dns === '') {
            return;
        }

        // Unmarshal the indexing data from the task payload.
        try {
            $data = self::unmarshalIndexData($t);
        } catch (\Throwable $err) {
            Log::get()->error($err->getMessage());
            throw $err;
        }

        $collection = $data->collection;
        $payload = $data->payload;

        // Initialize a new TypeSense client and ensure collections exist.
        $newSearch = new TypesenseClient($cnf->typeSenseKey, [$cnf->typeSense->dns]);
        try {
            $newSearch->ensureCollectionsExist();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Failed to ensure collections exist: %s', $err->getMessage()));
            throw $err;
        }

        // Handle the notification and send the payload to the collection for indexing.
        try {
            $newSearch->handleNotification($collection, $payload ?? []);
        } catch (\Throwable $err) {
            Log::get()->error('Error indexing data', ['error' => $err->getMessage()]);
            throw $err;
        }

        Log::get()->info(sprintf(' [*] Data indexed: %s', $collection));
    }

    /**
     * indexBatchData indexes a batch of items into TypeSense in dependency order.
     * It first indexes all dependencies (e.g., balances), then indexes the primary item (e.g., transaction).
     * This ensures referential integrity in the search index.
     *
     * @throws \Throwable
     */
    public function indexBatchData(QueueTask $t): void
    {
        $cnf = $this->cnf();
        if ($cnf->typeSense->dns === '') {
            return;
        }

        // Unmarshal the batch data from the task payload.
        try {
            $batch = self::unmarshalIndexBatch($t);
        } catch (\Throwable $err) {
            Log::get()->error($err->getMessage());
            throw $err;
        }

        // Initialize a new TypeSense client and ensure collections exist.
        $newSearch = new TypesenseClient($cnf->typeSenseKey, [$cnf->typeSense->dns]);
        try {
            $newSearch->ensureCollectionsExist();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Failed to ensure collections exist: %s', $err->getMessage()));
            throw $err;
        }

        // Handle the batch notification - indexes dependencies first, then primary.
        try {
            $newSearch->handleBatchNotification($batch);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error indexing batch %s: %s', $batch->id, $err->getMessage()));
            throw $err;
        }

        Log::get()->info(sprintf(' [*] Batch indexed: %s (deps: %d)', $batch->id, \count($batch->dependencies)));
    }

    /**
     * processInflightCommit runs a queued inflight commit/void
     * (InflightActionPayload), accepting the legacy bare transaction-id payload.
     *
     * The PHP {@see Blnk::runInflightActionByParent()} throws exactly when Go
     * reports `retryable == true`, so a throw here means "retry"; a
     * non-retryable failure is logged by the service layer and the task is
     * acked (Go: "inflight action failed permanently; acking task").
     *
     * @throws \Throwable transient failure; the worker will retry
     */
    public function processInflightCommit(QueueTask $t): void
    {
        $payload = $t->payload;

        $p = null;
        if (\is_array($payload) && !array_is_list($payload)) {
            $p = InflightActionPayload::fromArray($payload);
        }
        if ($p === null || $p->transactionID === '') {
            // Legacy payload: a bare JSON string transaction ID from a scheduled
            // auto-commit enqueued before this change. Treat it as a full commit.
            if (!\is_string($payload)) {
                $serr = new \RuntimeException('json: cannot unmarshal inflight action payload into Go value of type string');
                Log::get()->error('failed to unmarshal inflight action payload', ['error' => $serr->getMessage()]);
                throw $serr;
            }
            if ($payload === '') {
                // Go: serr == nil && txnID == "" → logs and returns serr (nil): the task is acked.
                Log::get()->error('failed to unmarshal inflight action payload', ['error' => null]);
                return;
            }
            $p = new InflightActionPayload($payload, Queue::InflightActionCommit);
        }

        $action = $p->action;
        if ($action === '') {
            $action = Queue::InflightActionCommit;
        }

        $amount = BigInteger::zero();
        if ($p->preciseAmount !== '') {
            try {
                $amount = BigInteger::of($p->preciseAmount); // new(big.Int).SetString(p.PreciseAmount, 10)
            } catch (\Throwable) {
                // !ok: keep 0
            }
        }

        $this->blnk()->runInflightActionByParent($p->transactionID, $action, $amount, $p->actionID); // throws when retryable
    }

    /**
     * processInflightExpiry handles the expiry of inflight transactions.
     * It voids the transaction by its ID and logs the action.
     *
     * @throws \Throwable
     */
    public function processInflightExpiry(QueueTask $t): void
    {
        // Unmarshal the transaction ID from the task payload.
        $txnID = $t->payload;
        if (!\is_string($txnID)) {
            $err = new \RuntimeException('json: cannot unmarshal inflight expiry payload into Go value of type string');
            Log::get()->error($err->getMessage());
            throw $err;
        }

        // Void the inflight transaction by its ID.
        $this->blnk()->voidInflightTransaction($txnID);

        Log::get()->info(sprintf(' [*] Inflight Transaction Expired %s', $txnID));
    }

    // ------------------------------------------------------------------
    // Queue / server setup
    // ------------------------------------------------------------------

    /**
     * initializeQueues lists the transaction worker queues with their asynq weights.
     *
     * @return array<string, int>|null null when the configuration is not loaded
     */
    public static function initializeQueues(): ?array
    {
        try {
            $cfg = Configuration::fetch();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching config, using defaults: %s', $err->getMessage()));
            return null;
        }

        $queues = [];
        $queues[$cfg->queue->inflightExpiryQueue] = 1;
        $queues[$cfg->queue->inflightCommitQueue] = 1;

        for ($i = 1; $i <= $cfg->queue->numberOfQueues; $i++) {
            $queueName = sprintf('%s_%d', $cfg->queue->transactionQueue, $i);
            $queues[$queueName] = 1;
        }
        return $queues;
    }

    /**
     * initializeHotQueues lists the hot-lane queue (null when the hot lane is disabled).
     *
     * @return array<string, int>|null
     */
    public static function initializeHotQueues(): ?array
    {
        try {
            $cfg = Configuration::fetch();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching config, using defaults: %s', $err->getMessage()));
            return null;
        }
        if (!$cfg->queue->enableHotLane) {
            return null;
        }

        return [
            $cfg->queue->hotQueueName => 1,
        ];
    }

    /**
     * initializeWebhookQueues lists the webhook and index queues with their weights.
     *
     * @return array<string, int>|null
     */
    public static function initializeWebhookQueues(): ?array
    {
        try {
            $cfg = Configuration::fetch();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching config, using defaults: %s', $err->getMessage()));
            return null;
        }

        $queues = [];
        $queues[$cfg->queue->webhookQueue] = 3;
        $queues[$cfg->queue->indexQueue] = 1;

        return $queues;
    }

    /**
     * @param array<string, int> $queues
     * @throws \RuntimeException "error parsing Redis URL: ..." or a Redis connection failure
     */
    public static function initializeWebhookWorkerServer(Configuration $conf, array $queues): WorkerServer
    {
        return self::newWorkerServer($conf, $queues, $conf->queue->webhookConcurrency, 'webhook worker');
    }

    /**
     * @param array<string, int> $queues
     * @throws \RuntimeException
     */
    public static function initializeWorkerServer(Configuration $conf, array $queues): WorkerServer
    {
        return self::newWorkerServer($conf, $queues, $conf->queue->transactionWorkerConcurrency, 'transaction worker');
    }

    /**
     * @param array<string, int> $queues
     * @throws \RuntimeException
     */
    public static function initializeHotWorkerServer(Configuration $conf, array $queues): WorkerServer
    {
        return self::newWorkerServer($conf, $queues, $conf->queue->hotQueueConcurrency, 'hot transaction worker');
    }

    /**
     * newWorkerServer is the shared body of the three initialize*WorkerServer
     * functions (Go: ParseRedisURL + asynq.NewServer with the same
     * RedisClientOpt and a ShutdownTimeout of 30s).
     *
     * @param array<string, int> $queues
     * @throws \RuntimeException
     */
    private static function newWorkerServer(Configuration $conf, array $queues, int $concurrency, string $name): WorkerServer
    {
        try {
            RedisDb::parseRedisURL($conf->redis->dns, $conf->redis->skipTLSVerify);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('error parsing Redis URL: %s', $err->getMessage()), 0, $err);
        }

        $client = RedisDb::newRedisClient([$conf->redis->dns], $conf->redis->skipTLSVerify, new PoolConfig(
            $conf->redis->poolSize,
            $conf->redis->minIdleConns
        ))->client();

        return new WorkerServer(WorkerQueue::newQueue($conf, $client), $queues, $concurrency, self::ShutdownTimeoutSec, $name);
    }

    /**
     * initializeTaskHandlers registers the transaction, hot-lane, inflight
     * expiry and inflight commit handlers on the mux.
     */
    public static function initializeTaskHandlers(self $b, ServeMux $mux): void
    {
        try {
            $cfg = Configuration::fetch();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching config, using defaults: %s', $err->getMessage()));
            return;
        }

        for ($i = 1; $i <= $cfg->queue->numberOfQueues; $i++) {
            $queueName = sprintf('%s_%d', $cfg->queue->transactionQueue, $i);
            $mux->handleFunc($queueName, $b->processTransaction(...));
        }
        if ($cfg->queue->enableHotLane) {
            $mux->handleFunc($cfg->queue->hotQueueName, $b->processTransaction(...));
        }
        $mux->handleFunc($cfg->queue->inflightExpiryQueue, $b->processInflightExpiry(...));
        $mux->handleFunc($cfg->queue->inflightCommitQueue, $b->processInflightCommit(...));
    }

    /**
     * initializeWebhookTaskHandlers registers the webhook, hook execution,
     * index and index-batch handlers on the mux.
     */
    public static function initializeWebhookTaskHandlers(self $b, ServeMux $mux): void
    {
        try {
            $cfg = Configuration::fetch();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Error fetching config, using defaults: %s', $err->getMessage()));
            return;
        }

        $mux->handleFunc($cfg->queue->webhookQueue, static function (QueueTask $t) use ($b): void {
            $b->blnk()->processWebhook(self::rawPayload($t));
        });
        $mux->handleFunc(self::HookExecutionTaskType, static function (QueueTask $t) use ($b): void {
            $b->blnk()->hooks->processHookTask(self::rawPayload($t));
        });
        $mux->handleFunc($cfg->queue->indexQueue, $b->indexData(...));
        $mux->handleFunc(self::IndexBatchTaskType, $b->indexBatchData(...));
    }

    /**
     * registerCronJob adds a cron-scheduled job to the worker loop (see class doc).
     *
     * @param callable(): void $job
     * @throws \InvalidArgumentException on an invalid expression
     */
    public static function registerCronJob(string $expression, callable $job, string $name = ''): void
    {
        self::cron()->add($expression, $job, $name);
    }

    /**
     * run is the `workers` command (workerCommands' Run): traps SIGINT/SIGTERM,
     * fetches the configuration and runs the worker lifecycle. Returns the
     * process exit code (Go: logrus.Fatal exits 1).
     */
    public static function run(BlnkInstance $b): int
    {
        $trap = SignalTrap::install([SignalTrap::SIGINT, SignalTrap::SIGTERM]); // signal.NotifyContext
        try {
            try {
                $conf = Configuration::fetch();
            } catch (\Throwable $err) {
                Log::get()->critical(sprintf('Error fetching config:%s', $err->getMessage()));
                return 1;
            }

            try {
                self::runWorkers(static fn (): bool => $trap->done(), $b, $conf);
            } catch (\Throwable $err) {
                Log::get()->critical($err->getMessage());
                return 1;
            }
            return 0;
        } finally {
            $trap->release(); // defer stop()
        }
    }

    /**
     * runWorkers starts the transaction, hot-lane (optional), and webhook worker
     * servers plus the monitoring HTTP server, then blocks until ctx is canceled
     * and shuts everything down gracefully. Extracted from the cobra Run closure
     * so the full worker lifecycle is testable; startup failures are returned to
     * the caller, which keeps the process-exit decision at the command layer.
     *
     * @param callable(): bool $ctxDone true once the context is canceled (SIGINT/SIGTERM or test-driven)
     * @throws \Throwable startup failures
     */
    public static function runWorkers(callable $ctxDone, BlnkInstance $b, Configuration $conf): void
    {
        [$phClient, $shutdown] = ServerCommand::initializeTelemetryAndObservability($conf);

        /** @var array<int, callable(): void> $deferred */
        $deferred = [];
        $deferred[] = static function () use ($shutdown): void {
            try {
                $shutdown(); // with a 10s budget in Go
            } catch (\Throwable $err) {
                Log::get()->error(sprintf('Error during shutdown: %s', $err->getMessage()));
            }
        };
        if ($phClient !== null) {
            $deferred[] = static fn () => $phClient->close();
        }

        try {
            [$srv, $hotSrv, $webhookSrv, $mux, $webhookMux] = self::setupWorkerServers($b, $conf);

            $monitoringSrv = self::startMonitoringServer($conf);

            try {
                $srv->start($mux);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('could not start transaction worker server: %s', $err->getMessage()), 0, $err);
            }
            if ($hotSrv !== null) {
                try {
                    $hotSrv->start($mux);
                } catch (\Throwable $err) {
                    throw new \RuntimeException(sprintf('could not start hot transaction worker server: %s', $err->getMessage()), 0, $err);
                }
            }
            try {
                $webhookSrv->start($webhookMux);
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('could not start webhook worker server: %s', $err->getMessage()), 0, $err);
            }

            $recoveryProcessor = QueuedTransactionRecoveryProcessor::newQueuedTransactionRecoveryProcessor($b->blnk);
            $recoveryProcessor->start();

            Log::get()->info('Workers started.');

            $cron = self::cron();
            if ($cron->count() > 0) {
                Log::get()->info('cron jobs registered', ['jobs' => $cron->jobs()]);
            }

            // Wait for SIGINT/SIGTERM (or test-driven context cancellation),
            // driving the worker servers and the periodic work meanwhile.
            /** @var WorkerServer[] $servers */
            $servers = array_values(array_filter([$srv, $hotSrv, $webhookSrv]));
            while (!$ctxDone()) {
                $processed = 0;
                foreach ($servers as $server) {
                    try {
                        $processed += $server->runOnce();
                    } catch (\Throwable $err) {
                        // asynq logs dequeue errors and keeps polling; do the same with a short backoff.
                        Log::get()->error(sprintf('%s: queue error: %s', $server->name(), $err->getMessage()));
                        sleep(self::QueueErrorBackoffSec);
                    }
                    if ($ctxDone()) {
                        break;
                    }
                }
                $recoveryProcessor->tick();
                if ($monitoringSrv !== null) {
                    $monitoringSrv->poll();
                }
                $cron->tick();
                if ($phClient !== null) {
                    $phClient->tick();
                }
                if ($processed === 0 && !$ctxDone()) {
                    usleep(self::IdlePollIntervalUs);
                }
            }

            Log::get()->info('Shutdown signal received. Shutting down...');

            $recoveryProcessor->stop();

            if ($monitoringSrv !== null) {
                try {
                    $monitoringSrv->shutdown(); // with a 5s budget in Go
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('monitoring shutdown error: %s', $err->getMessage()));
                }
            }

            $webhookSrv->shutdown();
            if ($hotSrv !== null) {
                $hotSrv->shutdown();
            }
            $srv->shutdown();

            Log::get()->info('Shutdown complete.');
        } finally {
            foreach (array_reverse($deferred) as $fn) {
                try {
                    $fn();
                } catch (\Throwable $err) {
                    Log::get()->error($err->getMessage());
                }
            }
        }
    }

    /**
     * setupWorkerServers builds the three worker servers and the two muxes.
     *
     * @return array{0: WorkerServer, 1: WorkerServer|null, 2: WorkerServer, 3: ServeMux, 4: ServeMux}
     * @throws \RuntimeException
     */
    public static function setupWorkerServers(BlnkInstance $b, Configuration $conf): array
    {
        $queues = self::initializeQueues() ?? [];
        $hotQueues = self::initializeHotQueues() ?? [];
        $webhookQueues = self::initializeWebhookQueues() ?? [];

        $srv = self::initializeWorkerServer($conf, $queues);

        $hotSrv = null;
        if ($conf->queue->enableHotLane && \count($hotQueues) > 0) {
            $hotSrv = self::initializeHotWorkerServer($conf, $hotQueues);
        }

        $webhookSrv = self::initializeWebhookWorkerServer($conf, $webhookQueues);

        $handlers = new self($b);

        $mux = new ServeMux();
        self::initializeTaskHandlers($handlers, $mux);

        $webhookMux = new ServeMux();
        self::initializeWebhookTaskHandlers($handlers, $webhookMux);

        return [$srv, $hotSrv, $webhookSrv, $mux, $webhookMux];
    }

    /**
     * startMonitoringServer starts the worker monitoring HTTP server
     * (`/health`, `/monitoring/` dashboard, `/metrics`) on queue.monitoring_port.
     *
     * @throws \RuntimeException "could not start monitoring server: ..." (Go: logrus.Fatalf in the serve goroutine)
     */
    public static function startMonitoringServer(Configuration $conf): MonitoringServer
    {
        try {
            RedisDb::parseRedisURL($conf->redis->dns, $conf->redis->skipTLSVerify);
        } catch (\Throwable) {
            // Go: redisOption, _ := redis_db.ParseRedisURL(...) — the error is ignored
        }

        $queueNames = array_keys(
            (self::initializeQueues() ?? []) + (self::initializeHotQueues() ?? []) + (self::initializeWebhookQueues() ?? [])
        );

        $monitoringAddr = sprintf(':%s', $conf->queue->monitoringPort);
        $srv = new MonitoringServer($conf, $monitoringAddr, array_map('strval', $queueNames));

        Log::get()->info(sprintf('Worker monitoring server listening on %s (health: /health, dashboard: /monitoring)', $monitoringAddr));
        $srv->listenAndServe();

        return $srv;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** blnk returns the Blnk service (Go: `b.blnk`, nil-panics when unset). */
    public function blnk(): Blnk
    {
        if ($this->b->blnk === null) {
            throw new \LogicException('blnkInstance.blnk is nil');
        }
        return $this->b->blnk;
    }

    /** cnf returns the configuration (Go: `b.cnf`, nil-panics when unset). */
    public function cnf(): Configuration
    {
        if ($this->b->cnf === null) {
            throw new \LogicException('blnkInstance.cnf is nil');
        }
        return $this->b->cnf;
    }

    private static function cron(): CronScheduler
    {
        if (self::$cron === null) {
            self::$cron = new CronScheduler();
        }
        return self::$cron;
    }

    /**
     * rawPayload hands a task's payload to the handlers that accept "the
     * queued JSON": the decoded object when it is one, otherwise the JSON text
     * (Go: `t.Payload()` bytes).
     *
     * @return string|array<string, mixed>
     */
    private static function rawPayload(QueueTask $t): string|array
    {
        if (\is_array($t->payload) && !array_is_list($t->payload)) {
            return $t->payload;
        }
        return $t->payloadJson();
    }

    /**
     * unmarshalTransaction is `json.Unmarshal(t.Payload(), &txn)`: the payload
     * must be a JSON object.
     *
     * @throws \RuntimeException
     */
    private static function unmarshalTransaction(QueueTask $t): Transaction
    {
        $payload = $t->payload;
        if (!\is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new \RuntimeException('json: cannot unmarshal task payload into Go value of type model.Transaction');
        }
        return Transaction::fromArray($payload);
    }

    /**
     * @throws \RuntimeException
     */
    private static function unmarshalIndexData(QueueTask $t): IndexData
    {
        $payload = $t->payload;
        if (!\is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new \RuntimeException('json: cannot unmarshal task payload into Go value of type main.indexData');
        }
        return IndexData::fromArray($payload);
    }

    /**
     * @throws \RuntimeException
     */
    private static function unmarshalIndexBatch(QueueTask $t): IndexBatch
    {
        $payload = $t->payload;
        if (!\is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new \RuntimeException('json: cannot unmarshal task payload into Go value of type search.IndexBatch');
        }
        return IndexBatch::fromArray($payload);
    }

    /**
     * goErrorString renders an exception the way Go's `err.Error()` would
     * (an ApiErrorException prints as "CODE: message").
     */
    private static function goErrorString(\Throwable $err): string
    {
        if ($err instanceof ApiErrorException) {
            return $err->error();
        }
        return $err->getMessage();
    }
}
