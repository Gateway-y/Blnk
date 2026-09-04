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

/**
 * TransactionService is the port of the root-package file `transaction.go`.
 *
 * The Go file declares no `*Blnk` methods; it holds the package-level
 * transaction constants, the concurrency limiters, and the shared internal
 * types of the transaction pipeline. Per the Core contract every Go file maps
 * to one trait composed into {@see Blnk}, so the constants live here as trait
 * constants (reachable as `Blnk::StatusQueued` etc. — PHP forbids reading a
 * trait constant through the trait name) and the standalone types became
 * their own classes in this namespace:
 *
 *  - `queuedBatchPostCommitWork`  → {@see QueuedBatchPostCommitWork}
 *  - `queuedBatchPersistResult`   → {@see QueuedBatchPersistResult}
 *  - `transactionExecutionPlan`   → {@see TransactionExecutionPlan}
 *  - `transactionExecutionResult` → {@see TransactionExecutionResult}
 *  - `BatchJobResult`             → {@see BatchJobResult}
 *
 * `var tracer = otel.Tracer("blnk.transactions")` maps to
 * `Tracer::get('blnk.transactions')`, used inline by every method of the
 * transaction traits (see Blnk\Internal\Traces\Tracer, a debug-logging no-op).
 *
 * Function types (Go has `getTxns` and `transactionWorker`; PHP has no
 * function types, so these are the callable contracts every producer and
 * consumer in the port adheres to):
 *
 *  - getTxns is a function type that retrieves a batch of transactions based on the parent transaction ID, batch size, and offset.
 *
 *      callable(string $parentTransactionID, int $batchSize, int $offset): \Blnk\Model\Transaction[]
 *
 *    Parameters:
 *    - parentTransactionID string: The ID of the parent transaction.
 *    - batchSize int: The number of transactions to retrieve in a batch.
 *    - offset int64: The offset for pagination.
 *
 *    Returns:
 *    - []*model.Transaction: A slice of pointers to the retrieved Transaction models.
 *    - error: An error if the transactions could not be retrieved (PHP: thrown).
 *
 *  - transactionWorker is a function type that processes transactions from a job channel and sends the results to a results channel.
 *
 *      callable(iterable<\Blnk\Model\Transaction> $jobs, ?\Brick\Math\BigInteger $amount): BatchJobResult[]
 *
 *    Parameters:
 *    - jobs <-chan *model.Transaction: A channel from which transactions are received for processing
 *      (PHP: the iterable of transactions to process, in order).
 *    - results chan<- BatchJobResult: A channel to which the results of the processing are sent
 *      (PHP: the returned list of BatchJobResult, one per job, in job order).
 *    - wg *sync.WaitGroup: A wait group to synchronize the completion of the worker (PHP: dropped, workers run synchronously).
 *    - amount *big.Int: The amount to be processed in the transaction.
 */
trait TransactionService
{
    public const StatusQueued = 'QUEUED';
    public const StatusApplied = 'APPLIED';
    public const StatusScheduled = 'SCHEDULED';
    public const StatusRejected = 'REJECTED';

    /**
     * Go: `var asyncBulkSemaphore = semaphore.NewWeighted(100)` — max 100 concurrent
     * async bulk operations. PHP has no goroutines; the weight is kept as the
     * documented limit for the bulk pass (see transaction_bulk.go).
     */
    public const asyncBulkSemaphoreWeight = 100;

    /**
     * Go: `var asyncTxnSemaphore = semaphore.NewWeighted(20)` — max 20 concurrent
     * async transaction processors.
     */
    public const asyncTxnSemaphoreWeight = 20;

    /**
     * balanceMonitorSem bounds the number of concurrent balance-monitor checks
     * spawned by post-commit work across all transactions.
     * Go: `var balanceMonitorSem = make(chan struct{}, 32)`; monitor checks run
     * inline in the PHP port, so this is documentation only.
     */
    public const balanceMonitorSemSize = 32;

    public const maxQueuedCoalescingBatchSize = 10000;

    // queuedCoalescingScope (Go: `type queuedCoalescingScope string`).
    public const queuedCoalescingScopePair = 'pair';
    public const queuedCoalescingScopeSource = 'source';
    public const queuedCoalescingScopeDestination = 'destination';

    // transactionExecutionMode (Go: `type transactionExecutionMode string`).
    public const transactionExecutionModeSingle = 'single';
    public const transactionExecutionModeQueuedBatch = 'queued_batch';
    public const transactionExecutionModeHotQueuedBatch = 'hot_queued_batch';
}
