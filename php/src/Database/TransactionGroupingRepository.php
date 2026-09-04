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

/**
 * Port of Go `database/transaction_grouping.go`: the cached paginated listing,
 * the grouped listing and the inflight-by-parent lookup of the `transaction`
 * concern of {@see Datasource} (composed as a trait).
 *
 * The standalone Go helper `groupedTransactionsQuery(groupCriteria)` is the
 * private static method of the same name below.
 *
 * Cache access mirrors the Go `d.Cache.Get / d.Cache.Set` calls; because the
 * PHP {@see Datasource} may run without a cache (`$this->cache === null`,
 * see Datasource::getDBConnection), the cache steps are skipped in that case
 * instead of dereferencing nil as Go would.
 *
 * The trailing doc comment of the Go file ("GetRefundableTransactionsByParentID
 * retrieves transactions ... eligible for refunds") documents a method that
 * lives in transaction_refunds.go and is carried by
 * {@see TransactionRefundsRepository}.
 */
trait TransactionGroupingRepository
{
    /**
     * GetTransactionsPaginated retrieves a batch of transactions ordered by creation date,
     * serving repeated pages from the cache (1 hour) when available.
     *
     * Go: `GetTransactionsPaginated(ctx, _ string, batchSize int, offset int64)` — the first
     * parameter is unused.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve paginated transactions" / "Failed to scan transaction data" / "Failed to unmarshal metadata" / "Error occurred while iterating over transactions"
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getTransactionsPaginated(string $id, int $batchSize, int $offset): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetInternalTransactionsPaginated');
        try {
            // Create a cache key based on the pagination parameters
            $cacheKey = sprintf('transactions:paginated:%d:%d', $batchSize, $offset);

            /** @var Transaction[] $transactions */
            $transactions = [];
            // Attempt to retrieve transactions from cache
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

            // If not found in cache, fetch from the database
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT transaction_id, parent_transaction, source, reference, amount, precise_amount, precision, currency, destination, description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    ORDER BY created_at ASC
                    LIMIT ? OFFSET ?
                    SQL, [$batchSize, $offset]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve paginated transactions', $err);
            }

            $transactions = [];

            // Scan the rows into transactions
            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $transaction = TransactionRowMapper::scanTransaction($row);
                    } catch (\Exception $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan transaction data', $err);
                    }

                    try {
                        $transaction->metaData = TransactionRowMapper::unmarshalMetaData($row['meta_data'] ?? null);
                    } catch (\JsonException $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $err);
                    }

                    try {
                        $transaction->preciseAmount = TransactionRowMapper::parseBigInt((string) ($row['precise_amount'] ?? ''));
                    } catch (\RuntimeException $err) {
                        $span->recordError($err);
                        throw new \RuntimeException('failed to parse precise_amount: ' . $err->getMessage(), 0, $err);
                    }

                    $transactions[] = $transaction;
                }
            } catch (\PDOException $err) {
                // Handle any errors that occurred while iterating over the rows
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over transactions', $err);
            }

            // Cache the fetched transactions for future use
            if (\count($transactions) > 0 && $this->cache !== null) {
                try {
                    $this->cache->set($cacheKey, $transactions, 1 * 3600); // Cache for 1 hour
                } catch (\Throwable $err) {
                    // Log the error but don't return it since the main operation succeeded
                    Log::get()->error(sprintf('Failed to cache transactions: %s', $err->getMessage()));
                }
            }

            $span->setAttribute('Paginated transactions retrieved', [
                'transaction.count' => \count($transactions),
            ]);
            return $transactions;
        } finally {
            $span->end();
        }
    }

    /**
     * GroupTransactions retrieves and groups transactions from the database based on a specified column (groupCriteria).
     * It supports pagination and caches the grouped results for efficiency. If the data is found in the cache, it returns the cached data.
     * Parameters:
     * - groupCriteria: Column to group transactions by (e.g., "currency", "status").
     * - batchSize: Number of transactions to retrieve in one batch.
     * - offset: Number of transactions to skip before retrieving the batch.
     * Returns:
     * - A map of grouped transactions, or an error if retrieval or grouping fails.
     *
     * Note: PHP coerces integer-like string keys ("42") to int keys in the returned
     * map; consumers should cast the key back with `(string)`.
     *
     * @return array<string, Transaction[]>
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Invalid group criteria: <criteria>" (BAD_REQUEST); "Failed to retrieve grouped transactions" / "Failed to scan transaction data" / "Failed to unmarshal metadata" / "Error occurred while iterating over transactions"
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function groupTransactions(string $groupCriteria, int $batchSize, int $offset): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GroupTransactions');
        try {
            [$query, $ok] = self::groupedTransactionsQuery($groupCriteria);
            if (!$ok) {
                $span->recordError(new \RuntimeException(sprintf('invalid group criteria: %s', $groupCriteria)));
                throw TransactionRowMapper::apiError(ErrorCode::ErrBadRequest, sprintf('Invalid group criteria: %s', $groupCriteria), null);
            }

            // Create a cache key based on the grouping and pagination parameters
            $cacheKey = sprintf('transactions:grouped:%s:%d:%d', $groupCriteria, $batchSize, $offset);

            /** @var array<string, Transaction[]> $groupedTransactions */
            $groupedTransactions = [];
            if ($this->cache !== null) {
                try {
                    $cached = null;
                    $this->cache->get($cacheKey, $cached);
                    if (\is_array($cached) && \count($cached) > 0) {
                        $groupedTransactions = $cached;
                    }
                } catch (\Throwable) {
                    // A cache error falls through to the database, as in Go (err != nil).
                }
                if (\count($groupedTransactions) > 0) {
                    $span->setAttribute('Grouped transactions retrieved from cache', [
                        'group.count' => \count($groupedTransactions),
                    ]);
                    return $groupedTransactions;
                }
            }

            // If not in cache or error occurred, fetch from database
            try {
                $stmt = PgStatement::execute($this->conn, $query, [$batchSize, $offset]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve grouped transactions', $err);
            }

            $groupedTransactions = [];

            // Group transactions by the selected groupCriteria
            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $groupKey = RowScanner::toString($row['group_key'] ?? null);
                        $transaction = TransactionRowMapper::scanTransaction($row);
                    } catch (\Exception $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan transaction data', $err);
                    }

                    try {
                        $transaction->metaData = TransactionRowMapper::unmarshalMetaData($row['meta_data'] ?? null);
                    } catch (\JsonException $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $err);
                    }

                    try {
                        $transaction->preciseAmount = TransactionRowMapper::parseBigInt((string) ($row['precise_amount'] ?? ''));
                    } catch (\RuntimeException $err) {
                        $span->recordError($err);
                        throw new \RuntimeException('failed to parse precise_amount: ' . $err->getMessage(), 0, $err);
                    }

                    $groupedTransactions[$groupKey][] = $transaction;
                }
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over transactions', $err);
            }

            // Cache the fetched data if not empty
            if (\count($groupedTransactions) > 0 && $this->cache !== null) {
                try {
                    $this->cache->set($cacheKey, $groupedTransactions, 5 * 60); // 5*time.Minute
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('Failed to cache grouped transactions: %s', $err->getMessage()));
                }
            }

            $span->setAttribute('Grouped transactions retrieved', [
                'group.count' => \count($groupedTransactions),
            ]);
            return $groupedTransactions;
        } finally {
            $span->end();
        }
    }

    /**
     * groupedTransactionsQuery returns the grouped-listing query for a supported grouping
     * column. Go: `groupedTransactionsQuery(groupCriteria string) (string, bool)` — the pair
     * is returned as `[$query, $ok]`; `$ok` is false (and the query empty) for an unknown
     * criterion. Selecting the query by a switch over literal strings (instead of
     * interpolating the column) keeps the grouping column out of the SQL text.
     *
     * @return array{0: string, 1: bool}
     */
    private static function groupedTransactionsQuery(string $groupCriteria): array
    {
        switch ($groupCriteria) {
            case 'transaction_id':
                return [<<<'SQL'
                    SELECT transaction_id::text AS group_key, transaction_id, parent_transaction, source, reference,
                           amount, precise_amount, precision, currency, destination,
                           description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    WHERE transaction_id::text IS NOT NULL AND transaction_id::text != ''
                    ORDER BY transaction_id::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'parent_transaction':
                return [<<<'SQL'
                    SELECT parent_transaction::text AS group_key, transaction_id, parent_transaction, source, reference,
                           amount, precise_amount, precision, currency, destination,
                           description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    WHERE parent_transaction::text IS NOT NULL AND parent_transaction::text != ''
                    ORDER BY parent_transaction::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'source':
                return [<<<'SQL'
                    SELECT source::text AS group_key, transaction_id, parent_transaction, source, reference,
                           amount, precise_amount, precision, currency, destination,
                           description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    WHERE source::text IS NOT NULL AND source::text != ''
                    ORDER BY source::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'reference':
                return [<<<'SQL'
                    SELECT reference::text AS group_key, transaction_id, parent_transaction, source, reference,
                           amount, precise_amount, precision, currency, destination,
                           description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    WHERE reference::text IS NOT NULL AND reference::text != ''
                    ORDER BY reference::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'currency':
                return [<<<'SQL'
                    SELECT currency::text AS group_key, transaction_id, parent_transaction, source, reference,
                           amount, precise_amount, precision, currency, destination,
                           description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    WHERE currency::text IS NOT NULL AND currency::text != ''
                    ORDER BY currency::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'destination':
                return [<<<'SQL'
                    SELECT destination::text AS group_key, transaction_id, parent_transaction, source, reference,
                           amount, precise_amount, precision, currency, destination,
                           description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    WHERE destination::text IS NOT NULL AND destination::text != ''
                    ORDER BY destination::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'status':
                return [<<<'SQL'
                    SELECT status::text AS group_key, transaction_id, parent_transaction, source, reference,
                           amount, precise_amount, precision, currency, destination,
                           description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    WHERE status::text IS NOT NULL AND status::text != ''
                    ORDER BY status::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            case 'created_at':
                return [<<<'SQL'
                    SELECT created_at::text AS group_key, transaction_id, parent_transaction, source, reference,
                           amount, precise_amount, precision, currency, destination,
                           description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    WHERE created_at::text IS NOT NULL AND created_at::text != ''
                    ORDER BY created_at::text
                    LIMIT ? OFFSET ?
                    SQL, true];
            default:
                return ['', false];
        }
    }

    /**
     * GetInflightTransactionsByParentID retrieves all inflight transactions associated with a given parent transaction ID.
     * It supports pagination via batchSize and offset. Transactions with status 'INFLIGHT' are fetched.
     * If no INFLIGHT transactions exist, then transactions with status 'QUEUED' and meta_data.inflight=true are considered.
     * Parameters:
     * - parentTransactionID: The ID of the parent transaction to filter by.
     * - batchSize: Number of transactions to retrieve in one batch.
     * - offset: Number of transactions to skip before retrieving the batch.
     * Returns:
     * - A slice of inflight transactions or an error if retrieval fails.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve inflight transactions" / "Failed to scan transaction data" / "Failed to unmarshal metadata" / "Error occurred while iterating over transactions"
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getInflightTransactionsByParentID(string $parentTransactionID, int $batchSize, int $offset): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetInflightTransactionsByParentID');
        try {
            // This query first checks if there are any INFLIGHT transactions for this parentTransactionID
            // If there are, it returns only those. If not, it falls back to QUEUED with inflight=true
            // It excludes any REJECTED transactions
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    WITH inflight_transactions AS (
                        SELECT transaction_id, parent_transaction, source, reference, amount, precise_amount, precision,
                               currency, destination, description, status, created_at, meta_data, scheduled_for, hash
                        FROM blnk.transactions
                        WHERE (transaction_id = ? OR parent_transaction = ? OR meta_data->>'QUEUED_PARENT_TRANSACTION' = ?)
                        AND status = 'INFLIGHT'
                    ),
                    queued_inflight_transactions AS (
                        SELECT t.transaction_id, t.parent_transaction, t.source, t.reference, t.amount, t.precise_amount, t.precision,
                               t.currency, t.destination, t.description, t.status, t.created_at, t.meta_data, t.scheduled_for, t.hash
                        FROM blnk.transactions t
                        WHERE (t.transaction_id = ? OR t.parent_transaction = ?)
                        AND t.status = 'QUEUED' AND t.meta_data->>'inflight' = 'true'
                        -- Don't include transactions that have been rejected (check by reference with _q suffix)
                        AND NOT EXISTS (
                            SELECT 1
                            FROM blnk.transactions rejected
                            WHERE rejected.reference = t.reference || '_q' AND rejected.status = 'REJECTED'
                        )
                        -- Also don't include if there are child transactions with INFLIGHT status
                        AND NOT EXISTS (
                            SELECT 1
                            FROM blnk.transactions child
                            WHERE child.parent_transaction = t.transaction_id AND child.status = 'INFLIGHT'
                        )
                    )

                    SELECT * FROM inflight_transactions
                    UNION ALL
                    -- Only include queued_inflight if there are no inflight transactions
                    SELECT * FROM queued_inflight_transactions
                    WHERE NOT EXISTS (SELECT 1 FROM inflight_transactions)

                    ORDER BY created_at DESC
                    LIMIT ? OFFSET ?
                    SQL, [
                    // Go binds $1 (five occurrences), $2 and $3.
                    $parentTransactionID,
                    $parentTransactionID,
                    $parentTransactionID,
                    $parentTransactionID,
                    $parentTransactionID,
                    $batchSize,
                    $offset,
                ]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve inflight transactions', $err);
            }

            $transactions = [];

            // Iterate over the result set and map to transaction models
            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $transaction = TransactionRowMapper::scanTransaction($row);
                    } catch (\Exception $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan transaction data', $err);
                    }

                    try {
                        $transaction->metaData = TransactionRowMapper::unmarshalMetaData($row['meta_data'] ?? null);
                    } catch (\JsonException $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $err);
                    }

                    try {
                        $transaction->preciseAmount = TransactionRowMapper::parseBigInt((string) ($row['precise_amount'] ?? ''));
                    } catch (\RuntimeException $err) {
                        $span->recordError($err);
                        throw new \RuntimeException('failed to parse precise_amount: ' . $err->getMessage(), 0, $err);
                    }

                    $transactions[] = $transaction;
                }
            } catch (\PDOException $err) {
                // Handle any errors that occurred while iterating over the rows
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over transactions', $err);
            }

            $span->setAttribute('Inflight transactions retrieved', [
                'transaction.count' => \count($transactions),
            ]);
            return $transactions;
        } finally {
            $span->end();
        }
    }
}
