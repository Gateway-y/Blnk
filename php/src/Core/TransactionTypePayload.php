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

use Blnk\Model\Transaction;

/**
 * TransactionTypePayload represents the payload for a transaction type.
 *
 * (Go: `TransactionTypePayload`, queue.go.) The Go struct has no json tag, so
 * encoding/json uses the field name "Data" as the JSON key.
 */
final class TransactionTypePayload implements \JsonSerializable
{
    /** JSON: "Data" (untagged Go field). */
    public ?Transaction $data;

    public function __construct(?Transaction $data = null)
    {
        $this->data = $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $txn = $data['Data'] ?? null;
        return new self(\is_array($txn) ? Transaction::fromArray($txn) : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        // A zero-value model.Transaction marshals as an object, never null.
        return ['Data' => $this->data ?? new Transaction()];
    }
}
