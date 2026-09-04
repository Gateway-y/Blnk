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

use Blnk\Internal\Filter\QueryFilterSet;
use Blnk\Internal\Filter\QueryOptions;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Transaction;

/**
 * TransactionQueries is the port of the root-package file
 * `transaction_queries.go`: the read-only transaction lookups of the Go
 * `Blnk` struct, each wrapped in a tracing span.
 *
 * `context.Context` parameters are dropped; `(T, error)` returns become `T`
 * plus a thrown exception (a missing row surfaces as
 * {@see \Blnk\Database\NotFoundException} from the datasource).
 */
trait TransactionQueries
{
    /**
     * GetTransaction fetches a transaction by ID from the datasource.
     *
     * @throws \Blnk\Database\NotFoundException when no transaction has the given ID
     * @throws \Throwable
     */
    public function getTransaction(string $transactionID): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('GetTransaction');
        try {
            // Fetch the transaction from the datasource
            try {
                $transaction = $this->datasource->getTransaction($transactionID);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            self::spanEvent($span, 'Transaction retrieved', ['transaction.id' => $transactionID]);
            return $transaction;
        } finally {
            $span->end();
        }
    }

    /**
     * GetAllTransactions retrieves all transactions from the datasource.
     * It starts a tracing span, fetches all transactions, and records relevant events and errors.
     *
     * Returns:
     * - []model.Transaction: A slice of all retrieved Transaction models.
     * - error: An error if the transactions could not be retrieved (thrown).
     *
     * @return Transaction[]
     *
     * @throws \Throwable
     */
    public function getAllTransactions(int $limit, int $offset): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('GetAllTransactions');
        try {
            // Fetch all transactions from the datasource
            try {
                $transactions = $this->datasource->getAllTransactions($limit, $offset);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            self::spanEvent($span, 'All transactions retrieved');
            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * GetAllTransactionsWithFilter retrieves transactions using advanced filters.
     * It starts a tracing span, fetches transactions matching the filter criteria, and records relevant events.
     *
     * Parameters:
     * - filters *filter.QueryFilterSet: Filter conditions to apply.
     * - limit int: Maximum number of transactions to return.
     * - offset int: Offset for pagination.
     *
     * Returns:
     * - []model.Transaction: A slice of Transaction models matching the filter criteria.
     * - error: An error if the transactions could not be retrieved (thrown).
     *
     * @return Transaction[]
     *
     * @throws \Throwable
     */
    public function getAllTransactionsWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('GetAllTransactionsWithFilter');
        try {
            try {
                $transactions = $this->datasource->getAllTransactionsWithFilter($filters, $limit, $offset);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            self::spanEvent($span, 'Transactions with filter retrieved');
            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * GetAllTransactionsWithFilterAndOptions retrieves transactions with advanced filters, sorting, and optional count.
     *
     * Go returns `([]model.Transaction, *int64, error)`; the PHP port returns
     * `[$transactions, $count]` where `$count` is null unless the options
     * requested it (see DataSourceInterface).
     *
     * @return array{0: Transaction[], 1: int|null}
     *
     * @throws \Throwable
     */
    public function getAllTransactionsWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array
    {
        $span = Tracer::get('blnk.transactions')->startSpan('GetAllTransactionsWithFilterAndOptions');
        try {
            try {
                [$transactions, $count] = $this->datasource->getAllTransactionsWithFilterAndOptions($filters, $opts, $limit, $offset);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            self::spanEvent($span, 'Transactions with filter and options retrieved');
            return [$transactions, $count];
        } finally {
            $span->end();
        }
    }

    /**
     * GetTransactionByRef retrieves a transaction by its reference from the datasource.
     * It starts a tracing span, fetches the transaction by reference, and records relevant events and errors.
     *
     * Parameters:
     * - reference string: The reference of the transaction to be retrieved.
     *
     * Returns:
     * - model.Transaction: The retrieved Transaction model.
     * - error: An error if the transaction could not be retrieved (thrown).
     *
     * @throws \Blnk\Database\NotFoundException when no transaction has the given reference
     * @throws \Throwable
     */
    public function getTransactionByRef(string $reference): Transaction
    {
        $span = Tracer::get('blnk.transactions')->startSpan('GetTransactionByRef');
        try {
            // Fetch the transaction by reference from the datasource
            try {
                $transaction = $this->datasource->getTransactionByRef($reference);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            self::spanEvent($span, 'Transaction retrieved by reference', ['transaction.reference' => $reference]);
            return $transaction;
        } finally {
            $span->end();
        }
    }
}
