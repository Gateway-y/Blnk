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

use Blnk\Core\QueueTask;
use Blnk\Internal\Log;

/**
 * WorkerServer is the replacement for `asynq.Server` (PORTING.md "Queue"):
 * one consumer of a group of queues with an asynq-style configuration
 * (`Queues` map of name → weight, `Concurrency`, `ShutdownTimeout`) that
 * dispatches popped tasks through a {@see ServeMux}, acks them on success and
 * retries/dead-letters them on failure through {@see WorkerQueue}.
 *
 * Divergences from asynq, all consequences of the single-process worker loop
 * of the PHP port ({@see WorkersCommand}):
 *  - Concurrency: asynq runs `Concurrency` handler goroutines; here tasks are
 *    processed one at a time and `Concurrency` bounds how many tasks the
 *    server takes per {@see runOnce()} turn before the loop moves on to the
 *    next server group (the transaction, hot-lane and webhook servers share
 *    the process).
 *  - Queue priority: asynq picks queues randomly with probability
 *    proportional to their weight; here the queues are tried in descending
 *    weight order (equal weights keep registration order).
 *  - Shutdown: there are no in-flight goroutines to wait for, so
 *    `ShutdownTimeout` only bounds the current task (which always completes).
 *  - Recoverer: asynq re-queues tasks whose lease expired once a minute; the
 *    same pass ({@see WorkerQueue::requeueStale()}) runs at start and every
 *    {@see StaleRecoveryIntervalSec} seconds.
 *  - Panics: a handler that throws an \Error (asynq: panics) fails the task
 *    like any other error and is retried.
 */
final class WorkerServer
{
    /** asynq recoverer interval, in seconds. */
    public const StaleRecoveryIntervalSec = 60;

    private WorkerQueue $queue;

    /** @var array<string, int> queue name → weight (asynq `Config.Queues`) */
    private array $queues;

    private int $concurrency;

    private int $shutdownTimeoutSec;

    private string $name;

    private ?ServeMux $handler = null;

    private bool $running = false;

    private bool $closed = false;

    private float $nextStaleRecoveryAt = 0.0;

    /**
     * @param array<string, int> $queues queue name → weight
     */
    public function __construct(WorkerQueue $queue, array $queues, int $concurrency, int $shutdownTimeoutSec, string $name = 'worker')
    {
        $this->queue = $queue;
        $this->queues = $queues;
        $this->concurrency = max(1, $concurrency);
        $this->shutdownTimeoutSec = $shutdownTimeoutSec;
        $this->name = $name;
    }

    /**
     * Start starts the worker server (asynq `Server.Start(handler)`): it
     * records the handler, recovers stale processing entries left by a
     * previous crashed worker and marks the server running. Like asynq it
     * fails when the server is already running or was shut down.
     *
     * @throws \RuntimeException
     */
    public function start(ServeMux $handler): void
    {
        if ($this->closed) {
            throw new \RuntimeException('asynq: Server closed');
        }
        if ($this->running) {
            throw new \RuntimeException('asynq: the server is already running');
        }
        $this->handler = $handler;
        $this->running = true;
        $this->recoverStale(true);
        Log::get()->info(sprintf('%s server started', $this->name), [
            'queues' => $this->queueNames(),
            'concurrency' => $this->concurrency,
        ]);
    }

    /**
     * runOnce takes up to `Concurrency` pending tasks from the server's queues
     * (highest weight first) and processes them; returns how many were
     * processed (0 when every queue was empty).
     */
    public function runOnce(): int
    {
        if (!$this->running || $this->handler === null) {
            return 0;
        }
        $this->recoverStale(false);

        $processed = 0;
        $names = $this->queueNames();
        while ($processed < $this->concurrency) {
            $task = $this->queue->tryPop($names);
            if ($task === null) {
                break;
            }
            $this->processTask($task);
            $processed++;
        }
        return $processed;
    }

    /**
     * processTask runs one popped task through the handler: ack on success,
     * retry with backoff (or dead-letter once retries are exhausted) on failure.
     */
    public function processTask(QueueTask $task): void
    {
        if ($this->handler === null) {
            throw new \LogicException('worker server has no handler: call start() first');
        }
        try {
            $this->handler->processTask($task);
        } catch (\Throwable $err) {
            $this->handleFailure($task, $err);
            return;
        }

        try {
            $this->queue->ack($task);
        } catch (\Throwable $err) {
            // The lease keeps the entry in the processing list; requeueStale replays it later.
            Log::get()->error('failed to ack task', ['type' => $task->type, 'task_id' => $task->taskID, 'error' => $err->getMessage()]);
        }
    }

    /**
     * Shutdown stops the server (asynq `Server.Shutdown`). The current task,
     * if any, has already completed when this is reached.
     */
    public function shutdown(): void
    {
        if (!$this->running) {
            return;
        }
        $this->running = false;
        $this->closed = true;
        Log::get()->info(sprintf('%s server stopped', $this->name), ['shutdown_timeout_sec' => $this->shutdownTimeoutSec]);
    }

    /** IsRunning reports whether start() was called and shutdown() was not. */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * queueNames lists the server's queues ordered by descending weight
     * (equal weights keep registration order).
     *
     * @return string[]
     */
    public function queueNames(): array
    {
        $entries = [];
        $i = 0;
        foreach ($this->queues as $name => $weight) {
            $entries[] = [(string) $name, (int) $weight, $i++];
        }
        usort($entries, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: $a[2] <=> $b[2]);
        return array_map(static fn (array $e): string => $e[0], $entries);
    }

    /** queues returns the configured queue → weight map. @return array<string, int> */
    public function queues(): array
    {
        return $this->queues;
    }

    public function queue(): WorkerQueue
    {
        return $this->queue;
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * handleFailure records a failed attempt: the task is re-scheduled with
     * asynq's backoff while it has retries left, otherwise dead-lettered
     * (asynq: archived, "Retry exhausted").
     */
    private function handleFailure(QueueTask $task, \Throwable $err): void
    {
        $fields = ['type' => $task->type, 'queue' => $task->queue, 'task_id' => $task->taskID, 'retry_count' => $task->retryCount, 'max_retry' => $task->maxRetry, 'error' => $err->getMessage()];
        try {
            $retried = $this->queue->retry($task, $err);
        } catch (\Throwable $queueErr) {
            Log::get()->error('failed to record task failure; the task stays in the processing list until its lease expires', $fields + ['queue_error' => $queueErr->getMessage()]);
            return;
        }
        if ($retried) {
            Log::get()->warning('task failed; scheduled for retry', $fields);
            return;
        }
        Log::get()->error(sprintf('Retry exhausted for task id=%s', (string) $task->taskID), $fields);
    }

    /**
     * recoverStale replays asynq's recoverer for every queue of the server
     * (at start, then every StaleRecoveryIntervalSec seconds).
     */
    private function recoverStale(bool $force): void
    {
        $now = microtime(true);
        if (!$force && $now < $this->nextStaleRecoveryAt) {
            return;
        }
        $this->nextStaleRecoveryAt = $now + self::StaleRecoveryIntervalSec;
        foreach ($this->queueNames() as $queueName) {
            try {
                $recovered = $this->queue->requeueStale($queueName);
                if ($recovered > 0) {
                    Log::get()->warning(sprintf('recovered %d stale task(s) from queue %s', $recovered, $queueName));
                }
            } catch (\Throwable $err) {
                Log::get()->error('stale task recovery failed', ['queue' => $queueName, 'error' => $err->getMessage()]);
            }
        }
    }
}
