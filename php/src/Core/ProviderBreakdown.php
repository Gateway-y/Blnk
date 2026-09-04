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
 * ProviderBreakdown represents the fund breakdown for a specific provider in a balance's lineage.
 *
 * Fields:
 * - Provider string: The name/identifier of the fund provider.
 * - Amount *big.Int: The total amount received from this provider.
 * - Available *big.Int: The amount still available (not yet spent).
 * - Spent *big.Int: The amount that has been debited.
 * - BalanceID string: The ID of the shadow balance tracking this provider's funds.
 *
 * (Go: `ProviderBreakdown`, lineage.go.) JSON names follow the Go tags.
 */
final class ProviderBreakdown implements \JsonSerializable
{
    /** json:"provider" */
    public string $provider = '';

    /** json:"amount" */
    public ?BigInteger $amount = null;

    /** json:"available" */
    public ?BigInteger $available = null;

    /** json:"spent" */
    public ?BigInteger $spent = null;

    /** json:"shadow_balance_id" */
    public string $balanceID = '';

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $b = new self();
        $b->provider = (string) ($data['provider'] ?? '');
        $b->amount = ModelHelpers::bigIntegerFromJson($data['amount'] ?? null);
        $b->available = ModelHelpers::bigIntegerFromJson($data['available'] ?? null);
        $b->spent = ModelHelpers::bigIntegerFromJson($data['spent'] ?? null);
        $b->balanceID = (string) ($data['shadow_balance_id'] ?? '');
        return $b;
    }

    public function jsonSerialize(): array
    {
        return [
            'provider' => $this->provider,
            'amount' => ModelHelpers::bigIntegerToJson($this->amount),
            'available' => ModelHelpers::bigIntegerToJson($this->available),
            'spent' => ModelHelpers::bigIntegerToJson($this->spent),
            'shadow_balance_id' => $this->balanceID,
        ];
    }
}
