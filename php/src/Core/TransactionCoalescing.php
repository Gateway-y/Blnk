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

use Blnk\Internal\Hooks\Hook;
use Blnk\Internal\Hooks\HookType;
use Blnk\Internal\HotPairs\Router;
use Blnk\Internal\Lock\MultiLocker;
use Blnk\Internal\Log;
use Blnk\Internal\Metrics\Metrics;
use Blnk\Internal\Notification\Notification;
use Blnk\Internal\Traces\Span;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Balance;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;

/**
 * TransactionCoalescing is the port of the root-package file
 * `transaction_coalescing.go`: coalescing a queued transaction with its queued
 * siblings (same pair, source or destination) into one atomically persisted
 * batch under a balance-set lock.
 *
 * Naming: Go has both the exported `TryRecordQueuedTransactionBatch` and the
 * unexported `tryRecordQueuedTransactionBatch`; PHP method names are
 * case-insensitive, so the unexported one is
 * {@see self::tryRecordQueuedTransactionBatchInternal()} (same convention as
 * TransactionExecution::processQueuedTransactionInternal()).
 *
 * `(handled bool, err error)` results: the Go functions never return a non-nil
 * error (every failure fails open with `false, nil`), so the PHP methods return
 * the bool. `(bool, string)` eligibility results are returned as `[bool, reason]`
 * tuples. Go maps of `struct{}` are `array<string, true>` sets; the
 * `batchReferences` set the Go code mutates through the map reference is passed
 * by reference.
 */
trait TransactionCoalescing
{
    /**
     * TryRecordQueuedTransactionBatch attempts to coalesce a normal-lane queued
     * transaction; returns whether the batch path handled it.
     */
    public function tryRecordQueuedTransactionBatch(Transaction $transaction): bool
    {
        return $this->tryRecordQueuedTransactionBatchInternal($transaction, false);
    }

    /**
     * TryRecordQueuedTransactionBatchForHotLane attempts to coalesce a hot-lane
     * queued transaction (batching is forced on regardless of enable_coalescing).
     */
    public function tryRecordQueuedTransactionBatchForHotLane(Transaction $transaction): bool
    {
        return $this->tryRecordQueuedTransactionBatchInternal($transaction, true);
    }

    /**
     * tryRecordQueuedTransactionBatch builds and persists a coalesced queued batch when it is
     * safe and useful, otherwise it returns handled=false so callers can fail open.
     */
    protected function tryRecordQueuedTransactionBatchInternal(Transaction $transaction, bool $force): bool
    {
        $span = Tracer::get('blnk.transactions')->startSpan('TryRecordQueuedTransactionBatch');
        try {
            [$eligible, $reason] = $this->canCoalesceQueuedTransaction($transaction);
            if (!$eligible) {
                Log::get()->info('Skipping queued transaction coalescing', [
                    'transaction_id' => $transaction->transactionID,
                    'parent' => $transaction->parentTransaction,
                    'source' => $transaction->source,
                    'destination' => $transaction->destination,
                    'currency' => $transaction->currency,
                    'reason' => $reason,
                ]);
                return false;
            }

            $batchSize = $this->queuedCoalescingBatchSize($force);
            if ($batchSize < 2) {
                return false;
            }

            $scope = '';
            try {
                $batch = $this->buildQueuedCoalescingBatch($transaction, $batchSize, $scope);
            } catch (\Throwable $err) {
                $span->recordError($err);
                Log::get()->warning('Skipping transaction coalescing', [
                    'error' => $err->getMessage(),
                    'transaction_id' => $transaction->transactionID,
                    'parent' => $transaction->parentTransaction,
                    'source' => $transaction->source,
                    'destination' => $transaction->destination,
                    'currency' => $transaction->currency,
                    'scope' => $scope,
                    'reason' => 'queued_sibling_lookup_failed',
                ]);
                return false;
            }

            if ($batch === null || \count($batch) < 2) {
                Metrics::transactionBatchTotal()->add(1, ['result' => 'skipped']);
                return false;
            }

            try {
                $this->persistQueuedTransactionBatch($batch);
            } catch (\Throwable $err) {
                $span->recordError($err);
                Log::get()->warning('transaction coalescing failed open; switching leader to per-transaction processing', [
                    'error' => $err->getMessage(),
                    'transaction_id' => $transaction->transactionID,
                    'parent' => $transaction->parentTransaction,
                    'source' => $transaction->source,
                    'destination' => $transaction->destination,
                    'currency' => $transaction->currency,
                    'scope' => $scope,
                    'batch_size' => \count($batch),
                    'reason' => 'batch_processing_failed_open',
                ]);
                Metrics::transactionBatchTotal()->add(1, ['result' => 'failure']);
                return false;
            }

            Log::get()->info('Transactions processed successfully', [
                'scope' => $scope,
                'batch_size' => \count($batch),
            ]);

            self::spanEvent($span, 'Queued transaction batch processed', [
                'batch.size' => \count($batch),
                'batch.scope' => $scope,
                'source.balance_id' => $transaction->source,
                'destination.balance_id' => $transaction->destination,
            ]);

            // Record batch metrics on success.
            Metrics::transactionBatchSize()->record(\count($batch));
            Metrics::transactionBatchTotal()->add(1, ['result' => 'success']);

            return true;
        } finally {
            $span->end();
        }
    }

    /**
     * persistQueuedTransactionBatch acquires the balance-set lock, prepares the batch in memory,
     * commits the final state atomically, and dispatches post-commit side effects.
     *
     * @param Transaction[] $transactions
     *
     * @throws \RuntimeException "coalescing batch contains multiple currencies" / "failed to acquire batch lock: ..."
     * @throws \Throwable
     */
    protected function persistQueuedTransactionBatch(array $transactions): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('RecordQueuedTransactionBatch');
        try {
            $transactions = array_values($transactions);
            self::validateQueuedBatchCurrencies($transactions);

            if (\count($transactions) === 0) {
                return;
            }

            $currency = $transactions[0]->currency;

            $balanceIDs = self::collectQueuedCoalescingBalanceIDs($transactions);
            try {
                $locker = $this->acquireBalanceSetLock($balanceIDs);
            } catch (\Throwable $err) {
                Router::recordContention($this->hotPairs, $transactions[0]->source, $transactions[0]->destination, $currency, $err);
                throw self::wrapError('failed to acquire batch lock', $err);
            }

            $result = $this->persistQueuedTransactionBatchLocked($span, $locker, $balanceIDs, $transactions);

            $this->runQueuedBatchPostCommitWork($span, $result);
        } finally {
            $span->end();
        }
    }

    /**
     * validateQueuedBatchCurrencies ensures that every transaction in a coalesced batch uses the
     * same currency before any lock or balance work begins.
     *
     * @param Transaction[] $transactions
     *
     * @throws \RuntimeException "coalescing batch contains multiple currencies"
     */
    protected static function validateQueuedBatchCurrencies(array $transactions): void
    {
        $transactions = array_values($transactions);
        if (\count($transactions) === 0) {
            return;
        }

        $currency = $transactions[0]->currency;
        foreach (\array_slice($transactions, 1) as $txn) {
            if ($txn->currency !== $currency) {
                throw new \RuntimeException('coalescing batch contains multiple currencies');
            }
        }
    }

    /**
     * persistQueuedTransactionBatchLocked prepares every transaction against the shared in-memory
     * balance set and persists the final balance and transaction state atomically.
     *
     * Go: `defer l.releaseLock(ctx, locker)` — the lock is released in `finally`.
     *
     * @param string[] $balanceIDs
     * @param Transaction[] $transactions
     *
     * @throws \Throwable
     */
    protected function persistQueuedTransactionBatchLocked(Span $span, MultiLocker $locker, array $balanceIDs, array $transactions): QueuedBatchPersistResult
    {
        try {
            try {
                [$balancesByID, $orderedBalances] = $this->loadBalancesForQueuedBatch($balanceIDs);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to load balances for coalesced batch', $err);
            }
            try {
                $preHooks = $this->listHooksForExecution(HookType::PreTransaction);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to list pre-transaction hooks for coalesced batch', $err);
            }

            $finalizedTransactions = [];
            $outboxes = [];
            $postCommitWork = [];
            [$prefetchedReferences, $existingReferences] = $this->queuedBatchReferenceSets($transactions);
            $batchReferences = [];

            foreach ($transactions as $txn) {
                $work = $this->prepareQueuedBatchTransaction($span, $txn, $balancesByID, $preHooks, $prefetchedReferences, $existingReferences, $batchReferences);

                $finalizedTransactions[] = $work->transaction;
                $postCommitWork[] = $work;
                if ($work->outbox !== null) {
                    $outboxes[] = $work->outbox;
                }
            }

            try {
                $this->datasource->recordTransactionsWithBalanceSetAndOutboxes($finalizedTransactions, $orderedBalances, $outboxes);
            } catch (\Throwable $err) {
                throw $this->logAndRecordError($span, 'failed to persist coalesced transaction batch', $err);
            }

            return new QueuedBatchPersistResult($orderedBalances, $postCommitWork);
        } finally {
            $this->releaseLock($locker);
        }
    }

    /**
     * queuedBatchReferenceSets prepares the prefetched and existing reference sets used by batch
     * validation when batch reference checking is enabled.
     *
     * @param Transaction[] $transactions
     *
     * @return array{0: array<string, true>, 1: array<string, true>} [prefetchedReferences, existingReferences]
     *
     * @throws \RuntimeException "failed to validate coalesced transaction references: ..."
     */
    protected function queuedBatchReferenceSets(array $transactions): array
    {
        $prefetchedReferences = [];
        $existingReferences = [];
        if (!$this->batchReferenceCheckEnabled()) {
            return [$prefetchedReferences, $existingReferences];
        }

        try {
            [$prefetchedReferences, $existingReferences] = $this->getQueuedBatchExistingReferences($transactions);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to validate coalesced transaction references', $err);
        }

        return [$prefetchedReferences, $existingReferences];
    }

    /**
     * prepareQueuedBatchTransaction performs the per-transaction work inside a coalesced batch:
     * hooks, reference validation, in-memory balance application, and persistence shaping.
     *
     * @param array<string, Balance> $balancesByID
     * @param Hook[]|null $preHooks
     * @param array<string, true> $prefetchedReferences
     * @param array<string, true> $existingReferences
     * @param array<string, true> $batchReferences mutated: references accepted so far in this batch
     *
     * @throws \RuntimeException "batch pre-transaction hook failed: ..." / "batch transaction validation failed: ..." /
     *                           "batch coalescing does not support zero-amount transactions"
     * @throws \Throwable
     */
    protected function prepareQueuedBatchTransaction(Span $span, Transaction $txn, array $balancesByID, ?array $preHooks, array $prefetchedReferences, array $existingReferences, array &$batchReferences): QueuedBatchPostCommitWork
    {
        [$sourceBalance, $destinationBalance] = self::coalescingBalancesForTransaction($balancesByID, $txn);

        if (isset($this->hooks)) {
            try {
                $this->hooks->executeHooks($preHooks ?? [], HookType::PreTransaction, $txn->transactionID, $txn);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw self::wrapError('batch pre-transaction hook failed', $err);
            }
        }

        try {
            $this->validateQueuedBatchTransactionReference($txn, $prefetchedReferences, $existingReferences, $batchReferences);
        } catch (\Throwable $err) {
            throw self::wrapError('batch transaction validation failed', $err);
        }

        $finalizedTxn = $this->updateTransactionDetails($txn, $sourceBalance, $destinationBalance);
        $this->processBalances($finalizedTxn, $sourceBalance, $destinationBalance);

        [$work, $skipPersist] = $this->buildTransactionExecutionWork($finalizedTxn, $sourceBalance, $destinationBalance);
        if ($skipPersist) {
            throw new \RuntimeException('batch coalescing does not support zero-amount transactions');
        }

        return $work;
    }

    /**
     * runQueuedBatchPostCommitWork dispatches the post-commit work for a coalesced batch after
     * the balance-set lock has been released.
     */
    protected function runQueuedBatchPostCommitWork(Span $span, QueuedBatchPersistResult $result): void
    {
        try {
            $postHooks = $this->listHooksForExecution(HookType::PostTransaction);
        } catch (\Throwable $err) {
            Log::get()->warning('failed to list post-transaction hooks for coalesced batch; falling back to per-transaction lookup', ['error' => $err->getMessage()]);
            $this->runTransactionPostCommitWork($span, $result->orderedBalances, $result->postCommitWork);
            return;
        }

        $this->runTransactionPostCommitWorkWithHooks($span, $result->orderedBalances, $result->postCommitWork, $postHooks);
    }

    /**
     * queuedCoalescingBatchSize returns the configured batch size for coalescing
     * (0 when coalescing is disabled and not forced), capped at
     * maxQueuedCoalescingBatchSize.
     */
    protected function queuedCoalescingBatchSize(bool $force): int
    {
        if (!$force && !$this->config()->transaction->enableCoalescing) {
            return 0;
        }

        $size = $this->config()->transaction->batchSize;
        switch (true) {
            case $size <= 1:
                return $size;
            case $size > self::maxQueuedCoalescingBatchSize:
                return self::maxQueuedCoalescingBatchSize;
            default:
                return $size;
        }
    }

    /**
     * buildQueuedCoalescingBatch tries the pair, source and destination scopes in
     * turn and returns the first batch of at least two transactions.
     *
     * Go returns `(batch, scope, err)`; the scope is reported through the
     * by-reference `$scope` parameter so the caller can log it when the sibling
     * lookup throws. Returns null when no scope produced a batch (Go: nil, "", nil).
     *
     * @return Transaction[]|null
     *
     * @throws \Throwable when a sibling lookup fails
     */
    protected function buildQueuedCoalescingBatch(Transaction $transaction, int $batchSize, string &$scope = ''): ?array
    {
        foreach ([
            self::queuedCoalescingScopePair,
            self::queuedCoalescingScopeSource,
            self::queuedCoalescingScopeDestination,
        ] as $candidate) {
            $scope = $candidate;
            $batch = $this->buildQueuedCoalescingBatchForScope($transaction, $candidate, $batchSize);
            if (\count($batch) >= 2) {
                return $batch;
            }
        }

        $scope = '';
        return null;
    }

    /**
     * buildQueuedCoalescingBatchForScope loads the leader's queued siblings for a
     * scope and returns the leader followed by a queue copy of every eligible sibling.
     *
     * @return Transaction[]
     *
     * @throws \Throwable
     */
    protected function buildQueuedCoalescingBatchForScope(Transaction $leader, string $scope, int $batchSize): array
    {
        $siblings = $this->loadQueuedTransactionsForCoalescingScope($leader, $scope, $batchSize - 1);

        $batch = [$leader];
        foreach ($siblings as $sibling) {
            self::restoreTransactionFlagsFromMetadata($sibling);
            [$eligible, $reason] = $this->canCoalesceQueuedOriginal($sibling);
            if (!$eligible) {
                Log::get()->info('Skipping sibling transaction during coalescing', [
                    'transaction_id' => $sibling->transactionID,
                    'parent' => $sibling->parentTransaction,
                    'source' => $sibling->source,
                    'destination' => $sibling->destination,
                    'currency' => $sibling->currency,
                    'scope' => $scope,
                    'reason' => $reason,
                ]);
                continue;
            }
            $batch[] = self::createQueueCopy($sibling, $sibling->reference);
        }

        return $batch;
    }

    /**
     * loadQueuedTransactionsForCoalescingScope queries the queued siblings of the
     * leader for the given scope.
     *
     * @return Transaction[]
     *
     * @throws \RuntimeException "unsupported queued coalescing scope \"<scope>\""
     * @throws \Throwable
     */
    protected function loadQueuedTransactionsForCoalescingScope(Transaction $leader, string $scope, int $limit): array
    {
        // A null createdAt stands for Go's zero time.
        $createdAt = $leader->createdAt ?? new \DateTimeImmutable('0001-01-01T00:00:00+00:00');

        switch ($scope) {
            case self::queuedCoalescingScopePair:
                return $this->datasource->getQueuedTransactionsForCoalescing(
                    $leader->source,
                    $leader->destination,
                    $leader->currency,
                    $leader->parentTransaction,
                    $createdAt,
                    $limit
                );
            case self::queuedCoalescingScopeSource:
                return $this->datasource->getQueuedTransactionsForSourceCoalescing(
                    $leader->source,
                    $leader->currency,
                    $leader->parentTransaction,
                    $createdAt,
                    $limit
                );
            case self::queuedCoalescingScopeDestination:
                return $this->datasource->getQueuedTransactionsForDestinationCoalescing(
                    $leader->destination,
                    $leader->currency,
                    $leader->parentTransaction,
                    $createdAt,
                    $limit
                );
            default:
                throw new \RuntimeException(sprintf('unsupported queued coalescing scope "%s"', $scope));
        }
    }

    /**
     * collectQueuedCoalescingBalanceIDs returns the distinct, sorted source and
     * destination balance IDs of the batch (the lock set).
     *
     * @param (Transaction|null)[] $transactions
     *
     * @return string[]
     */
    protected static function collectQueuedCoalescingBalanceIDs(array $transactions): array
    {
        $seen = [];
        $ids = [];
        foreach ($transactions as $txn) {
            if ($txn === null) {
                continue;
            }
            foreach ([$txn->source, $txn->destination] as $id) {
                if ($id === '') {
                    continue;
                }
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $ids[] = $id;
            }
        }
        sort($ids, SORT_STRING); // sort.Strings: byte-wise ordering
        return $ids;
    }

    /**
     * acquireBalanceSetLock locks every balance of the batch with one MultiLocker
     * (deterministic key order prevents deadlocks).
     *
     * @param string[] $balanceIDs
     *
     * @throws \Throwable if the locks could not be acquired
     */
    protected function acquireBalanceSetLock(array $balanceIDs): MultiLocker
    {
        $span = Tracer::get('blnk.transactions')->startSpan('Acquiring Balance Set Lock');
        try {
            $locker = new MultiLocker($this->redis, $balanceIDs, ModelHelpers::generateUUIDWithSuffix('loc'));
            try {
                $this->acquireTransactionLocker($locker);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            self::spanEvent($span, 'Locks acquired', [
                'locked.keys' => $locker->keys(),
            ]);
            return $locker;
        } finally {
            $span->end();
        }
    }

    /**
     * acquireTransactionLocker acquires the locker honoring the configured lock
     * duration and, when set, the lock wait timeout (WaitLock with backoff).
     *
     * @throws \Throwable
     */
    protected function acquireTransactionLocker(MultiLocker $locker): void
    {
        $lockCfg = $this->config()->transaction;
        if ($lockCfg->lockWaitTimeout > 0) {
            $locker->waitLock($lockCfg->lockDuration, $lockCfg->lockWaitTimeout);
            return;
        }
        $locker->lock($lockCfg->lockDuration);
    }

    /**
     * loadBalancesForQueuedBatch loads every balance of the lock set (with queued
     * balances when enable_queued_checks is on, lite otherwise) and returns them
     * keyed by ID plus in lock order.
     *
     * @param string[] $balanceIDs
     *
     * @return array{0: array<string, Balance>, 1: Balance[]} [balancesByID, orderedBalances]
     *
     * @throws \RuntimeException "failed to load balance %s for coalesced batch"
     * @throws \Throwable
     */
    protected function loadBalancesForQueuedBatch(array $balanceIDs): array
    {
        $balancesByID = [];

        if ($this->config()->transaction->enableQueuedChecks) {
            foreach ($balanceIDs as $balanceID) {
                $balance = $this->datasource->getBalanceByID($balanceID, [], true);
                $balancesByID[$balanceID] = $balance;
            }
        } else {
            $loaded = $this->datasource->getBalancesByIDsLite($balanceIDs);
            foreach ($loaded as $balanceID => $balance) {
                $balancesByID[(string) $balanceID] = $balance;
            }
        }

        $orderedBalances = [];
        foreach ($balanceIDs as $balanceID) {
            $balance = $balancesByID[$balanceID] ?? null;
            if ($balance === null) {
                throw new \RuntimeException(sprintf('failed to load balance %s for coalesced batch', $balanceID));
            }
            $orderedBalances[] = $balance;
        }

        return [$balancesByID, $orderedBalances];
    }

    /**
     * coalescingBalancesForTransaction resolves the source and destination
     * balances of a batched transaction from the preloaded balance set.
     *
     * @param array<string, Balance> $balancesByID
     *
     * @return array{0: Balance, 1: Balance} [sourceBalance, destinationBalance]
     *
     * @throws \RuntimeException "missing source balance %s for coalesced transaction" / "missing destination balance %s for coalesced transaction"
     */
    protected static function coalescingBalancesForTransaction(array $balancesByID, Transaction $txn): array
    {
        $sourceBalance = $balancesByID[$txn->source] ?? null;
        if ($sourceBalance === null) {
            throw new \RuntimeException(sprintf('missing source balance %s for coalesced transaction', $txn->source));
        }

        $destinationBalance = $balancesByID[$txn->destination] ?? null;
        if ($destinationBalance === null) {
            throw new \RuntimeException(sprintf('missing destination balance %s for coalesced transaction', $txn->destination));
        }

        return [$sourceBalance, $destinationBalance];
    }

    /**
     * getQueuedBatchExistingReferences collects the distinct references of the
     * batch and looks up which of them already exist.
     *
     * @param (Transaction|null)[] $transactions
     *
     * @return array{0: array<string, true>, 1: array<string, true>} [prefetched, existing]
     *
     * @throws \Throwable
     */
    protected function getQueuedBatchExistingReferences(array $transactions): array
    {
        $references = [];
        $prefetched = [];
        foreach ($transactions as $txn) {
            if ($txn === null || $txn->reference === '') {
                continue;
            }
            if (isset($prefetched[$txn->reference])) {
                continue;
            }
            $prefetched[$txn->reference] = true;
            $references[] = $txn->reference;
        }

        $existing = $this->datasource->getExistingTransactionReferences($references);
        return [$prefetched, $existing];
    }

    /**
     * validateQueuedBatchTransactionReference validates the transaction's
     * reference against the batch, the prefetched existing set and — when the
     * reference was not prefetched — the single-reference datasource check.
     *
     * @param array<string, true> $prefetchedReferences
     * @param array<string, true> $existingReferences
     * @param array<string, true> $batchReferences mutated: the reference is added on success
     *
     * @throws \RuntimeException "nil transaction" / "reference is required" / "reference %s has already been used"
     * @throws \Throwable
     */
    protected function validateQueuedBatchTransactionReference(?Transaction $transaction, array $prefetchedReferences, array $existingReferences, array &$batchReferences): void
    {
        if ($transaction === null) {
            throw new \RuntimeException('nil transaction');
        }

        if ($transaction->reference === '') {
            throw new \RuntimeException('reference is required');
        }

        if (isset($batchReferences[$transaction->reference])) {
            $err = new \RuntimeException(sprintf('reference %s has already been used', $transaction->reference));
            Notification::notifyError($err);
            throw $err;
        }

        if (isset($existingReferences[$transaction->reference])) {
            $err = new \RuntimeException(sprintf('reference %s has already been used', $transaction->reference));
            Notification::notifyError($err);
            throw $err;
        }

        if (\count($prefetchedReferences) === 0) {
            $this->validateTxn($transaction);
            $batchReferences[$transaction->reference] = true;
            return;
        }

        // Fall back to the single-reference validation path only if the transaction's
        // current reference was not part of the prefetched batch reference set.
        if (!isset($prefetchedReferences[$transaction->reference])) {
            $this->validateTxn($transaction);
        }

        $batchReferences[$transaction->reference] = true;
    }

    /**
     * batchReferenceCheckEnabled reports whether the bulk reference prefetch is
     * enabled (transaction.disable_batch_reference_check is off).
     */
    protected function batchReferenceCheckEnabled(): bool
    {
        return !$this->config()->transaction->disableBatchReferenceCheck;
    }

    /**
     * canCoalesceQueuedTransaction reports whether a queued (leader) transaction
     * is eligible for coalescing, with the reason when it is not.
     *
     * @return array{0: bool, 1: string} [eligible, reason]
     */
    protected function canCoalesceQueuedTransaction(?Transaction $transaction): array
    {
        if ($transaction === null) {
            return [false, 'nil_transaction'];
        }
        if ($transaction->parentTransaction === '') {
            return [false, 'missing_parent_transaction'];
        }
        if ($transaction->status !== self::StatusQueued) {
            return [false, 'status_not_queued'];
        }
        if ($transaction->atomic || $transaction->skipQueue) {
            switch (true) {
                case $transaction->atomic:
                    return [false, 'atomic_transaction'];
                default:
                    return [false, 'skip_queue_enabled'];
            }
        }
        if (\count($transaction->sources) > 0 || \count($transaction->destinations) > 0) {
            return [false, 'split_transaction'];
        }
        if ($transaction->scheduledFor !== null) {
            return [false, 'scheduled_transaction'];
        }
        if ($transaction->source === '' || $transaction->destination === '' || $transaction->currency === '') {
            return [false, 'missing_pair_or_currency'];
        }
        return [true, ''];
    }

    /**
     * canCoalesceQueuedOriginal reports whether a queued sibling (original,
     * persisted) transaction is eligible to join a batch, with the reason when
     * it is not.
     *
     * @return array{0: bool, 1: string} [eligible, reason]
     */
    protected function canCoalesceQueuedOriginal(?Transaction $transaction): array
    {
        if ($transaction === null) {
            return [false, 'nil_transaction'];
        }
        if ($transaction->status !== self::StatusQueued) {
            return [false, 'status_not_queued'];
        }
        if ($transaction->atomic || $transaction->skipQueue) {
            switch (true) {
                case $transaction->atomic:
                    return [false, 'atomic_transaction'];
                default:
                    return [false, 'skip_queue_enabled'];
            }
        }
        if (\count($transaction->sources) > 0 || \count($transaction->destinations) > 0) {
            return [false, 'split_transaction'];
        }
        if ($transaction->scheduledFor !== null) {
            return [false, 'scheduled_transaction'];
        }
        if ($transaction->source === '' || $transaction->destination === '' || $transaction->currency === '') {
            return [false, 'missing_pair_or_currency'];
        }
        return [true, ''];
    }

    /**
     * restoreTransactionFlagsFromMetadata restores the inflight/atomic/allow_overdraft
     * flags of a persisted transaction from the boolean markers stored in its metadata.
     *
     * Public static because the standalone Go function is also used by
     * {@see QueuedTransactionRecoveryProcessor}.
     */
    public static function restoreTransactionFlagsFromMetadata(?Transaction $transaction): void
    {
        if ($transaction === null || $transaction->metaData === null) {
            return;
        }

        if (\is_bool($transaction->metaData['inflight'] ?? null)) {
            $transaction->inflight = $transaction->metaData['inflight'];
        }
        if (\is_bool($transaction->metaData['atomic'] ?? null)) {
            $transaction->atomic = $transaction->metaData['atomic'];
        }
        if (\is_bool($transaction->metaData['allow_overdraft'] ?? null)) {
            $transaction->allowOverdraft = $transaction->metaData['allow_overdraft'];
        }
    }
}
