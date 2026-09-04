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
 * LineageSource represents a source of funds available for allocation during lineage debit processing.
 *
 * Fields:
 * - BalanceID string: The ID of the shadow balance holding the funds.
 * - Balance *big.Int: The available balance amount.
 * - CreatedAt time.Time: The creation time of the lineage mapping, used for FIFO/LIFO ordering.
 *
 * (Go: `LineageSource`, lineage.go.)
 */
final class LineageSource
{
    public string $balanceID;

    public BigInteger $balance;

    /** A null stands for Go's zero time. */
    public ?\DateTimeImmutable $createdAt;

    public function __construct(string $balanceID, BigInteger $balance, ?\DateTimeImmutable $createdAt)
    {
        $this->balanceID = $balanceID;
        $this->balance = $balance;
        $this->createdAt = $createdAt;
    }
}
