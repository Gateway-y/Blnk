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

namespace Blnk\Database;

use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\Log;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;

/**
 * Port of Go `database/transaction_queue.go`: queued-amount aggregation,
 * parent/child lookups and the refund check of the `transaction` concern of
 * {@see Datasource} (composed as a trait).
 *
 * The trailing doc comment of the Go file ("GetTransactionsByCriteria
 * retrieves transactions based on specified criteria ...") documents a method
 * that lives in transaction_criteria.go and is carried by
 * {@see TransactionCriteriaRepository}.
 */
trait TransactionQueueRepository
{
    /**
     * GetQueuedAmounts retrieves the total queued debit and credit amounts for a given balance ID.
     * It only includes transactions with status 'QUEUED' that don't have child transactions with status 'APPLIED' or 'REJECTED'.
     * This ensures that queued transactions that have been processed (either applied or rejected) are excluded from the totals.
     * Parameters:
     * - balanceID: The ID of the balance to retrieve queued amounts for.
     * Returns:
     * - The total debit and credit amounts as BigInteger values; throws if the retrieval fails.
     *
     * Go: `(debit, credit *big.Int, err error)` — the PHP port returns the
     * pair `[$debit, $credit]`. This method is not part of `IDataSource`; it
     * is called by the balance repository (`GetBalanceByID` with queued
     * amounts).
     *
     * @return array{0: BigInteger, 1: BigInteger} `[$debit, $credit]`
     * @throws DatabaseException the bare query failure (Go returns the raw error, unwrapped)
     * @throws \RuntimeException "failed to parse queued debit amount: ..." / "failed to parse queued credit amount: ..."
     */
    public function getQueuedAmounts(string $balanceID): array
    {
        // Aggregate in SQL with separate indexed scans for source (debit) and
        // destination (credit) instead of an un-indexable OR predicate plus
        // row-by-row summation in Go. A transaction where source = destination =
        // balanceID counts only as a debit (hence the t.source <> $1 guard),
        // preserving the previous if/else semantics.
        try {
            $stmt = $this->conn->prepare(<<<'SQL'
                SELECT
                    (SELECT COALESCE(SUM(t.precise_amount), 0)
                     FROM blnk.transactions t
                     WHERE t.source = ?
                     AND t.status = 'QUEUED'
                     AND NOT EXISTS (
                         SELECT 1
                         FROM blnk.transactions child
                         WHERE child.parent_transaction = t.transaction_id
                         AND (child.status = 'APPLIED' OR child.status = 'REJECTED' OR child.status = 'VOID' or child.status = 'INFLIGHT')
                     ))::text AS queued_debit,
                    (SELECT COALESCE(SUM(t.precise_amount), 0)
                     FROM blnk.transactions t
                     WHERE t.destination = ?
                     AND t.source <> ?
                     AND t.status = 'QUEUED'
                     AND NOT EXISTS (
                         SELECT 1
                         FROM blnk.transactions child
                         WHERE child.parent_transaction = t.transaction_id
                         AND (child.status = 'APPLIED' OR child.status = 'REJECTED' OR child.status = 'VOID' or child.status = 'INFLIGHT')
                     ))::text AS queued_credit
                SQL);
            // Go binds a single $1; the three occurrences become three positional binds of the same value.
            $stmt->execute([$balanceID, $balanceID, $balanceID]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $err) {
            throw DatabaseException::fromPDOException($err);
        }
        if ($row === false) {
            throw new NotFoundException(); // sql.ErrNoRows (a scalar SELECT always yields one row)
        }
        $debitStr = (string) $row['queued_debit'];
        $creditStr = (string) $row['queued_credit'];

        try {
            $debit = TransactionRowMapper::parseBigInt($debitStr);
        } catch (\RuntimeException) {
            throw new \RuntimeException(sprintf('failed to parse queued debit amount: %s', $debitStr));
        }
        try {
            $credit = TransactionRowMapper::parseBigInt($creditStr);
        } catch (\RuntimeException) {
            throw new \RuntimeException(sprintf('failed to parse queued credit amount: %s', $creditStr));
        }

        return [$debit, $credit];
    }

    /**
     * TransactionExistsByIDOrParentID checks if a transaction exists either by its direct ID
     * or as a parent transaction ID for other transactions.
     * Parameters:
     * - id: The ID to search for in both transaction_id and parent_transaction fields.
     * Returns:
     * - A boolean indicating whether the transaction exists in either capacity; throws if the check fails.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to check if transaction exists"
     */
    public function transactionExistsByIDOrParentID(string $id): bool
    {
        $span = Tracer::get('transaction.database')->startSpan('TransactionExistsByIDOrParentID');
        try {
            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT EXISTS(
                        SELECT 1 FROM blnk.transactions
                        WHERE transaction_id = ? OR parent_transaction = ?
                    )
                    SQL);
                // Go binds a single $1 used twice.
                $stmt->execute([$id, $id]);
                $exists = TransactionRowMapper::toBool($stmt->fetchColumn());
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to check if transaction exists', $err);
            }

            $span->setAttribute('Transaction existence check', [
                'transaction.id' => $id,
                'transaction.exists' => $exists,
            ]);

            return $exists;
        } finally {
            $span->end();
        }
    }

    /**
     * GetTransactionsByParent retrieves all transactions associated with a given parent transaction ID.
     * It supports pagination via limit and offset parameters.
     * Parameters:
     * - parentID: The ID of the parent transaction to filter by.
     * - limit: Maximum number of transactions to retrieve.
     * - offset: Number of transactions to skip before retrieving the batch.
     * Returns:
     * - A list of transactions; throws if retrieval fails.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getTransactionsByParent(string $parentID, int $limit, int $offset): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetTransactionsByParent');
        try {
            // Create a cache key based on the parameters
            $cacheKey = sprintf('transactions:parent:%s:%d:%d', $parentID, $limit, $offset);

            /** @var Transaction[] $transactions */
            $transactions = [];
            // Attempt to retrieve from cache first
            // (Go dereferences d.Cache unconditionally; the PHP Datasource may run without a cache.)
            if ($this->cache !== null) {
                try {
                    $cached = null;
                    $this->cache->get($cacheKey, $cached);
                    if (\is_array($cached) && \count($cached) > 0) {
                        $transactions = $cached;
                    }
                } catch (\Throwable) {
                    // A cache error falls through to the database, as in Go (err != nil).
                }
                if (\count($transactions) > 0) {
                    $span->setAttribute('Transactions retrieved from cache', [
                        'transaction.count' => \count($transactions),
                    ]);
                    return $transactions;
                }
            }

            // If not in cache, query the database
            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT transaction_id, parent_transaction, source, reference, amount, precise_amount, precision,
                           currency, destination, description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    WHERE parent_transaction = ?
                    ORDER BY created_at DESC
                    LIMIT ? OFFSET ?
                    SQL);
                $stmt->execute([$parentID, $limit, $offset]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve transactions by parent', $err);
            }

            $transactions = [];

            // Iterate over the result set
            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    $transaction = TransactionRowMapper::scanTransaction($row);

                    try {
                        $transaction->metaData = TransactionRowMapper::unmarshalMetaData($row['meta_data']);
                    } catch (\JsonException $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $err);
                    }

                    try {
                        $transaction->preciseAmount = TransactionRowMapper::parseBigInt((string) $row['precise_amount']);
                    } catch (\RuntimeException $err) {
                        $span->recordError($err);
                        throw new \RuntimeException('failed to parse precise_amount: ' . $err->getMessage(), 0, $err);
                    }
                    $transactions[] = $transaction;
                }
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over transactions', $err);
            }

            // Cache the results if there are any
            if (\count($transactions) > 0 && $this->cache !== null) {
                try {
                    $this->cache->set($cacheKey, $transactions, 5 * 60); // 5*time.Minute
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('Failed to cache transactions by parent: %s', $err->getMessage()));
                }
            }

            $span->setAttribute('Transactions by parent retrieved', [
                'parent_transaction.id' => $parentID,
                'transaction.count' => \count($transactions),
            ]);
            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * IsTransactionRefunded checks if a transaction has already been refunded by looking for
     * a transaction that has the inverse source/destination and references the original
     * transaction as its parent.
     * Parameters:
     * - transaction: The original transaction to check for refunds.
     * Returns:
     * - bool: true if the transaction has been refunded, false otherwise; throws if the check fails
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to check refund status"
     */
    public function isTransactionRefunded(Transaction $transaction): bool
    {
        $span = Tracer::get('transaction.database')->startSpan('IsTransactionRefunded');
        try {
            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT EXISTS (
                        SELECT 1
                        FROM blnk.transactions
                        WHERE parent_transaction = ?
                        AND source = ?
                        AND destination = ?
                    )
                    SQL);
                $stmt->execute([$transaction->transactionID, $transaction->destination, $transaction->source]);
                $exists = TransactionRowMapper::toBool($stmt->fetchColumn());
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to check refund status', $err);
            }

            $span->setAttribute('Refund status checked', [
                'transaction.id' => $transaction->transactionID,
                'is_refunded' => $exists,
            ]);

            return $exists;
        } finally {
            $span->end();
        }
    }
}
