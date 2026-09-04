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

use Blnk\Database\NotFoundException;
use Blnk\Internal\Lock\Locker;
use Blnk\Internal\Log;
use Blnk\Internal\Metrics\Metrics;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\LineageOutbox;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * TransactionInflight is the port of the root-package file
 * `transaction_inflight.go`: inflight commit/void (with partial amounts), the
 * queued inflight-action worker entrypoint, and bulk inflight updates.
 *
 * The standalone Go types of the file became classes in this namespace:
 * `BulkInflightAction` → {@see BulkInflightAction}, `BulkInflightItem` →
 * {@see BulkInflightItem}, `BulkInflightOutcome` → {@see BulkInflightOutcome}.
 *
 * Both the exported `ClassifyInflightError` and the unexported
 * `classifyInflightError` map to {@see self::classifyInflightError()} (the
 * exported one only delegates).
 *
 * Conventions: `l.Config()` → {@see Blnk::config()}; `fmt.Errorf("...: %w", err)`
 * → {@see Blnk::wrapError()}; `span.AddEvent` → `$span->setAttribute('event', ...)`.
 */
trait TransactionInflight
{
    public const StatusInflight = 'INFLIGHT';
    public const StatusVoid = 'VOID';
    public const StatusCommit = 'COMMIT';

    /**
     * GetInflightTransactionsByParentID retrieves inflight transactions by their parent transaction ID.
     * It starts a tracing span, fetches the transactions from the datasource, and records relevant events and errors.
     *
     * Parameters:
     * - parentTransactionID string: The ID of the parent transaction.
     * - batchSize int: The number of transactions to retrieve in a batch.
     * - offset int64: The offset for pagination.
     *
     * Returns:
     * - []*model.Transaction: A slice of pointers to the retrieved Transaction models.
     * - error: An error if the transactions could not be retrieved (thrown).
     *
     * This method satisfies the `getTxns` callable contract (see {@see TransactionService}).
     *
     * @return Transaction[]
     *
     * @throws \Throwable
     */
    public function getInflightTransactionsByParentID(string $parentTransactionID, int $batchSize, int $offset): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('GetInflightTransactionsByParentID');
        try {
            try {
                $transactions = $this->datasource->getInflightTransactionsByParentID($parentTransactionID, $batchSize, $offset);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            $span->setAttribute('parent_transaction_id', $parentTransactionID);
            $span->setAttribute('event', 'Inflight transactions retrieved'); // span.AddEvent
            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * IsInflightTransaction checks whether a transaction is considered "inflight" based on its status and metadata.
     * A transaction is considered inflight if:
     * 1. It has a status of StatusInflight, OR
     * 2. It has a status of StatusQueued AND has the inflight flag set to true in its metadata
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to check
     *
     * Returns:
     * - bool: true if the transaction is considered inflight, false otherwise
     */
    public static function isInflightTransaction(Transaction $transaction): bool
    {
        return $transaction->status === self::StatusInflight ||
            ($transaction->status === self::StatusQueued &&
                $transaction->metaData !== null &&
                ($transaction->metaData['inflight'] ?? null) === true);
    }

    /**
     * CommitWorker processes commit transactions from the jobs channel and sends the results to the results channel.
     * It starts a tracing span, processes each transaction, and records relevant events and errors.
     *
     * Parameters:
     * - jobs <-chan *model.Transaction: A channel from which transactions are received for processing.
     * - results chan<- BatchJobResult: A channel to which the results of the processing are sent.
     * - wg *sync.WaitGroup: A wait group to synchronize the completion of the worker.
     * - amount *big.Int: The amount to be processed in the transaction.
     *
     * PHP `transactionWorker` contract: the jobs iterable is consumed in order and
     * the results are returned as a list (see {@see TransactionService}).
     *
     * @param iterable<Transaction> $jobs
     *
     * @return BatchJobResult[]
     */
    public function commitWorker(iterable $jobs, ?BigInteger $amount): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('CommitWorker');
        try {
            $results = [];
            foreach ($jobs as $originalTxn) {
                if (!self::isInflightTransaction($originalTxn)) {
                    $err = new \RuntimeException('transaction is not in inflight status');
                    $results[] = new BatchJobResult(null, $err);
                    $span->recordError($err);
                    continue;
                }
                try {
                    $queuedCommitTxn = $this->commitInflightTransaction($originalTxn->transactionID, $amount);
                } catch (\Throwable $err) {
                    $results[] = new BatchJobResult(null, $err);
                    $span->recordError($err);
                    continue;
                }
                $results[] = new BatchJobResult($queuedCommitTxn);
                $span->setAttribute('event', 'Commit processed'); // span.AddEvent
                $span->setAttribute('transaction.id', $queuedCommitTxn->transactionID);
            }
            return $results;
        } finally {
            $span->end();
        }
    }

    /**
     * VoidWorker processes void transactions from the jobs channel and sends the results to the results channel.
     * It starts a tracing span, processes each transaction, and records relevant events and errors.
     *
     * Parameters:
     * - jobs <-chan *model.Transaction: A channel from which transactions are received for processing.
     * - results chan<- BatchJobResult: A channel to which the results of the processing are sent.
     * - wg *sync.WaitGroup: A wait group to synchronize the completion of the worker.
     * - amount *big.Int: The amount to be processed in the transaction.
     *
     * @param iterable<Transaction> $jobs
     *
     * @return BatchJobResult[]
     */
    public function voidWorker(iterable $jobs, ?BigInteger $amount): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('VoidWorker');
        try {
            $results = [];
            foreach ($jobs as $originalTxn) {
                if (!self::isInflightTransaction($originalTxn)) {
                    $err = new \RuntimeException('transaction is not in inflight status');
                    $results[] = new BatchJobResult(null, $err);
                    $span->recordError($err);
                    continue;
                }
                try {
                    $queuedVoidTxn = $this->voidInflightTransaction($originalTxn->transactionID);
                } catch (\Throwable $err) {
                    $results[] = new BatchJobResult(null, $err);
                    $span->recordError($err);
                    continue;
                }
                $results[] = new BatchJobResult($queuedVoidTxn);
                $span->setAttribute('event', 'Void processed'); // span.AddEvent
                $span->setAttribute('transaction.id', $queuedVoidTxn->transactionID);
            }
            return $results;
        } finally {
            $span->end();
        }
    }

    /**
     * releaseSingleLock releases a single distributed lock.
     * This is used for operations that lock on a single key (e.g., inflight transaction operations).
     *
     * Parameters:
     * - locker *redlock.Locker: The Locker object representing the acquired lock.
     */
    protected function releaseSingleLock(Locker $locker): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ReleaseSingleLock');
        try {
            try {
                $locker->unlock();
            } catch (\Throwable $err) {
                $span->recordError($err);
                Log::get()->error('failed to release lock', ['error' => $err->getMessage()]);
            }
            $span->setAttribute('event', 'Lock released'); // span.AddEvent
        } finally {
            $span->end();
        }
    }

    /**
     * CommitInflightTransaction commits an inflight transaction by validating and updating its amount, and finalizing the commitment.
     * It starts a tracing span, fetches and validates the inflight transaction, updates the amount, and finalizes the commitment.
     *
     * Parameters:
     * - transactionID string: The ID of the inflight transaction to be committed.
     * - amount float64: The amount to be validated and updated in the transaction.
     *
     * Returns:
     * - *model.Transaction: A pointer to the committed Transaction model.
     * - error: An error if the transaction could not be committed (thrown).
     *
     * @throws \Throwable
     */
    public function commitInflightTransaction(string $transactionID, BigInteger $amount): Transaction
    {
        return $this->commitInflightTransactionWithRef($transactionID, $amount, '');
    }

    /**
     * CommitInflightTransactionWithRef commits an inflight transaction using a
     * caller-supplied reference for the committed child. A non-empty reference makes
     * asynq retries idempotent: a retry that re-commits the same amount collides on
     * the unique reference index. An empty reference preserves the legacy behavior
     * of generating a random reference.
     *
     * @throws \RuntimeException "failed to acquire lock for inflight commit: ..."
     * @throws \Throwable
     */
    public function commitInflightTransactionWithRef(string $transactionID, BigInteger $amount, string $reference): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('CommitInflightTransaction');
        try {
            $lockKey = sprintf('inflight-commit:%s', $transactionID);
            $locker = new Locker($this->redis, $lockKey, ModelHelpers::generateUUIDWithSuffix('loc'));

            try {
                $locker->lock($this->config()->transaction->lockDuration);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw self::wrapError('failed to acquire lock for inflight commit', $err);
            }

            try {
                try {
                    $transaction = $this->fetchAndValidateInflightTransaction($transactionID);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }

                try {
                    $this->validateAndUpdateAmount($transaction, $amount);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }

                $span->setAttribute('event', 'Inflight transaction committed'); // span.AddEvent
                $span->setAttribute('transaction.id', $transaction->transactionID);
                Metrics::inflightCommitTotal()->add(1);

                $committedTxn = $this->finalizeCommitment($transaction, false, $reference);

                try {
                    $this->queueShadowWork($transactionID, LineageOutbox::LineageTypeShadowCommit);
                } catch (\Throwable $err) {
                    Log::get()->error('failed to queue shadow commit', ['error' => $err->getMessage(), 'transaction_id' => $transactionID]);
                }

                return $committedTxn;
            } finally {
                // Go: defer l.releaseSingleLock(ctx, locker)
                $this->releaseSingleLock($locker);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * CommitInflightTransactionWithQueue commits an inflight transaction like
     * CommitInflightTransaction, but enqueues the committed child instead of
     * recording it synchronously.
     *
     * @throws \RuntimeException "failed to acquire lock for inflight commit: ..."
     * @throws \Throwable
     */
    public function commitInflightTransactionWithQueue(string $transactionID, BigInteger $amount): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('CommitInflightTransactionWithQueue');
        try {
            $lockKey = sprintf('inflight-commit:%s', $transactionID);
            $locker = new Locker($this->redis, $lockKey, ModelHelpers::generateUUIDWithSuffix('loc'));

            try {
                $locker->lock($this->config()->transaction->lockDuration);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw self::wrapError('failed to acquire lock for inflight commit', $err);
            }

            try {
                try {
                    $transaction = $this->fetchAndValidateInflightTransaction($transactionID);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }

                try {
                    $this->validateAndUpdateAmount($transaction, $amount);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }

                $span->setAttribute('event', 'Inflight transaction committed'); // span.AddEvent
                $span->setAttribute('transaction.id', $transaction->transactionID);

                $committedTxn = $this->finalizeCommitment($transaction, true, '');

                try {
                    $this->queueShadowWork($transactionID, LineageOutbox::LineageTypeShadowCommit);
                } catch (\Throwable $err) {
                    Log::get()->error('failed to queue shadow commit', ['error' => $err->getMessage(), 'transaction_id' => $transactionID]);
                }

                return $committedTxn;
            } finally {
                // Go: defer l.releaseSingleLock(ctx, locker)
                $this->releaseSingleLock($locker);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * validateAndUpdateAmount validates the amount to be committed for a transaction and updates the transaction's amount.
     * It orchestrates the validation and update process by calling more specialized functions.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be validated and updated.
     * - amount float64: The amount to be validated and updated in the transaction.
     *
     * Returns:
     * - error: An error if the amount validation or update fails (thrown).
     *
     * @throws \Throwable
     */
    protected function validateAndUpdateAmount(Transaction $transaction, BigInteger $amount): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('ValidateAndUpdateAmount');
        try {
            try {
                $amountLeft = $this->calculateRemainingAmount($transaction);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            try {
                $this->checkTransactionCommitStatus($amountLeft);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            try {
                $this->validateRequestedAmount($transaction, $amount, $amountLeft);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            $this->updateTransactionAmount($transaction, $amount, $amountLeft);

            $amountLeftValue = $this->convertPreciseToFloat($amountLeft, $transaction->precision);
            $span->setAttribute('event', 'Amount validated and updated'); // span.AddEvent
            $span->setAttribute('amount.left', $amountLeftValue);
        } finally {
            $span->end();
        }
    }

    /**
     * checkTransactionCommitStatus checks if a transaction can be committed based on its remaining amount.
     *
     * Parameters:
     * - amountLeft *big.Int: The remaining amount that can be committed.
     *
     * Returns:
     * - error: An error if the transaction is already fully committed (thrown).
     *
     * @throws \RuntimeException "cannot commit. Transaction already committed"
     */
    protected function checkTransactionCommitStatus(BigInteger $amountLeft): void
    {
        if ($amountLeft->isZero()) {
            throw new \RuntimeException('cannot commit. Transaction already committed');
        }
    }

    /**
     * validateRequestedAmount validates that the requested amount is within allowed limits.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction being validated.
     * - amount float64: The requested amount to commit.
     * - amountLeft *big.Int: The remaining amount that can be committed.
     *
     * Returns:
     * - error: An error if the requested amount exceeds allowed limits (thrown).
     *
     * Note on the "Requested" figure: Go formats the `*big.Int` amount with the
     * `%.2f` verb; big.Int implements fmt.Formatter and renders unknown verbs
     * as `%!f(big.Int=<value>)`, so that is the exact text the Go build emits
     * and the port reproduces it verbatim.
     *
     * @throws \RuntimeException
     */
    protected function validateRequestedAmount(Transaction $transaction, BigInteger $amount, BigInteger $amountLeft): void
    {
        if ($amount->getSign() < 0) {
            throw new \RuntimeException('commit amount cannot be negative');
        }
        // Zero is the sentinel for "commit the full remaining amount".
        if ($amount->getSign() === 0) {
            return;
        }

        $requestedAmount = $amount;

        if ($requestedAmount->compareTo($transaction->preciseAmount) > 0) {
            throw new \RuntimeException(sprintf(
                'cannot commit more than the original transaction amount. Original: %s%.2f, Requested: %s%%!f(big.Int=%s)',
                $transaction->currency,
                $transaction->amount,
                $transaction->currency,
                (string) $amount
            ));
        }

        if ($requestedAmount->compareTo($amountLeft) > 0) {
            $amountLeftValue = $this->convertPreciseToFloat($amountLeft, $transaction->precision);
            throw new \RuntimeException(sprintf(
                'cannot commit more than the remaining amount. Available: %s%.2f, Requested: %s%%!f(big.Int=%s)',
                $transaction->currency,
                $amountLeftValue,
                $transaction->currency,
                (string) $amount
            ));
        }
    }

    /**
     * updateTransactionAmount updates the transaction with the specified amount or the full remaining amount.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to update.
     * - amount float64: The amount to commit (0 means commit the full remaining amount).
     * - amountLeft *big.Int: The remaining amount that can be committed.
     */
    protected function updateTransactionAmount(Transaction $transaction, BigInteger $amount, BigInteger $amountLeft): void
    {
        if (!$amount->isZero()) {
            $transaction->preciseAmount = $amount;
        } else {
            $transaction->amount = $this->convertPreciseToFloat($amountLeft, $transaction->precision);
            $transaction->preciseAmount = $amountLeft;
        }
    }

    /**
     * convertPreciseToFloat converts a precise amount (big.Int) to a floating-point representation.
     *
     * Parameters:
     * - preciseAmount *big.Int: The precise amount to convert.
     * - precision float64: The precision factor (e.g., 100 for 2 decimal places).
     *
     * Returns:
     * - float64: The floating-point representation of the precise amount.
     *
     * Go divides with big.Float (exact integer, >= 64-bit mantissa) and rounds
     * once to float64; the port divides exactly with BigDecimal and converts
     * once. A zero precision follows IEEE semantics (x/0 = ±Inf, 0/0 = NaN)
     * like big.Float.Quo.
     */
    protected function convertPreciseToFloat(BigInteger $preciseAmount, float $precision): float
    {
        if ($precision == 0.0) {
            return fdiv($preciseAmount->toFloat(), $precision);
        }
        return BigDecimal::of((string) $preciseAmount)
            ->dividedBy(BigDecimal::of(ModelHelpers::goFloatString($precision)), 20, RoundingMode::HALF_EVEN)
            ->toFloat();
    }

    /**
     * finalizeCommitment finalizes the commitment of a transaction by updating its status and generating new identifiers.
     * It starts a tracing span, updates the transaction details, queues the transaction, and records relevant events and errors.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be finalized.
     *
     * Returns:
     * - *model.Transaction: A pointer to the finalized Transaction model.
     * - error: An error if the transaction could not be queued (thrown).
     *
     * @throws \RuntimeException "failed to enqueue transaction: ..." / "saving transaction to db error: ..."
     */
    protected function finalizeCommitment(Transaction $transaction, bool $withQueue, string $reference): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('FinalizeCommitment');
        try {
            $transaction->status = self::StatusCommit;
            $transaction->parentTransaction = $transaction->transactionID;
            $transaction->createdAt = new \DateTimeImmutable('now');
            if ($transaction->effectiveDate === null) {
                $transaction->effectiveDate = $transaction->createdAt;
            }
            $transaction->transactionID = ModelHelpers::generateUUIDWithSuffix('txn');
            if ($reference !== '') {
                $transaction->reference = $reference;
            } else {
                $transaction->reference = ModelHelpers::generateUUIDWithSuffix('ref');
            }
            $transaction->hash = $transaction->hashTxn();

            if ($withQueue) {
                try {
                    self::enqueueTransactions($this->queue, $transaction, []);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $this->logAndRecordError($span, 'failed to enqueue transaction', $err);
                }
            } else {
                try {
                    $transaction = $this->recordTransaction($transaction);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $this->logAndRecordError($span, 'saving transaction to db error', $err);
                }
                return $transaction;
            }

            $transaction->status = self::StatusApplied;

            $span->setAttribute('event', 'Commitment finalized'); // span.AddEvent
            $span->setAttribute('transaction.id', $transaction->transactionID);
            return $transaction;
        } finally {
            $span->end();
        }
    }

    /**
     * VoidInflightTransaction voids an inflight transaction by validating it, calculating the remaining amount, and finalizing the void.
     * It starts a tracing span, fetches and validates the inflight transaction, calculates the remaining amount, and finalizes the void.
     *
     * Parameters:
     * - transactionID string: The ID of the inflight transaction to be voided.
     *
     * Returns:
     * - *model.Transaction: A pointer to the voided Transaction model.
     * - error: An error if the transaction could not be voided (thrown).
     *
     * @throws \Throwable
     */
    public function voidInflightTransaction(string $transactionID): Transaction
    {
        return $this->voidInflightTransactionWithRef($transactionID, '');
    }

    /**
     * VoidInflightTransactionWithRef voids an inflight transaction using a
     * caller-supplied reference for the voided child, making asynq retries
     * idempotent. An empty reference preserves the legacy random-reference behavior.
     *
     * Go returns `(transaction, err)` for the already-committed case; every
     * caller checks the error first, so the PHP port throws
     * "cannot void. Transaction already committed".
     *
     * @throws \RuntimeException "failed to acquire lock for inflight void: ..."
     * @throws \Throwable
     */
    public function voidInflightTransactionWithRef(string $transactionID, string $reference): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('VoidInflightTransaction');
        try {
            $lockKey = sprintf('inflight-commit:%s', $transactionID);
            $locker = new Locker($this->redis, $lockKey, ModelHelpers::generateUUIDWithSuffix('loc'));

            try {
                $locker->lock($this->config()->transaction->lockDuration);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw self::wrapError('failed to acquire lock for inflight void', $err);
            }

            try {
                try {
                    $transaction = $this->fetchAndValidateInflightTransaction($transactionID);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }

                try {
                    $amountLeft = $this->calculateRemainingAmount($transaction);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }

                if ($amountLeft->isZero()) {
                    $err = new \RuntimeException('cannot void. Transaction already committed');
                    $span->recordError($err);
                    throw $err;
                }
                $span->setAttribute('event', 'Inflight transaction voided'); // span.AddEvent
                $span->setAttribute('transaction.id', $transaction->transactionID);
                Metrics::inflightVoidTotal()->add(1);

                $voidedTxn = $this->finalizeVoidTransaction($transaction, $amountLeft, $reference);

                try {
                    $this->queueShadowWork($transactionID, LineageOutbox::LineageTypeShadowVoid);
                } catch (\Throwable $err) {
                    Log::get()->error('failed to queue shadow void', ['error' => $err->getMessage(), 'transaction_id' => $transactionID]);
                }

                return $voidedTxn;
            } finally {
                // Go: defer l.releaseSingleLock(ctx, locker)
                $this->releaseSingleLock($locker);
            }
        } finally {
            $span->end();
        }
    }

    /**
     * fetchAndValidateInflightTransaction fetches and validates an inflight transaction by its ID.
     * It starts a tracing span, attempts to retrieve the transaction from the database or queue, and validates its status.
     *
     * Parameters:
     * - transactionID string: The ID of the inflight transaction to be fetched and validated.
     *
     * Returns:
     * - *model.Transaction: A pointer to the validated Transaction model.
     * - error: An error if the transaction could not be fetched or validated (thrown).
     *
     * Go switches on `err == sql.ErrNoRows` to fall back to the queue; per
     * PORTING.md the datasource surfaces `sql.ErrNoRows` as
     * {@see NotFoundException}, which is what the fallback catches here.
     *
     * @throws \RuntimeException "transaction not found" / "transaction is not in inflight status" /
     *                           "transaction has already been voided" / "error checking parent transaction status: ..."
     * @throws \Throwable other datasource/queue failures
     */
    protected function fetchAndValidateInflightTransaction(string $transactionID): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('FetchAndValidateInflightTransaction');
        try {
            try {
                $transaction = $this->datasource->getTransaction($transactionID);
            } catch (NotFoundException $noRows) {
                // case sql.ErrNoRows: look for the (not yet persisted) transaction in the queue
                try {
                    $queuedTxn = $this->queue->getTransactionFromQueue($transactionID);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }
                if ($queuedTxn === null) {
                    $err = new \RuntimeException('transaction not found');
                    $span->recordError($err);
                    throw $err;
                }
                // Go: logrus.Info(msg, transactionID, queuedTxn.TransactionID) — fmt.Sprint concatenation of strings.
                Log::get()->info('found inflight transaction in queue using it for commit/void' . $transactionID . $queuedTxn->transactionID);
                $transaction = $queuedTxn;
            } catch (\Throwable $err) {
                // default: any other datasource error is returned as-is
                $span->recordError($err);
                throw $err;
            }

            if (!self::isInflightTransaction($transaction)) {
                $err = new \RuntimeException('transaction is not in inflight status');
                $span->recordError($err);
                throw $err;
            }

            try {
                $parentVoided = $this->datasource->isParentTransactionVoid($transactionID);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $this->logAndRecordError($span, 'error checking parent transaction status', $err);
            }

            if ($parentVoided) {
                $err = new \RuntimeException('transaction has already been voided');
                $span->recordError($err);
                throw $err;
            }

            $span->setAttribute('event', 'Inflight transaction validated'); // span.AddEvent
            $span->setAttribute('transaction.id', $transaction->transactionID);
            return $transaction;
        } finally {
            $span->end();
        }
    }

    /**
     * calculateRemainingAmount calculates the remaining amount for an inflight transaction by subtracting the committed amount from the precise amount.
     * It starts a tracing span, fetches the total committed amount, calculates the remaining amount, and records relevant events and errors.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction for which to calculate the remaining amount.
     *
     * Returns:
     * - int64: The remaining amount for the transaction.
     * - error: An error if the committed amount could not be fetched (thrown).
     *
     * @throws \RuntimeException "error fetching committed amount: ..."
     */
    protected function calculateRemainingAmount(Transaction $transaction): BigInteger
    {
        $span = Tracer::get('blnk.transactions')->startSpan('CalculateRemainingAmount');
        try {
            try {
                $committedAmount = $this->datasource->getTotalCommittedTransactions($transaction->transactionID);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $this->logAndRecordError($span, 'error fetching committed amount', $err);
            }

            $remainingAmount = $transaction->preciseAmount->minus($committedAmount);

            $span->setAttribute('event', 'Remaining amount calculated'); // span.AddEvent
            $span->setAttribute('amount.remaining', (string) $remainingAmount);
            return $remainingAmount;
        } finally {
            $span->end();
        }
    }

    /**
     * finalizeVoidTransaction finalizes the voiding of a transaction by updating its status and generating new identifiers.
     * It starts a tracing span, updates the transaction details, queues the transaction, and records relevant events and errors.
     *
     * Parameters:
     * - transaction *model.Transaction: The transaction to be voided.
     * - amountLeft int64: The remaining amount to be set in the transaction.
     *
     * Returns:
     * - *model.Transaction: A pointer to the voided Transaction model.
     * - error: An error if the transaction could not be queued (thrown).
     *
     * @throws \RuntimeException "saving transaction to db error: ..."
     */
    protected function finalizeVoidTransaction(Transaction $transaction, BigInteger $amountLeft, string $reference): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('FinalizeVoidTransaction');
        try {
            $transaction->status = self::StatusVoid;
            $transaction->preciseAmount = $amountLeft;
            $transaction->createdAt = new \DateTimeImmutable('now');
            if ($transaction->effectiveDate === null) {
                $transaction->effectiveDate = $transaction->createdAt;
            }
            $transaction->parentTransaction = $transaction->transactionID;
            $transaction->transactionID = ModelHelpers::generateUUIDWithSuffix('txn');
            if ($reference !== '') {
                $transaction->reference = $reference;
            } else {
                $transaction->reference = ModelHelpers::generateUUIDWithSuffix('ref');
            }
            $transaction->hash = $transaction->hashTxn();
            ModelHelpers::applyPrecision($transaction);

            try {
                $transaction = $this->recordTransaction($transaction);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $this->logAndRecordError($span, 'saving transaction to db error', $err);
            }

            $span->setAttribute('event', 'Void transaction finalized'); // span.AddEvent
            $span->setAttribute('transaction.id', $transaction->transactionID);
            return $transaction;
        } finally {
            $span->end();
        }
    }

    /**
     * inflightActionReference derives the deterministic reference for a committed or
     * voided leg from the action ID carried in the queued task. The same actionID
     * on an asynq retry reproduces the same reference, so a re-applied leg collides
     * on the unique reference index. An empty actionID yields "" (random reference).
     */
    protected static function inflightActionReference(string $legTransactionID, string $action, string $actionID): string
    {
        if ($actionID === '') {
            return '';
        }
        return sprintf('%s_%s_%s', $legTransactionID, $action, $actionID);
    }

    /**
     * isTerminalInflightError reports whether an inflight commit/void error is
     * terminal — the action cannot succeed on retry and the queued task should be
     * acked rather than retried.
     */
    protected function isTerminalInflightError(?\Throwable $err): bool
    {
        if (self::isDuplicateReferenceError($err)) {
            return true;
        }
        switch (self::classifyInflightError($err)) {
            case 'ALREADY_COMMITTED':
            case 'ALREADY_VOIDED':
            case 'NOT_INFLIGHT':
            case 'INVALID_AMOUNT':
                return true;
            default:
                return false;
        }
    }

    /**
     * collectInflightLegs snapshots every inflight leg under a parent transaction
     * (transaction_id == parent OR parent_transaction == parent). Committing a leg
     * creates a child row but leaves the leg's own row INFLIGHT, so snapshotting up
     * front avoids re-processing the same legs across pages.
     *
     * @return Transaction[]
     *
     * @throws \Throwable
     */
    protected function collectInflightLegs(string $parentTransactionID): array
    {
        $batchSize = $this->config()->transaction->batchSize;
        if ($batchSize <= 0) {
            $batchSize = 100;
        }

        $legs = [];
        for ($offset = 0; ; $offset += $batchSize) {
            $batch = $this->getInflightTransactionsByParentID($parentTransactionID, $batchSize, $offset);
            foreach ($batch as $leg) {
                $legs[] = $leg;
            }
            if (\count($batch) < $batchSize) {
                return $legs;
            }
        }
    }

    /**
     * applyInflightActionToLeg commits or voids a single inflight leg using the
     * deterministic reference (derived from actionID) that makes asynq retries
     * idempotent.
     *
     * @throws \Throwable
     */
    protected function applyInflightActionToLeg(Transaction $leg, string $action, BigInteger $amount, string $actionID): void
    {
        $ref = self::inflightActionReference($leg->transactionID, $action, $actionID);
        if ($action === Queue::InflightActionVoid) {
            $this->voidInflightTransactionWithRef($leg->transactionID, $ref);
            return;
        }
        $this->commitInflightTransactionWithRef($leg->transactionID, $amount, $ref);
    }

    /**
     * RunInflightActionByParent commits or voids every inflight leg under a parent
     * transaction — the same expansion the synchronous endpoint performs — and is
     * the queued worker's entrypoint.
     *
     * retryable is true when at least one leg failed with a transient error (lock
     * contention, fetch failure, not-yet-visible), so the asynq task should retry;
     * already-finalized legs are terminal, skipped, and never block the ack.
     *
     * Go returns `(retryable bool, err error)` and `retryable` is true exactly
     * when `err` is non-nil, so the PHP port THROWS the transient error (the
     * caller retries on any exception) and returns `false` (not retryable)
     * whenever it returns normally.
     *
     * @return bool always false: a retryable failure is signalled by throwing
     *
     * @throws \Throwable the fetch failure or the last transient per-leg error (retryable)
     */
    public function runInflightActionByParent(string $parentTransactionID, string $action, BigInteger $amount, string $actionID): bool
    {
        $span = Tracer::get('blnk.transactions')->startSpan('RunInflightActionByParent');
        try {
            try {
                $legs = $this->collectInflightLegs($parentTransactionID);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err; // a fetch failure is transient
            }
            if (\count($legs) === 0) {
                // Nothing inflight under this parent: already finalized or never existed.
                $span->setAttribute('event', 'No inflight legs to process'); // span.AddEvent
                return false;
            }

            $applied = 0;
            $skipped = 0;
            $lastTransientErr = null;
            foreach ($legs as $leg) {
                try {
                    $this->applyInflightActionToLeg($leg, $action, $amount, $actionID);
                    $applied++;
                } catch (\Throwable $err) {
                    if ($this->isTerminalInflightError($err)) {
                        // Expected on a retry once a leg is already finalized; benign.
                        $skipped++;
                        Log::get()->debug('inflight leg already finalized; skipping (idempotent)', [
                            'transaction_id' => $leg->transactionID,
                            'reason' => self::classifyInflightError($err),
                        ]);
                    } else {
                        $span->recordError($err);
                        $lastTransientErr = $err;
                    }
                }
            }

            if ($lastTransientErr !== null) {
                throw $lastTransientErr; // retryable
            }

            Log::get()->info('inflight action processed', [
                'parent_transaction_id' => $parentTransactionID,
                'action' => $action,
                'applied' => $applied,
                'skipped' => $skipped,
            ]);
            return false;
        } finally {
            $span->end();
        }
    }

    /**
     * preValidateInflightAction performs the read-only checks an inflight commit/void
     * would perform, without mutating or persisting anything. It lets the API reject
     * an obviously-invalid request (not inflight, already finalized, amount too high)
     * synchronously before enqueuing, while the worker remains the authority.
     *
     * @throws \Throwable
     */
    protected function preValidateInflightAction(string $transactionID, string $action, ?BigInteger $amount): Transaction
    {
        $transaction = $this->fetchAndValidateInflightTransaction($transactionID);

        $amountLeft = $this->calculateRemainingAmount($transaction);

        $this->checkTransactionCommitStatus($amountLeft);

        if ($action === Queue::InflightActionCommit && $amount !== null) {
            $this->validateRequestedAmount($transaction, $amount, $amountLeft);
        }

        return $transaction;
    }

    /**
     * QueueInflightAction pre-validates a commit/void and enqueues it for the worker.
     * It returns the still-inflight parent transaction (for the API response) and
     * ErrInflightActionQueued if an action for the same transaction is already queued
     * (PHP: the exception thrown by Queue::enqueueInflightAction propagates).
     *
     * @throws \Throwable
     */
    public function queueInflightAction(string $transactionID, ?BigInteger $amount, string $action): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('QueueInflightAction');
        try {
            try {
                $transaction = $this->preValidateInflightAction($transactionID, $action, $amount);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            $preciseAmount = '0';
            if ($amount !== null) {
                $preciseAmount = (string) $amount;
            }

            $payload = new InflightActionPayload(
                $transactionID,
                $action,
                $preciseAmount,
                ModelHelpers::generateUUIDWithSuffix('act')
            );
            try {
                $this->queue->enqueueInflightAction($payload);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            return $transaction;
        } finally {
            $span->end();
        }
    }

    /**
     * classifyInflightError maps the unstructured errors emitted by
     * CommitInflightTransaction / VoidInflightTransaction to stable codes so
     * bulk callers can branch on them programmatically instead of regex-matching
     * human-readable messages.
     *
     * Codes are intentionally action-agnostic where possible. ALREADY_VOIDED and
     * ALREADY_COMMITTED are reported as observed — a void on an already-committed
     * txn surfaces ALREADY_COMMITTED, and vice versa.
     *
     * ClassifyInflightError maps an inflight commit/void error to a stable code
     * (see classifyInflightError) so callers outside this package — e.g. the queued
     * bulk API path — can report per-item codes without regex-matching messages.
     * (Both Go functions map to this single public method.)
     */
    public static function classifyInflightError(?\Throwable $err): string
    {
        if ($err === null) {
            return '';
        }
        $msg = $err->getMessage();
        switch (true) {
            case str_contains($msg, 'transaction not found'):
            case str_contains($msg, 'no rows in result set'):
            case str_contains($msg, 'not found'):
                return 'NOT_FOUND';
            case str_contains($msg, 'has already been voided'):
                return 'ALREADY_VOIDED';
            case str_contains($msg, 'Transaction already committed'):
            case str_contains($msg, 'cannot void. Transaction already committed'):
                return 'ALREADY_COMMITTED';
            case str_contains($msg, 'not in inflight status'):
                return 'NOT_INFLIGHT';
            case str_contains($msg, 'cannot commit more than'):
                return 'INVALID_AMOUNT';
            case str_contains($msg, 'failed to acquire lock'):
                return 'LOCKED';
            default:
                return 'INTERNAL_ERROR';
        }
    }

    /**
     * BulkInflightUpdate voids or commits a list of independently-created
     * inflight transactions in parallel.
     *
     * Each id is processed in its own goroutine via a worker pool of size
     * `maxWorkers` (clamped to [1, 16]). Per-item failures are reported in the
     * returned result slice and do not abort the rest of the batch; the returned
     * error is non-nil only for input validation failures (empty list, too many
     * items).
     *
     * Idempotency: bulk has no batch-wide idempotency key. Retries that include
     * already-processed ids will see those ids surface as ALREADY_VOIDED /
     * ALREADY_COMMITTED, which callers should treat as success-equivalent for
     * retry semantics.
     *
     * Results are returned in the same order as the input.
     *
     * The PHP port has no goroutines: the worker-pool size is clamped exactly as
     * in Go but the items are processed sequentially, in input order.
     *
     * @param int $action one of the {@see BulkInflightAction} constants
     * @param BulkInflightItem[] $items
     *
     * @return BulkInflightOutcome[]
     *
     * @throws \RuntimeException "transaction_ids cannot be empty"
     */
    public function bulkInflightUpdate(int $action, array $items, int $maxWorkers): array
    {
        if (\count($items) === 0) {
            throw new \RuntimeException('transaction_ids cannot be empty');
        }
        if ($maxWorkers < 1) {
            $maxWorkers = 4;
        }
        if ($maxWorkers > 16) {
            $maxWorkers = 16;
        }
        if ($maxWorkers > \count($items)) {
            $maxWorkers = \count($items);
        }

        $results = [];
        foreach (array_values($items) as $idx => $item) {
            $txn = null;
            $err = null;
            try {
                switch ($action) {
                    case BulkInflightAction::BulkInflightCommit:
                        $txn = $this->commitInflightTransaction($item->transactionID, $item->amount);
                        break;
                    case BulkInflightAction::BulkInflightVoid:
                        $txn = $this->voidInflightTransaction($item->transactionID);
                        break;
                    default:
                        throw new \RuntimeException(sprintf('unknown bulk inflight action: %d', $action));
                }
            } catch (\Throwable $e) {
                $err = $e;
            }
            $results[$idx] = new BulkInflightOutcome(
                $item->transactionID,
                $txn,
                $err,
                self::classifyInflightError($err)
            );
        }
        return $results;
    }
}
