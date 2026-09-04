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
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Balance;
use Blnk\Model\LineageMapping;
use Blnk\Model\Transaction;

/**
 * LineageCredit is the port of the root-package file `lineage_credit.go`:
 * credit-side fund lineage (shadow/aggregate balances, shadow credit
 * transaction, lineage mapping).
 *
 * The package-level `tracer` maps to `Tracer::get('blnk.transactions')`.
 */
trait LineageCredit
{
    /**
     * processLineageCredit processes a credit transaction for fund lineage tracking.
     * It creates shadow balances and queues a shadow transaction to track the provider's funds.
     *
     * Parameters:
     * - txn *model.Transaction: The credit transaction being processed.
     * - destBalance *model.Balance: The destination balance receiving the funds.
     * - provider string: The identifier of the fund provider.
     *
     * Returns:
     * - error: An error if the credit processing fails (thrown).
     *
     * @throws \Throwable
     */
    protected function processLineageCredit(Transaction $txn, Balance $destBalance, string $provider): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ProcessLineageCredit');
        try {
            $identityID = $destBalance->identityID;
            if ($identityID === '') {
                throw new \RuntimeException(sprintf('destination balance %s has no identity_id for lineage tracking', $destBalance->balanceID));
            }

            // Get or create the shadow and aggregate balances first (before locking)
            [$shadowBalance, $aggregateBalance] = $this->getOrCreateLineageBalances($identityID, $provider, $txn->currency);

            // Use MultiLocker to lock both shadow and aggregate balances
            try {
                $locker = $this->acquireLineageLocks([$shadowBalance->balanceID, $aggregateBalance->balanceID]);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            try {
                $this->queueShadowCreditTransaction($txn, $destBalance, $provider, $shadowBalance, $aggregateBalance, $identityID);

                try {
                    $this->upsertCreditLineageMapping($destBalance, $provider, $shadowBalance, $aggregateBalance, $identityID);
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('failed to create lineage mapping after shadow transaction: %s (txn: %s, provider: %s)', self::goErrorString($err), $txn->transactionID, $provider));
                    $span->recordError($err);
                    // Don't return error - shadow transaction succeeded, mapping is for optimization
                }

                self::spanEvent($span, 'Lineage credit processed', [
                    'provider' => $provider,
                    'shadow_balance' => $shadowBalance->balanceID,
                ]);
            } finally {
                // Go: defer l.releaseLock(ctx, locker)
                $this->releaseLock($locker);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * getOrCreateLineageBalances retrieves or creates the shadow and aggregate balances for lineage tracking.
     *
     * Parameters:
     * - identityID string: The identity ID associated with the balance.
     * - provider string: The fund provider identifier.
     * - currency string: The currency for the balances.
     *
     * Returns:
     * - *model.Balance: The shadow balance for the provider.
     * - *model.Balance: The aggregate balance for all providers.
     * - error: An error if the balances could not be retrieved or created (thrown).
     *
     * @return array{0: Balance, 1: Balance} `[$shadowBalance, $aggregateBalance]`
     * @throws \Throwable
     */
    protected function getOrCreateLineageBalances(string $identityID, string $provider, string $currency): array
    {
        try {
            $identity = $this->datasource->getIdentityByID($identityID);
        } catch (\Throwable $err) {
            throw self::wrapError(sprintf('failed to get identity %s', $identityID), $err);
        }

        $identifier = $this->getIdentityIdentifier($identity);
        $shadowBalanceIndicator = sprintf('@%s_%s_lineage', $provider, $identifier);
        $aggregateBalanceIndicator = sprintf('@%s_lineage', $identifier);

        try {
            $shadowBalance = $this->getOrCreateBalanceByIndicator($shadowBalanceIndicator, $currency);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to get/create shadow balance', $err);
        }

        try {
            $aggregateBalance = $this->getOrCreateBalanceByIndicator($aggregateBalanceIndicator, $currency);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to get/create aggregate balance', $err);
        }

        return [$shadowBalance, $aggregateBalance];
    }

    /**
     * upsertCreditLineageMapping creates or updates the lineage mapping for a credit transaction.
     *
     * Parameters:
     * - destBalance *model.Balance: The destination balance.
     * - provider string: The fund provider identifier.
     * - shadowBalance *model.Balance: The shadow balance for the provider.
     * - aggregateBalance *model.Balance: The aggregate balance.
     * - identityID string: The identity ID associated with the balance.
     *
     * Returns:
     * - error: An error if the mapping could not be created (thrown).
     *
     * @throws \Throwable "failed to upsert lineage mapping: ..."
     */
    protected function upsertCreditLineageMapping(Balance $destBalance, string $provider, Balance $shadowBalance, Balance $aggregateBalance, string $identityID): void
    {
        $mapping = new LineageMapping();
        $mapping->balanceID = $destBalance->balanceID;
        $mapping->provider = $provider;
        $mapping->shadowBalanceID = $shadowBalance->balanceID;
        $mapping->aggregateBalanceID = $aggregateBalance->balanceID;
        $mapping->identityID = $identityID;

        try {
            $this->datasource->upsertLineageMapping($mapping);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to upsert lineage mapping', $err);
        }
    }

    /**
     * queueShadowCreditTransaction queues a shadow transaction to track credited funds from a provider.
     *
     * Parameters:
     * - txn *model.Transaction: The original credit transaction.
     * - destBalance *model.Balance: The destination balance.
     * - provider string: The fund provider identifier.
     * - shadowBalance *model.Balance: The shadow balance for the provider.
     * - aggregateBalance *model.Balance: The aggregate balance.
     * - identityID string: The identity ID associated with the balance.
     *
     * Returns:
     * - error: An error if the shadow transaction could not be queued (thrown).
     *
     * @throws \Throwable "failed to queue shadow credit transaction: ..."
     */
    protected function queueShadowCreditTransaction(Transaction $txn, Balance $destBalance, string $provider, Balance $shadowBalance, Balance $aggregateBalance, string $identityID): void
    {
        $shadowTxn = new Transaction();
        $shadowTxn->source = $shadowBalance->balanceID;
        $shadowTxn->destination = $aggregateBalance->balanceID;
        $shadowTxn->amount = $txn->amount;
        $shadowTxn->preciseAmount = $txn->preciseAmount; // Go: new(big.Int).Set(txn.PreciseAmount) — BigInteger is immutable
        $shadowTxn->currency = $destBalance->currency;
        $shadowTxn->precision = $txn->precision;
        $shadowTxn->reference = sprintf('%s_shadow_%s', $txn->reference, $provider);
        $shadowTxn->description = sprintf('Shadow credit from %s', $provider);
        $shadowTxn->metaData = [
            '_shadow_for' => $txn->transactionID,
            '_provider' => $provider,
            '_identity_id' => $identityID,
            '_lineage_type' => 'credit',
            '_main_balance' => $destBalance->balanceID,
        ];
        $shadowTxn->allowOverdraft = true;
        $shadowTxn->skipQueue = true;
        $shadowTxn->inflight = $txn->inflight;

        try {
            $this->queueTransaction($shadowTxn);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to queue shadow credit transaction', $err);
        }
    }
}
