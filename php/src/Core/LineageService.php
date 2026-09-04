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
 * LineageService is the port of the root-package file `lineage.go`.
 *
 * The Go file declares no `*Blnk` methods: it holds the fund-lineage
 * constants (kept here as trait constants, reachable as `Blnk::LineageProviderKey`
 * etc.) and the standalone types of the lineage pipeline, which became their
 * own classes in this namespace:
 *
 *  - `LineageSource`          → {@see LineageSource}
 *  - `Allocation`             → {@see Allocation}
 *  - `LineageOutboxPayload`   → {@see LineageOutboxPayload}
 *  - `ProviderBreakdown`      → {@see ProviderBreakdown}
 *  - `BalanceLineage`         → {@see BalanceLineage}
 *  - `destinationLineageInfo` → {@see DestinationLineageInfo}
 *
 * The lineage methods themselves live in the traits of the other lineage_*.go
 * files (LineageProcessing, LineageCredit, LineageDebit, LineageAllocation,
 * LineageAllocationMetadata, LineageOutbox, LineageQueries, LineageShadow).
 */
trait LineageService
{
    /** LineageProviderKey is the metadata key used to identify the provider of funds in a transaction. */
    public const LineageProviderKey = 'BLNK_LINEAGE_PROVIDER';

    /** LineageFundAllocation is the metadata key used to store fund allocation details in a transaction. */
    public const LineageFundAllocation = 'BLNK_FUND_ALLOCATION';

    // Allocation strategies for fund lineage debit processing.
    public const AllocationFIFO = 'FIFO';
    public const AllocationLIFO = 'LIFO';
    public const AllocationProp = 'PROPORTIONAL';

    /**
     * goTimeOrZero maps a nullable timestamp to the value Go would hold: a
     * null stands for Go's zero `time.Time` (0001-01-01T00:00:00Z), so
     * comparisons (`Before`/`After`/`Sub`) behave as in Go.
     *
     * Porting helper shared by the lineage and reconciliation traits.
     */
    protected static function goTimeOrZero(?\DateTimeImmutable $t): \DateTimeImmutable
    {
        return $t ?? new \DateTimeImmutable('0001-01-01T00:00:00+00:00');
    }
}
