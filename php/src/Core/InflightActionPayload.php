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
 * InflightActionPayload is the task payload for a queued inflight commit/void.
 * ActionID is generated once at enqueue time and seeds the per-leg commit/void
 * references so asynq retries are idempotent.
 *
 * (Go: `InflightActionPayload`, queue.go.) JSON tags are preserved:
 * transaction_id, action, precise_amount, action_id.
 */
final class InflightActionPayload implements \JsonSerializable
{
    /** JSON: "transaction_id". */
    public string $transactionID;

    /** JSON: "action" — "commit" | "void" ({@see Queue::InflightActionCommit}, {@see Queue::InflightActionVoid}). */
    public string $action;

    /** JSON: "precise_amount" — big.Int string; "0" = full remaining. */
    public string $preciseAmount;

    /** JSON: "action_id". */
    public string $actionID;

    public function __construct(string $transactionID = '', string $action = '', string $preciseAmount = '', string $actionID = '')
    {
        $this->transactionID = $transactionID;
        $this->action = $action;
        $this->preciseAmount = $preciseAmount;
        $this->actionID = $actionID;
    }

    /**
     * Rebuilds the payload from its JSON form (Go: json.Unmarshal into InflightActionPayload).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['transaction_id'] ?? ''),
            (string) ($data['action'] ?? ''),
            (string) ($data['precise_amount'] ?? ''),
            (string) ($data['action_id'] ?? '')
        );
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return [
            'transaction_id' => $this->transactionID,
            'action' => $this->action,
            'precise_amount' => $this->preciseAmount,
            'action_id' => $this->actionID,
        ];
    }
}
