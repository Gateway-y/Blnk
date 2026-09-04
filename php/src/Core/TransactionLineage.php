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

use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;

/**
 * TransactionLineage represents the lineage information for a specific transaction.
 *
 * Fields:
 * - TransactionID string: The ID of the transaction being queried.
 * - FundAllocation []map[string]interface{}: The allocation of funds by provider for debit transactions.
 * - ShadowTransactions []model.Transaction: The shadow transactions created for this transaction.
 *
 * (Go: `TransactionLineage`, lineage_queries.go.) JSON names follow the Go tags.
 */
final class TransactionLineage implements \JsonSerializable
{
    /** json:"transaction_id" */
    public string $transactionID = '';

    /**
     * json:"fund_allocation,omitempty"
     *
     * @var array<int, array<string, mixed>>|null
     */
    public ?array $fundAllocation = null;

    /**
     * json:"shadow_transactions" — a nil slice marshals as null, so the property is nullable.
     *
     * @var Transaction[]|null
     */
    public ?array $shadowTransactions = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $l = new self();
        $l->transactionID = (string) ($data['transaction_id'] ?? '');
        if (isset($data['fund_allocation']) && \is_array($data['fund_allocation'])) {
            $l->fundAllocation = [];
            foreach ($data['fund_allocation'] as $entry) {
                if (\is_array($entry)) {
                    $l->fundAllocation[] = $entry;
                }
            }
        }
        if (isset($data['shadow_transactions']) && \is_array($data['shadow_transactions'])) {
            $l->shadowTransactions = [];
            foreach ($data['shadow_transactions'] as $txn) {
                if (\is_array($txn)) {
                    $l->shadowTransactions[] = Transaction::fromArray($txn);
                }
            }
        }
        return $l;
    }

    public function jsonSerialize(): array
    {
        $out = [];
        $out['transaction_id'] = $this->transactionID;
        if ($this->fundAllocation !== null && \count($this->fundAllocation) > 0) { // omitempty (slice)
            $out['fund_allocation'] = array_map(
                static fn (array $m): mixed => ModelHelpers::mapToJson($m),
                array_values($this->fundAllocation)
            );
        }
        $out['shadow_transactions'] = $this->shadowTransactions === null ? null : array_values($this->shadowTransactions);
        return $out;
    }
}
