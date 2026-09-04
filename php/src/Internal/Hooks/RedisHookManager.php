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

namespace Blnk\Internal\Hooks;

use Blnk\Config\Configuration;
use Blnk\Internal\Log;
use Blnk\Internal\Notification\Notification;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;

/**
 * Redis-based hook manager.
 *
 * Port of internal/hooks/manager.go, client.go and worker.go (`redisHookManager`).
 * Hook configurations are stored in Redis under the same keys as the Go
 * implementation ("hooks::<id>" registry entries plus the "hooks:pre" /
 * "hooks:post" type sets), and hook execution is dispatched over HTTP with the
 * same headers, HMAC signature and response handling.
 *
 * Queue divergence (documented): Go enqueues hook executions through asynq
 * (task type "new:hook_execution" on the configured webhook queue, with
 * asynq.MaxRetry(hook.RetryCount)). Per PORTING.md ("Queue") the PHP port
 * enqueues the same task type as a JSON envelope LPUSHed onto the Redis list
 * `blnk:queue:<webhook_queue>`:
 *
 *   {"type": "new:hook_execution", "payload": <HookTaskPayload>,
 *    "queue": <queue name>, "max_retry": <capped retry count>,
 *    "retry_count": 0, "enqueued_at": <RFC3339>}
 *
 * The worker CLI (`blnk workers`) BRPOPLPUSHes these envelopes and routes
 * "new:hook_execution" to processHookTask() with the "payload" member,
 * handling retries via "retry_count"/"max_retry".
 */
final class RedisHookManager implements HookManagerInterface
{
    private const hookKeyPrefix = 'hooks:';
    private const preHookKeyPrefix = 'hooks:pre';
    private const postHookKeyPrefix = 'hooks:post';

    /**
     * maxHookRetryCount caps a hook's retry count so a misconfigured hook can't
     * schedule an unbounded number of retries (asynq.MaxRetry(hook.RetryCount)).
     */
    private const maxHookRetryCount = 10;

    private \Redis $client;

    /** @var object|null Configuration (Blnk\Config\Configuration). */
    private ?object $config = null;

    private ?ClientInterface $httpClient;

    /**
     * newHookManager creates a new Redis-based hook manager.
     * It initializes the hook manager with the provided Redis client.
     *
     * Parameters:
     * - $redisClient: The Redis client for storing hook configurations (and,
     *   in this port, for enqueueing hook execution tasks — Go's separate
     *   asynq client points at the same Redis).
     * - $httpClient: Optional Guzzle client override (tests); by default a
     *   client is created per execution with the hook's timeout, as in Go.
     */
    public function __construct(\Redis $redisClient, ?ClientInterface $httpClient = null)
    {
        try {
            $this->config = Configuration::fetch();
        } catch (\Throwable $e) {
            Log::get()->error(sprintf('failed to fetch config: %s', $e->getMessage()));
            $this->config = null;
        }
        $this->client = $redisClient;
        $this->httpClient = $httpClient;
    }

    /**
     * registerHook registers a new webhook in Redis.
     * It assigns a new ID if not provided, validates the hook configuration,
     * and stores it in both the main registry and type-specific sets.
     *
     * @throws HookException if registration fails
     */
    public function registerHook(Hook $hook): void
    {
        if ($hook->id === '') {
            $hook->id = \Blnk\Model\ModelHelpers::generateUUIDWithSuffix('hook');
        }
        $hook->createdAt = new \DateTimeImmutable('now');

        // Validate hook
        self::validateHook($hook);

        // Store hook in Redis
        $key = sprintf('%s:%s', self::hookKeyPrefix, $hook->id);
        $data = self::marshalHook($hook);

        // Store in main hook registry
        if ($this->client->set($key, $data) === false) {
            throw new HookException('failed to store hook');
        }

        // Add to type-specific set for faster lookups
        $typeKey = self::getTypeKey($hook->type);
        if ($this->client->sAdd($typeKey, $hook->id) === false) {
            throw new HookException('failed to add hook to type set');
        }
    }

    /**
     * updateHook updates an existing webhook in Redis.
     * It retrieves the existing hook, updates its fields while preserving metadata,
     * handles type changes by updating sets, and saves the updated hook.
     *
     * @throws HookException if the hook is not found or the update fails
     */
    public function updateHook(string $hookID, Hook $hook): void
    {
        try {
            $existing = $this->getHook($hookID);
        } catch (\Throwable) {
            throw new HookException(sprintf('hook not found: %s', $hookID));
        }

        // Update fields while preserving metadata
        $hook->id = $existing->id;
        $hook->createdAt = $existing->createdAt;
        $hook->lastRun = $existing->lastRun;
        $hook->lastSuccess = $existing->lastSuccess;

        // Handle type change
        if ($existing->type !== $hook->type) {
            // Remove from old type set
            $this->client->sRem(self::getTypeKey($existing->type), $hookID);
            // Add to new type set
            $this->client->sAdd(self::getTypeKey($hook->type), $hookID);
        }

        // Store updated hook
        $data = self::marshalHook($hook);

        $key = sprintf('%s:%s', self::hookKeyPrefix, $hookID);
        if ($this->client->set($key, $data) === false) {
            throw new HookException('failed to store hook');
        }
    }

    /**
     * deleteHook removes a webhook from Redis.
     * It retrieves the hook to determine its type, then deletes it from
     * both the main registry and the type-specific set using a pipeline.
     *
     * @throws HookException if the deletion fails
     */
    public function deleteHook(string $hookID): void
    {
        $hook = $this->getHook($hookID);

        $key = sprintf('%s:%s', self::hookKeyPrefix, $hookID);
        $typeKey = self::getTypeKey($hook->type);

        // Use pipeline for atomic operations
        $pipe = $this->client->multi(\Redis::PIPELINE);
        $pipe->del($key);
        $pipe->sRem($typeKey, $hookID);
        $pipe->exec();
    }

    /**
     * getHook retrieves a webhook by its ID.
     * It fetches the hook data from Redis and unmarshals it into a Hook object.
     *
     * @throws HookException if the hook is not found or retrieval fails
     */
    public function getHook(string $hookID): Hook
    {
        $key = sprintf('%s:%s', self::hookKeyPrefix, $hookID);
        $data = $this->client->get($key);
        if ($data === false || $data === null) {
            throw new HookException(sprintf('hook not found: %s', $hookID));
        }

        $decoded = json_decode((string) $data, true);
        if (!is_array($decoded)) {
            throw new HookException(sprintf('failed to unmarshal hook: %s', json_last_error_msg()));
        }

        return Hook::fromArray($decoded);
    }

    /**
     * listHooks retrieves all hooks of a specific type or all hooks if type is empty.
     * If $hookType is provided, it fetches hooks from that specific set.
     *
     * @return Hook[]
     *
     * @throws HookException if retrieval fails
     */
    public function listHooks(string $hookType): array
    {
        $typeKey = self::getTypeKey($hookType);
        $hookIDs = $this->client->sMembers($typeKey);
        if (!is_array($hookIDs)) {
            throw new HookException('failed to list hooks');
        }

        $hooks = [];
        foreach ($hookIDs as $id) {
            try {
                $hooks[] = $this->getHook((string) $id);
            } catch (\Throwable) {
                continue; // Skip failed hooks
            }
        }

        return $hooks;
    }

    /**
     * executePreHooks queues all active pre-transaction hooks for execution.
     * It retrieves all PreTransaction hooks and queues them with the transaction data.
     */
    public function executePreHooks(string $transactionID, mixed $data): void
    {
        $hooks = $this->listHooks(HookType::PreTransaction);

        $this->executeHooks($hooks, HookType::PreTransaction, $transactionID, $data);
    }

    /**
     * executePostHooks queues all active post-transaction hooks for execution.
     * It retrieves all PostTransaction hooks and queues them with the transaction data.
     */
    public function executePostHooks(string $transactionID, mixed $data): void
    {
        $hooks = $this->listHooks(HookType::PostTransaction);

        $this->executeHooks($hooks, HookType::PostTransaction, $transactionID, $data);
    }

    /**
     * executeHooks marshals the hook data and queues each active hook for execution.
     *
     * @param Hook[] $hooks
     *
     * @throws HookException if data marshalling fails
     */
    public function executeHooks(array $hooks, string $hookType, string $transactionID, mixed $data): void
    {
        try {
            // Marshal once (as Go does) to guarantee the payload is JSON-safe;
            // the decoded form is kept so HookPayload re-encodes it in place of
            // Go's json.RawMessage.
            $dataBytes = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HookException(sprintf('failed to marshal hook data: %s', $e->getMessage()), 0, $e);
        }

        $payload = new HookPayload(
            $transactionID,
            $hookType,
            new \DateTimeImmutable('now'),
            json_decode($dataBytes, true)
        );

        foreach ($hooks as $hook) {
            if (!$hook->active) {
                continue;
            }

            // Queue hook execution instead of running directly
            try {
                $this->queueHook($hook, $payload);
            } catch (\Throwable $e) {
                Notification::notifyError(new HookException(sprintf(
                    'failed to queue hook execution for hook %s: %s',
                    $hook->id,
                    $e->getMessage()
                )));
            }
        }
    }

    /**
     * queueHook creates a queue task for a hook execution and enqueues it onto
     * the Redis-list queue (see the class doc for the asynq divergence).
     *
     * @throws HookException if task creation or enqueuing fails
     */
    private function queueHook(Hook $hook, HookPayload $payload): void
    {
        $taskPayload = new HookTaskPayload();
        $taskPayload->hook = $hook;
        $taskPayload->payload = $payload;

        $conf = $this->config;
        if ($conf === null) {
            try {
                $conf = Configuration::fetch();
            } catch (\Throwable $e) {
                throw new HookException(sprintf('failed to fetch config: %s', $e->getMessage()), 0, $e);
            }
            $this->config = $conf;
        }

        // Use webhook queue from config
        $queueName = (string) $conf->queue->webhookQueue;
        // Go: asynq.MaxRetry(hook.RetryCount) — already capped by validateHook.
        $maxRetry = $hook->retryCount;

        try {
            $task = json_encode([
                'type' => 'new:hook_execution',
                'payload' => $taskPayload,
                'queue' => $queueName,
                'max_retry' => $maxRetry,
                'retry_count' => 0,
                'enqueued_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::RFC3339),
            ], JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HookException(sprintf('failed to marshal hook task payload: %s', $e->getMessage()), 0, $e);
        }

        $pushed = $this->client->lPush('blnk:queue:' . $queueName, $task);
        if ($pushed === false) {
            throw new HookException('failed to enqueue hook task');
        }
    }

    /**
     * processHookTask processes a hook execution task from the queue.
     * It unmarshals the task payload and executes the hook with a timeout based
     * on the hook configuration (applied as the HTTP client timeout).
     *
     * Go signature: ProcessHookTask(ctx, *asynq.Task); the PHP port receives
     * the queued JSON payload (either the full queue envelope or the bare
     * HookTaskPayload JSON) as a string or decoded array.
     *
     * @param string|array<string, mixed> $task
     *
     * @throws HookException if hook execution fails
     */
    public function processHookTask(string|array $task): void
    {
        if (is_string($task)) {
            $decoded = json_decode($task, true);
            if (!is_array($decoded)) {
                throw new HookException(sprintf('failed to unmarshal hook task payload: %s', json_last_error_msg()));
            }
            $task = $decoded;
        }

        // Accept the Redis queue envelope produced by queueHook().
        if (($task['type'] ?? null) === 'new:hook_execution' && is_array($task['payload'] ?? null)) {
            $task = $task['payload'];
        }

        $taskPayload = HookTaskPayload::fromArray($task);
        if ($taskPayload->hook === null || $taskPayload->payload === null) {
            throw new HookException('failed to unmarshal hook task payload: missing hook or payload');
        }

        Log::get()->info('Processing queued hook task', [
            'hook_id' => $taskPayload->hook->id,
            'hook_type' => $taskPayload->hook->type,
        ]);

        $this->executeHook($taskPayload->hook, $taskPayload->payload);
    }

    /**
     * executeHook is a public wrapper for processing a hook task.
     * It executes the webhook by sending an HTTP POST request to the configured URL.
     *
     * @throws HookException if the webhook execution fails
     */
    public function executeHook(Hook $hook, HookPayload $payload): void
    {
        $this->doExecuteHook($hook, $payload);
    }

    /**
     * doExecuteHook performs the actual HTTP request for the webhook.
     * It handles marshalling the payload, creating the request, and processing the response.
     * Retries are handled by the queue system, so this function only performs a single attempt.
     *
     * (Port of the unexported Go executeHook; renamed to avoid clashing with the
     * public wrapper above.)
     */
    private function doExecuteHook(Hook $hook, HookPayload $payload): void
    {
        // Marshal payload with explicit handling. json_encode with
        // JSON_THROW_ON_ERROR also covers Go's follow-up json.Valid check.
        try {
            $payloadBytes = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HookException(sprintf('failed to marshal payload: %s', $e->getMessage()), 0, $e);
        }

        Log::get()->info('Executing webhook', [
            'hook_id' => $hook->id,
            'hook_name' => $hook->name,
            'hook_url' => $hook->url,
            'hook_type' => $hook->type,
        ]);

        $timestamp = (string) time();

        if ($this->config === null) {
            throw new HookException('config is not initialized');
        }
        $secret = (string) $this->config->server->secretKey;
        // Create signature: HMAC-SHA256(timestamp + "." + payload)
        $signatureData = $timestamp . '.' . $payloadBytes;
        $signature = hash_hmac('sha256', $signatureData, $secret);

        // Create HTTP client with timeout (Go: &http.Client{Timeout: hook.Timeout}).
        $client = $this->httpClient ?? new GuzzleClient();

        try {
            $resp = $client->request('POST', $hook->url, [
                'body' => $payloadBytes,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Blnk-Signature' => $signature,
                    'X-Blnk-Timestamp' => $timestamp,
                    'X-Hook-ID' => $hook->id,
                    'X-Hook-Type' => $hook->type,
                ],
                'timeout' => $hook->timeout,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            // Update hook execution status on failure
            $this->updateHookStatus($hook, false);
            throw new HookException(sprintf('failed to execute request: %s', $e->getMessage()), 0, $e);
        }

        // Read response body
        $body = (string) $resp->getBody();
        $statusCode = $resp->getStatusCode();

        Log::get()->debug('Hook response received', [
            'hook_id' => $hook->id,
            'hook_type' => $hook->type,
            'status_code' => $statusCode,
            'response' => $body,
        ]);

        // Handle empty response
        if ($body === '') {
            if ($statusCode >= 200 && $statusCode < 300) {
                Log::get()->info('Hook executed successfully with empty response', [
                    'hook_id' => $hook->id,
                    'hook_name' => $hook->name,
                    'hook_url' => $hook->url,
                    'hook_type' => $hook->type,
                    'status_code' => $statusCode,
                ]);
                $this->updateHookStatus($hook, true);

                return;
            }
            $this->updateHookStatus($hook, false);
            throw new HookException(sprintf('hook returned empty response with status %d', $statusCode));
        }

        // Check if response is JSON
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($statusCode >= 200 && $statusCode < 300) {
                Log::get()->info('Hook executed successfully with non-JSON response', [
                    'hook_id' => $hook->id,
                    'hook_name' => $hook->name,
                    'hook_url' => $hook->url,
                    'hook_type' => $hook->type,
                    'status_code' => $statusCode,
                    'response' => $body,
                ]);
                $this->updateHookStatus($hook, true);

                return;
            }
            if ($statusCode >= 400) {
                $this->updateHookStatus($hook, false);
                throw new HookException(sprintf('hook returned non-JSON error response (status %d): %s', $statusCode, $body));
            }
            // For non-error status codes, treat non-JSON response as success but log a warning
            Log::get()->warning('Hook returned non-JSON response', [
                'hook_id' => $hook->id,
                'hook_type' => $hook->type,
                'status_code' => $statusCode,
                'response' => $body,
            ]);
            $this->updateHookStatus($hook, true);

            return;
        }

        // For JSON responses, try to parse as HookResponse (Go json.Unmarshal
        // into the struct fails for non-object JSON such as a bare string).
        if (!is_array($decoded)) {
            if ($statusCode >= 400) {
                $this->updateHookStatus($hook, false);
                throw new HookException('invalid JSON error response: json: cannot unmarshal value into HookResponse');
            }
            // For non-error status codes, treat invalid JSON as success but log warning
            Log::get()->warning('Could not parse hook response as JSON', [
                'hook_id' => $hook->id,
                'error' => 'response is not a JSON object',
            ]);
            $this->updateHookStatus($hook, true);

            return;
        }

        $hookResp = HookResponse::fromArray($decoded);

        if (!$hookResp->success) {
            $this->updateHookStatus($hook, false);
            throw new HookException(sprintf('hook execution failed: %s', $hookResp->message));
        }

        Log::get()->info('Hook executed successfully with JSON response', [
            'hook_id' => $hook->id,
            'hook_name' => $hook->name,
            'hook_url' => $hook->url,
            'hook_type' => $hook->type,
            'status_code' => $statusCode,
            'message' => $hookResp->message,
        ]);

        $this->updateHookStatus($hook, true);
    }

    /**
     * updateHookStatus persists the last-run metadata after an execution
     * attempt. Errors are logged and swallowed, mirroring the discarded return
     * value at every Go call site.
     */
    private function updateHookStatus(Hook $hook, bool $success): void
    {
        $hook->lastRun = new \DateTimeImmutable('now');
        $hook->lastSuccess = $success;

        Log::get()->info('Updated hook execution status', [
            'hook_id' => $hook->id,
            'hook_name' => $hook->name,
            'hook_type' => $hook->type,
            'success' => $success,
            'last_run' => $hook->lastRun->format(\DateTimeInterface::RFC3339),
        ]);

        try {
            $data = self::marshalHook($hook);
            $key = sprintf('%s:%s', self::hookKeyPrefix, $hook->id);
            $this->client->set($key, $data);
        } catch (\Throwable $e) {
            Log::get()->error(sprintf('failed to update hook status: %s', $e->getMessage()));
        }
    }

    /**
     * validateHook checks if a hook configuration is valid.
     * It ensures the URL and type are set correctly, and sets default values for
     * timeout and retry count if missing.
     *
     * @throws HookException if validation fails
     */
    private static function validateHook(Hook $hook): void
    {
        if ($hook->url === '') {
            throw new HookException('hook URL is required');
        }
        if ($hook->type !== HookType::PreTransaction && $hook->type !== HookType::PostTransaction) {
            throw new HookException(sprintf('invalid hook type: %s', $hook->type));
        }
        if ($hook->timeout <= 0) {
            $hook->timeout = 30; // Default timeout
        }
        if ($hook->retryCount < 0) {
            $hook->retryCount = 3; // Default retry count
        }
        if ($hook->retryCount > self::maxHookRetryCount) {
            $hook->retryCount = self::maxHookRetryCount;
        }
    }

    /**
     * getTypeKey returns the Redis key for a specific hook type set.
     */
    private static function getTypeKey(string $hookType): string
    {
        switch ($hookType) {
            case HookType::PreTransaction:
                return self::preHookKeyPrefix;
            case HookType::PostTransaction:
                return self::postHookKeyPrefix;
            default:
                return self::hookKeyPrefix;
        }
    }

    /**
     * @throws HookException when the hook cannot be encoded
     */
    private static function marshalHook(Hook $hook): string
    {
        try {
            return json_encode($hook, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HookException(sprintf('failed to marshal hook: %s', $e->getMessage()), 0, $e);
        }
    }
}
