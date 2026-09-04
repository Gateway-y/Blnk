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
use Blnk\Model\Transaction;

/**
 * Port of Go `database/transaction_criteria.go`: the amount / currency / date
 * range lookup of the `transaction` concern of {@see Datasource} (composed as
 * a trait).
 *
 * The Go file defines no standalone types or helpers.
 */
trait TransactionCriteriaRepository
{
    /**
     * GetTransactionsByCriteria retrieves transactions based on specified criteria: an optional
     * amount range, an optional currency and an optional creation-date range, ordered by
     * creation date and paginated with limit/offset.
     *
     * Go: `GetTransactionsByCriteria(ctx, minAmount, maxAmount *float64, currency *string,
     * minDate, maxDate *time.Time, limit int, offset int64)`. Each nullable criterion is only
     * applied when non-null (Go: non-nil pointer); a currency is also skipped when empty.
     *
     * The Go code numbers its `$n` placeholders with an `argCount` counter; the PDO port uses
     * positional `?` placeholders, so the arguments are simply appended in SQL-text order.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve transactions by criteria" / "Failed to scan transaction data" / "Failed to unmarshal metadata" / "Error occurred while iterating over transactions"
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getTransactionsByCriteria(?float $minAmount, ?float $maxAmount, ?string $currency, ?\DateTimeImmutable $minDate, ?\DateTimeImmutable $maxDate, int $limit, int $offset): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetTransactionsByCriteria');
        try {
            $query = <<<'SQL'

                SELECT transaction_id, parent_transaction, source, reference, amount, precise_amount, precision,
                       currency, destination, description, status, created_at, meta_data, scheduled_for, hash
                FROM blnk.transactions
                WHERE 1=1

            SQL;
            $args = [];

            if ($minAmount !== null && $maxAmount !== null) {
                $query .= ' AND amount >= ? AND amount <= ?';
                $args[] = PqEncoder::float($minAmount);
                $args[] = PqEncoder::float($maxAmount);
            } elseif ($minAmount !== null) {
                $query .= ' AND amount >= ?';
                $args[] = PqEncoder::float($minAmount);
            } elseif ($maxAmount !== null) {
                $query .= ' AND amount <= ?';
                $args[] = PqEncoder::float($maxAmount);
            }

            if ($currency !== null && $currency !== '') {
                $query .= ' AND currency = ?';
                $args[] = $currency;
            }

            if ($minDate !== null) {
                $query .= ' AND created_at >= ?';
                // Go binds *minDate as-is (no UTC conversion): lib/pq renders the value in its
                // own zone, and the TIMESTAMP column ignores the offset. PqEncoder::time does the same.
                $args[] = PqEncoder::time($minDate);
            }

            if ($maxDate !== null) {
                $query .= ' AND created_at <= ?';
                $args[] = PqEncoder::time($maxDate);
            }

            $query .= ' ORDER BY created_at ASC LIMIT ? OFFSET ?';
            $args[] = $limit;
            $args[] = $offset;

            try {
                $stmt = PgStatement::execute($this->conn, $query, $args);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve transactions by criteria', $err);
            }

            $transactions = [];
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
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over transactions', $err);
            }

            $span->setAttribute('Transactions by criteria retrieved', [
                'transaction.count' => \count($transactions),
            ]);
            return $transactions;
        } finally {
            $span->end();
        }
    }
}
