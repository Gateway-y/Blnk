<?php

/*
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
use Blnk\Internal\Log;
use GuzzleHttp\ClientInterface;

/**
 * Port of webhooks.go: outbound webhook delivery of the Go `Blnk` struct
 * (plus the package-level `getEventFromStatus` / `processHTTP`), composed into
 * {@see Blnk}. The `NewWebhook` struct is {@see NewWebhook}.
 *
 * Queue divergence (documented per PORTING.md "Queue"): Go enqueues the
 * webhook through asynq (task type = the configured webhook queue name, on
 * that queue). The PHP port pushes the same task as a JSON envelope onto the
 * Redis list `blnk:queue:<webhook_queue>` — the shape shared with
 * {@see \Blnk\Internal\Hooks\RedisHookManager}:
 *
 *   {"type": <webhook_queue>, "payload": {"event": ..., "data": ...},
 *    "queue": <webhook_queue>, "max_retry": 25, "retry_count": 0,
 *    "enqueued_at": <RFC3339>}
 *
 * The worker CLI (`blnk workers`) routes the "type" to
 * {@see WebhookService::processWebhook()} with the "payload" member, retrying
 * up to "max_retry" times (asynq's default MaxRetry, which Go relies on here).
 */
trait WebhookService
{
    /**
     * Retry budget of a queued webhook task: asynq's default `MaxRetry` (Go
     * passes no MaxRetry option for webhook tasks).
     */
    private const WEBHOOK_TASK_MAX_RETRY = 25;

    /**
     * getEventFromStatus maps a transaction status to a corresponding event string.
     *
     * Parameters:
     * - $status: The status of the transaction.
     *
     * Returns the corresponding event string for the transaction status.
     *
     * The Status* constants are the root-package constants of transaction.go /
     * transaction_inflight.go, reachable on the composing {@see Blnk} class.
     */
    protected static function getEventFromStatus(string $status): string
    {
        switch (strtolower($status)) {
            case strtolower(self::StatusQueued):
                return 'transaction.queued';
            case strtolower(self::StatusApplied):
                return 'transaction.applied';
            case strtolower(self::StatusScheduled):
                return 'transaction.scheduled';
            case strtolower(self::StatusInflight):
                return 'transaction.inflight';
            case strtolower(self::StatusVoid):
                return 'transaction.void';
            case strtolower(self::StatusRejected):
                return 'transaction.rejected';
            default:
                return 'transaction.unknown';
        }
    }

    /**
     * processHTTP sends a webhook notification via HTTP POST request.
     *
     * Parameters:
     * - $data: The webhook notification data to send.
     * - $client: The HTTP client to use for the request.
     *
     * @throws \Throwable if the request or processing fails; a non-2xx response
     *         throws "webhook delivery failed with status %d".
     */
    protected static function processHTTP(NewWebhook $data, ClientInterface $client): void
    {
        $conf = Configuration::fetch();

        $payloadBytes = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $secret = $conf->server->secretKey;

        $headers = ['Content-Type' => 'application/json'];

        if ($secret !== '') {
            $timestamp = (string) time();
            $signatureData = $timestamp . '.' . $payloadBytes;
            $signature = hash_hmac('sha256', $signatureData, $secret);
            $headers['X-Blnk-Signature'] = $signature;
            $headers['X-Blnk-Timestamp'] = $timestamp;
        } else {
            Log::get()->warning('webhook sent unsigned: server.secret_key is not configured');
        }

        foreach ($conf->notification->webhook->headers as $key => $value) {
            $headers[(string) $key] = (string) $value;
        }

        $resp = $client->request('POST', $conf->notification->webhook->url, [
            'body' => $payloadBytes,
            'headers' => $headers,
            'http_errors' => false,
        ]);

        $statusCode = $resp->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            Log::get()->warning(sprintf('Webhook failed with status %d', $statusCode));
            // Returning the error lets asynq retry the delivery; swallowing it
            // would permanently drop the webhook on receiver-side failures.
            throw new \RuntimeException(sprintf('webhook delivery failed with status %d', $statusCode));
        }
    }

    /**
     * SendWebhook enqueues a webhook notification task using the Blnk instance's asynq client.
     *
     * Parameters:
     * - $newWebhook: The webhook notification data to enqueue.
     *
     * @throws \Throwable if the task could not be enqueued.
     */
    public function sendWebhook(NewWebhook $newWebhook): void
    {
        $conf = $this->config();

        if ($conf->notification->webhook->url === '') {
            return;
        }

        $payload = json_encode($newWebhook, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Go: asynq.NewTask(conf.Queue.WebhookQueue, payload, asynq.Queue(conf.Queue.WebhookQueue))
        $queueName = $conf->queue->webhookQueue;
        $task = json_encode([
            'type' => $queueName,
            'payload' => json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            'queue' => $queueName,
            'max_retry' => self::WEBHOOK_TASK_MAX_RETRY,
            'retry_count' => 0,
            'enqueued_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::RFC3339),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $info = $this->asynqClient->lPush('blnk:queue:' . $queueName, $task);
        } catch (\Throwable $err) {
            Log::get()->error($err->getMessage());
            throw $err;
        }
        if ($info === false) {
            $err = new \RuntimeException('failed to enqueue webhook task');
            Log::get()->error($err->getMessage(), ['info' => $info]);
            throw $err;
        }
    }

    /**
     * ProcessWebhook processes a webhook notification task from the queue.
     *
     * Parameters:
     * - $task: The task containing the webhook notification data. Go receives
     *   an `*asynq.Task`; the PHP port receives the queued JSON (either the
     *   full queue envelope written by sendWebhook(), or the bare NewWebhook
     *   JSON) as a string or decoded array.
     *
     * @param string|array<string, mixed> $task
     * @throws \Throwable if the webhook processing fails.
     */
    public function processWebhook(string|array $task): void
    {
        $conf = Configuration::fetch();

        if ($conf->notification->webhook->url === '') {
            return;
        }

        if (\is_string($task)) {
            $decoded = json_decode($task, true);
            if (!\is_array($decoded)) {
                $err = new \RuntimeException(sprintf('Error unmarshaling task payload: %s', json_last_error_msg()));
                Log::get()->error($err->getMessage());
                throw $err;
            }
            $task = $decoded;
        }

        // Accept the Redis queue envelope produced by sendWebhook().
        if (\is_array($task['payload'] ?? null) && !\array_key_exists('event', $task)) {
            $task = $task['payload'];
        }

        $payload = NewWebhook::fromArray($task);
        self::processHTTP($payload, $this->httpClient);
    }
}
