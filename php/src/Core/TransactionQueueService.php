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

use Blnk\Internal\HotPairs\PairLaneCounter;
use Blnk\Internal\HotPairs\Router;
use Blnk\Internal\Log;
use Blnk\Internal\Notification\Notification;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;

/**
 * TransactionQueueService is the port of the root-package file
 * `transaction_queue.go`: preparing, persisting and enqueueing transactions
 * (single or split) for the queue workers.
 *
 * Conventions (see PORTING.md): `context.Context` parameters are dropped,
 * `(T, error)` returns become `T` plus a thrown exception, `fmt.Errorf("%s: %w")`
 * wrapping goes through {@see Blnk::wrapError()} (keeps the wrapped exception as
 * `previous` and preserves API error codes, like Go's `errors.As` through `%w`).
 *
 * Concurrency divergence (documented): the `go func()` of
 * `processTransactionAsync` runs inline — the transaction is persisted and its
 * queue copy is enqueued before QueueTransaction returns, exactly the work the
 * goroutine does, and the worker CLI still applies it to the balances. The
 * `asyncTxnSemaphore` (max 20 concurrent async processors) therefore has no
 * effect and is documented on {@see TransactionService::asyncTxnSemaphoreWeight}.
 */
trait TransactionQueueService
{
    /**
     * prepareTransactionForQueue sets the status/metadata of a transaction,
     * resolves its source/destination balances (rewriting them to balance IDs)
     * and stamps the hot-pair queue lane into its metadata.
     *
     * @throws \RuntimeException "failed to get source/destination balances: ..." / "failed to assign queue lane: ..."
     */
    protected function prepareTransactionForQueue(Transaction $transaction): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('PrepareTransactionForQueue');
        try {
            self::setTransactionStatus($transaction);
            self::setTransactionMetadata($transaction);

            // Get and update source/destination
            try {
                [$sourceBalance, $destinationBalance] = $this->getSourceAndDestination($transaction);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw self::wrapError('failed to get source/destination balances', $err);
            }

            $transaction->source = $sourceBalance->balanceID;
            $transaction->destination = $destinationBalance->balanceID;

            try {
                Router::assignQueueLane($this->hotPairs, $this->pairLaneCounter(), $transaction);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw self::wrapError('failed to assign queue lane', $err);
            }

            return $transaction;
        } finally {
            $span->end();
        }
    }

    /**
     * pairLaneCounter adapts the datasource to the hot-pairs
     * {@see PairLaneCounter} contract. Go passes `l.datasource` directly
     * (IDataSource satisfies the interface structurally); PHP interfaces are
     * nominal and the datasource does not declare it, so a thin delegating
     * adapter is used.
     */
    protected function pairLaneCounter(): PairLaneCounter
    {
        $datasource = $this->datasource;
        return new class ($datasource) implements PairLaneCounter {
            public function __construct(private object $datasource)
            {
            }

            public function countQueuedTransactionsForPairLane(string $source, string $destination, string $currency, string $lane): int
            {
                return $this->datasource->countQueuedTransactionsForPairLane($source, $destination, $currency, $lane);
            }
        };
    }

    /**
     * QueueTransaction processes and queues a transaction for execution.
     * It handles both single transactions and split transactions, preparing them for processing
     * by setting metadata, status, and managing their persistence and queueing.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be queued.
     *
     * Returns:
     * - *model.Transaction: A pointer to the queued Transaction model.
     * - error: An error if the transaction could not be queued (thrown).
     *
     * @throws \Throwable
     */
    public function queueTransaction(Transaction $transaction): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('QueueTransaction');
        try {
            // Initialize transaction metadata and status
            $originalRef = $transaction->reference;
            self::setTransactionMetadata($transaction);
            self::setTransactionStatus($transaction);
            $originalTxnID = $transaction->transactionID;

            // Handle split transactions if needed
            try {
                $transactions = $this->handleSplitTransactions($transaction);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            // If SkipQueue is true, process synchronously
            if ($transaction->skipQueue) {
                try {
                    $this->processTxns($transaction, $transactions, $originalTxnID, $originalRef);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }

                // Update transaction status based on inflight flag
                if ($transaction->inflight) {
                    $transaction->status = self::StatusInflight;
                } else {
                    $transaction->status = self::StatusApplied;
                }
            } else {
                if ($transaction->metaData !== null && !$transaction->skipQueue) {
                    $transaction->metaData['QUEUED_PARENT_TRANSACTION'] = $originalTxnID;
                }

                if (str_contains($transaction->parentTransaction, 'bulk')) {
                    $transaction->metaData['QUEUED_PARENT_TRANSACTION'] = $transaction->parentTransaction;
                }
                // For normal queue mode, process asynchronously
                $this->processTransactionAsync($transaction, $originalRef, $originalTxnID, $transactions);
            }

            self::spanEvent($span, 'Transaction successfully queued', [
                'transaction.id' => $transaction->transactionID,
            ]);

            if ($transaction->inflightExpiryDate !== null) {
                try {
                    $this->queue->queueInflightExpiry($transaction);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $this->logAndRecordError($span, 'failed to queue inflight expiry', $err);
                }
            }

            // Queue an automatic commit task if inflight_commit_date is set.
            if ($transaction->inflightCommitDate !== null) {
                try {
                    $this->queue->queueInflightCommit($transaction);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $this->logAndRecordError($span, 'failed to queue inflight commit', $err);
                }
            }

            return $transaction;
        } finally {
            $span->end();
        }
    }

    /**
     * processTransactionAsync persists the transaction (or its splits) and
     * enqueues the queue copies for the workers.
     *
     * Go: a package-level function `processTransactionAsync(ctx, l, ...)` that
     * runs the work in a goroutine guarded by asyncTxnSemaphore; the PHP port
     * is a method that runs it inline (see the trait doc). Errors are recorded
     * on the span and never thrown, exactly as the goroutine only records them.
     *
     * @param Transaction[] $transactions
     */
    protected function processTransactionAsync(Transaction $transaction, string $originalRef, string $originalTxnID, array $transactions): void
    {
        // The background worker mutates the transaction (metadata, timestamps,
        // precise amount) while the caller still holds the pointer returned from
        // QueueTransaction. Snapshot it so the worker never races a caller that
        // inspects the returned transaction.
        $asyncTxn = self::cloneTransactionForAsync($transaction);

        // Go: go func() { asyncTxnSemaphore.Acquire(ctx, 1) ... }() — inline here.
        $span = Tracer::get('blnk.transactions')->startSpan('ProcessTransactionAsync');
        try {
            $queueTransactions = [];
            try {
                $queueTransactions = $this->processTxns($asyncTxn, $transactions, $originalTxnID, $originalRef);
            } catch (\Throwable $err) {
                $span->recordError($err);
            }

            if (!$asyncTxn->skipQueue) {
                try {
                    self::enqueueTransactions($this->queue, $asyncTxn, $queueTransactions);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                }
            }
        } finally {
            $span->end();
        }
    }

    /**
     * cloneTransactionForAsync returns a copy of the transaction safe to hand to the
     * async worker: the MetaData map and EffectiveDate pointer are duplicated so the
     * worker's mutations never touch the object the caller still holds.
     *
     * PHP: `clone` already gives the copy its own `metaData` array (arrays are
     * values) and `effectiveDate` is an immutable object, so a shallow clone is
     * the exact equivalent of the Go copy.
     */
    public static function cloneTransactionForAsync(Transaction $t): Transaction
    {
        return clone $t;
    }

    /**
     * cloneTransactionsForAsync clones each transaction so a background worker can
     * mutate them without touching the slice the caller still holds.
     *
     * @param Transaction[] $txns
     *
     * @return Transaction[]
     */
    public static function cloneTransactionsForAsync(array $txns): array
    {
        $clones = [];
        foreach (array_values($txns) as $i => $t) {
            $clones[$i] = self::cloneTransactionForAsync($t);
        }
        return $clones;
    }

    /**
     * handleSplitTransactions attempts to split a transaction into multiple transactions if needed.
     * It starts a tracing span, attempts to split the transaction, and validates the result.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be potentially split.
     *
     * Returns:
     * - []*model.Transaction: A slice of split transactions, or empty if no split is needed.
     * - error: An error if the transaction splitting fails (thrown).
     *
     * @return Transaction[]
     *
     * @throws \RuntimeException "failed to split transaction: ..."
     */
    protected function handleSplitTransactions(Transaction $transaction): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('HandleSplitTransactions');
        try {
            try {
                return $transaction->splitTransactionPrecise();
            } catch (\Throwable $err) {
                throw self::wrapError('failed to split transaction', $err);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * processTxns handles the processing of transactions based on whether they are split or single.
     * It delegates to the appropriate processing function based on the presence of split transactions.
     *
     * Parameters:
     * - originalTxn *model.Transaction: The original transaction before processing.
     * - splitTxns []*model.Transaction: Any split transactions derived from the original.
     * - originalTxnID string: The ID of the original transaction.
     * - originalRef string: The reference of the original transaction.
     *
     * Returns:
     * - []*model.Transaction: A slice of processed transactions ready for queueing.
     * - error: An error if the processing fails (thrown).
     *
     * @param Transaction[] $splitTxns
     *
     * @return Transaction[]
     *
     * @throws \Throwable
     */
    protected function processTxns(Transaction $originalTxn, array $splitTxns, string $originalTxnID, string $originalRef): array
    {
        if ($originalTxn->skipQueue) {
            if (\count($splitTxns) === 0) {
                $recorded = $this->recordTransaction($originalTxn);
                return [$recorded];
            }

            $result = [];
            foreach (array_values($splitTxns) as $i => $txn) {
                try {
                    $recorded = $this->recordTransaction($txn);
                } catch (\Throwable $err) {
                    if ($txn->atomic) {
                        $this->handleAsyncBulkTransactionFailure($err, $originalTxnID, true, $txn->inflight);
                    }
                    throw self::wrapError(sprintf('failed to record split transaction %d', $i), $err);
                }
                $result[$i] = $recorded;
            }
            return $result;
        }
        if (\count($splitTxns) === 0) {
            return $this->processSingleTransaction($originalTxn, $originalRef);
        }
        return $this->processSplitTransactions($splitTxns, $originalTxnID, $originalRef);
    }

    /**
     * processSingleTransaction handles the processing of a single (non-split) transaction.
     * It prepares the transaction, persists it to the database, and creates a queue copy.
     *
     * Parameters:
     * - transaction *model.Transaction: The single transaction to process.
     * - originalRef string: The original reference of the transaction.
     *
     * Returns:
     * - []*model.Transaction: A slice containing the processed transaction ready for queueing.
     * - error: An error if the transaction processing fails (thrown).
     *
     * @return Transaction[]
     *
     * @throws \RuntimeException "failed to persist original transaction: ..."
     * @throws \Throwable
     */
    protected function processSingleTransaction(Transaction $transaction, string $originalRef): array
    {
        if (\count($transaction->sources) === 0 && \count($transaction->destinations) === 0) {
            $preparedTxn = $this->prepareTransactionForQueue($transaction);
            $transaction = $preparedTxn;
        }

        try {
            $persistedTxn = $this->datasource->recordTransaction($transaction);
        } catch (\Throwable $err) {
            throw self::wrapError('failed to persist original transaction', $err);
        }

        $queueTxn = self::createQueueCopy($persistedTxn, $originalRef);
        return [$queueTxn];
    }

    /**
     * processSplitTransactions handles the processing of multiple split transactions.
     * It prepares each split transaction, persists them to the database, and creates queue copies.
     *
     * Parameters:
     * - transactions []*model.Transaction: The split transactions to process.
     * - originalTxnID string: The ID of the original transaction.
     * - originalRef string: The reference of the original transaction.
     *
     * Returns:
     * - []*model.Transaction: A slice of processed transactions ready for queueing.
     * - error: An error if any transaction processing fails (thrown).
     *
     * @param Transaction[] $transactions
     *
     * @return Transaction[]
     *
     * @throws \RuntimeException "failed to prepare split transaction %d: ..." / "failed to persist original transaction: ..."
     */
    protected function processSplitTransactions(array $transactions, string $originalTxnID, string $originalRef): array
    {
        $transactions = array_values($transactions);
        $queueTransactions = [];
        self::updateSplitTransactions($transactions, $originalTxnID, $originalRef);

        foreach ($transactions as $i => $splitTxn) {
            try {
                $preparedSplitTxn = $this->prepareTransactionForQueue($splitTxn);
            } catch (\Throwable $err) {
                throw self::wrapError(sprintf('failed to prepare split transaction %d', $i), $err);
            }

            // Persist the original transaction
            try {
                $persistedTxn = $this->datasource->recordTransaction($preparedSplitTxn);
            } catch (\Throwable $err) {
                throw self::wrapError('failed to persist original transaction', $err);
            }

            $queueTxn = self::createQueueCopy($persistedTxn, $splitTxn->reference);
            $queueTransactions[$i] = $queueTxn;
        }

        return $queueTransactions;
    }

    /**
     * setTransactionStatus determines and sets the appropriate status for a transaction.
     * It evaluates the transaction's scheduled time and current status to set the correct status.
     * If the transaction is scheduled for the future, it sets StatusScheduled.
     * If the transaction has no status, it sets StatusQueued.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction for which to set the status.
     */
    public static function setTransactionStatus(Transaction $transaction): void
    {
        if ($transaction->scheduledFor !== null) {
            $transaction->status = self::StatusScheduled;
        } elseif ($transaction->status === '') {
            $transaction->status = self::StatusQueued;
        }
    }

    /**
     * setTransactionMetadata initializes and sets the required metadata for a transaction.
     * calculates the transaction hash, and sets the precise amount based on the transaction's precision.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction for which to set metadata.
     */
    public static function setTransactionMetadata(Transaction $transaction): void
    {
        $transaction->createdAt = new \DateTimeImmutable('now');
        if ($transaction->effectiveDate === null) {
            $transaction->effectiveDate = $transaction->createdAt;
        }
        $transaction->hash = $transaction->hashTxn();
        $transaction->preciseAmount = ModelHelpers::applyPrecision($transaction);
        if ($transaction->transactionID === '') {
            $transaction->transactionID = ModelHelpers::generateUUIDWithSuffix('txn');
        }

        // Initialize metadata if it doesn't exist
        if ($transaction->metaData === null) {
            $transaction->metaData = [];
        }

        // Set inflight flag in metadata if the transaction is inflight
        //maybe move to their respective columns in the db later
        if ($transaction->inflight) {
            $transaction->metaData['inflight'] = true;
        }
        if ($transaction->atomic) {
            $transaction->metaData['atomic'] = true;
        }
        if ($transaction->allowOverdraft) {
            $transaction->metaData['allow_overdraft'] = true;
        }
    }

    /**
     * createQueueCopy creates a new copy of a transaction specifically for queueing.
     * It generates new identifiers and maintains the relationship with the original transaction.
     *
     * Parameters:
     * - persistedTxn *model.Transaction: The persisted transaction to copy.
     * - originalRef string: The original reference to base the new reference on.
     *
     * Returns:
     * - *model.Transaction: A pointer to the new queue copy of the transaction.
     *
     * Go's struct copy shares the MetaData map between the original and the
     * copy; PHP's `clone` gives the copy its own metadata array. No call site
     * relies on the sharing (the copy is serialized or persisted before the
     * original's metadata is touched again). Public static because the
     * standalone Go function is also used by {@see QueuedTransactionRecoveryProcessor}.
     */
    public static function createQueueCopy(Transaction $persistedTxn, string $originalRef): Transaction
    {
        $queueTxn = clone $persistedTxn;
        $queueTxn->transactionID = ModelHelpers::generateUUIDWithSuffix('txn');
        $queueTxn->parentTransaction = $persistedTxn->transactionID;
        $queueTxn->reference = sprintf('%s_q', $originalRef);
        return $queueTxn;
    }

    /**
     * updateSplitTransactions updates the metadata of split transactions to maintain their relationships.
     * It sets the parent transaction ID and creates linked references for each split transaction.
     *
     * Parameters:
     * - transactions []*model.Transaction: The split transactions to update.
     * - parentID string: The ID of the parent transaction.
     * - originalRef string: The original reference to base the new references on.
     *
     * @param Transaction[] $transactions
     */
    public static function updateSplitTransactions(array $transactions, string $parentID, string $originalRef): void
    {
        foreach (array_values($transactions) as $i => $txn) {
            $txn->parentTransaction = $parentID;
            $txn->reference = sprintf('%s_%d', $originalRef, $i + 1);
        }
    }

    /**
     * enqueueTransactions enqueues the original transaction or its split transactions into the provided queue.
     * It starts by determining which transactions to enqueue, then iterates through them and enqueues each one.
     * If an error occurs during enqueuing, it logs the error and sends a notification.
     *
     * Parameters:
     * - queue *Queue: The queue to which the transactions will be enqueued.
     * - originalTransaction *model.Transaction: The original transaction to be enqueued if no split transactions are provided.
     * - splitTransactions []*model.Transaction: A slice of split transactions to be enqueued.
     *
     * Returns:
     * - error: An error if any of the transactions could not be enqueued (thrown).
     *
     * @param Transaction[] $splitTransactions
     *
     * @throws \Throwable
     */
    public static function enqueueTransactions(Queue $queue, Transaction $originalTransaction, array $splitTransactions): void
    {
        $transactionsToEnqueue = $splitTransactions;
        if (\count($transactionsToEnqueue) === 0) {
            $transactionsToEnqueue = [$originalTransaction];
        }

        foreach ($transactionsToEnqueue as $txn) {
            try {
                $queue->enqueue($txn);
            } catch (\Throwable $err) {
                Notification::notifyError($err);
                Log::get()->error('failed to queue transaction', ['error' => $err->getMessage()]);
                throw $err;
            }
        }
    }
}
