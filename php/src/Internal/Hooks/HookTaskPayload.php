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

/**
 * HookTaskPayload represents the payload for a queued hook task.
 *
 * Port of the Go `HookTaskPayload` struct (internal/hooks/types.go). The
 * `transaction` field references \Blnk\Model\Transaction; it stays null on the
 * enqueue path (as in Go) and is carried as decoded data when round-tripping
 * through the queue.
 */
final class HookTaskPayload implements \JsonSerializable
{
    /** JSON: "hook". */
    public ?Hook $hook = null;

    /** JSON: "payload". */
    public ?HookPayload $payload = null;

    /** JSON: "transaction_id". */
    public string $transactionId = '';

    /** JSON: "data". */
    public mixed $data = null;

    /**
     * JSON: "transaction".
     *
     * @var \Blnk\Model\Transaction|array<string, mixed>|null
     */
    public mixed $transaction = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $task = new self();
        if (is_array($data['hook'] ?? null)) {
            $task->hook = Hook::fromArray($data['hook']);
        }
        if (is_array($data['payload'] ?? null)) {
            $task->payload = HookPayload::fromArray($data['payload']);
        }
        $task->transactionId = (string) ($data['transaction_id'] ?? '');
        $task->data = $data['data'] ?? null;
        $transaction = $data['transaction'] ?? null;
        if (is_array($transaction) && class_exists(\Blnk\Model\Transaction::class)) {
            $task->transaction = \Blnk\Model\Transaction::fromArray($transaction);
        } else {
            $task->transaction = $transaction;
        }

        return $task;
    }

    public function jsonSerialize(): array
    {
        // Go marshals every field (no omitempty): nil pointers/interfaces
        // serialize as null and the empty string as "".
        return [
            'hook' => $this->hook,
            'payload' => $this->payload,
            'transaction_id' => $this->transactionId,
            'data' => $this->data,
            'transaction' => $this->transaction,
        ];
    }
}
