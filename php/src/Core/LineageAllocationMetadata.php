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
use Blnk\Model\Identity;
use Blnk\Model\LineageMapping;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;

/**
 * LineageAllocationMetadata is the port of the root-package file
 * `lineage_allocation_metadata.go`: fund-allocation metadata persistence and
 * the identity identifier used to name lineage balances.
 */
trait LineageAllocationMetadata
{
    /**
     * updateFundAllocationMetadata updates the transaction metadata with fund allocation details.
     *
     * Parameters:
     * - txn *model.Transaction: The transaction to update.
     * - allocations []Allocation: The calculated allocations.
     * - mappings []model.LineageMapping: The lineage mappings.
     *
     * @param Allocation[] $allocations
     * @param LineageMapping[] $mappings
     */
    protected function updateFundAllocationMetadata(Transaction $txn, array $allocations, array $mappings): void
    {
        if (\count($allocations) === 0) {
            return;
        }

        $fundAllocation = $this->buildFundAllocationList($allocations, $mappings, $txn->precision);
        if (\count($fundAllocation) === 0) {
            return;
        }

        $newMetadata = [
            self::LineageFundAllocation => $fundAllocation,
        ];
        try {
            $this->datasource->updateTransactionMetadata($txn->transactionID, $newMetadata);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('failed to update transaction with fund allocation: %s', self::goErrorString($err)));
        }
    }

    /**
     * buildFundAllocationList builds a list of fund allocations for metadata storage.
     *
     * Parameters:
     * - allocations []Allocation: The calculated allocations.
     * - mappings []model.LineageMapping: The lineage mappings.
     * - precision float64: The precision multiplier.
     *
     * Returns:
     * - []map[string]interface{}: The fund allocation list for metadata.
     *
     * (The `*big.Int` amount marshals as a JSON number in Go; it is stored via
     * {@see ModelHelpers::bigIntegerToJson()} so the persisted metadata matches.)
     *
     * @param Allocation[] $allocations
     * @param LineageMapping[] $mappings
     * @return array<int, array<string, mixed>>
     */
    protected function buildFundAllocationList(array $allocations, array $mappings, float $precision): array
    {
        $fundAllocation = [];

        foreach ($allocations as $alloc) {
            $mapping = $this->findMappingByShadowID($mappings, $alloc->balanceID);
            if ($mapping === null) {
                continue;
            }

            $fundAllocation[] = [
                'provider' => $mapping->provider,
                'amount' => ModelHelpers::bigIntegerToJson($alloc->amount),
            ];
        }

        return $fundAllocation;
    }

    /**
     * getIdentityIdentifier generates a unique identifier string for an identity.
     * It uses the identity's name (first/last or organization) combined with a portion of the ID.
     *
     * Parameters:
     * - identity *model.Identity: The identity to generate an identifier for.
     *
     * Returns:
     * - string: The generated identifier.
     */
    protected function getIdentityIdentifier(Identity $identity): string
    {
        if ($identity->firstName !== '' && $identity->lastName !== '') {
            $namePart = mb_strtolower(sprintf('%s_%s', $identity->firstName, $identity->lastName));
        } elseif ($identity->organizationName !== '') {
            $namePart = mb_strtolower(str_replace(' ', '_', $identity->organizationName));
        } else {
            // No name available, use full ID
            return $identity->identityID;
        }

        // Use first 8 characters of ID for uniqueness
        // (Go slices bytes: idPart[:8])
        $idPart = $identity->identityID;
        if (\strlen($idPart) > 8) {
            $idPart = substr($idPart, 0, 8);
        }

        return sprintf('%s_%s', $namePart, $idPart);
    }
}
