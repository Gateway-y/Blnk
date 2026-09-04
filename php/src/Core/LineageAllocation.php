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

use Blnk\Internal\Log;
use Blnk\Model\LineageMapping;
use Brick\Math\BigInteger;

/**
 * LineageAllocation is the port of the root-package file `lineage_allocation.go`:
 * fund-source discovery and the FIFO / LIFO / PROPORTIONAL allocation strategies.
 *
 * `*big.Int` arithmetic maps to the immutable {@see BigInteger}: every Go
 * in-place mutation (`remaining.Sub(remaining, alloc)`) is a reassignment here.
 */
trait LineageAllocation
{
    /**
     * getLineageSources retrieves the available fund sources from shadow balances for allocation.
     * Uses a single batch query to fetch all shadow balances instead of N individual queries.
     *
     * Parameters:
     * - mappings []model.LineageMapping: The lineage mappings to get sources from.
     *
     * Returns:
     * - []LineageSource: The available fund sources.
     * - error: An error if the sources could not be retrieved (thrown).
     *
     * @param LineageMapping[] $mappings
     * @return LineageSource[]
     * @throws \Throwable "failed to fetch shadow balances: ..."
     */
    protected function getLineageSources(array $mappings): array
    {
        if (\count($mappings) === 0) {
            return [];
        }

        // Collect all shadow balance IDs for batch query
        $shadowBalanceIDs = [];
        foreach ($mappings as $mapping) {
            $shadowBalanceIDs[] = $mapping->shadowBalanceID;
        }

        // Fetch all shadow balances in a single query
        try {
            $balances = $this->datasource->getBalancesByIDsLite($shadowBalanceIDs);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to fetch shadow balances', $err);
        }

        // Build sources from the fetched balances
        $sources = [];
        foreach ($mappings as $mapping) {
            if (!isset($balances[$mapping->shadowBalanceID])) {
                continue;
            }
            $balance = $balances[$mapping->shadowBalanceID];

            if ($balance->debitBalance !== null && $balance->creditBalance !== null && $balance->debitBalance->getSign() > 0) {
                $available = $balance->debitBalance->minus($balance->creditBalance);
                if ($available->getSign() > 0) {
                    $sources[] = new LineageSource($mapping->shadowBalanceID, $available, $mapping->createdAt);
                }
            }
        }

        return $sources;
    }

    /**
     * calculateAllocation calculates fund allocations based on the specified strategy.
     * Supported strategies are FIFO, LIFO, and PROPORTIONAL.
     *
     * Parameters:
     * - sources []LineageSource: The available fund sources.
     * - amount *big.Int: The amount to allocate.
     * - strategy string: The allocation strategy (FIFO, LIFO, or PROPORTIONAL).
     *
     * Returns:
     * - []Allocation: The calculated allocations.
     *
     * (Go sorts the caller's slice in place with the unstable sort.Slice; PHP
     * sorts a copy with the stable usort — the caller never reuses the order.)
     *
     * @param LineageSource[] $sources
     * @return Allocation[]
     */
    protected function calculateAllocation(array $sources, BigInteger $amount, string $strategy): array
    {
        if (\count($sources) === 0) {
            return [];
        }

        switch ($strategy) {
            case self::AllocationLIFO:
                usort($sources, static function (LineageSource $i, LineageSource $j): int {
                    // Go less(i, j): sources[i].CreatedAt.After(sources[j].CreatedAt)
                    return self::goTimeOrZero($j->createdAt) <=> self::goTimeOrZero($i->createdAt);
                });
                return $this->sequentialAllocation($sources, $amount);
            case self::AllocationProp:
                return $this->proportionalAllocation($sources, $amount);
            default:
                usort($sources, static function (LineageSource $i, LineageSource $j): int {
                    // Go less(i, j): sources[i].CreatedAt.Before(sources[j].CreatedAt)
                    return self::goTimeOrZero($i->createdAt) <=> self::goTimeOrZero($j->createdAt);
                });
                return $this->sequentialAllocation($sources, $amount);
        }
    }

    /**
     * sequentialAllocation allocates funds sequentially from sources (used for FIFO/LIFO).
     *
     * Parameters:
     * - sources []LineageSource: The available fund sources in order.
     * - amount *big.Int: The amount to allocate.
     *
     * Returns:
     * - []Allocation: The calculated allocations.
     *
     * @param LineageSource[] $sources
     * @return Allocation[]
     */
    protected function sequentialAllocation(array $sources, BigInteger $amount): array
    {
        $allocations = [];

        // Skip if amount is zero or negative
        if ($amount->getSign() <= 0) {
            return [];
        }

        $remaining = $amount;

        foreach ($sources as $source) {
            if ($remaining->getSign() <= 0) {
                break;
            }

            if ($source->balance->compareTo($remaining) >= 0) {
                $alloc = $remaining;
            } else {
                $alloc = $source->balance;
            }

            // Skip zero allocations
            if ($alloc->getSign() <= 0) {
                continue;
            }

            $allocations[] = new Allocation($source->balanceID, $alloc);

            $remaining = $remaining->minus($alloc);
        }

        // Log warning if we couldn't allocate the full amount
        if ($remaining->getSign() > 0) {
            Log::get()->warning(sprintf('sequential allocation: could not allocate full amount, %s remaining unallocated', (string) $remaining));
        }

        return $allocations;
    }

    /**
     * proportionalAllocation allocates funds proportionally across all sources.
     *
     * Parameters:
     * - sources []LineageSource: The available fund sources.
     * - amount *big.Int: The amount to allocate.
     *
     * Returns:
     * - []Allocation: The calculated allocations.
     *
     * @param LineageSource[] $sources
     * @return Allocation[]
     */
    protected function proportionalAllocation(array $sources, BigInteger $amount): array
    {
        $allocations = [];

        // Skip if amount is zero or negative
        if ($amount->getSign() <= 0) {
            return [];
        }

        $total = BigInteger::zero();
        foreach ($sources as $source) {
            $total = $total->plus($source->balance);
        }

        if ($total->getSign() === 0) {
            return [];
        }

        // Cap amount at total available to prevent over-allocation
        $effectiveAmount = $amount;
        if ($effectiveAmount->compareTo($total) > 0) {
            Log::get()->warning(sprintf('proportional allocation: requested amount %s exceeds total available %s, capping at available', (string) $amount, (string) $total));
            $effectiveAmount = $total;
        }

        $remaining = $effectiveAmount;

        $sources = array_values($sources);
        $lastIndex = \count($sources) - 1;
        foreach ($sources as $i => $source) {
            if ($i === $lastIndex) {
                // Last source gets the remainder to handle rounding
                $alloc = $remaining;
            } else {
                // Calculate proportional share: (effectiveAmount * source.Balance) / total
                // (big.Int.Div is Euclidean; for the non-negative operands here it
                // equals the truncating quotient.)
                $proportion = $effectiveAmount->multipliedBy($source->balance);
                $alloc = $proportion->quotient($total);
            }

            // Cap at source's available balance
            if ($alloc->compareTo($source->balance) > 0) {
                $alloc = $source->balance;
            }

            // Skip zero allocations
            if ($alloc->getSign() <= 0) {
                continue;
            }

            $allocations[] = new Allocation($source->balanceID, $alloc);

            $remaining = $remaining->minus($alloc);
        }

        return $allocations;
    }

    /**
     * findMappingByShadowID finds a lineage mapping by its shadow balance ID.
     *
     * Parameters:
     * - mappings []model.LineageMapping: The lineage mappings to search.
     * - shadowBalanceID string: The shadow balance ID to find.
     *
     * Returns:
     * - *model.LineageMapping: The matching mapping, or nil if not found.
     *
     * @param LineageMapping[] $mappings
     */
    protected function findMappingByShadowID(array $mappings, string $shadowBalanceID): ?LineageMapping
    {
        foreach ($mappings as $mapping) {
            if ($mapping->shadowBalanceID === $shadowBalanceID) {
                return $mapping;
            }
        }
        return null;
    }
}
