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
use Blnk\Internal\Filter\FilterValidationException;
use Blnk\Internal\Filter\LogicalOperator;
use Blnk\Internal\Filter\QueryFilterSet;
use Blnk\Internal\Filter\QueryOptions;
use Blnk\Internal\Filter\SqlBuilder;
use Blnk\Internal\Filter\Validation;
use Blnk\Internal\Traces\Tracer;
use Blnk\Model\Transaction;

/**
 * Port of Go `database/transaction_filters.go`: the advanced-filter listing of
 * the `transaction` concern of {@see Datasource} (composed as a trait).
 *
 * The Go file defines no standalone types or helpers. Its trailing doc comment
 * ("GetStuckQueuedTransactions retrieves QUEUED transactions that are older
 * than the threshold ...") documents a method that lives in
 * transaction_recovery.go and is carried by {@see TransactionRecoveryRepository}.
 */
trait TransactionFiltersRepository
{
    /**
     * GetAllTransactionsWithFilter retrieves transactions with advanced filtering support.
     * It delegates to GetAllTransactionsWithFilterAndOptions with nil options.
     *
     * Parameters:
     * - filters: A QueryFilterSet containing the filter conditions.
     * - limit: The maximum number of transactions to return.
     * - offset: The offset to start fetching transactions from (for pagination).
     *
     * Returns:
     * - []model.Transaction: A slice of transactions matching the filter criteria.
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    public function getAllTransactionsWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array
    {
        [$transactions] = $this->getAllTransactionsWithFilterAndOptions($filters, null, $limit, $offset);
        return $transactions;
    }

    /**
     * GetAllTransactionsWithFilterAndOptions retrieves transactions with filtering, sorting, and optional count.
     * It uses the filter package to build SQL WHERE and ORDER BY conditions.
     *
     * Parameters:
     * - filters: A QueryFilterSet containing the filter conditions.
     * - opts: Query options including sorting and count settings.
     * - limit: The maximum number of transactions to return.
     * - offset: The offset to start fetching transactions from (for pagination).
     *
     * Returns:
     * - []model.Transaction: A slice of transactions matching the filter criteria.
     * - *int64: Optional total count of matching records (if opts.IncludeCount is true).
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * Bind order: the filter builder emits positional `?` placeholders and orders its
     * arguments as CTE arguments followed by condition arguments (see
     * {@see \Blnk\Internal\Filter\BuildResult}); as the `WITH` clause precedes the WHERE
     * clause in the assembled query, `$result->args` + [limit, offset] is the SQL-text order.
     *
     * @return array{0: Transaction[], 1: int|null} `[$transactions, $totalCount]`; `$totalCount` is null unless `$opts->includeCount`.
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Invalid sort_by field" / "Invalid filter: ..." (BAD_REQUEST); "Failed to retrieve transactions" / "Failed to scan transaction data" / "Failed to unmarshal metadata" / "Error occurred while iterating over transactions"
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getAllTransactionsWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetAllTransactionsWithFilterAndOptions');
        try {
            if ($limit <= 0 || $limit > 1000) {
                $limit = 1000;
            }

            if ($opts === null) {
                $opts = new QueryOptions();
            }
            try {
                Validation::validateSortByForTable($opts, 'transactions');
            } catch (FilterValidationException) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrBadRequest, 'Invalid sort_by field', null);
            }

            try {
                $result = SqlBuilder::buildWithOptions($filters, 'transactions', '', 1, $opts);
            } catch (FilterValidationException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrBadRequest, sprintf('Invalid filter: %s', $err->getMessage()), $err);
            }

            // Determine select fields based on whether count is requested
            $selectFields = 'transaction_id, source, reference, amount, precise_amount, precision, currency, destination, description, status, hash, created_at, effective_date, meta_data';
            if ($opts->includeCount) {
                $selectFields .= ', COUNT(*) OVER() AS total_count';
            }

            // Build base query
            $baseQuery = sprintf(<<<'SQL'

                SELECT %s
                FROM blnk.transactions

            SQL, $selectFields);

            $args = [];
            $args = array_merge($args, $result->args);
            // (Go: argPos := result.NextArgPos — unused with positional `?` placeholders.)

            // Add WHERE clause if filters are provided
            if (\count($result->conditions) > 0) {
                $logicalOperator = LogicalOperator::LogicalAnd;
                if ($filters !== null) {
                    $logicalOperator = $filters->logicalOperator;
                }
                $baseQuery .= ' WHERE ' . SqlBuilder::buildConditionExpression($result->conditions, $logicalOperator);
            }

            // Prepend CTEs if any
            if ($result->ctes !== null && \count($result->ctes) > 0) {
                $baseQuery = 'WITH ' . implode(', ', $result->ctes) . ' ' . $baseQuery;
            }

            // Add ORDER BY clause
            $baseQuery .= ' ORDER BY ' . $result->orderBy;

            // Add pagination (Go: " LIMIT $%d OFFSET $%d" at argPos, argPos+1)
            $baseQuery .= ' LIMIT ? OFFSET ?';
            $args[] = $limit;
            $args[] = $offset;

            try {
                $stmt = PgStatement::execute($this->conn, $baseQuery, $args);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve transactions', $err);
            }

            $transactions = [];
            $totalCount = null;

            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $transaction = TransactionRowMapper::scanTransaction($row);
                        if ($opts->includeCount) {
                            $count = RowScanner::toInt($row['total_count'] ?? null);
                            if ($totalCount === null) {
                                $totalCount = $count;
                            }
                        }
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
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over transactions', $err);
            }

            $span->setAttribute('Transactions with filter and options retrieved', [
                'transaction.count' => \count($transactions),
            ]);

            return [$transactions, $totalCount];
        } finally {
            $span->end();
        }
    }
}
