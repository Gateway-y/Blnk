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
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\ModelHelpers;
use Blnk\Model\Transaction;

/**
 * Port of Go `database/transaction_coalescing.go`: the queued-transaction
 * lookups used by the coalescing / hot-pair machinery, part of the
 * `transaction` concern of {@see Datasource} (composed as a trait).
 *
 * The standalone Go helper `scanQueuedTransactionsForCoalescing(rows)` is the
 * private method of the same name below (it takes the executed \PDOStatement
 * in place of `*sql.Rows`).
 */
trait TransactionCoalescingRepository
{
    /**
     * GetQueuedTransactionsForCoalescing retrieves QUEUED transactions for the exact same
     * (source, destination, currency) pair, created at or after the given instant, that have
     * no child transaction yet, excluding the given transaction ID.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve queued transactions for coalescing" and the scan errors
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getQueuedTransactionsForCoalescing(string $source, string $destination, string $currency, string $excludeTransactionID, \DateTimeImmutable $createdAtOrAfter, int $limit): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetQueuedTransactionsForCoalescing');
        try {
            try {
                $rows = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT transaction_id, parent_transaction, source, reference, amount, precise_amount, precision, currency, destination, description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions t
                    WHERE t.status = 'QUEUED'
                      AND t.source = ?
                      AND t.destination = ?
                      AND t.currency = ?
                      AND t.transaction_id <> ?
                      AND t.created_at >= ?
                      AND NOT EXISTS (
                          SELECT 1 FROM blnk.transactions child
                          WHERE child.parent_transaction = t.transaction_id
                      )
                    ORDER BY t.created_at ASC
                    LIMIT ?
                    SQL, [
                    $source,
                    $destination,
                    $currency,
                    $excludeTransactionID,
                    TransactionRowMapper::formatTimestamp($createdAtOrAfter), // createdAtOrAfter.UTC()
                    $limit,
                ]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve queued transactions for coalescing', $err);
            }

            try {
                $transactions = $this->scanQueuedTransactionsForCoalescing($rows);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            $span->setAttribute('Queued transactions for coalescing retrieved', [
                'transaction.count' => \count($transactions),
                'source.balance_id' => $source,
                'destination.balance_id' => $destination,
            ]);

            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * GetQueuedTransactionsForSourceCoalescing retrieves QUEUED transactions sharing the same
     * source balance and currency (any destination) that have no child transaction yet.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve source-scoped queued transactions for coalescing" and the scan errors
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getQueuedTransactionsForSourceCoalescing(string $source, string $currency, string $excludeTransactionID, \DateTimeImmutable $createdAtOrAfter, int $limit): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetQueuedTransactionsForSourceCoalescing');
        try {
            try {
                $rows = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT transaction_id, parent_transaction, source, reference, amount, precise_amount, precision, currency, destination, description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions t
                    WHERE t.status = 'QUEUED'
                      AND t.source = ?
                      AND t.currency = ?
                      AND t.transaction_id <> ?
                      AND t.created_at >= ?
                      AND NOT EXISTS (
                          SELECT 1 FROM blnk.transactions child
                          WHERE child.parent_transaction = t.transaction_id
                      )
                    ORDER BY t.created_at ASC
                    LIMIT ?
                    SQL, [
                    $source,
                    $currency,
                    $excludeTransactionID,
                    TransactionRowMapper::formatTimestamp($createdAtOrAfter), // createdAtOrAfter.UTC()
                    $limit,
                ]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve source-scoped queued transactions for coalescing', $err);
            }

            try {
                $transactions = $this->scanQueuedTransactionsForCoalescing($rows);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            $span->setAttribute('Source-scoped queued transactions for coalescing retrieved', [
                'transaction.count' => \count($transactions),
                'source.balance_id' => $source,
            ]);

            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * GetQueuedTransactionsForDestinationCoalescing retrieves QUEUED transactions sharing the
     * same destination balance and currency (any source) that have no child transaction yet.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve destination-scoped queued transactions for coalescing" and the scan errors
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getQueuedTransactionsForDestinationCoalescing(string $destination, string $currency, string $excludeTransactionID, \DateTimeImmutable $createdAtOrAfter, int $limit): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetQueuedTransactionsForDestinationCoalescing');
        try {
            try {
                $rows = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT transaction_id, parent_transaction, source, reference, amount, precise_amount, precision, currency, destination, description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions t
                    WHERE t.status = 'QUEUED'
                      AND t.destination = ?
                      AND t.currency = ?
                      AND t.transaction_id <> ?
                      AND t.created_at >= ?
                      AND NOT EXISTS (
                          SELECT 1 FROM blnk.transactions child
                          WHERE child.parent_transaction = t.transaction_id
                      )
                    ORDER BY t.created_at ASC
                    LIMIT ?
                    SQL, [
                    $destination,
                    $currency,
                    $excludeTransactionID,
                    TransactionRowMapper::formatTimestamp($createdAtOrAfter), // createdAtOrAfter.UTC()
                    $limit,
                ]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve destination-scoped queued transactions for coalescing', $err);
            }

            try {
                $transactions = $this->scanQueuedTransactionsForCoalescing($rows);
            } catch (\Throwable $err) {
                $span->recordError($err);
                throw $err;
            }

            $span->setAttribute('Destination-scoped queued transactions for coalescing retrieved', [
                'transaction.count' => \count($transactions),
                'destination.balance_id' => $destination,
            ]);

            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * scanQueuedTransactionsForCoalescing reads every row of a coalescing query into a
     * transaction (metadata unmarshalled, precise_amount parsed, precision applied).
     *
     * Go: `scanQueuedTransactionsForCoalescing(rows *sql.Rows) ([]*model.Transaction, error)`;
     * the deferred `rows.Close()` is implicit in PDO (the statement is released with its
     * last reference).
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to scan queued transaction for coalescing" / "Failed to unmarshal metadata" / "Error iterating queued transactions for coalescing"
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    private function scanQueuedTransactionsForCoalescing(\PDOStatement $rows): array
    {
        $transactions = [];
        try {
            while (($row = $rows->fetch(\PDO::FETCH_ASSOC)) !== false) {
                try {
                    $txn = TransactionRowMapper::scanTransaction($row);
                } catch (\Exception $err) {
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan queued transaction for coalescing', $err);
                }

                try {
                    $txn->metaData = TransactionRowMapper::unmarshalMetaData($row['meta_data'] ?? null);
                } catch (\JsonException $err) {
                    throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $err);
                }

                try {
                    $preciseAmount = TransactionRowMapper::parseBigInt((string) ($row['precise_amount'] ?? ''));
                } catch (\RuntimeException $err) {
                    throw new \RuntimeException('failed to parse precise_amount: ' . $err->getMessage(), 0, $err);
                }
                $txn->preciseAmount = $preciseAmount;

                ModelHelpers::applyPrecision($txn);
                $transactions[] = $txn;
            }
        } catch (\PDOException $err) {
            throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error iterating queued transactions for coalescing', $err);
        }

        return $transactions;
    }

    /**
     * CountQueuedTransactionsForPairLane counts the QUEUED transactions of a
     * (source, destination, currency) pair sitting in the given queue lane
     * (`meta_data->>'queue_lane'`, defaulting to "normal") that have no child transaction yet.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to count queued transactions for pair lane"
     */
    public function countQueuedTransactionsForPairLane(string $source, string $destination, string $currency, string $lane): int
    {
        $span = Tracer::get('transaction.database')->startSpan('CountQueuedTransactionsForPairLane');
        try {
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT COUNT(*)
                    FROM blnk.transactions t
                    WHERE t.status = 'QUEUED'
                      AND t.source = ?
                      AND t.destination = ?
                      AND t.currency = ?
                      AND COALESCE(t.meta_data->>'queue_lane', 'normal') = ?
                      AND NOT EXISTS (
                          SELECT 1 FROM blnk.transactions child
                          WHERE child.parent_transaction = t.transaction_id
                      )
                    SQL, [$source, $destination, $currency, $lane]);
                $count = RowScanner::toInt($stmt->fetchColumn());
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to count queued transactions for pair lane', $err);
            }

            return $count;
        } finally {
            $span->end();
        }
    }
}
