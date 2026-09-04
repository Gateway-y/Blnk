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

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\Log;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\BulkTransactionRequest;
use Blnk\Model\BulkTransactionResult;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;

/**
 * TransactionBulk is the port of the root-package file `transaction_bulk.go`:
 * bulk (batch) transaction creation with atomic rollback and webhook
 * notifications.
 *
 * Concurrency divergence (documented, PORTING.md "Concurrency"): the
 * `run_async` goroutine (bounded by asyncBulkSemaphore, 100 permits, with a
 * 30-minute background context) runs inline — the batch is fully processed,
 * and its webhooks are sent, before the "processing" result is returned. The
 * semaphore is mirrored by an in-process counter so the same
 * "too many async bulk operations in progress" error exists, although a
 * single-threaded PHP process can never exhaust it.
 */
trait TransactionBulk
{
    /**
     * In-process analogue of Go's `asyncBulkSemaphore.TryAcquire(1)` /
     * `Release(1)` — the number of async bulk batches currently in flight,
     * bounded by {@see TransactionService::asyncBulkSemaphoreWeight}.
     */
    private static int $asyncBulkInFlight = 0;

    /**
     * processBulkTransactions queues every transaction of a batch in order,
     * stamping the batch ID as parent transaction and a 1-based "sequence"
     * metadata entry.
     *
     * @param Transaction[] $transactions
     *
     * @throws \RuntimeException "failed to queue transaction %d (Reference: %s, Source: %s, Destination: %s, Amount: %.2f): ..."
     */
    protected function processBulkTransactions(array $transactions, string $batchID, bool $inflight, bool $skipQueue): void
    {
        foreach (array_values($transactions) as $i => $txn) {
            // Set transaction properties
            $txn->inflight = $inflight;
            $txn->skipQueue = $skipQueue; // Process synchronously within the batch context first
            $txn->parentTransaction = $batchID;

            // Add sequence number to metadata
            if ($txn->metaData === null) {
                $txn->metaData = [];
            }
            $txn->metaData['sequence'] = $i + 1;

            // Queue the transaction (which will record it if SkipQueue is true)
            try {
                $this->queueTransaction($txn);
            } catch (\Throwable $err) {
                // Create a more descriptive error that includes transaction reference details
                throw self::wrapError(sprintf(
                    'failed to queue transaction %d (Reference: %s, Source: %s, Destination: %s, Amount: %.2F)',
                    $i + 1,
                    $txn->reference,
                    $txn->source,
                    $txn->destination,
                    $txn->amount
                ), $err);
            }
        }
    }

    /**
     * rollbackBatchTransactions performs a rollback of transactions in a batch
     * Returns the action performed (voided/refunded) and any error that occurred
     * (PHP: the action is returned, the error is thrown after being logged).
     *
     * @throws \Throwable
     */
    protected function rollbackBatchTransactions(string $batchID, bool $isInflight): string
    {
        $rollbackErr = null;
        // Go's helpers return their action name alongside the error.
        $action = $isInflight ? 'voided' : 'refunded';

        try {
            if ($isInflight) {
                $action = $this->voidInflightBatchTransactions($batchID);
            } else {
                $action = $this->refundNonInflightBatchTransactions($batchID);
            }
        } catch (\Throwable $err) {
            $rollbackErr = $err;
        }

        $this->logRollbackResult($batchID, $action, $rollbackErr);
        if ($rollbackErr !== null) {
            throw $rollbackErr;
        }
        return $action;
    }

    /**
     * voidInflightBatchTransactions voids all inflight transactions in a batch
     *
     * @return string "voided"
     *
     * @throws \Throwable
     */
    protected function voidInflightBatchTransactions(string $batchID): string
    {
        $this->processTransactionInBatches(
            $batchID,
            BigInteger::zero(),
            1, // Assuming 1 worker is sufficient for rollback, adjust if needed
            false,
            $this->getInflightTransactionsByParentID(...),
            $this->voidWorker(...)
        );
        return 'voided';
    }

    /**
     * refundNonInflightBatchTransactions refunds all non-inflight transactions in a batch
     *
     * @return string "refunded"
     *
     * @throws \Throwable
     */
    protected function refundNonInflightBatchTransactions(string $batchID): string
    {
        $this->processTransactionInBatches(
            $batchID,
            BigInteger::zero(),
            1, // Assuming 1 worker is sufficient for rollback, adjust if needed
            false,
            $this->getRefundableTransactionsByParentID(...),
            $this->refundWorker(...)
        );
        return 'refunded';
    }

    /**
     * logRollbackResult logs the outcome of a rollback operation
     */
    protected function logRollbackResult(string $batchID, string $action, ?\Throwable $err): void
    {
        if ($err !== null) {
            Log::get()->error('failed to rollback batch transactions', ['error' => $err->getMessage(), 'batch_id' => $batchID]);
        } else {
            Log::get()->info('successfully rolled back atomic batch', [
                'batch_id' => $batchID,
                'action' => $action,
            ]);
        }
    }

    /**
     * sendBulkTransactionWebhook sends a webhook notification for a bulk transaction result
     */
    protected function sendBulkTransactionWebhook(string $batchID, string $status, string $errorMsg, int $transactionCount): void
    {
        // Create payload with or without error info depending on status
        $payload = [
            'batch_id' => $batchID,
            'status' => $status,
            'timestamp' => ModelHelpers::goTimeString(new \DateTimeImmutable('now')), // Go: time.Now()
        ];

        // Only include transaction count for success cases
        if ($status !== 'failed') {
            $payload['transaction_count'] = $transactionCount;
        }

        // Include error details for failure cases
        if ($status === 'failed' && $errorMsg !== '') {
            $payload['error'] = $errorMsg;
        }

        try {
            $this->sendWebhook(new NewWebhook(
                'bulk_transaction.' . $status,
                $payload
            ));
        } catch (\Throwable $err) {
            Log::get()->error('failed to send webhook notification', ['error' => $err->getMessage(), 'batch_id' => $batchID]);
        }
    }

    /**
     * handleAsyncBulkTransactionFailure handles failures in asynchronous processing
     * and builds a detailed error message including rollback status
     */
    protected function handleAsyncBulkTransactionFailure(\Throwable $err, string $batchID, bool $isAtomic, bool $isInflight): void
    {
        Log::get()->error('async bulk transaction error', ['error' => $err->getMessage(), 'batch_id' => $batchID]);

        if ($isAtomic) {
            try {
                $action = $this->rollbackBatchTransactions($batchID, $isInflight);
                $errorMessage = sprintf('%s. All transactions in this batch have been %s.', self::goErrorString($err), $action);
                Log::get()->info('successfully rolled back async batch', [
                    'batch_id' => $batchID,
                    'action' => $action,
                ]);
            } catch (\Throwable $rollbackErr) {
                $errorMessage = sprintf('%s. Failed to roll back all transactions: %s', self::goErrorString($err), self::goErrorString($rollbackErr));
                Log::get()->error('failed to roll back batch', ['error' => $rollbackErr->getMessage(), 'batch_id' => $batchID]);
            }
        } else {
            // If not atomic, just include the original error and note about no rollback
            $errorMessage = sprintf('%s. Previous transactions were not rolled back.', self::goErrorString($err));
        }

        // Send webhook with the complete error message including rollback status
        $this->sendBulkTransactionWebhook($batchID, 'failed', $errorMessage, 0); // 0 count for failed batch
    }

    /**
     * CreateBulkTransactions handles the creation of multiple transactions in a batch.
     * If atomic is true: Any failure will cause all transactions to be rolled back (or voided if inflight).
     * If atomic is false: Failures will stop processing but previous transactions remain unaffected.
     * If run_async is true: Processing happens in background with webhook notifications.
     *
     * @throws ApiErrorException RATE_LIMITED "too many async bulk operations in progress, try again later" (Go: nil result + APIError)
     * @throws BulkTransactionException on synchronous failure, carrying the "failed" result (Go: result + error)
     */
    public function createBulkTransactions(BulkTransactionRequest $req): BulkTransactionResult
    {
        $span = Tracer::get('blnk.transactions')->startSpan('Blnk.CreateBulkTransactions');
        try {
            // Generate batch ID (parent transaction ID)
            $batchID = ModelHelpers::generateUUIDWithSuffix('bulk');
            $span->setAttribute('batch.id', $batchID);

            $transactions = $req->transactions ?? [];

            // Check if this should be run asynchronously
            if ($req->runAsync) {
                if (self::$asyncBulkInFlight >= self::asyncBulkSemaphoreWeight) {
                    throw ApiErrorException::newApiError(
                        ErrorCode::ErrRateLimited,
                        'too many async bulk operations in progress, try again later',
                        null
                    );
                }
                self::$asyncBulkInFlight++;

                // processBulkTransactions mutates each transaction (status, metadata,
                // parent); clone them so the background goroutine never races the caller
                // still holding the request's transactions.
                $asyncTxns = self::cloneTransactionsForAsync($transactions);

                // Start processing in background (Go: go func() with a 30-minute
                // background context; inline in the PHP port — see the trait doc).
                try {
                    Log::get()->info(sprintf(
                        'Starting async bulk transaction batch %s with %d transactions (atomic: %s, inflight: %s)',
                        $batchID,
                        \count($asyncTxns),
                        $req->atomic ? 'true' : 'false',
                        $req->inflight ? 'true' : 'false'
                    ));

                    // Process transactions in batch
                    $processErr = null;
                    try {
                        $this->processBulkTransactions($asyncTxns, $batchID, $req->inflight, $req->skipQueue);
                    } catch (\Throwable $e) {
                        $processErr = $e;
                    }

                    if ($processErr !== null) {
                        // Handle failure (rollback if atomic, send webhook)
                        $this->handleAsyncBulkTransactionFailure($processErr, $batchID, $req->atomic, $req->inflight);
                    } else {
                        // Send webhook notification for success
                        $status = 'inflight';
                        if (!$req->inflight) {
                            $status = 'applied';
                        }
                        $this->sendBulkTransactionWebhook($batchID, $status, '', \count($transactions));
                        Log::get()->info(sprintf('Completed async bulk transaction batch %s successfully', $batchID));
                    }
                } finally {
                    self::$asyncBulkInFlight--;
                }

                // Return immediate response indicating async processing started
                $result = new BulkTransactionResult();
                $result->batchID = $batchID;
                $result->status = 'processing'; // Indicate that it's running in the background
                return $result;
            }

            // Synchronous processing
            Log::get()->info(sprintf(
                'Starting sync bulk transaction batch %s with %d transactions (atomic: %s, inflight: %s)',
                $batchID,
                \count($transactions),
                $req->atomic ? 'true' : 'false',
                $req->inflight ? 'true' : 'false'
            ));

            // Process transactions in batch
            try {
                $this->processBulkTransactions($transactions, $batchID, $req->inflight, $req->skipQueue);
            } catch (\Throwable $err) {
                $span->recordError($err);
                Log::get()->error('sync bulk transaction error', ['error' => $err->getMessage(), 'batch_id' => $batchID]);

                if ($req->atomic) {
                    try {
                        $action = $this->rollbackBatchTransactions($batchID, $req->inflight);
                        $responseError = sprintf('%s. All transactions in this batch have been %s.', self::goErrorString($err), $action);
                    } catch (\Throwable $rollbackErr) {
                        $responseError = sprintf('%s. Failed to roll back all transactions: %s', self::goErrorString($err), self::goErrorString($rollbackErr));
                    }
                } else {
                    $responseError = sprintf('%s. Previous transactions were not rolled back.', self::goErrorString($err));
                }

                // Return error result for synchronous failure
                $result = new BulkTransactionResult();
                $result->batchID = $batchID;
                $result->status = 'failed';
                $result->error = $responseError;
                throw new BulkTransactionException($result, $err); // Return the error itself as well
            }

            // Synchronous success
            $status = 'inflight';
            if (!$req->inflight) {
                $status = 'applied';
            }

            Log::get()->info(sprintf('Completed sync bulk transaction batch %s successfully', $batchID));
            $result = new BulkTransactionResult();
            $result->batchID = $batchID;
            $result->status = $status;
            $result->transactionCount = \count($transactions);
            return $result;
        } finally {
            $span->end();
        }
    }
}
