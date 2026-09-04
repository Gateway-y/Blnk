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

/**
 * QueueTask is the PHP port's replacement for `asynq.Task` + `asynq.TaskInfo`
 * (there is no Go counterpart in queue.go; asynq owned these types).
 *
 * It is the JSON "envelope" stored on the Redis-list queues described in
 * PORTING.md ("Queue") and in {@see Queue}. The envelope shape is shared with
 * the two other producers of the port ({@see WebhookService::sendWebhook()}
 * and {@see \Blnk\Internal\Hooks\RedisHookManager}):
 *
 *   {"type": <task type>,            // asynq task.Type()  (handler routing key)
 *    "payload": <JSON value>,        // asynq task.Payload() decoded (object, array, string, ...)
 *    "queue": <queue name>,          // asynq.Queue(...)
 *    "task_id": <id>,                // asynq.TaskID(...) — optional
 *    "max_retry": <int>,             // asynq.MaxRetry(...) (default 25)
 *    "retry_count": <int>,           // asynq TaskInfo.Retried / GetRetryCount(ctx)
 *    "enqueued_at": <RFC3339>,
 *    "process_at": <RFC3339>,        // asynq.ProcessIn/ProcessAt — optional
 *    "last_error": <string>,         // asynq TaskInfo.LastErr — optional
 *    "failed_at": <RFC3339>}         // asynq TaskInfo.LastFailedAt — optional
 *
 * `raw` holds the exact envelope string as stored in Redis; it is the task's
 * identity for the processing-list bookkeeping (LREM) and is only set on
 * tasks produced by {@see Queue::pop()} / {@see Queue::enqueueTask()}.
 */
final class QueueTask implements \JsonSerializable
{
    /** asynq task.Type(): the handler routing key (a queue name or e.g. "new:index:batch"). */
    public string $type;

    /** asynq task.Payload(), decoded from JSON. */
    public mixed $payload;

    /** The queue the task lives on (asynq.Queue option). */
    public string $queue;

    /** asynq.TaskID option; null when the task was enqueued without an ID. */
    public ?string $taskID;

    /** asynq.MaxRetry option. */
    public int $maxRetry;

    /** Number of times the task has been retried so far (asynq TaskInfo.Retried). */
    public int $retryCount = 0;

    public ?\DateTimeImmutable $enqueuedAt = null;

    /** Fire time of a scheduled/retried task; null for immediately pending tasks. */
    public ?\DateTimeImmutable $processAt = null;

    /** The error message of the last failed attempt (asynq TaskInfo.LastErr). */
    public ?string $lastError = null;

    /** When the last attempt failed (asynq TaskInfo.LastFailedAt). */
    public ?\DateTimeImmutable $failedAt = null;

    /**
     * The exact envelope string stored in Redis (identity for LREM on the
     * processing list). Set by {@see Queue}; do not modify.
     *
     * @internal
     */
    public ?string $raw = null;

    public function __construct(
        string $type,
        mixed $payload,
        string $queue,
        ?string $taskID = null,
        ?\DateTimeImmutable $processAt = null,
        int $maxRetry = Queue::DefaultMaxRetry
    ) {
        $this->type = $type;
        $this->payload = $payload;
        $this->queue = $queue;
        $this->taskID = ($taskID === '') ? null : $taskID;
        $this->processAt = $processAt;
        $this->maxRetry = $maxRetry;
    }

    /**
     * payloadJson returns the payload re-encoded as JSON — the analogue of the
     * raw bytes Go handlers receive from `t.Payload()` and pass to
     * `json.Unmarshal`.
     *
     * @throws \JsonException
     */
    public function payloadJson(): string
    {
        return json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * leaseID identifies the task's processing lease: the task ID when one was
     * given, otherwise a digest of the envelope.
     */
    public function leaseID(): string
    {
        if ($this->taskID !== null) {
            return $this->taskID;
        }
        return sha1($this->raw ?? $this->toEnvelope());
    }

    /**
     * fromEnvelope decodes an envelope string popped from Redis. Envelopes
     * written by the other producers of the port (webhooks, hooks) carry only
     * the six base fields; every optional field defaults to null.
     *
     * @throws \RuntimeException when the string is not a valid envelope.
     */
    public static function fromEnvelope(string $raw): self
    {
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !\array_key_exists('type', $decoded) || !\is_string($decoded['type'])) {
            throw new \RuntimeException(sprintf('invalid queue task envelope: %s', json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'missing task type'));
        }

        $task = new self(
            $decoded['type'],
            $decoded['payload'] ?? null,
            (string) ($decoded['queue'] ?? ''),
            isset($decoded['task_id']) ? (string) $decoded['task_id'] : null,
            self::parseTime($decoded['process_at'] ?? null),
            (int) ($decoded['max_retry'] ?? Queue::DefaultMaxRetry)
        );
        $task->retryCount = (int) ($decoded['retry_count'] ?? 0);
        $task->enqueuedAt = self::parseTime($decoded['enqueued_at'] ?? null);
        $task->lastError = isset($decoded['last_error']) ? (string) $decoded['last_error'] : null;
        $task->failedAt = self::parseTime($decoded['failed_at'] ?? null);
        $task->raw = $raw;

        return $task;
    }

    /**
     * toEnvelope encodes the task as the envelope string stored in Redis.
     *
     * @throws \JsonException
     */
    public function toEnvelope(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $out = [
            'type' => $this->type,
            'payload' => $this->payload,
            'queue' => $this->queue,
        ];
        if ($this->taskID !== null) {
            $out['task_id'] = $this->taskID;
        }
        $out['max_retry'] = $this->maxRetry;
        $out['retry_count'] = $this->retryCount;
        $out['enqueued_at'] = ($this->enqueuedAt ?? new \DateTimeImmutable('now'))->format(\DateTimeInterface::RFC3339);
        if ($this->processAt !== null) {
            $out['process_at'] = $this->processAt->format(\DateTimeInterface::RFC3339);
        }
        if ($this->lastError !== null) {
            $out['last_error'] = $this->lastError;
        }
        if ($this->failedAt !== null) {
            $out['failed_at'] = $this->failedAt->format(\DateTimeInterface::RFC3339);
        }
        return $out;
    }

    private static function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
