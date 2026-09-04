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

namespace Blnk\Core;

use Blnk\Config\Configuration;
use Blnk\Internal\HotPairs\Manager as HotPairsManager;
use Blnk\Internal\HotPairs\Router;
use Blnk\Internal\Log;
use Blnk\Internal\Metrics\Metrics;
use Blnk\Internal\Redis\RedisDb;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;

/**
 * Queue represents a queue for handling various tasks.
 *
 * Port of queue.go. The Go struct wraps an `*asynq.Client` (producer) and an
 * `*asynq.Inspector`; the PHP port replaces hibiken/asynq with the same
 * semantics implemented on raw Redis through phpredis (PORTING.md "Queue").
 * Queue names (including the sharded `<transaction_queue>_<n>` names and the
 * hot lane), task type strings and payloads are identical to Go, so a Go
 * worker and a PHP worker agree on routing. The Inspector's only use
 * (`GetTaskInfo`) lives here as {@see Queue::getTaskInfo()}.
 *
 * asynq replacement — storage layout (all keys share the `blnk:queue:` prefix):
 *
 *   blnk:queue:<queue>              LIST  pending tasks, LPUSH by producers, BRPOPLPUSH by workers (FIFO)
 *   blnk:queue:<queue>:processing   LIST  tasks popped by a worker and not yet acked (asynq "active")
 *   blnk:queue:<queue>:scheduled    ZSET  scheduled (asynq.ProcessIn/ProcessAt) AND retried tasks, scored by unix fire time
 *   blnk:queue:<queue>:dead         LIST  dead-lettered tasks (asynq "archived"), capped at MaxDeadLetterSize
 *   blnk:queue:<queue>:t:<task_id>  STRING the envelope of a task enqueued with asynq.TaskID (uniqueness + lookup)
 *   blnk:queue:<queue>:lease:<id>   STRING processing lease with TTL (asynq lease)
 *
 * Every task is a JSON envelope ({@see QueueTask}): the shape shared with
 * {@see WebhookService::sendWebhook()} and
 * {@see \Blnk\Internal\Hooks\RedisHookManager}, which push onto the same lists.
 *
 * Documented divergences from asynq:
 *  - Task-ID uniqueness (asynq.TaskID → asynq.ErrTaskIDConflict, here
 *    {@see TaskIDConflictException}) is enforced per queue by the `:t:<id>` key,
 *    which is released on ack AND on dead-letter. asynq keeps archived tasks
 *    (and their IDs) until they are pruned/deleted from the archive.
 *  - Scheduled and retried tasks share one sorted set; asynq keeps separate
 *    "scheduled" and "retry" sets. {@see Queue::pop()} forwards due entries to
 *    the pending list before popping (asynq's forwarder goroutine).
 *  - Retry backoff reproduces asynq's DefaultRetryDelayFunc
 *    (`n^4 + 15 + rand(0..29)*(n+1)` seconds); a task whose retry_count has
 *    reached max_retry is dead-lettered instead of retried (asynq
 *    `msg.Retried >= msg.Retry`).
 *  - Leases are a fixed TTL (see {@see Queue::setLeaseDuration()}) without
 *    asynq's heartbeat extension; {@see Queue::extendLease()} is the manual
 *    equivalent. {@see Queue::requeueStale()} plays asynq's recoverer: a task
 *    whose lease expired is treated as a failed attempt ("asynq: task lease
 *    expired") and retried or dead-lettered.
 *  - The dead-letter list is capped at 10000 entries (asynq maxArchiveSize)
 *    but has no 90-day expiry.
 *  - Popping from several queues polls them round-robin at equal priority;
 *    asynq's queue weights/strict priority are not implemented.
 *  - Unsupported asynq options: Unique, Retention, Deadline, Timeout, Group.
 */
class Queue
{
    // Inflight action names carried in an InflightActionPayload.
    public const InflightActionCommit = 'commit';
    public const InflightActionVoid = 'void';

    /** asynq's default MaxRetry for tasks enqueued without an explicit MaxRetry option. */
    public const DefaultMaxRetry = 25;

    /** Redis key prefix of every queue key (PORTING.md: `blnk:queue:<queue_name>`). */
    public const KeyPrefix = 'blnk:queue:';

    /** asynq maxArchiveSize: the dead-letter list is trimmed to this many entries. */
    public const MaxDeadLetterSize = 10000;

    /** Default processing lease, in seconds (asynq: 30s + heartbeat extension). */
    public const DefaultLeaseDurationSec = 300;

    /** Longest single BRPOPLPUSH block of {@see Queue::pop()}, so due scheduled tasks are forwarded promptly. */
    private const POP_BLOCK_SLICE_SEC = 1;

    /** Poll interval of the multi-queue {@see Queue::pop()} loop. */
    private const POP_POLL_INTERVAL_US = 100000;

    /** Maximum number of due scheduled tasks forwarded per {@see Queue::pop()} call. */
    private const FORWARD_BATCH_SIZE = 100;

    /**
     * Atomic enqueue: KEYS = [task-id key, pending list, scheduled zset];
     * ARGV = [envelope, score ('' = pending now), has-task-id flag].
     * Returns 0 on a task-ID conflict, 1 when enqueued.
     */
    private const ENQUEUE_SCRIPT = <<<'LUA'
if ARGV[3] == '1' then
  local ok = redis.call('SET', KEYS[1], ARGV[1], 'NX')
  if not ok then return 0 end
end
if ARGV[2] == '' then
  redis.call('LPUSH', KEYS[2], ARGV[1])
else
  redis.call('ZADD', KEYS[3], ARGV[2], ARGV[1])
end
return 1
LUA;

    /**
     * Forward due scheduled/retry tasks: KEYS = [scheduled zset, pending list];
     * ARGV = [now, limit]. Returns the number of forwarded tasks.
     */
    private const FORWARD_SCRIPT = <<<'LUA'
local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, ARGV[2])
for i = 1, #due do
  redis.call('ZREM', KEYS[1], due[i])
  redis.call('LPUSH', KEYS[2], due[i])
end
return #due
LUA;

    /**
     * Ack: KEYS = [processing list, task-id key, lease key];
     * ARGV = [envelope, has-task-id flag].
     */
    private const ACK_SCRIPT = <<<'LUA'
redis.call('LREM', KEYS[1], 1, ARGV[1])
if ARGV[2] == '1' then redis.call('DEL', KEYS[2]) end
redis.call('DEL', KEYS[3])
return 1
LUA;

    /**
     * Retry: KEYS = [processing list, scheduled zset, task-id key, lease key];
     * ARGV = [old envelope, new envelope, score, has-task-id flag].
     */
    private const RETRY_SCRIPT = <<<'LUA'
redis.call('LREM', KEYS[1], 1, ARGV[1])
redis.call('ZADD', KEYS[2], ARGV[3], ARGV[2])
if ARGV[4] == '1' then redis.call('SET', KEYS[3], ARGV[2]) end
redis.call('DEL', KEYS[4])
return 1
LUA;

    /**
     * Dead-letter: KEYS = [processing list, dead list, task-id key, lease key];
     * ARGV = [old envelope, new envelope, max dead-letter size, has-task-id flag].
     */
    private const DEAD_LETTER_SCRIPT = <<<'LUA'
redis.call('LREM', KEYS[1], 1, ARGV[1])
redis.call('LPUSH', KEYS[2], ARGV[2])
redis.call('LTRIM', KEYS[2], 0, tonumber(ARGV[3]) - 1)
if ARGV[4] == '1' then redis.call('DEL', KEYS[3]) end
redis.call('DEL', KEYS[4])
return 1
LUA;

    /** Requeue a stale processing entry: KEYS = [processing list, pending list]; ARGV = [envelope]. */
    private const REQUEUE_SCRIPT = <<<'LUA'
if redis.call('LREM', KEYS[1], 1, ARGV[1]) == 1 then
  redis.call('LPUSH', KEYS[2], ARGV[1])
  return 1
end
return 0
LUA;

    /**
     * Go: `Client *asynq.Client` — in the PHP port the phpredis connection the
     * task envelopes are written to (and read from by the worker loop).
     */
    public \Redis $client;

    /** Go: `config *config.Configuration`. */
    protected Configuration $config;

    /** Processing lease TTL in seconds. */
    protected int $leaseDurationSec = self::DefaultLeaseDurationSec;

    public function __construct(Configuration $config, \Redis $client)
    {
        $this->config = $config;
        $this->client = $client;
    }

    /**
     * NewQueue initializes a new Queue instance with the provided configuration.
     *
     * Parameters:
     * - conf *config.Configuration: The configuration for the queue.
     *
     * Returns:
     * - *Queue: A pointer to the newly created Queue instance.
     *
     * Go parses the Redis URL to build the Inspector's connection options and
     * exits fatally when it cannot; the PHP port performs the same parse for
     * validation (the Inspector shares `$client`) and throws instead of exiting.
     *
     * @throws \RuntimeException if the Redis URL cannot be parsed.
     */
    public static function newQueue(Configuration $conf, \Redis $client): static
    {
        try {
            RedisDb::parseRedisURL($conf->redis->dns, $conf->redis->skipTLSVerify);
        } catch (\Throwable $err) {
            Log::get()->critical('failed to parse Redis URL', ['error' => $err->getMessage()]);
            throw new \RuntimeException(sprintf('failed to parse Redis URL: %s', $err->getMessage()), 0, $err);
        }

        return new static($conf, $client);
    }

    // ------------------------------------------------------------------
    // Producers (queue.go)
    // ------------------------------------------------------------------

    /**
     * EnqueueInflightAction enqueues a commit or void to the inflight-commit queue.
     * The asynq TaskID dedups concurrent actions for the same transaction: a second
     * enqueue while one is pending/active returns ErrInflightActionQueued. The
     * "inflight-action:" prefix is intentionally distinct from the scheduled
     * auto-commit TaskID (the bare transaction ID) so a pre-scheduled
     * inflight_commit_date task does not block a manual commit/void.
     *
     * @throws InflightActionQueuedException when a commit or void is already queued for the transaction.
     * @throws \Throwable if the task could not be enqueued.
     */
    public function enqueueInflightAction(InflightActionPayload $p): void
    {
        $payload = $this->marshal($p);

        $task = new QueueTask(
            $this->config->queue->inflightCommitQueue,
            $payload,
            $this->config->queue->inflightCommitQueue,
            'inflight-action:' . $p->transactionID,
            null,
            $this->config->queue->maxRetryAttempts
        );

        try {
            $this->enqueueTask($task);
        } catch (TaskIDConflictException $err) {
            throw new InflightActionQueuedException($err);
        } catch (\Throwable $err) {
            Log::get()->error('failed to enqueue inflight action', ['error' => $err->getMessage(), 'transaction_id' => $p->transactionID]);
            throw $err;
        }

        Log::get()->debug('successfully enqueued inflight action', ['transaction_id' => $p->transactionID, 'action' => $p->action]);
    }

    /**
     * queueInflightExpiry enqueues a task to handle inflight expiry for a transaction.
     *
     * Parameters:
     * - transactionID string: The ID of the transaction.
     * - expiresAt time.Time: The expiration time for the inflight status.
     *
     * Returns:
     * - error: An error if the task could not be enqueued (thrown).
     *
     * Go has both the unexported `queueInflightExpiry(transactionID, expiresAt)`
     * and the exported `QueueInflightExpiry(ctx, transaction)`; PHP method
     * names are case-insensitive, so the unexported variant carries the
     * `Internal` suffix (same convention as TransactionExecution).
     *
     * @throws \Throwable
     */
    protected function queueInflightExpiryInternal(string $transactionID, \DateTimeImmutable $expiresAt): void
    {
        // Go: json.Marshal(transactionID) — the payload is a bare JSON string.
        $task = new QueueTask(
            $this->config->queue->inflightExpiryQueue,
            $transactionID,
            $this->config->queue->inflightExpiryQueue,
            $transactionID,
            $expiresAt
        );

        try {
            $this->enqueueTask($task);
        } catch (\Throwable $err) {
            Log::get()->error('failed to enqueue inflight expiry', ['error' => $err->getMessage(), 'transaction_id' => $transactionID]);
            throw $err;
        }
        Log::get()->debug('successfully enqueued inflight expiry', ['transaction_id' => $transactionID]);
    }

    /**
     * queueIndexBatch enqueues a batch of items to be indexed in dependency order.
     * This ensures that dependencies (e.g., balances) are indexed before the primary item (e.g., transaction).
     * Uses the same IndexQueue but with a different task type for routing.
     *
     * Parameters:
     * - batch interface{}: The batch containing dependencies and primary item to index.
     *
     * Returns:
     * - error: An error if the task could not be enqueued (thrown).
     *
     * @throws \Throwable
     */
    public function queueIndexBatch(mixed $batch): void
    {
        if ($this->config->typeSense->dns === '') {
            return;
        }

        $payload = $this->marshal($batch);

        $task = new QueueTask('new:index:batch', $payload, $this->config->queue->indexQueue);
        try {
            $this->enqueueTask($task);
        } catch (\Throwable $err) {
            Log::get()->error('failed to enqueue index batch', ['error' => $err->getMessage()]);
            throw $err;
        }
        Log::get()->debug('successfully enqueued index batch');
    }

    /**
     * queueIndexData enqueues a task to index data in a specified collection.
     *
     * Parameters:
     * - id string: The ID of the data to index.
     * - collection string: The name of the collection to index the data in.
     * - data interface{}: The data to be indexed.
     *
     * Returns:
     * - error: An error if the task could not be enqueued (thrown).
     *
     * @throws \Throwable
     */
    public function queueIndexData(string $id, string $collection, mixed $data): void
    {
        if ($this->config->typeSense->dns === '') {
            return;
        }

        $payload = [
            'collection' => $collection,
            'payload' => $data,
        ];

        $iPayload = $this->marshal($payload);

        $task = new QueueTask($this->config->queue->indexQueue, $iPayload, $this->config->queue->indexQueue);
        try {
            $this->enqueueTask($task);
        } catch (\Throwable $err) {
            Log::get()->error('failed to enqueue index data', ['error' => $err->getMessage(), 'id' => $id]);
            throw $err;
        }
        Log::get()->debug('successfully enqueued index data', ['id' => $id]);
    }

    /**
     * Enqueue enqueues a transaction to the Redis queue.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be enqueued.
     *
     * Returns:
     * - error: An error if the transaction could not be enqueued (thrown).
     *
     * @throws \Throwable
     */
    public function enqueue(Transaction $transaction): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('Adding Transaction To Redis Queue');
        try {
            $payload = $this->marshal($transaction);
            $task = $this->geTask($transaction, $payload);
            $task->maxRetry = $this->config->queue->maxRetryAttempts; // asynq.MaxRetry(q.config.Queue.MaxRetryAttempts)
            try {
                $this->enqueueTask($task);
            } catch (\Throwable $err) {
                Log::get()->error('failed to enqueue transaction', ['error' => $err->getMessage(), 'reference' => $transaction->reference]);
                throw $err;
            }
            Log::get()->debug('successfully enqueued transaction', ['reference' => $transaction->reference]);

            // Record enqueue metrics.
            Metrics::queueEnqueuedTotal()->add(1, ['queue_name' => $task->type]);
        } finally {
            $span->end();
        }
    }

    /**
     * QueueInflightExpiry handles queuing a transaction for inflight expiration.
     * This method is separate from the main Enqueue to ensure expiration is handled
     * regardless of whether the transaction is queued or processed immediately.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to queue for expiration.
     *
     * Returns:
     * - error: An error if the expiration could not be queued (thrown).
     *
     * @throws \Throwable
     */
    public function queueInflightExpiry(Transaction $transaction): void
    {
        if ($transaction->inflightExpiryDate !== null) {
            $this->queueInflightExpiryInternal($transaction->transactionID, $transaction->inflightExpiryDate);
        }
    }

    /**
     * geTask generates a task for a transaction and assigns it to a specific queue based on the balance ID.
     * It ensures that transactions are evenly distributed across multiple queues by hashing the balance ID.
     * This approach helps to avoid race conditions on a balance by ensuring that all transactions related to the same balance
     * are processed serially within the same queue, thereby maintaining accuracy and consistency.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction for which to generate the task.
     * - payload []byte: The payload for the task, typically the serialized transaction data (PHP: decoded JSON).
     *
     * Returns:
     * - *asynq.Task: The generated task ready to be enqueued.
     *
     * (The Go name — with its typo — is kept for 1:1 fidelity.)
     */
    protected function geTask(Transaction $transaction, mixed $payload): QueueTask
    {
        $queueName = $this->transactionQueueName($transaction);

        $processAt = null;
        if ($transaction->scheduledFor !== null) {
            $processAt = $transaction->scheduledFor; // asynq.ProcessIn(time.Until(transaction.ScheduledFor))
        }

        return new QueueTask($queueName, $payload, $queueName, $transaction->transactionID, $processAt);
    }

    /**
     * transactionQueueName resolves the queue a transaction is routed to: the
     * hot queue when hot-lane routing stamped the "hot" lane into its metadata,
     * otherwise one of the `<transaction_queue>_<n>` shards chosen by hashing
     * the source balance ID.
     */
    protected function transactionQueueName(?Transaction $transaction): string
    {
        if ($this->config->queue->enableHotLane && $transaction !== null && $transaction->metaData !== null) {
            if (Router::queueLaneFromMetadata($transaction->metaData) === HotPairsManager::LaneHot) {
                Metrics::hotpairsLaneRoutedTotal()->add(1, ['lane' => 'hot']);
                return $this->config->queue->hotQueueName;
            }
        }

        Metrics::hotpairsLaneRoutedTotal()->add(1, ['lane' => 'normal']);
        // Go dereferences transaction.Source unconditionally (nil panics); PHP throws a TypeError likewise.
        $queueIndex = self::hashBalanceID($transaction->source) % $this->config->queue->numberOfQueues;
        return sprintf('%s_%d', $this->config->queue->transactionQueue, $queueIndex + 1);
    }

    /**
     * hashBalanceID returns a consistent hash value for a string balance ID.
     *
     * Parameters:
     * - balanceID string: The balance ID to hash.
     *
     * Returns:
     * - int: The hash value of the balance ID (FNV-1a 32-bit, as Go's hash/fnv New32a).
     */
    public static function hashBalanceID(string $balanceID): int
    {
        return (int) hexdec(hash('fnv1a32', $balanceID));
    }

    /**
     * GetTransactionFromQueue retrieves a transaction from the queue by its ID.
     *
     * Parameters:
     * - transactionID string: The ID of the transaction to retrieve.
     *
     * Returns:
     * - *model.Transaction: A pointer to the Transaction model if found; null if the
     *   transaction is not found in any queue (Go: nil, nil).
     * - error: An error if the transaction could not be retrieved (thrown).
     *
     * @throws \Throwable if a found task's payload cannot be decoded.
     */
    public function getTransactionFromQueue(string $transactionID): ?Transaction
    {
        for ($i = 1; $i <= $this->config->queue->numberOfQueues; $i++) {
            $queueName = sprintf('%s_%d', $this->config->queue->transactionQueue, $i);
            $task = $this->getTaskInfoSilently($queueName, $transactionID);
            if ($task !== null) {
                return $this->transactionFromTask($task);
            }
        }
        if ($this->config->queue->enableHotLane) {
            $task = $this->getTaskInfoSilently($this->config->queue->hotQueueName, $transactionID);
            if ($task !== null) {
                return $this->transactionFromTask($task);
            }
        }
        return null; // Return nil if transaction is not found in any queue
    }

    /**
     * queueInflightCommit enqueues a task to handle inflight commit for a transaction.
     *
     * Parameters:
     * - transactionID string: The ID of the transaction.
     * - commitAt time.Time: The scheduled time to automatically commit the inflight transaction.
     *
     * Returns:
     * - error: An error if the task could not be enqueued (thrown).
     *
     * (Unexported Go `queueInflightCommit`; `Internal` suffix as for queueInflightExpiryInternal.)
     *
     * @throws \Throwable
     */
    protected function queueInflightCommitInternal(string $transactionID, \DateTimeImmutable $commitAt): void
    {
        // Use the same struct payload and worker path as manual actions so the
        // scheduled auto-commit also covers all inflight legs and is idempotent on
        // retry. The TaskID stays the bare transaction ID (unchanged).
        $iPayload = $this->marshal(new InflightActionPayload(
            $transactionID,
            self::InflightActionCommit,
            '0',
            ModelHelpers::generateUUIDWithSuffix('act')
        ));

        $task = new QueueTask(
            $this->config->queue->inflightCommitQueue,
            $iPayload,
            $this->config->queue->inflightCommitQueue,
            $transactionID,
            $commitAt
        );

        try {
            $this->enqueueTask($task);
        } catch (\Throwable $err) {
            Log::get()->error('failed to enqueue inflight commit', ['error' => $err->getMessage(), 'transaction_id' => $transactionID]);
            throw $err;
        }

        Log::get()->debug('successfully enqueued inflight commit', ['transaction_id' => $transactionID]);
    }

    /**
     * QueueInflightCommit schedules an automatic commit for an inflight transaction at the specified date.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be committed automatically.
     *
     * Returns:
     * - error: An error if the task could not be enqueued (thrown).
     *
     * @throws \Throwable
     */
    public function queueInflightCommit(Transaction $transaction): void
    {
        if ($transaction->inflightCommitDate !== null) {
            $this->queueInflightCommitInternal($transaction->transactionID, $transaction->inflightCommitDate);
        }
    }

    // ------------------------------------------------------------------
    // asynq client replacement (generic enqueue)
    // ------------------------------------------------------------------

    /**
     * enqueueTask is the replacement for `asynq.Client.Enqueue(task, opts...)`:
     * it atomically registers the task ID (when set), then pushes the envelope
     * onto the pending list, or onto the scheduled sorted set when `processAt`
     * lies in the future (a past `processAt` is pending immediately, as in
     * asynq). The task's `raw` is set to the stored envelope.
     *
     * @throws TaskIDConflictException when a task with the same ID exists on the queue.
     * @throws \RuntimeException|\RedisException when Redis rejects the write.
     */
    public function enqueueTask(QueueTask $task): QueueTask
    {
        if ($task->queue === '') {
            throw new \InvalidArgumentException('queue name is required');
        }
        if ($task->enqueuedAt === null) {
            $task->enqueuedAt = new \DateTimeImmutable('now');
        }

        $envelope = $task->toEnvelope();

        $score = '';
        if ($task->processAt !== null) {
            $fireAt = (float) $task->processAt->format('U.u');
            if ($fireAt > microtime(true)) {
                $score = sprintf('%.6F', $fireAt);
            }
        }

        $hasID = $task->taskID !== null;
        $result = $this->client->eval(self::ENQUEUE_SCRIPT, [
            $hasID ? self::taskKey($task->queue, $task->taskID) : self::taskKey($task->queue, ''),
            self::pendingKey($task->queue),
            self::scheduledKey($task->queue),
            $envelope,
            $score,
            $hasID ? '1' : '0',
        ], 3);

        if ($result === false || $result === null) {
            throw new \RuntimeException(sprintf('failed to enqueue task: %s', (string) ($this->client->getLastError() ?? 'redis error')));
        }
        if ((int) $result === 0) {
            throw new TaskIDConflictException();
        }

        $task->raw = $envelope;
        return $task;
    }

    /**
     * enqueueScheduled is the explicit form of a scheduled enqueue
     * (`asynq.ProcessAt`): sets the fire time and enqueues.
     *
     * @throws TaskIDConflictException|\RuntimeException
     */
    public function enqueueScheduled(QueueTask $task, \DateTimeImmutable $processAt): QueueTask
    {
        $task->processAt = $processAt;
        return $this->enqueueTask($task);
    }

    // ------------------------------------------------------------------
    // asynq server/inspector replacement (consumer API for `blnk workers`)
    // ------------------------------------------------------------------

    /**
     * pop takes the next task of the given queue(s): due scheduled/retried
     * tasks are forwarded first, then the oldest pending envelope is moved to
     * the queue's processing list (BRPOPLPUSH) and leased. Returns null when
     * `timeout` seconds elapse without a task; a timeout of 0 blocks
     * indefinitely (Redis semantics). Undecodable envelopes are dead-lettered
     * and skipped.
     *
     * A single queue blocks on Redis (in slices of at most 1s so newly due
     * scheduled tasks keep flowing); several queues are polled round-robin at
     * equal priority.
     *
     * @param string|string[] $queueNames
     */
    public function pop(string|array $queueNames, int|float $timeout = 0): ?QueueTask
    {
        $queues = array_values(array_unique(\is_string($queueNames) ? [$queueNames] : array_map('strval', $queueNames)));
        if ($queues === []) {
            return null;
        }
        $deadline = $timeout > 0 ? microtime(true) + (float) $timeout : null;

        while (true) {
            foreach ($queues as $queue) {
                $this->forwardScheduled($queue);
            }

            $queue = $queues[0];
            $raw = false;
            if (\count($queues) === 1) {
                $slice = self::POP_BLOCK_SLICE_SEC;
                if ($deadline !== null) {
                    $remaining = $deadline - microtime(true);
                    if ($remaining <= 0) {
                        return null;
                    }
                    $slice = max(1, min(self::POP_BLOCK_SLICE_SEC, (int) ceil($remaining)));
                }
                $raw = $this->client->brpoplpush(self::pendingKey($queue), self::processingKey($queue), $slice);
            } else {
                foreach ($queues as $candidate) {
                    $raw = $this->client->rpoplpush(self::pendingKey($candidate), self::processingKey($candidate));
                    if (\is_string($raw) && $raw !== '') {
                        $queue = $candidate;
                        break;
                    }
                    $raw = false;
                }
            }

            if (\is_string($raw) && $raw !== '') {
                $task = $this->leaseTask($queue, $raw);
                if ($task !== null) {
                    return $task;
                }
                continue; // an undecodable envelope was dead-lettered; keep popping
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                return null;
            }
            if (\count($queues) > 1) {
                usleep(self::POP_POLL_INTERVAL_US);
            }
        }
    }

    /**
     * ack marks a popped task as successfully processed: it leaves the
     * processing list and its task-ID key and lease are released.
     */
    public function ack(QueueTask $task): void
    {
        $raw = $this->requireRaw($task);
        $this->client->eval(self::ACK_SCRIPT, [
            self::processingKey($task->queue),
            self::taskKey($task->queue, $task->taskID ?? ''),
            self::leaseKey($task->queue, $task->leaseID()),
            $raw,
            $task->taskID !== null ? '1' : '0',
        ], 3);
    }

    /**
     * retry records a failed attempt. When the task still has retries left it
     * is re-scheduled with asynq's default backoff and `true` is returned;
     * otherwise it is dead-lettered and `false` is returned (asynq:
     * `msg.Retried >= msg.Retry` → archive).
     */
    public function retry(QueueTask $task, \Throwable $err): bool
    {
        $raw = $this->requireRaw($task);

        if ($task->retryCount >= $task->maxRetry) {
            $this->deadLetter($task, $err->getMessage());
            return false;
        }

        $delay = self::retryDelay($task->retryCount, $err);
        $retryAt = microtime(true) + $delay;

        $task->retryCount++;
        $task->lastError = $err->getMessage();
        $task->failedAt = new \DateTimeImmutable('now');
        $task->processAt = (new \DateTimeImmutable('@' . (int) floor($retryAt)));
        $newRaw = $task->toEnvelope();

        $this->client->eval(self::RETRY_SCRIPT, [
            self::processingKey($task->queue),
            self::scheduledKey($task->queue),
            self::taskKey($task->queue, $task->taskID ?? ''),
            self::leaseKey($task->queue, $task->leaseID()),
            $raw,
            $newRaw,
            sprintf('%.6F', $retryAt),
            $task->taskID !== null ? '1' : '0',
        ], 4);

        $task->raw = $newRaw;
        return true;
    }

    /**
     * deadLetter moves a popped task to the queue's dead-letter list (asynq
     * "archive"), records the failure reason, releases the task-ID key and the
     * lease, and trims the list to MaxDeadLetterSize.
     */
    public function deadLetter(QueueTask $task, string $reason): void
    {
        $raw = $this->requireRaw($task);

        $task->lastError = $reason;
        $task->failedAt = new \DateTimeImmutable('now');
        $newRaw = $task->toEnvelope();

        $this->client->eval(self::DEAD_LETTER_SCRIPT, [
            self::processingKey($task->queue),
            self::deadKey($task->queue),
            self::taskKey($task->queue, $task->taskID ?? ''),
            self::leaseKey($task->queue, $task->leaseID()),
            $raw,
            $newRaw,
            (string) self::MaxDeadLetterSize,
            $task->taskID !== null ? '1' : '0',
        ], 4);

        $task->raw = $newRaw;
    }

    /**
     * extendLease renews the processing lease of a popped task (the manual
     * equivalent of asynq's heartbeat) for `$seconds` (default: the configured
     * lease duration).
     */
    public function extendLease(QueueTask $task, ?int $seconds = null): void
    {
        $this->requireRaw($task);
        $this->client->set(self::leaseKey($task->queue, $task->leaseID()), (string) time(), ['EX' => $seconds ?? $this->leaseDurationSec]);
    }

    /**
     * requeueStale plays asynq's recoverer for one queue: every entry of the
     * processing list whose lease has expired (worker crash, lost connection)
     * is treated as a failed attempt with error "asynq: task lease expired" and
     * retried with backoff or dead-lettered. Undecodable entries are
     * dead-lettered as-is. Returns the number of recovered entries.
     */
    public function requeueStale(string $queueName): int
    {
        $entries = $this->client->lRange(self::processingKey($queueName), 0, -1);
        if (!\is_array($entries)) {
            return 0;
        }

        $recovered = 0;
        foreach ($entries as $raw) {
            if (!\is_string($raw)) {
                continue;
            }
            try {
                $task = QueueTask::fromEnvelope($raw);
            } catch (\Throwable $err) {
                $this->deadLetterRaw($queueName, $raw);
                $recovered++;
                continue;
            }
            $task->queue = $task->queue !== '' ? $task->queue : $queueName;
            $task->raw = $raw;

            if ($this->client->exists(self::leaseKey($queueName, $task->leaseID()))) {
                continue; // still leased by a live worker
            }

            Log::get()->warning('requeueing task with expired lease', ['queue' => $queueName, 'type' => $task->type, 'task_id' => $task->taskID]);
            $this->retry($task, new \RuntimeException('asynq: task lease expired'));
            $recovered++;
        }

        return $recovered;
    }

    /**
     * getTaskInfo is the replacement for `asynq.Inspector.GetTaskInfo(queue, id)`:
     * returns the task enqueued with the given asynq.TaskID on the queue while
     * it is pending, scheduled, retrying or active; null once it was acked
     * (or dead-lettered — see the class doc).
     *
     * @throws \RuntimeException when the stored envelope cannot be decoded.
     */
    public function getTaskInfo(string $queueName, string $taskID): ?QueueTask
    {
        if ($taskID === '') {
            return null;
        }
        $raw = $this->client->get(self::taskKey($queueName, $taskID));
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        $task = QueueTask::fromEnvelope($raw);
        $task->queue = $task->queue !== '' ? $task->queue : $queueName;
        return $task;
    }

    /**
     * queueStats reports the sizes of a queue's lists/sets (asynq
     * Inspector.GetQueueInfo subset).
     *
     * @return array{pending: int, processing: int, scheduled: int, dead: int}
     */
    public function queueStats(string $queueName): array
    {
        return [
            'pending' => (int) $this->client->lLen(self::pendingKey($queueName)),
            'processing' => (int) $this->client->lLen(self::processingKey($queueName)),
            'scheduled' => (int) $this->client->zCard(self::scheduledKey($queueName)),
            'dead' => (int) $this->client->lLen(self::deadKey($queueName)),
        ];
    }

    /**
     * transactionQueueNames lists the sharded transaction queues
     * `<transaction_queue>_1 .. _<number_of_queues>` (cmd/workers.go
     * initializeQueues), without the hot lane.
     *
     * @return string[]
     */
    public function transactionQueueNames(): array
    {
        $names = [];
        for ($i = 1; $i <= $this->config->queue->numberOfQueues; $i++) {
            $names[] = sprintf('%s_%d', $this->config->queue->transactionQueue, $i);
        }
        return $names;
    }

    /**
     * hotQueueNames lists the hot-lane queue when the hot lane is enabled
     * (cmd/workers.go initializeHotQueues).
     *
     * @return string[]
     */
    public function hotQueueNames(): array
    {
        if (!$this->config->queue->enableHotLane) {
            return [];
        }
        return [$this->config->queue->hotQueueName];
    }

    /** setLeaseDuration overrides the processing lease TTL (seconds). */
    public function setLeaseDuration(int $seconds): void
    {
        $this->leaseDurationSec = max(1, $seconds);
    }

    public function leaseDuration(): int
    {
        return $this->leaseDurationSec;
    }

    /**
     * retryDelay reproduces asynq's DefaultRetryDelayFunc (formula taken from
     * sidekiq): `n^4 + 15 + rand(0..29) * (n + 1)` seconds, where `n` is the
     * number of retries so far.
     */
    public static function retryDelay(int $n, ?\Throwable $err = null): int
    {
        return (int) ($n ** 4) + 15 + (random_int(0, 29) * ($n + 1));
    }

    // ------------------------------------------------------------------
    // Redis key helpers
    // ------------------------------------------------------------------

    public static function pendingKey(string $queue): string
    {
        return self::KeyPrefix . $queue;
    }

    public static function processingKey(string $queue): string
    {
        return self::KeyPrefix . $queue . ':processing';
    }

    public static function scheduledKey(string $queue): string
    {
        return self::KeyPrefix . $queue . ':scheduled';
    }

    public static function deadKey(string $queue): string
    {
        return self::KeyPrefix . $queue . ':dead';
    }

    public static function taskKey(string $queue, string $taskID): string
    {
        return self::KeyPrefix . $queue . ':t:' . $taskID;
    }

    public static function leaseKey(string $queue, string $leaseID): string
    {
        return self::KeyPrefix . $queue . ':lease:' . $leaseID;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * forwardScheduled moves due entries of the queue's scheduled set to its
     * pending list (asynq forwarder). Returns the number of forwarded tasks.
     */
    protected function forwardScheduled(string $queue): int
    {
        $result = $this->client->eval(self::FORWARD_SCRIPT, [
            self::scheduledKey($queue),
            self::pendingKey($queue),
            sprintf('%.6F', microtime(true)),
            (string) self::FORWARD_BATCH_SIZE,
        ], 2);
        return \is_int($result) ? $result : 0;
    }

    /**
     * leaseTask decodes a popped envelope and takes its processing lease.
     * Returns null (after dead-lettering the raw entry) when it cannot be decoded.
     */
    protected function leaseTask(string $queue, string $raw): ?QueueTask
    {
        try {
            $task = QueueTask::fromEnvelope($raw);
        } catch (\Throwable $err) {
            Log::get()->error('dead-lettering undecodable queue task', ['queue' => $queue, 'error' => $err->getMessage()]);
            $this->deadLetterRaw($queue, $raw);
            return null;
        }
        $task->queue = $task->queue !== '' ? $task->queue : $queue;
        $task->raw = $raw;
        $this->client->set(self::leaseKey($queue, $task->leaseID()), (string) time(), ['EX' => $this->leaseDurationSec]);
        return $task;
    }

    /** deadLetterRaw moves an undecodable processing entry to the dead-letter list unchanged. */
    protected function deadLetterRaw(string $queue, string $raw): void
    {
        $this->client->eval(self::DEAD_LETTER_SCRIPT, [
            self::processingKey($queue),
            self::deadKey($queue),
            self::taskKey($queue, ''),
            self::leaseKey($queue, sha1($raw)),
            $raw,
            $raw,
            (string) self::MaxDeadLetterSize,
            '0',
        ], 4);
    }

    /**
     * @throws \InvalidArgumentException when the task was not produced by pop()/enqueueTask().
     */
    private function requireRaw(QueueTask $task): string
    {
        if ($task->raw === null) {
            throw new \InvalidArgumentException('task was not popped from the queue (missing raw envelope)');
        }
        return $task->raw;
    }

    /**
     * marshal is the analogue of `json.Marshal(v)` for envelope payloads: the
     * value is encoded (throwing on failure, as Go returns the error) and
     * decoded back so the envelope embeds it as a JSON value.
     *
     * @throws \JsonException
     */
    private function marshal(mixed $value): mixed
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** getTaskInfo variant swallowing lookup errors (Go: `err == nil && task != nil`). */
    private function getTaskInfoSilently(string $queueName, string $taskID): ?QueueTask
    {
        try {
            return $this->getTaskInfo($queueName, $taskID);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @throws \RuntimeException when the payload is not a transaction object (Go: json.Unmarshal error).
     */
    private function transactionFromTask(QueueTask $task): Transaction
    {
        if (!\is_array($task->payload)) {
            throw new \RuntimeException('json: cannot unmarshal queued task payload into model.Transaction');
        }
        return Transaction::fromArray($task->payload);
    }
}
