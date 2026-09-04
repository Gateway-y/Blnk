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

use Brick\Math\BigInteger;

/**
 * Allocation represents the amount allocated from a specific shadow balance during debit processing.
 *
 * Fields:
 * - BalanceID string: The ID of the shadow balance from which funds are allocated.
 * - Amount *big.Int: The amount allocated from this balance.
 *
 * (Go: `Allocation`, lineage.go.)
 */
final class Allocation
{
    public string $balanceID;

    public BigInteger $amount;

    public function __construct(string $balanceID, BigInteger $amount)
    {
        $this->balanceID = $balanceID;
        $this->amount = $amount;
    }
}
