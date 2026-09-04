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

use Blnk\Database\DatabaseException;
use Blnk\Internal\Hooks\Hook;
use Blnk\Internal\Hooks\HookType;
use Blnk\Internal\HotPairs\Router;
use Blnk\Internal\Lock\MultiLocker;
use Blnk\Internal\Log;
use Blnk\Internal\Metrics\Metrics;
use Blnk\Internal\Notification\Notification;
use Blnk\Internal\Search\IndexBatch;
use Blnk\Internal\Traces\Span;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Balance;
use Blnk\Model\LineageOutbox;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;

/**
 * TransactionExecution is the port of the root-package file
 * `transaction_execution.go`: balance resolution, locking, the single
 * transaction execution path and its post-commit work.
 *
 * Conventions (see PORTING.md): `context.Context` parameters are dropped,
 * `(T, error)` returns become `T` plus a thrown exception, `fmt.Errorf("%s: %w")`
 * wrapping becomes {@see Blnk::wrapError()} (an exception whose `previous` is
 * the wrapped error and whose message renders it like Go's `err.Error()`),
 * `span.AddEvent(...)` becomes `$span->setAttribute('event', ...)` on the
 * debug-logging no-op tracer, `l.Config()` / `l.config` become
 * {@see Blnk::config()}, and the `go func()` blocks run inline.
 */
trait TransactionExecution
{
    /**
     * getSourceAndDestination retrieves (or, for "@" indicators such as @world,
     * creates) the source and destination balances of a transaction, rewriting
     * the transaction's Source/Destination to the resolved balance IDs.
     *
     * @return array{0: Balance, 1: Balance} [source, destination]
     *
     * @throws \Throwable if either balance lookup fails
     */
    protected function getSourceAndDestination(Transaction $transaction): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('GetSourceAndDestination');
        try {
            $cfg = $this->config();

            // Check if Source starts with "@"
            if (str_starts_with($transaction->source, '@')) {
                try {
                    $sourceBalance = $this->getOrCreateBalanceByIndicator($transaction->source, $transaction->currency);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    Log::get()->error('source balance lookup failed', ['error' => $err->getMessage()]);
                    throw $err;
                }
                // Update transaction source with the balance ID
                $transaction->source = $sourceBalance->balanceID;
                $span->setAttribute('source.balance_id', $sourceBalance->balanceID);
            } else {
                // Use GetBalanceByID with queued checks if enabled, otherwise use lite version
                try {
                    if ($cfg->transaction->enableQueuedChecks) {
                        $sourceBalance = $this->datasource->getBalanceByID($transaction->source, [], true);
                    } else {
                        $sourceBalance = $this->datasource->getBalanceByIDLite($transaction->source);
                    }
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    Log::get()->error('source balance lookup failed', ['error' => $err->getMessage()]);
                    throw $err;
                }
            }

            // Check if Destination starts with "@"
            if (str_starts_with($transaction->destination, '@')) {
                try {
                    $destinationBalance = $this->getOrCreateBalanceByIndicator($transaction->destination, $transaction->currency);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    Log::get()->error('destination balance lookup failed', ['error' => $err->getMessage()]);
                    throw $err;
                }
                // Update transaction destination with the balance ID
                $transaction->destination = $destinationBalance->balanceID;
                $span->setAttribute('destination.balance_id', $destinationBalance->balanceID);
            } else {
                // Use GetBalanceByID with queued checks if enabled, otherwise use lite version
                try {
                    if ($cfg->transaction->enableQueuedChecks) {
                        $destinationBalance = $this->datasource->getBalanceByID($transaction->destination, [], true);
                    } else {
                        $destinationBalance = $this->datasource->getBalanceByIDLite($transaction->destination);
                    }
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    Log::get()->error('destination balance lookup failed', ['error' => $err->getMessage()]);
                    throw $err;
                }
            }
            $span->setAttribute('event', 'Retrieved source and destination balances'); // span.AddEvent
            return [$sourceBalance, $destinationBalance];
        } finally {
            $span->end();
        }
    }

    /**
     * resolveBalanceIDs resolves source and destination to actual balance IDs.
     * If the source or destination starts with "@", it indicates a balance indicator (like @world)
     * that needs to be resolved to an actual balance ID. This function creates the balance if needed.
     * This should be called BEFORE acquiring locks to ensure we lock on the correct balance IDs.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction containing source and destination.
     *
     * Returns:
     * - sourceBalanceID string: The resolved source balance ID.
     * - destinationBalanceID string: The resolved destination balance ID.
     * - error: An error if the balance IDs could not be resolved (thrown).
     *
     * @return array{0: string, 1: string} [sourceBalanceID, destinationBalanceID]
     *
     * @throws \RuntimeException "failed to resolve source/destination balance indicator: ..."
     */
    protected function resolveBalanceIDs(Transaction $transaction): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ResolveBalanceIDs');
        try {
            $sourceID = $transaction->source;
            $destID = $transaction->destination;

            // Resolve source if it's an indicator (starts with "@")
            if (str_starts_with($transaction->source, '@')) {
                try {
                    $sourceBalance = $this->getOrCreateBalanceByIndicator($transaction->source, $transaction->currency);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw self::wrapError('failed to resolve source balance indicator', $err);
                }
                $sourceID = $sourceBalance->balanceID;
                $span->setAttribute('source.resolved_id', $sourceID);
            }

            // Resolve destination if it's an indicator (starts with "@")
            if (str_starts_with($transaction->destination, '@')) {
                try {
                    $destBalance = $this->getOrCreateBalanceByIndicator($transaction->destination, $transaction->currency);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw self::wrapError('failed to resolve destination balance indicator', $err);
                }
                $destID = $destBalance->balanceID;
                $span->setAttribute('destination.resolved_id', $destID);
            }

            $span->setAttribute('event', 'Balance IDs resolved'); // span.AddEvent
            return [$sourceID, $destID];
        } finally {
            $span->end();
        }
    }

    /**
     * acquireLock acquires distributed locks for a transaction to ensure exclusive access to both
     * source and destination balances. It uses a MultiLocker with deterministic ordering to prevent
     * deadlocks when multiple transactions target the same balances.
     *
     * Parameters:
     * - sourceBalanceID string: The ID of the source balance to lock.
     * - destinationBalanceID string: The ID of the destination balance to lock.
     *
     * Returns:
     * - *redlock.MultiLocker: A pointer to the acquired MultiLocker if successful.
     * - error: An error if the locks could not be acquired (thrown).
     *
     * @throws \Throwable if the locks could not be acquired
     */
    protected function acquireLock(string $sourceBalanceID, string $destinationBalanceID): MultiLocker
    {
        $span = Tracer::get('blnk.transactions')->startSpan('Acquiring Lock');
        try {
            // Create a MultiLocker with both balance IDs
            // MultiLocker handles deduplication (if source == destination) and sorts keys lexicographically
            $locker = new MultiLocker($this->redis, [$sourceBalanceID, $destinationBalanceID], ModelHelpers::generateUUIDWithSuffix('loc'));
            try {
                $this->acquireTransactionLocker($locker);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            $span->setAttribute('event', 'Locks acquired'); // span.AddEvent
            $span->setAttribute('locked.keys', $locker->keys());
            return $locker;
        } finally {
            $span->end();
        }
    }

    /**
     * updateTransactionDetails updates the details of a transaction, including source and destination balances and status.
     * It starts a tracing span, creates a new transaction object with updated details, and records relevant events.
     *
     * Parameters:
     * - transaction *model.Transaction: The original transaction to be updated.
     * - sourceBalance *model.Balance: The source balance for the transaction.
     * - destinationBalance *model.Balance: The destination balance for the transaction.
     *
     * Returns:
     * - *model.Transaction: A pointer to the new transaction object with updated details.
     */
    protected function updateTransactionDetails(Transaction $transaction, Balance $sourceBalance, Balance $destinationBalance): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('Updating Transaction Details');
        try {
            // Create a new transaction object with updated details (immutable pattern)
            $newTransaction = clone $transaction; // Copy the original transaction
            $newTransaction->source = $sourceBalance->balanceID;
            $newTransaction->destination = $destinationBalance->balanceID;

            // Update the status based on the current status and inflight flag
            $applicableStatus = [
                self::StatusQueued => self::StatusApplied,
                self::StatusApplied => self::StatusApplied,
                self::StatusScheduled => self::StatusApplied,
                self::StatusCommit => self::StatusApplied,
                self::StatusVoid => self::StatusVoid,
            ];
            // Go map lookup: a status without a mapping yields the zero value "".
            $newTransaction->status = $applicableStatus[$transaction->status] ?? '';
            if ($transaction->inflight) {
                $newTransaction->status = self::StatusInflight;
            }

            $span->setAttribute('event', 'Transaction details updated'); // span.AddEvent
            return $newTransaction;
        } finally {
            $span->end();
        }
    }

    /**
     * postTransactionActions performs post-processing actions for a transaction.
     * It starts a tracing span, queues the transaction and balance data for indexing in dependency order,
     * sends a webhook notification, and processes fund lineage if applicable.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction for which to perform post-processing actions.
     * - sourceBalance *model.Balance: The source balance (can be nil for rejected transactions).
     * - destinationBalance *model.Balance: The destination balance (can be nil for rejected transactions).
     *
     * Go runs the body in a goroutine; the PHP port runs it inline (PORTING.md
     * "Concurrency"). Every failure is recorded and notified, never thrown.
     */
    protected function postTransactionActions(Transaction $transaction, ?Balance $sourceBalance, ?Balance $destinationBalance): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('Post Transaction Actions');
        try {
            // Create an index batch to ensure balances are indexed before the transaction
            $batch = new IndexBatch($transaction->transactionID);

            // Add balances as dependencies (indexed first) if they exist
            if ($sourceBalance !== null) {
                $batch->addDependency('balances', $sourceBalance->balanceID, $sourceBalance);
            }
            if ($destinationBalance !== null) {
                $batch->addDependency('balances', $destinationBalance->balanceID, $destinationBalance);
            }

            // Set transaction as the primary item (indexed after dependencies)
            $batch->setPrimary($this->config()->transaction->indexQueuePrefix, $transaction->transactionID, $transaction);

            // Queue the batch for indexing
            try {
                $this->queue->queueIndexBatch($batch);
            } catch (\Throwable $err) {
                $span->recordError($err);
                Notification::notifyError($err);
            }

            // Send webhook notification
            try {
                $this->sendWebhook(new NewWebhook(
                    self::getEventFromStatus($transaction->status),
                    $transaction
                ));
            } catch (\Throwable $err) {
                $span->recordError($err);
                Notification::notifyError($err);
            }

            $span->setAttribute('event', 'Post-transaction actions completed'); // span.AddEvent
        } finally {
            $span->end();
        }
    }

    /**
     * validateTxn validates a transaction by checking if its reference has already been used.
     * It starts a tracing span, checks the existence of the transaction reference, and records relevant events and errors.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be validated.
     *
     * Returns:
     * - error: An error if the transaction reference has already been used or if there was an issue checking the reference (thrown).
     *
     * @throws \RuntimeException "reference <ref> has already been used"
     * @throws \Throwable if the reference check itself fails
     */
    protected function validateTxn(Transaction $transaction): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('Validating Transaction Reference');
        try {
            // Check if a transaction with the same reference already exists
            $txn = $this->datasource->transactionExistsByRef($transaction->reference);

            // If the transaction reference already exists, return an error
            if ($txn) {
                $err = new \RuntimeException(sprintf('reference %s has already been used', $transaction->reference));
                $span->recordError($err);
                Notification::notifyError($err);
                throw $err;
            }

            $span->setAttribute('event', 'Transaction validated'); // span.AddEvent
        } finally {
            $span->end();
        }
    }

    /**
     * IsDuplicateReferenceError reports whether an error is a duplicate
     * transaction-reference failure — either the Postgres unique violation on
     * the reference index or the pre-check "reference ... has already been used".
     *
     * Go inspects a wrapped `*pq.Error` (`errors.As`) for Code 23505 and a
     * constraint name containing "reference". PDO does not expose the constraint
     * name separately, so the PHP port walks the `previous` chain for a
     * {@see DatabaseException} with SQLSTATE 23505 and looks for "reference" in
     * its message (Postgres embeds the constraint name there:
     * `duplicate key value violates unique constraint "<name>"`).
     */
    public static function isDuplicateReferenceError(?\Throwable $err): bool
    {
        if ($err === null) {
            return false;
        }

        // errors.As(err, &pqErr)
        for ($e = $err; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof DatabaseException) {
                return $e->isUniqueViolation() && str_contains(strtolower($e->getMessage()), 'reference');
            }
        }

        $msg = strtolower($err->getMessage());
        if (str_contains($msg, 'duplicate key value violates unique constraint') && str_contains($msg, 'reference')) {
            return true;
        }
        return str_contains($msg, 'reference') && str_contains($msg, 'already been used');
    }

    /**
     * applyTransactionToBalances applies a transaction to the provided balances.
     * It starts a tracing span, calculates new balances, and updates the balances based on the transaction status.
     *
     * Parameters:
     * - balances []*model.Balance: A slice of Balance models to be updated. The first balance is the source, and the second is the destination.
     * - transaction *model.Transaction: The transaction to be applied to the balances.
     *
     * Returns:
     * - error: An error if the balances could not be updated (thrown).
     *
     * @param Balance[] $balances
     *
     * @throws \RuntimeException
     */
    protected function applyTransactionToBalances(array $balances, Transaction $transaction): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('Applying Transaction to Balances');
        try {
            $span->setAttribute('event', 'Calculating new balances'); // span.AddEvent

            // Handle committed inflight transactions.
            if ($transaction->status === self::StatusCommit) {
                try {
                    $balances[0]->commitInflightDebit($transaction);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw self::wrapError('commit inflight debit failed', $err);
                }
                try {
                    $balances[1]->commitInflightCredit($transaction);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw self::wrapError('commit inflight credit failed', $err);
                }
                $span->setAttribute('event', 'Committed inflight balances'); // span.AddEvent
                return;
            }

            $transactionAmount = $transaction->preciseAmount;

            // Handle voided transactions.
            if ($transaction->status === self::StatusVoid) {
                try {
                    $balances[0]->rollbackInflightDebit($transactionAmount);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw self::wrapError('void inflight debit failed', $err);
                }
                try {
                    $balances[1]->rollbackInflightCredit($transactionAmount);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw self::wrapError('void inflight credit failed', $err);
                }
                $span->setAttribute('event', 'Rolled back inflight balances'); // span.AddEvent
                return;
            }

            // Update balances for other transaction statuses
            try {
                ModelHelpers::updateBalances($transaction, $balances[0], $balances[1]);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            $span->setAttribute('event', 'Balances updated'); // span.AddEvent
        } finally {
            $span->end();
        }
    }

    /**
     * planTransactionExecution selects the internal execution mode for a transaction based on
     * whether queued batching is allowed and whether hot-lane execution should be used.
     */
    protected function planTransactionExecution(Transaction $transaction, bool $allowQueuedBatch, bool $hotLane): TransactionExecutionPlan
    {
        if ($allowQueuedBatch) {
            if ($hotLane) {
                return new TransactionExecutionPlan(self::transactionExecutionModeHotQueuedBatch, $transaction);
            }
            return new TransactionExecutionPlan(self::transactionExecutionModeQueuedBatch, $transaction);
        }

        return new TransactionExecutionPlan(self::transactionExecutionModeSingle, $transaction);
    }

    /**
     * executeTransactionPlan runs the selected internal execution mode and fails open from queued
     * batching back to the single-transaction path when batching does not handle the work.
     *
     * Go's `TryRecordQueuedTransactionBatch` returns `(handled bool, err error)` and
     * never `(true, err)`; the PHP port returns `handled` and throws, so a thrown
     * coalescing error is logged and treated as "not handled", exactly as in Go.
     *
     * @throws \Throwable from the single-transaction path
     */
    protected function executeTransactionPlan(TransactionExecutionPlan $plan): TransactionExecutionResult
    {
        switch ($plan->mode) {
            case self::transactionExecutionModeQueuedBatch:
                $handled = false;
                try {
                    $handled = $this->tryRecordQueuedTransactionBatch($plan->transaction);
                } catch (\Throwable $err) {
                    Log::get()->warning(sprintf('coalesced processing attempt failed for transaction %s', $plan->transaction->transactionID), ['error' => $err->getMessage()]);
                }
                if ($handled) {
                    return new TransactionExecutionResult(self::transactionExecutionModeQueuedBatch, $plan->transaction);
                }
                return $this->executeTransactionPlan($this->planTransactionExecution($plan->transaction, false, false));
            case self::transactionExecutionModeHotQueuedBatch:
                $handled = false;
                try {
                    $handled = $this->tryRecordQueuedTransactionBatchForHotLane($plan->transaction);
                } catch (\Throwable $err) {
                    Log::get()->warning(sprintf('coalesced hot-lane processing attempt failed for transaction %s', $plan->transaction->transactionID), ['error' => $err->getMessage()]);
                }
                if ($handled) {
                    return new TransactionExecutionResult(self::transactionExecutionModeHotQueuedBatch, $plan->transaction);
                }
                return $this->executeTransactionPlan($this->planTransactionExecution($plan->transaction, false, false));
            case self::transactionExecutionModeSingle:
                $transaction = $this->recordTransactionSingle($plan->transaction);
                return new TransactionExecutionResult(self::transactionExecutionModeSingle, $transaction);
            default:
                $transaction = $this->recordTransactionSingle($plan->transaction);
                return new TransactionExecutionResult(self::transactionExecutionModeSingle, $transaction);
        }
    }

    /**
     * processQueuedTransaction routes queued work through the shared executor so the planner can
     * choose between normal queued batching, hot-lane batching, and single-transaction fallback.
     *
     * Go has both the unexported `processQueuedTransaction` (returning the
     * execution result) and the exported `ProcessQueuedTransaction` (returning
     * the transaction); both would map to `processQueuedTransaction` in PHP, so
     * the unexported variant carries the `Internal` suffix.
     *
     * @throws \Throwable
     */
    protected function processQueuedTransactionInternal(Transaction $transaction, bool $hotLane): TransactionExecutionResult
    {
        return $this->executeTransactionPlan($this->planTransactionExecution($transaction, true, $hotLane));
    }

    /**
     * ProcessQueuedTransaction preserves the existing queued-worker behavior while routing the
     * decision through the shared internal transaction executor.
     *
     * @throws \Throwable
     */
    public function processQueuedTransaction(Transaction $transaction, bool $hotLane): Transaction
    {
        $result = $this->processQueuedTransactionInternal($transaction, $hotLane);
        return $result->transaction;
    }

    /**
     * RecordTransaction records a transaction by validating, processing balances, and finalizing the transaction.
     * It starts a tracing span, acquires a lock, and performs the necessary steps to record the transaction.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be recorded.
     *
     * Returns:
     * - *model.Transaction: A pointer to the recorded Transaction model.
     * - error: An error if the transaction could not be recorded (thrown).
     *
     * @throws \Throwable
     */
    public function recordTransaction(Transaction $transaction): Transaction
    {
        $result = $this->executeTransactionPlan($this->planTransactionExecution($transaction, false, false));
        return $result->transaction;
    }

    /**
     * recordTransactionSingle preserves the existing direct transaction-processing semantics by
     * running the single-transaction flow under the balance lock.
     *
     * @throws \Throwable
     */
    protected function recordTransactionSingle(Transaction $transaction): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('RecordTransaction');
        try {
            $startTime = microtime(true);

            $result = null;
            $err = null;
            try {
                $result = $this->executeWithLock($transaction, function () use ($transaction, $span): Transaction {
                    // Execute pre-transaction hooks
                    try {
                        $this->hooks->executePreHooks($transaction->transactionID, $transaction);
                    } catch (\Throwable $err) {
                        $span->recordError($err);
                        throw $err;
                    }

                    // Validate and prepare the transaction, including retrieving source and destination balances
                    try {
                        [$transaction, $sourceBalance, $destinationBalance] = $this->validateAndPrepareTransaction($transaction);
                    } catch (\Throwable $err) {
                        $span->recordError($err);
                        throw $err;
                    }

                    // Process the balances by applying the transaction
                    try {
                        $this->processBalances($transaction, $sourceBalance, $destinationBalance);
                    } catch (\Throwable $err) {
                        $span->recordError($err);
                        throw $err;
                    }

                    [$work, $skipPersist] = $this->buildTransactionExecutionWork($transaction, $sourceBalance, $destinationBalance);
                    if ($skipPersist) {
                        $span->setAttribute('event', 'Transaction with zero amount discarded, not persisted'); // span.AddEvent
                        $span->setAttribute('transaction.id', $work->transaction->transactionID);
                        return $work->transaction;
                    }

                    try {
                        $work = $this->persistSingleTransactionExecutionWork($work);
                    } catch (\Throwable $err) {
                        $span->recordError($err);
                        throw $err;
                    }

                    $this->runTransactionPostCommitWork($span, [$sourceBalance, $destinationBalance], [$work]);

                    $span->setAttribute('event', 'Transaction processed'); // span.AddEvent
                    $span->setAttribute('transaction.id', $work->transaction->transactionID);
                    Log::get()->info(sprintf('Transaction %s processed successfully', $work->transaction->transactionID));
                    return $work->transaction;
                });
            } catch (\Throwable $e) {
                $err = $e;
            }

            // Record metrics regardless of success or failure.
            $duration = microtime(true) - $startTime;
            $status = 'error';
            $currency = $transaction->currency;
            if ($result !== null) {
                $status = $result->status;
                $currency = $result->currency;
            }
            Metrics::transactionDuration()->record($duration, ['status' => $status]);
            if ($err === null) {
                Metrics::transactionTotal()->add(1, [
                    'status' => $status,
                    'currency' => $currency,
                ]);
            }

            if ($err !== null) {
                throw $err;
            }
            return $result;
        } finally {
            $span->end();
        }
    }

    /**
     * runTransactionPostCommitWork executes the post-commit work for persisted
     * work items using the current post-hook set.
     *
     * @param Balance[] $orderedBalances
     * @param QueuedBatchPostCommitWork[] $postCommitWork
     */
    protected function runTransactionPostCommitWork(Span $span, array $orderedBalances, array $postCommitWork): void
    {
        $this->runTransactionPostCommitWorkWithHooks($span, $orderedBalances, $postCommitWork, null);
    }

    /**
     * runTransactionPostCommitWorkWithHooks executes monitor checks, post-hooks, and
     * post-transaction actions for persisted work items, optionally reusing a preloaded hook set.
     *
     * Go bounds the monitor checks with `balanceMonitorSem` and runs each in its
     * own goroutine (with a context detached from cancellation); the PHP port
     * runs them inline, in order.
     *
     * @param Balance[] $orderedBalances
     * @param QueuedBatchPostCommitWork[] $postCommitWork
     * @param Hook[]|null $postHooks
     */
    protected function runTransactionPostCommitWorkWithHooks(Span $span, array $orderedBalances, array $postCommitWork, ?array $postHooks): void
    {
        foreach ($orderedBalances as $balance) {
            // Bound concurrent monitor checks instead of spawning one unbounded
            // goroutine per balance; acquiring the semaphore applies backpressure
            // outside the locked persistence path.
            $this->checkBalanceMonitors($balance);
        }

        foreach ($postCommitWork as $work) {
            if ($this->hooks !== null) {
                try {
                    if ($postHooks !== null) {
                        $this->hooks->executeHooks($postHooks, HookType::PostTransaction, $work->transaction->transactionID, $work->transaction);
                    } else {
                        $this->hooks->executePostHooks($work->transaction->transactionID, $work->transaction);
                    }
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    Log::get()->error('post-transaction hooks failed', ['error' => $err->getMessage()]);
                }
            }
            $this->postTransactionActions($work->transaction, $work->sourceBalance, $work->destinationBalance);
        }
    }

    /**
     * listHooksForExecution returns the current hook set for the requested type, or nil when
     * hook execution is not configured for the current Blnk instance.
     *
     * @return Hook[]|null
     *
     * @throws \Throwable if listing the hooks fails
     */
    protected function listHooksForExecution(string $hookType): ?array
    {
        if ($this->hooks === null) {
            return null;
        }

        return $this->hooks->listHooks($hookType);
    }

    /**
     * executeWithLock executes a function with distributed locks to ensure exclusive access to both
     * source and destination balances. It resolves balance IDs first (handling @world indicators),
     * then acquires locks in deterministic order to prevent deadlocks.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction for which to acquire the locks.
     * - fn func(context.Context) (*model.Transaction, error): The function to execute with the locks.
     *
     * Returns:
     * - *model.Transaction: A pointer to the Transaction model returned by the function.
     * - error: An error if the locks could not be acquired or if the function execution fails (thrown).
     *
     * @param callable(): Transaction $fn
     *
     * @throws \RuntimeException "failed to resolve balance IDs: ..." / "failed to acquire lock: ..."
     * @throws \Throwable whatever $fn throws
     */
    protected function executeWithLock(Transaction $transaction, callable $fn): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ExecuteWithLock');
        try {
            // First resolve balance IDs (handle @ indicators) BEFORE acquiring locks
            // This ensures we lock on the correct balance IDs
            try {
                [$sourceID, $destID] = $this->resolveBalanceIDs($transaction);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw self::wrapError('failed to resolve balance IDs', $err);
            }

            // Update transaction with resolved IDs
            $transaction->source = $sourceID;
            $transaction->destination = $destID;

            // Acquire distributed locks for both source and destination balances
            // MultiLocker handles deduplication (if source == destination) and sorts keys lexicographically
            try {
                $locker = $this->acquireLock($sourceID, $destID);
            } catch (\Throwable $err) {
                Router::recordContention($this->hotPairs, $sourceID, $destID, $transaction->currency, $err);
                Metrics::hotpairsContentionTotal()->add(1);
                $span->recordError($err);
                throw self::wrapError('failed to acquire lock', $err);
            }

            try {
                // Execute the provided function with the locks held
                // The function will re-fetch balances to get fresh state after lock acquisition
                return $fn();
            } finally {
                $this->releaseLock($locker);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * validateAndPrepareTransaction validates the transaction and prepares it by retrieving the source and destination balances.
     * It starts a tracing span, validates the transaction, retrieves the balances, and updates the transaction with the balance IDs.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be validated and prepared.
     *
     * Returns:
     * - *model.Transaction: A pointer to the new transaction object with updated details.
     * - *model.Balance: A pointer to the source Balance model.
     * - *model.Balance: A pointer to the destination Balance model.
     * - error: An error if the transaction validation or balance retrieval fails (thrown).
     *
     * @return array{0: Transaction, 1: Balance, 2: Balance} [newTransaction, sourceBalance, destinationBalance]
     *
     * @throws \RuntimeException "transaction validation failed: ..." / "failed to get source and destination balances: ..."
     */
    protected function validateAndPrepareTransaction(Transaction $transaction): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ValidateAndPrepareTransaction');
        try {
            // Validate the transaction
            try {
                $this->validateTxn($transaction);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $this->logAndRecordError($span, 'transaction validation failed', $err);
            }

            // Retrieve the source and destination balances
            try {
                [$sourceBalance, $destinationBalance] = $this->getSourceAndDestination($transaction);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $this->logAndRecordError($span, 'failed to get source and destination balances', $err);
            }

            // Create a copy of the transaction and update it (immutable)
            $newTransaction = clone $transaction; // Copy the original transaction
            $newTransaction->source = $sourceBalance->balanceID;
            $newTransaction->destination = $destinationBalance->balanceID;

            $span->setAttribute('event', 'Transaction validated and prepared'); // span.AddEvent
            $span->setAttribute('source.balance_id', $sourceBalance->balanceID);
            $span->setAttribute('destination.balance_id', $destinationBalance->balanceID);

            // Return the new transaction, source, and destination balances
            return [$newTransaction, $sourceBalance, $destinationBalance];
        } finally {
            $span->end();
        }
    }

    /**
     * processBalances processes the source and destination balances by applying the transaction in-memory.
     * It starts a tracing span, applies the transaction to the balances, and records relevant events and errors.
     * Note: The actual database update of balances is done atomically with the transaction persistence
     * step to ensure consistency.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be applied to the balances.
     * - sourceBalance *model.Balance: The source balance to be updated.
     * - destinationBalance *model.Balance: The destination balance to be updated.
     *
     * Returns:
     * - error: An error if the transaction could not be applied to the balances (thrown).
     *
     * @throws \RuntimeException "failed to apply transaction to balances: ..."
     */
    protected function processBalances(Transaction $transaction, Balance $sourceBalance, Balance $destinationBalance): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ProcessBalances');
        try {
            // Apply the transaction to the source and destination balances (in-memory only)
            try {
                $this->applyTransactionToBalances([$sourceBalance, $destinationBalance], $transaction);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $this->logAndRecordError($span, 'failed to apply transaction to balances', $err);
            }

            $span->setAttribute('event', 'Balances calculated'); // span.AddEvent
        } finally {
            $span->end();
        }
    }

    /**
     * buildTransactionExecutionWork converts an in-memory-applied transaction into the shared
     * persistence and post-commit work shape used by both single and batched execution paths.
     *
     * @return array{0: QueuedBatchPostCommitWork, 1: bool} [work, skipPersist]
     */
    protected function buildTransactionExecutionWork(Transaction $transaction, Balance $sourceBalance, Balance $destinationBalance): array
    {
        $transaction = $this->updateTransactionDetails($transaction, $sourceBalance, $destinationBalance);
        if ($transaction->preciseAmount !== null && $transaction->preciseAmount->isZero()) {
            return [new QueuedBatchPostCommitWork(
                $transaction,
                $sourceBalance,
                $destinationBalance
            ), true];
        }

        return [new QueuedBatchPostCommitWork(
            $transaction,
            $sourceBalance,
            $destinationBalance,
            $this->prepareTransactionOutbox($transaction, $sourceBalance, $destinationBalance)
        ), false];
    }

    /**
     * persistSingleTransactionExecutionWork atomically persists one prepared transaction, its
     * updated balances, and any lineage outbox using the shared execution work shape.
     *
     * @throws \RuntimeException "failed to persist transaction with balances: ..."
     */
    protected function persistSingleTransactionExecutionWork(QueuedBatchPostCommitWork $work): QueuedBatchPostCommitWork
    {
        $span = Tracer::get('blnk.transactions')->startSpan('PersistSingleTransactionExecutionWork');
        try {
            try {
                $transaction = $this->datasource->recordTransactionWithBalancesAndOutbox($work->transaction, $work->sourceBalance, $work->destinationBalance, $work->outbox);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $this->logAndRecordError($span, 'failed to persist transaction with balances', $err);
            }

            $work->transaction = $transaction;
            $span->setAttribute('event', 'Transaction and balances persisted atomically'); // span.AddEvent
            $span->setAttribute('transaction.id', $transaction->transactionID);
            $span->setAttribute('lineage.outbox_created', $work->outbox !== null);

            return $work;
        } finally {
            $span->end();
        }
    }

    /**
     * prepareTransactionOutbox builds the lineage outbox entry for an applied or
     * inflight transaction, skipping commits of inflight transactions (lineage
     * was already created for the original).
     */
    protected function prepareTransactionOutbox(Transaction $transaction, Balance $sourceBalance, Balance $destinationBalance): ?LineageOutbox
    {
        if ($transaction->status !== self::StatusApplied && $transaction->status !== self::StatusInflight) {
            return null;
        }

        // Skip lineage for commits of inflight transactions - lineage was already created for the original.
        $isInflightCommit = $transaction->parentTransaction !== '' && $transaction->metaData !== null && ($transaction->metaData['inflight'] ?? null) === true;
        if ($isInflightCommit) {
            return null;
        }

        return $this->prepareLineageOutbox($transaction, $sourceBalance, $destinationBalance);
    }

    /**
     * releaseLock releases all locks held by the MultiLocker, logging (never
     * throwing) on failure.
     */
    protected function releaseLock(MultiLocker $locker): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ReleaseLock');
        try {
            // Attempt to release all locks
            try {
                $locker->unlock();
            } catch (\Throwable $err) {
                $span->recordError($err);
                Log::get()->error('failed to release lock', ['error' => $err->getMessage()]);
            }
            $span->setAttribute('event', 'Locks released'); // span.AddEvent
        } finally {
            $span->end();
        }
    }

    /**
     * logAndRecordError logs an error message and records the error in the tracing span.
     * It returns a formatted error message combining the provided message and the original error.
     *
     * Parameters:
     * - span trace.Span: The tracing span to record the error.
     * - msg string: The error message to log and include in the formatted error.
     * - err error: The original error to be logged and recorded.
     *
     * Returns:
     * - error: A formatted error message combining the provided message and the original error
     *   (Go: `fmt.Errorf("%s: %w", msg, err)` → {@see Blnk::wrapError()}, which keeps `err`
     *   as `previous`). The caller throws the returned exception.
     */
    protected function logAndRecordError(Span $span, string $msg, \Throwable $err): \RuntimeException
    {
        $span->recordError($err);
        Log::get()->error($msg, ['error' => $err->getMessage()]);
        return self::wrapError($msg, $err);
    }
}
