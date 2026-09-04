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
use Brick\Math\BigInteger;

/**
 * BalanceLineage represents the complete fund lineage for a balance.
 *
 * Fields:
 * - BalanceID string: The ID of the balance being queried.
 * - TotalWithLineage *big.Int: The total funds tracked across all providers.
 * - AggregateBalanceID string: The ID of the aggregate shadow balance.
 * - Providers []ProviderBreakdown: The breakdown of funds by provider.
 *
 * (Go: `BalanceLineage`, lineage.go.) JSON names follow the Go tags.
 */
final class BalanceLineage implements \JsonSerializable
{
    /** json:"balance_id" */
    public string $balanceID = '';

    /** json:"total_with_lineage" */
    public ?BigInteger $totalWithLineage = null;

    /** json:"aggregate_balance_id" */
    public string $aggregateBalanceID = '';

    /**
     * json:"providers" — a nil slice marshals as null, so the property is nullable.
     *
     * @var ProviderBreakdown[]|null
     */
    public ?array $providers = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $l = new self();
        $l->balanceID = (string) ($data['balance_id'] ?? '');
        $l->totalWithLineage = ModelHelpers::bigIntegerFromJson($data['total_with_lineage'] ?? null);
        $l->aggregateBalanceID = (string) ($data['aggregate_balance_id'] ?? '');
        if (isset($data['providers']) && \is_array($data['providers'])) {
            $l->providers = [];
            foreach ($data['providers'] as $provider) {
                if (\is_array($provider)) {
                    $l->providers[] = ProviderBreakdown::fromArray($provider);
                }
            }
        }
        return $l;
    }

    public function jsonSerialize(): array
    {
        return [
            'balance_id' => $this->balanceID,
            'total_with_lineage' => ModelHelpers::bigIntegerToJson($this->totalWithLineage),
            'aggregate_balance_id' => $this->aggregateBalanceID,
            'providers' => $this->providers === null ? null : array_values($this->providers),
        ];
    }
}
