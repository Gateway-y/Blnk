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

use Blnk\Internal\Traces\Tracer;
use Blnk\Model\LineageMapping;
use Brick\Math\BigInteger;

/**
 * LineageQueries is the port of the root-package file `lineage_queries.go`:
 * the balance and transaction lineage read models.
 *
 * The standalone type `TransactionLineage` became {@see TransactionLineage}.
 * The package-level `tracer` maps to `Tracer::get('blnk.transactions')`.
 */
trait LineageQueries
{
    /**
     * GetBalanceLineage retrieves the fund lineage for a balance.
     * It returns a breakdown of funds by provider, showing how much was received and spent from each source.
     *
     * Parameters:
     * - balanceID string: The ID of the balance to get lineage for.
     *
     * Returns:
     * - *BalanceLineage: The lineage information for the balance.
     * - error: An error if the lineage could not be retrieved (thrown).
     *
     * @throws \Throwable
     */
    public function getBalanceLineage(string $balanceID): BalanceLineage
    {
        $span = Tracer::get('blnk.transactions')->startSpan('GetBalanceLineage');
        try {
            try {
                $balance = $this->datasource->getBalanceByID($balanceID, [], false);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to get balance', $err);
            }

            if (!$balance->trackFundLineage) {
                throw new \RuntimeException(sprintf('balance %s does not have fund lineage tracking enabled', $balanceID));
            }

            try {
                $mappings = $this->datasource->getLineageMappings($balanceID);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to get lineage mappings', $err);
            }

            $lineage = new BalanceLineage();
            $lineage->balanceID = $balanceID;
            $lineage->providers = [];
            $lineage->totalWithLineage = BigInteger::zero();

            $this->populateLineageProviders($lineage, $mappings);

            return $lineage;
        } finally {
            $span->end();
        }
    }

    /**
     * populateLineageProviders populates the provider breakdown in a balance lineage.
     *
     * Parameters:
     * - lineage *BalanceLineage: The lineage to populate.
     * - mappings []model.LineageMapping: The lineage mappings.
     *
     * @param LineageMapping[] $mappings
     */
    protected function populateLineageProviders(BalanceLineage $lineage, array $mappings): void
    {
        foreach ($mappings as $mapping) {
            try {
                $breakdown = $this->calculateProviderBreakdown($mapping);
            } catch (\Throwable) {
                continue;
            }

            $lineage->providers[] = $breakdown;
            $lineage->totalWithLineage = ($lineage->totalWithLineage ?? BigInteger::zero())->plus($breakdown->available ?? BigInteger::zero());

            if ($lineage->aggregateBalanceID === '') {
                $lineage->aggregateBalanceID = $mapping->aggregateBalanceID;
            }
        }
    }

    /**
     * calculateProviderBreakdown calculates the fund breakdown for a provider.
     *
     * Parameters:
     * - mapping model.LineageMapping: The lineage mapping for the provider.
     *
     * Returns:
     * - *ProviderBreakdown: The calculated breakdown.
     * - error: An error if the breakdown could not be calculated (thrown).
     *
     * @throws \Throwable
     */
    protected function calculateProviderBreakdown(LineageMapping $mapping): ProviderBreakdown
    {
        $shadowBalance = $this->datasource->getBalanceByIDLite($mapping->shadowBalanceID);

        $debit = BigInteger::zero();
        $credit = BigInteger::zero();

        if ($shadowBalance->debitBalance !== null) {
            $debit = $shadowBalance->debitBalance;
        }
        if ($shadowBalance->creditBalance !== null) {
            $credit = $shadowBalance->creditBalance;
        }

        $available = $debit->minus($credit);

        $breakdown = new ProviderBreakdown();
        $breakdown->provider = $mapping->provider;
        $breakdown->amount = $debit;
        $breakdown->available = $available;
        $breakdown->spent = $credit;
        $breakdown->balanceID = $mapping->shadowBalanceID;
        return $breakdown;
    }

    /**
     * GetTransactionLineage retrieves the lineage information for a transaction.
     * It returns the fund allocation details and any shadow transactions created for the transaction.
     *
     * Parameters:
     * - transactionID string: The ID of the transaction to get lineage for.
     *
     * Returns:
     * - *TransactionLineage: The lineage information for the transaction.
     * - error: An error if the lineage could not be retrieved (thrown).
     *
     * @throws \Throwable "failed to get transaction: ..."
     */
    public function getTransactionLineage(string $transactionID): TransactionLineage
    {
        $span = Tracer::get('blnk.transactions')->startSpan('GetTransactionLineage');
        try {
            try {
                $txn = $this->getTransaction($transactionID);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to get transaction', $err);
            }

            $lineage = new TransactionLineage();
            $lineage->transactionID = $transactionID;
            $lineage->fundAllocation = $this->extractFundAllocation($txn->metaData);
            $lineage->shadowTransactions = [];

            try {
                $shadowTxns = $this->datasource->getTransactionsByShadowFor($transactionID);
                $lineage->shadowTransactions = $shadowTxns;
            } catch (\Throwable) {
                // Go: only assigned when err == nil.
            }

            return $lineage;
        } finally {
            $span->end();
        }
    }

    /**
     * extractFundAllocation extracts fund allocation data from transaction metadata.
     *
     * Parameters:
     * - metadata map[string]interface{}: The transaction metadata.
     *
     * Returns:
     * - []map[string]interface{}: The fund allocation data, or nil if not present.
     *
     * @param array<string, mixed>|null $metadata
     * @return array<int, array<string, mixed>>|null
     */
    protected function extractFundAllocation(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        if (!\array_key_exists(self::LineageFundAllocation, $metadata)) {
            return null;
        }
        $allocation = $metadata[self::LineageFundAllocation];

        // Go: allocation.([]interface{})
        if (!\is_array($allocation) || !array_is_list($allocation)) {
            return null;
        }

        $result = [];
        foreach ($allocation as $a) {
            // Go: a.(map[string]interface{}) — a decoded JSON object ({} decodes to []).
            if (\is_array($a) && ($a === [] || !array_is_list($a))) {
                $result[] = $a;
            }
        }

        return $result;
    }
}
