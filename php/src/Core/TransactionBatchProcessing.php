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
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;

/**
 * TransactionBatchProcessing is the port of the root-package file
 * `transaction_batch_processing.go`: paging the transactions of a parent and
 * feeding them to a `transactionWorker` (commit, void, refund).
 *
 * Concurrency divergence (documented, PORTING.md "Concurrency"): Go runs
 * `maxWorkers` worker goroutines over a jobs channel filled by a fetching
 * goroutine and collects results from a results channel. The PHP port feeds
 * the worker a lazy generator of jobs ({@see self::fetchTransactions()}, one
 * page of `batch_size` at a time, exactly like the Go fetch loop) and reads
 * the returned result list; `maxWorkers` and `max_queue_size` (the channel
 * capacity) are accepted for parity but do not change the sequential
 * execution. The callable contracts (`getTxns`, `transactionWorker`) are
 * documented on {@see TransactionService}.
 */
trait TransactionBatchProcessing
{
    /**
     * GetRefundableTransactionsByParentID retrieves the refundable transactions
     * of a parent (satisfies the `getTxns` callable contract).
     *
     * @return Transaction[]
     *
     * @throws \Throwable
     */
    public function getRefundableTransactionsByParentID(string $parentTransactionID, int $batchSize, int $offset): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('GetRefundableTransactionsByParentID');
        try {
            try {
                $transactions = $this->datasource->getRefundableTransactionsByParentID($parentTransactionID, $batchSize, $offset);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }
            $span->setAttribute('parent_transaction_id', $parentTransactionID);
            self::spanEvent($span, 'Refundable transactions retrieved');
            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * ProcessTransactionInBatches processes transactions in batches or streams them based on the provided mode.
     * It starts a tracing span, initializes worker pools, fetches transactions, and processes them concurrently.
     *
     * Parameters:
     * - parentTransactionID string: The ID of the parent transaction.
     * - amount *big.Int: The amount to be processed in the transaction.
     * - maxWorkers int: The maximum number of workers to process transactions concurrently.
     * - streamMode bool: A flag indicating whether to process transactions in streaming mode.
     * - gt getTxns: A function to retrieve transactions in batches.
     * - tw transactionWorker: A function to process transactions.
     *
     * Returns:
     * - []*model.Transaction: A slice of pointers to the processed Transaction models
     *   (empty in streaming mode, as Go returns nil).
     * - error: An error if the transactions could not be processed (thrown; Go
     *   also returns the partially collected transactions alongside it).
     *
     * @param callable(string, int, int): Transaction[] $gt
     * @param callable(iterable<Transaction>, ?BigInteger): BatchJobResult[] $tw
     *
     * @return Transaction[]
     *
     * @throws \RuntimeException "error occurred during processing: [...]" when any job failed
     * @throws \Throwable the fetch error when a page could not be retrieved
     */
    public function processTransactionInBatches(string $parentTransactionID, ?BigInteger $amount, int $maxWorkers, bool $streamMode, callable $gt, callable $tw): array
    {
        // Start a tracing span
        $span = Tracer::get('blnk.transactions')->startSpan('ProcessTransactionInBatches');
        try {
            $batchSize = $this->config()->transaction->batchSize;
            $maxQueueSize = $this->config()->transaction->maxQueueSize; // Go: capacity of the jobs/results channels
            $span->setAttribute('max_workers', $maxWorkers);
            $span->setAttribute('max_queue_size', $maxQueueSize);

            // Slice to collect all processed transactions and errors
            $allTxns = [];
            $allErrors = [];

            if (!$streamMode) {
                // Fetch transactions in batches and hand them to the worker(s);
                // a fetch failure surfaces through the jobs generator (Go: errChan).
                try {
                    $results = $tw($this->fetchTransactions($parentTransactionID, $batchSize, $gt), $amount);
                } catch (\Throwable $err) {
                    $span->recordError($err);
                    throw $err;
                }

                self::processResults($results, $allTxns, $allErrors);

                if (\count($allErrors) > 0) {
                    // Log errors and return a combined error
                    $messages = [];
                    foreach ($allErrors as $err) {
                        Log::get()->error(sprintf('Error during processing: %s', self::goErrorString($err)));
                        $span->recordError($err);
                        $messages[] = self::goErrorString($err);
                    }
                    // Go: fmt.Errorf("error occurred during processing: %v", allErrors) — a []error prints as "[err1 err2]".
                    throw new \RuntimeException(sprintf('error occurred during processing: [%s]', implode(' ', $messages)));
                }

                self::spanEvent($span, 'Processed all transactions in batches');
                return $allTxns;
            }

            // Stream mode: just fetch transactions and send to jobs channel.
            // Go never reads the fetch error (buffered errChan) nor the results and
            // returns nil, nil once fetching completes.
            try {
                $tw($this->fetchTransactions($parentTransactionID, $batchSize, $gt), $amount);
            } catch (\Throwable $err) {
                $span->recordError($err);
            }
            self::spanEvent($span, 'Processed all transactions in streaming mode');
            return [];
        } finally {
            $span->end();
        }
    }

    /**
     * processResults processes the results from the results channel, collecting transactions and errors.
     * It locks access to shared data to ensure thread safety and signals completion when done.
     *
     * Parameters:
     * - results chan BatchJobResult: The channel from which to receive results (PHP: the returned list).
     * - mu *sync.Mutex: A mutex to synchronize access to shared data (PHP: dropped).
     * - allTxns *[]*model.Transaction: A slice to collect all processed transactions.
     * - allErrors *[]error: A slice to collect all errors encountered during processing.
     * - done chan struct{}: A channel to signal when processing is complete (PHP: dropped).
     *
     * @param iterable<BatchJobResult> $results
     * @param Transaction[] $allTxns
     * @param \Throwable[] $allErrors
     */
    protected static function processResults(iterable $results, array &$allTxns, array &$allErrors): void
    {
        foreach ($results as $result) {
            if ($result->error !== null) {
                // Log any error encountered during transaction processing
                Log::get()->error(sprintf('Error processing transaction: %s', self::goErrorString($result->error)));
                $allErrors[] = $result->error;
            } elseif ($result->txn !== null) {
                $allTxns[] = $result->txn;
            } else {
                // Handle the case where the result contains no transaction and no error
                Log::get()->warning('Received a result with no transaction and no error');
            }
        }
    }

    /**
     * fetchTransactions fetches transactions in batches and sends them to the jobs channel.
     * It starts a tracing span, iterates through the transactions, and handles context cancellation and errors.
     *
     * Parameters:
     * - parentTransactionID string: The ID of the parent transaction.
     * - batchSize int: The number of transactions to retrieve in a batch.
     * - gt getTxns: A function to retrieve transactions in batches.
     * - jobs chan *model.Transaction: The channel to send fetched transactions to (PHP: the yielded values).
     * - errChan chan error: The channel to send errors to (PHP: thrown from the generator).
     *
     * The generator is lazy: the next page is fetched only once the worker has
     * consumed the previous one, so the page offset (`offset += len(txns)`)
     * advances exactly as in Go.
     *
     * @param callable(string, int, int): Transaction[] $gt
     *
     * @return \Generator<int, Transaction>
     *
     * @throws \Throwable when a page could not be fetched
     */
    protected function fetchTransactions(string $parentTransactionID, int $batchSize, callable $gt): \Generator
    {
        $span = Tracer::get('blnk.transactions')->startSpan('FetchTransactions');
        try {
            $offset = 0;
            while (true) {
                // Fetch the transactions in batches
                try {
                    $txns = $gt($parentTransactionID, $batchSize, $offset);
                } catch (\Throwable $err) {
                    // Log and send error if fetching transactions fails
                    Log::get()->error(sprintf('Error fetching transactions: %s', self::goErrorString($err)));
                    $span->recordError($err);
                    throw $err;
                }
                if (\count($txns) === 0) {
                    // Stop if no more transactions are found
                    self::spanEvent($span, 'No more transactions to fetch');
                    return;
                }

                // Send fetched transactions to the jobs channel
                foreach ($txns as $txn) {
                    yield $txn; // Send the transaction to be processed
                }

                // Increment offset to fetch the next batch
                $offset += \count($txns);
            }
        } finally {
            $span->end();
        }
    }
}
