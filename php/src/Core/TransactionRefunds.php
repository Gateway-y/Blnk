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
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;

/**
 * TransactionRefunds is the port of the root-package file
 * `transaction_refunds.go`: refund workers and the refund flow (lookup in DB
 * or queue, eligibility validation, reversed transaction, queueing).
 *
 * Conventions: `l.Config()` → {@see Blnk::config()}; `fmt.Errorf("...: %w", err)`
 * → {@see Blnk::wrapError()}; `err.Error()` → {@see Blnk::goErrorString()};
 * `span.AddEvent` → `$span->setAttribute('event', ...)`.
 */
trait TransactionRefunds
{
    /**
     * RefundWorker processes refund transactions from the jobs channel and sends the results to the results channel.
     * It starts a tracing span, processes each transaction, and records relevant events and errors.
     *
     * Parameters:
     * - jobs <-chan *model.Transaction: A channel from which transactions are received for processing.
     * - results chan<- BatchJobResult: A channel to which the results of the processing are sent.
     * - wg *sync.WaitGroup: A wait group to synchronize the completion of the worker.
     * - amount float64: The amount to be processed in the transaction.
     *
     * PHP `transactionWorker` contract: the jobs iterable is consumed in order and
     * the results are returned as a list (see {@see TransactionService}).
     *
     * @param iterable<Transaction> $jobs
     *
     * @return BatchJobResult[]
     */
    public function refundWorker(iterable $jobs, ?BigInteger $amount): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('RefundWorker');
        try {
            $results = [];
            foreach ($jobs as $originalTxn) {
                try {
                    $queuedRefundTxn = $this->refundTransaction($originalTxn->transactionID, $originalTxn->skipQueue);
                } catch (\Throwable $err) {
                    $results[] = new BatchJobResult(null, $err);
                    $span->recordError($err);
                    continue;
                }
                $results[] = new BatchJobResult($queuedRefundTxn);
                $span->setAttribute('event', 'Refund processed'); // span.AddEvent
                $span->setAttribute('transaction.id', $queuedRefundTxn->transactionID);
            }
            return $results;
        } finally {
            $span->end();
        }
    }

    /**
     * RefundWorkerWithOptions returns a transactionWorker that refunds each
     * job with an explicit skipQueue decision, rather than inheriting it
     * from the original transaction.
     *
     * This exists because skip_queue is a request-time routing flag and is
     * deliberately NOT persisted (see Transaction Lifecycle docs). The
     * original transaction fetched from storage for a refund therefore
     * always deserializes SkipQueue=false, so the plain RefundWorker can
     * never produce a synchronous refund. RefundWorkerWithOptions lets the
     * caller — e.g. the /refund-transaction API handler honoring a
     * "skip_queue" field in the request body — choose synchronous
     * processing for time-sensitive refunds.
     *
     * RefundWorker is left unchanged; this is purely additive.
     *
     * @return \Closure(iterable<Transaction> $jobs, ?BigInteger $amount): BatchJobResult[] a `transactionWorker`
     */
    public function refundWorkerWithOptions(bool $skipQueue): \Closure
    {
        return function (iterable $jobs, ?BigInteger $amount) use ($skipQueue): array {
            $span = Tracer::get('blnk.transactions')->startSpan('RefundWorkerWithOptions');
            try {
                $span->setAttribute('refund.skip_queue', $skipQueue);

                $results = [];
                foreach ($jobs as $originalTxn) {
                    try {
                        $queuedRefundTxn = $this->refundTransaction($originalTxn->transactionID, $skipQueue);
                    } catch (\Throwable $err) {
                        $results[] = new BatchJobResult(null, $err);
                        $span->recordError($err);
                        continue;
                    }
                    $results[] = new BatchJobResult($queuedRefundTxn);
                    $span->setAttribute('event', 'Refund processed'); // span.AddEvent
                    $span->setAttribute('transaction.id', $queuedRefundTxn->transactionID);
                }
                return $results;
            } finally {
                $span->end();
            }
        };
    }

    /**
     * getOriginalTransactionForRefund retrieves the transaction to refund from
     * the database, falling back to the queue when it has not been persisted yet.
     *
     * Go checks `errors.Is(err, sql.ErrNoRows)` (PHP: a {@see NotFoundException}
     * anywhere in the `previous` chain) or the datasource's
     * "Transaction with ID '<id>' not found" message.
     *
     * @throws \RuntimeException "transaction <id> not found in DB or queue[: ...]" / "failed to get transaction <id> from DB: ..."
     */
    protected function getOriginalTransactionForRefund(string $transactionID): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('getOriginalTransactionForRefund');
        try {
            try {
                $originalTxn = $this->datasource->getTransaction($transactionID);
            } catch (\Throwable $err) {
                // Check if the error is due to no row found
                $isNoRows = false;
                for ($e = $err; $e !== null; $e = $e->getPrevious()) {
                    if ($e instanceof NotFoundException) {
                        $isNoRows = true;
                        break;
                    }
                }
                if ($isNoRows || str_contains(self::goErrorString($err), sprintf("Transaction with ID '%s' not found", $transactionID))) {
                    // Check the queue for the transaction
                    try {
                        $queuedTxn = $this->queue->getTransactionFromQueue($transactionID);
                    } catch (\Throwable $queueErr) {
                        $span->recordError($queueErr);
                        // Return the original DB error if queue retrieval also fails
                        throw self::wrapError(sprintf('transaction %s not found in DB or queue', $transactionID), $err);
                    }
                    if ($queuedTxn === null) {
                        $notFound = new \RuntimeException(sprintf('transaction %s not found in DB or queue', $transactionID));
                        $span->recordError($notFound);
                        throw $notFound;
                    }
                    $span->setAttribute('event', 'Transaction found in queue'); // span.AddEvent
                    $span->setAttribute('transaction.id', $transactionID);
                    return $queuedTxn; // Return the transaction found in the queue
                }
                // Return other database errors directly
                $span->recordError($err);
                throw self::wrapError(sprintf('failed to get transaction %s from DB', $transactionID), $err);
            }
            $span->setAttribute('event', 'Transaction found in DB'); // span.AddEvent
            $span->setAttribute('transaction.id', $transactionID);
            return $originalTxn;
        } finally {
            $span->end();
        }
    }

    /**
     * validateTransactionForRefund checks if the given transaction is eligible for a refund.
     *
     * @throws \RuntimeException "transaction <id> cannot be refunded in status <status> (only APPLIED or VOID)" /
     *                           "failed to check if transaction <id> was already refunded: ..." /
     *                           "transaction <id> has already been refunded"
     */
    protected function validateTransactionForRefund(Transaction $originalTxn): void
    {
        $span = Tracer::get('blnk.transactions')->startSpan('validateTransactionForRefund');
        try {
            // Only settled (APPLIED) or voided originals are refundable. Refunding a
            // QUEUED/INFLIGHT/SCHEDULED original would reverse funds that were never
            // settled.
            switch ($originalTxn->status) {
                case self::StatusApplied:
                case self::StatusVoid:
                    // refundable
                    break;
                default:
                    $err = new \RuntimeException(sprintf('transaction %s cannot be refunded in status %s (only APPLIED or VOID)', $originalTxn->transactionID, $originalTxn->status));
                    $span->recordError($err);
                    throw $err;
            }

            // Check if the transaction has already been refunded
            try {
                $isRefunded = $this->datasource->isTransactionRefunded($originalTxn);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw self::wrapError(sprintf('failed to check if transaction %s was already refunded', $originalTxn->transactionID), $err);
            }
            if ($isRefunded) {
                $err = new \RuntimeException(sprintf('transaction %s has already been refunded', $originalTxn->transactionID));
                $span->recordError($err);
                throw $err;
            }

            $span->setAttribute('event', 'Transaction validated for refund'); // span.AddEvent
            $span->setAttribute('transaction.id', $originalTxn->transactionID);
        } finally {
            $span->end();
        }
    }

    /**
     * prepareRefundTransaction creates and configures a new transaction object for the refund.
     */
    protected static function prepareRefundTransaction(Transaction $originalTxn, bool $skipQueue): Transaction
    {
        $newTransaction = clone $originalTxn; // Create a copy
        $newTransaction->transactionID = ModelHelpers::generateUUIDWithSuffix('txn');
        $newTransaction->reference = sprintf('%s_refund', $originalTxn->transactionID);
        $newTransaction->parentTransaction = $originalTxn->transactionID;
        $newTransaction->source = $originalTxn->destination; // Swap source and destination
        $newTransaction->destination = $originalTxn->source;
        $newTransaction->allowOverdraft = true;
        $newTransaction->skipQueue = $skipQueue;

        // Adjust status based on original status for proper processing
        if ($originalTxn->status === self::StatusVoid) {
            // If original was voided, the refund should process like an inflight reversal
            $newTransaction->inflight = true;
            $newTransaction->status = ''; // Reset status to let QueueTransaction handle it
        } else {
            $newTransaction->status = ''; // Reset status for standard queuing
            $newTransaction->inflight = false;
        }

        return $newTransaction;
    }

    /**
     * RefundTransaction processes a refund for a given transaction by its ID.
     * It starts a tracing span, retrieves the original transaction, validates its status, creates a new refund transaction, and queues it.
     *
     * Parameters:
     * - transactionID string: The ID of the transaction to be refunded.
     *
     * Returns:
     * - *model.Transaction: A pointer to the refunded Transaction model.
     * - error: An error if the transaction could not be refunded (thrown).
     *
     * @throws \RuntimeException "failed to acquire lock for refund: ..." / "failed to queue refund transaction <id>: ..."
     * @throws \Throwable
     */
    public function refundTransaction(string $transactionID, bool $skipQueue): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('RefundTransaction');
        try {
            // Serialize refunds of the same transaction so the already-refunded check
            // can't be raced into a double refund.
            $lockKey = sprintf('refund:%s', $transactionID);
            $locker = new Locker($this->redis, $lockKey, ModelHelpers::generateUUIDWithSuffix('loc'));
            try {
                $locker->lock($this->config()->transaction->lockDuration);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw self::wrapError('failed to acquire lock for refund', $err);
            }

            try {
                // 1. Retrieve the original transaction (from DB or Queue)
                try {
                    $originalTxn = $this->getOriginalTransactionForRefund($transactionID);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err; // Error includes context from the helper function
                }

                // 2. Validate if the transaction can be refunded
                try {
                    $this->validateTransactionForRefund($originalTxn);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err; // Error includes context from the helper function
                }

                // 3. Prepare the refund transaction object
                $refundTxnObject = self::prepareRefundTransaction($originalTxn, $skipQueue);

                // 4. Queue the refund transaction for processing
                try {
                    $queuedRefundTxn = $this->queueTransaction($refundTxnObject);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw self::wrapError(sprintf('failed to queue refund transaction %s', $refundTxnObject->transactionID), $err);
                }

                $span->setAttribute('event', 'Refund transaction queued'); // span.AddEvent
                $span->setAttribute('refund.transaction.id', $queuedRefundTxn->transactionID);
                return $queuedRefundTxn;
            } finally {
                // Go: defer l.releaseSingleLock(ctx, locker)
                $this->releaseSingleLock($locker);
            }
        } finally {
            $span->end();
        }
    }
}
