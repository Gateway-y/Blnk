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

use Blnk\Internal\Lock\MultiLocker;
use Blnk\Internal\Log;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Balance;
use Blnk\Model\LineageMapping;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;

/**
 * LineageDebit is the port of the root-package file `lineage_debit.go`:
 * debit-side fund lineage (allocation from shadow balances, release/receive
 * shadow transactions, destination lineage, distributed lineage locks).
 *
 * The standalone functions `releaseReference` and `receiveReference` are the
 * static methods of this trait. The package-level `tracer` maps to
 * `Tracer::get('blnk.transactions')`.
 */
trait LineageDebit
{
    /**
     * processLineageDebit processes a debit transaction for fund lineage tracking.
     * It allocates funds from shadow balances based on the configured allocation strategy.
     * Uses MultiLocker to lock ALL involved shadow balances (source AND destination) atomically,
     * preventing both race conditions and deadlocks from nested lock acquisition.
     *
     * Parameters:
     * - txn *model.Transaction: The debit transaction being processed.
     * - sourceBalance *model.Balance: The source balance being debited.
     * - destinationBalance *model.Balance: The destination balance receiving the funds.
     *
     * Returns:
     * - error: An error if the debit processing fails (thrown).
     *
     * @throws \Throwable
     */
    protected function processLineageDebit(Transaction $txn, Balance $sourceBalance, ?Balance $destinationBalance): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ProcessLineageDebit');
        try {
            // Get mappings first (before locking) to know which shadow balances we need
            try {
                $mappings = array_values($this->datasource->getLineageMappings($sourceBalance->balanceID));
            } catch (\Throwable $err) {
                throw self::wrapError('failed to get lineage mappings', $err);
            }

            if (\count($mappings) === 0) {
                // Check if there are pending credit outbox entries for this balance
                // If so, retry later after those credits are processed
                try {
                    $hasPending = $this->datasource->hasPendingCreditOutbox($sourceBalance->balanceID);
                } catch (\Throwable $err) {
                    Log::get()->warning(sprintf('failed to check pending credit outbox for balance %s: %s', $sourceBalance->balanceID, self::goErrorString($err)));
                    return;
                }
                if ($hasPending) {
                    throw new \RuntimeException(sprintf('pending credit outbox entries exist for balance %s, retry later', $sourceBalance->balanceID));
                }
                return;
            }

            // Collect all balance IDs for locking - source shadows + source aggregate
            $lockKeys = [];
            foreach ($mappings as $m) {
                $lockKeys[] = $m->shadowBalanceID;
            }
            $lockKeys[] = $mappings[0]->aggregateBalanceID;

            // Pre-create destination lineage balances (if destination tracks lineage) BEFORE acquiring locks
            $destLineageBalances = null;
            if ($destinationBalance !== null && $destinationBalance->trackFundLineage && $destinationBalance->identityID !== '') {
                try {
                    $destLineageBalances = $this->prepareDestinationLineageBalances($mappings, $destinationBalance);
                    foreach ($destLineageBalances as $info) {
                        $lockKeys[] = $info->shadowBalance->balanceID;
                        $lockKeys[] = $info->aggregateBalance->balanceID;
                    }
                } catch (\Throwable $err) {
                    Log::get()->warning(sprintf('failed to prepare destination lineage balances: %s', self::goErrorString($err)));
                }
            }

            try {
                $locker = $this->acquireLineageLocks($lockKeys);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            try {
                try {
                    $sources = $this->getLineageSources($mappings);
                } catch (\Throwable $err) {
                    throw self::wrapError('failed to get lineage sources', $err);
                }

                $remaining = $this->remainingDebitAmount($txn, $mappings);
                if ($remaining->getSign() <= 0) {
                    self::spanEvent($span, 'Lineage debit already fully processed; nothing to allocate');
                    return;
                }

                $allocations = $this->calculateAllocation($sources, $remaining, $sourceBalance->allocationStrategy);

                $sourceAggBalance = $this->getSourceAggregateBalance($sourceBalance);

                $this->processAllocations($txn, $allocations, $mappings, $sourceBalance, $destinationBalance, $sourceAggBalance, $destLineageBalances);
                $this->updateFundAllocationMetadata($txn, $allocations, $mappings);

                self::spanEvent($span, 'Lineage debit processed', ['allocations' => \count($allocations)]);
            } finally {
                // Go: defer l.releaseLock(ctx, locker)
                $this->releaseLock($locker);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * releaseReference and receiveReference build a provider's shadow transaction
     * references, keyed on the parent reference and provider so they are stable
     * across retries.
     */
    protected static function releaseReference(string $parentRef, string $provider): string
    {
        return sprintf('%s_release_%s', $parentRef, $provider);
    }

    protected static function receiveReference(string $parentRef, string $provider): string
    {
        return sprintf('%s_receive_%s', $parentRef, $provider);
    }

    /**
     * remainingDebitAmount returns the original debit amount minus any releases
     * already persisted for this transaction's providers.
     *
     * @param LineageMapping[] $mappings
     * @throws \Throwable
     */
    protected function remainingDebitAmount(Transaction $txn, array $mappings): BigInteger
    {
        $released = BigInteger::zero();
        foreach ($mappings as $m) {
            $ref = self::releaseReference($txn->reference, $m->provider);
            try {
                $exists = $this->datasource->transactionExistsByRef($ref);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to check release idempotency', $err);
            }
            if (!$exists) {
                continue;
            }
            try {
                $prior = $this->datasource->getTransactionByRef($ref);
            } catch (\Throwable $err) {
                throw self::wrapError(sprintf('failed to load prior release %s', $ref), $err);
            }
            // (Go: released.Add(released, prior.PreciseAmount) — a nil PreciseAmount would panic there.)
            $released = $released->plus($prior->preciseAmount ?? BigInteger::zero());
        }
        return ($txn->preciseAmount ?? BigInteger::zero())->minus($released);
    }

    /**
     * prepareDestinationLineageBalances pre-creates destination shadow and aggregate balances for all providers.
     * This is called BEFORE acquiring locks to avoid nested lock acquisition deadlocks.
     *
     * Parameters:
     * - mappings []model.LineageMapping: The source lineage mappings (one per provider).
     * - destinationBalance *model.Balance: The destination balance.
     *
     * Returns:
     * - map[string]*destinationLineageInfo: Map of provider to destination lineage balances.
     * - error: An error if any balance could not be created (thrown).
     *
     * @param LineageMapping[] $mappings
     * @return array<string, DestinationLineageInfo>
     * @throws \Throwable "failed to prepare destination lineage for provider %s: ..."
     */
    protected function prepareDestinationLineageBalances(array $mappings, Balance $destinationBalance): array
    {
        $result = [];

        foreach ($mappings as $mapping) {
            try {
                [$shadowBalance, $aggBalance] = $this->getOrCreateDestinationLineageBalances($mapping->provider, $destinationBalance);
            } catch (\Throwable $err) {
                throw self::wrapError(sprintf('failed to prepare destination lineage for provider %s', $mapping->provider), $err);
            }
            $result[$mapping->provider] = new DestinationLineageInfo($shadowBalance, $aggBalance);
        }

        return $result;
    }

    /**
     * acquireLineageLocks acquires distributed locks for multiple shadow balances using MultiLocker.
     * MultiLocker handles sorting (prevents deadlock) and deduplication automatically.
     * This is used for both credit and debit lineage processing to prevent race conditions.
     *
     * Parameters:
     * - balanceIDs []string: The balance IDs to lock.
     *
     * Returns:
     * - *redlock.MultiLocker: The acquired multi-lock.
     * - error: An error if the locks could not be acquired (thrown).
     *
     * @param string[] $balanceIDs
     * @throws \Throwable "failed to acquire lineage locks: ..."
     */
    protected function acquireLineageLocks(array $balanceIDs): MultiLocker
    {
        // Prefix all keys to avoid collision with main transaction locks
        $lockKeys = [];
        foreach ($balanceIDs as $id) {
            $lockKeys[] = sprintf('lineage:%s', $id);
        }

        // MultiLocker handles deduplication and sorts keys lexicographically
        $locker = new MultiLocker($this->redis, $lockKeys, ModelHelpers::generateUUIDWithSuffix('loc'));

        try {
            $locker->lock($this->config()->transaction->lockDuration);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to acquire lineage locks', $err);
        }

        return $locker;
    }

    /**
     * getSourceAggregateBalance retrieves or creates the aggregate balance for the source identity.
     *
     * Parameters:
     * - sourceBalance *model.Balance: The source balance.
     *
     * Returns:
     * - *model.Balance: The aggregate balance.
     * - error: An error if the balance could not be retrieved or created (thrown).
     *
     * @throws \Throwable
     */
    protected function getSourceAggregateBalance(Balance $sourceBalance): Balance
    {
        try {
            $sourceIdentity = $this->datasource->getIdentityByID($sourceBalance->identityID);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to get source identity', $err);
        }

        $sourceIdentifier = $this->getIdentityIdentifier($sourceIdentity);
        $sourceAggIndicator = sprintf('@%s_lineage', $sourceIdentifier);

        try {
            $sourceAggBalance = $this->getOrCreateBalanceByIndicator($sourceAggIndicator, $sourceBalance->currency);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to get source aggregate balance', $err);
        }

        return $sourceAggBalance;
    }

    /**
     * processAllocations processes fund allocations by queuing release and receive transactions.
     *
     * Parameters:
     * - txn *model.Transaction: The original transaction.
     * - allocations []Allocation: The calculated allocations.
     * - mappings []model.LineageMapping: The lineage mappings.
     * - sourceBalance *model.Balance: The source balance.
     * - destinationBalance *model.Balance: The destination balance.
     * - sourceAggBalance *model.Balance: The source aggregate balance.
     * - destLineageBalances map[string]*destinationLineageInfo: Pre-created destination lineage balances (may be nil).
     *
     * @param Allocation[] $allocations
     * @param LineageMapping[] $mappings
     * @param array<string, DestinationLineageInfo>|null $destLineageBalances
     * @throws \Throwable
     */
    protected function processAllocations(Transaction $txn, array $allocations, array $mappings, Balance $sourceBalance, ?Balance $destinationBalance, Balance $sourceAggBalance, ?array $destLineageBalances): void
    {
        foreach ($allocations as $alloc) {
            if ($alloc->amount->getSign() === 0) {
                continue;
            }

            $mapping = $this->findMappingByShadowID($mappings, $alloc->balanceID);
            if ($mapping === null) {
                continue;
            }

            try {
                $exists = $this->datasource->transactionExistsByRef(self::releaseReference($txn->reference, $mapping->provider));
            } catch (\Throwable $err) {
                throw self::wrapError('failed to check release idempotency', $err);
            }
            if ($exists) {
                continue;
            }

            try {
                $this->queueReleaseTransaction($txn, $alloc, $mapping, $sourceBalance, $sourceAggBalance);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to queue release transaction', $err);
            }

            try {
                $this->processDestinationLineage($txn, $alloc, $mapping, $sourceBalance, $destinationBalance, $destLineageBalances);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to process destination lineage', $err);
            }
        }
    }

    /**
     * queueReleaseTransaction queues a transaction to release funds from the aggregate balance back to a shadow balance.
     *
     * Parameters:
     * - txn *model.Transaction: The original transaction.
     * - alloc Allocation: The allocation details.
     * - mapping *model.LineageMapping: The lineage mapping for the provider.
     * - sourceBalance *model.Balance: The source balance.
     * - sourceAggBalance *model.Balance: The source aggregate balance.
     * - index int: The allocation index for reference uniqueness.
     *
     * Returns:
     * - error: An error if the transaction could not be queued (thrown).
     *
     * @throws \Throwable
     */
    protected function queueReleaseTransaction(Transaction $txn, Allocation $alloc, LineageMapping $mapping, Balance $sourceBalance, Balance $sourceAggBalance): void
    {
        $releaseTxn = new Transaction();
        $releaseTxn->source = $sourceAggBalance->balanceID;
        $releaseTxn->destination = $alloc->balanceID;
        $releaseTxn->preciseAmount = $alloc->amount; // Go: new(big.Int).Set(alloc.Amount) — BigInteger is immutable
        $releaseTxn->currency = $sourceBalance->currency;
        $releaseTxn->precision = $txn->precision;
        $releaseTxn->reference = self::releaseReference($txn->reference, $mapping->provider);
        $releaseTxn->description = sprintf('Release %s funds', $mapping->provider);
        $releaseTxn->metaData = [
            '_shadow_for' => $txn->transactionID,
            '_provider' => $mapping->provider,
            '_lineage_type' => 'release',
            '_main_balance' => $sourceBalance->balanceID,
            '_allocation' => $sourceBalance->allocationStrategy,
        ];
        $releaseTxn->skipQueue = true;
        $releaseTxn->inflight = $txn->inflight;

        $this->queueTransaction($releaseTxn);
    }

    /**
     * processDestinationLineage processes lineage tracking for the destination balance when it also tracks fund lineage.
     * Uses pre-created destination balances to avoid nested lock acquisition (locks are already held by caller).
     *
     * Parameters:
     * - txn *model.Transaction: The original transaction.
     * - alloc Allocation: The allocation details.
     * - mapping *model.LineageMapping: The lineage mapping for the provider.
     * - sourceBalance *model.Balance: The source balance.
     * - destinationBalance *model.Balance: The destination balance.
     * - destLineageBalances map[string]*destinationLineageInfo: Pre-created destination lineage balances (may be nil).
     * - index int: The allocation index for reference uniqueness.
     *
     * Returns:
     * - error: An error if destination lineage processing fails (thrown).
     *
     * @param array<string, DestinationLineageInfo>|null $destLineageBalances
     * @throws \Throwable
     */
    protected function processDestinationLineage(Transaction $txn, Allocation $alloc, LineageMapping $mapping, Balance $sourceBalance, ?Balance $destinationBalance, ?array $destLineageBalances): void
    {
        if ($destinationBalance === null || !$destinationBalance->trackFundLineage || $destinationBalance->identityID === '') {
            return;
        }

        // Use pre-created destination balances (locks already held by caller)
        if ($destLineageBalances === null) {
            return;
        }

        $destInfo = $destLineageBalances[$mapping->provider] ?? null;
        if ($destInfo === null) {
            return;
        }

        $destShadowBalance = $destInfo->shadowBalance;
        $destAggBalance = $destInfo->aggregateBalance;

        try {
            $this->queueReceiveTransaction($txn, $alloc, $mapping, $sourceBalance, $destinationBalance, $destShadowBalance, $destAggBalance);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to queue receive transaction', $err);
        }

        $destMapping = new LineageMapping();
        $destMapping->balanceID = $destinationBalance->balanceID;
        $destMapping->provider = $mapping->provider;
        $destMapping->shadowBalanceID = $destShadowBalance->balanceID;
        $destMapping->aggregateBalanceID = $destAggBalance->balanceID;
        $destMapping->identityID = $destinationBalance->identityID;
        try {
            $this->datasource->upsertLineageMapping($destMapping);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to upsert destination lineage mapping', $err);
        }
    }

    /**
     * getOrCreateDestinationLineageBalances retrieves or creates shadow and aggregate balances for the destination.
     *
     * Parameters:
     * - provider string: The fund provider identifier.
     * - destinationBalance *model.Balance: The destination balance.
     *
     * Returns:
     * - *model.Balance: The destination shadow balance.
     * - *model.Balance: The destination aggregate balance.
     * - error: An error if the balances could not be retrieved or created (thrown).
     *
     * @return array{0: Balance, 1: Balance} `[$destShadowBalance, $destAggBalance]`
     * @throws \Throwable
     */
    protected function getOrCreateDestinationLineageBalances(string $provider, Balance $destinationBalance): array
    {
        try {
            $destIdentity = $this->datasource->getIdentityByID($destinationBalance->identityID);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to get destination identity', $err);
        }

        $destIdentifier = $this->getIdentityIdentifier($destIdentity);
        $destShadowIndicator = sprintf('@%s_%s_lineage', $provider, $destIdentifier);
        $destAggIndicator = sprintf('@%s_lineage', $destIdentifier);

        try {
            $destShadowBalance = $this->getOrCreateBalanceByIndicator($destShadowIndicator, $destinationBalance->currency);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to create destination shadow balance', $err);
        }

        try {
            $destAggBalance = $this->getOrCreateBalanceByIndicator($destAggIndicator, $destinationBalance->currency);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to create destination aggregate balance', $err);
        }

        return [$destShadowBalance, $destAggBalance];
    }

    /**
     * queueReceiveTransaction queues a transaction to receive funds into the destination's shadow balance.
     *
     * Parameters:
     * - txn *model.Transaction: The original transaction.
     * - alloc Allocation: The allocation details.
     * - mapping *model.LineageMapping: The lineage mapping for the provider.
     * - sourceBalance *model.Balance: The source balance.
     * - destinationBalance *model.Balance: The destination balance.
     * - destShadowBalance *model.Balance: The destination shadow balance.
     * - destAggBalance *model.Balance: The destination aggregate balance.
     * - allocAmount float64: The allocation amount as a float.
     * - index int: The allocation index for reference uniqueness.
     *
     * Returns:
     * - error: An error if the transaction could not be queued (thrown).
     *
     * @throws \Throwable
     */
    protected function queueReceiveTransaction(Transaction $txn, Allocation $alloc, LineageMapping $mapping, Balance $sourceBalance, Balance $destinationBalance, Balance $destShadowBalance, Balance $destAggBalance): void
    {
        $receiveTxn = new Transaction();
        $receiveTxn->source = $destShadowBalance->balanceID;
        $receiveTxn->destination = $destAggBalance->balanceID;
        $receiveTxn->preciseAmount = $alloc->amount; // Go: new(big.Int).Set(alloc.Amount) — BigInteger is immutable
        $receiveTxn->currency = $destinationBalance->currency;
        $receiveTxn->precision = $txn->precision;
        $receiveTxn->reference = self::receiveReference($txn->reference, $mapping->provider);
        $receiveTxn->description = sprintf('Receive %s funds', $mapping->provider);
        $receiveTxn->metaData = [
            '_shadow_for' => $txn->transactionID,
            '_provider' => $mapping->provider,
            '_lineage_type' => 'receive',
            '_main_balance' => $destinationBalance->balanceID,
            '_from_balance' => $sourceBalance->balanceID,
        ];
        $receiveTxn->allowOverdraft = true;
        $receiveTxn->skipQueue = true;
        $receiveTxn->inflight = $txn->inflight;

        $this->queueTransaction($receiveTxn);
    }
}
