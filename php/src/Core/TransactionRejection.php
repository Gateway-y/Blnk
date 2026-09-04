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

use Blnk\Internal\HotPairs\Router;
use Blnk\Internal\Log;
use Blnk\Internal\Metrics\Metrics;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Transaction;

/**
 * TransactionRejection is the port of the root-package file
 * `transaction_rejection.go`: persisting a rejected transaction, recording the
 * rejection metrics, and unwinding atomic bulk batches.
 */
trait TransactionRejection
{
    /**
     * RejectTransaction marks the transaction as rejected (storing the reason in
     * its metadata), persists it, records rejection metrics, unwinds the
     * parent batch when the transaction is atomic, and runs the post-transaction
     * actions (indexing + webhook).
     *
     * @throws \RuntimeException "parent transaction ID not found in meta data"
     * @throws \Throwable if the transaction could not be saved
     */
    public function rejectTransaction(Transaction $transaction, string $reason): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('RejectTransaction');
        try {
            // Update the transaction status to rejected
            $transaction->status = self::StatusRejected;

            // Initialize MetaData if it's nil and add the rejection reason
            if ($transaction->metaData === null) {
                $transaction->metaData = [];
            }
            $transaction->metaData['blnk_rejection_reason'] = $reason;

            // Persist the transaction with the updated status and metadata
            try {
                $transaction = $this->datasource->recordTransaction($transaction);
            } catch (\Throwable $err) {
                $span->recordError($err);
                Log::get()->error('failed to save transaction to db', ['error' => $err->getMessage()]);
                throw $err;
            }

            $span->setAttribute('event', 'Transaction rejected'); // span.AddEvent
            $span->setAttribute('transaction.id', $transaction->transactionID);

            // Record rejection metrics.
            $rejectionReason = self::categorizeRejectionReason($reason);
            Metrics::transactionRejectedTotal()->add(1, ['reason' => $rejectionReason]);
            Metrics::transactionTotal()->add(1, [
                'status' => self::StatusRejected,
                'currency' => $transaction->currency,
            ]);

            if ($transaction->atomic) {
                // Go: logrus.Info(parent, "parent transaction", atomic, "atomic", inflight, "inflight")
                // — fmt.Sprint adds no spaces next to string operands.
                Log::get()->info(
                    $transaction->parentTransaction . 'parent transaction'
                    . ($transaction->atomic ? 'true' : 'false') . 'atomic'
                    . ($transaction->inflight ? 'true' : 'false') . 'inflight'
                );
                $parentTransactionID = $transaction->metaData['QUEUED_PARENT_TRANSACTION'] ?? null;
                if (!\is_string($parentTransactionID)) {
                    throw new \RuntimeException('parent transaction ID not found in meta data');
                }
                $this->handleAsyncBulkTransactionFailure(new \RuntimeException('transaction rejected'), $parentTransactionID, $transaction->atomic, $transaction->inflight);
            }
            // For rejected transactions, no balances were updated, so pass nil
            $this->postTransactionActions($transaction, null, null);
            return $transaction;
        } finally {
            $span->end();
        }
    }

    /**
     * categorizeRejectionReason maps a free-text rejection reason to a bounded set of metric labels
     * to keep Prometheus cardinality under control.
     */
    protected static function categorizeRejectionReason(string $reason): string
    {
        $lower = strtolower($reason);
        switch (true) {
            case str_contains($lower, 'insufficient funds'):
                return 'insufficient_funds';
            case str_contains($lower, 'overdraft limit'):
                return 'overdraft_limit';
            case Router::isLockContentionError(new \RuntimeException($reason)):
                return 'lock_contention';
            case str_contains($lower, 'exceeded max'):
                return 'max_retries';
            default:
                return 'other';
        }
    }
}
