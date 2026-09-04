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
use Brick\Math\BigInteger;

/**
 * Port of Go `database/transaction_inflight.go`: the committed-amount
 * aggregation used by inflight commits, part of the `transaction` concern of
 * {@see Datasource} (composed as a trait).
 *
 * The trailing doc comment of the Go file ("GetTransactionsPaginated
 * retrieves a batch of transactions ...") documents a method that lives in
 * another transaction_*.go file and is carried by its own trait.
 */
trait TransactionInflightRepository
{
    /**
     * GetTotalCommittedTransactions calculates the total committed transaction amounts for a given parent transaction.
     * It uses OpenTelemetry for tracing and throws if the retrieval fails.
     * Parameters:
     * - parentID: The ID of the parent transaction to retrieve totals for.
     * Returns:
     * - The total committed amount as a BigInteger, or 0 if no transactions are found; throws if the retrieval fails.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to get total committed transactions" / "Failed to parse precise amount"
     */
    public function getTotalCommittedTransactions(string $parentID): BigInteger
    {
        // Start a new tracing span for the operation
        $span = Tracer::get('transaction.database')->startSpan('GetTotalCommittedTransactions');
        try {
            // SQL query to calculate the total precise amount for the given parent transaction
            $query = <<<'SQL'
                SELECT SUM(precise_amount) AS total_amount
                FROM blnk.transactions
                WHERE parent_transaction = ? AND status = 'APPLIED'
                GROUP BY parent_transaction;
                SQL;

            // Execute the query and scan the result into totalAmount
            try {
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$parentID]);
                $value = $stmt->fetchColumn();
            } catch (\PDOException $err) {
                // Record the error in the tracing span and rethrow
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to get total committed transactions', $err);
            }

            // If no rows are found, return 0 without error
            if ($value === false) {
                return BigInteger::zero();
            }
            if ($value === null) {
                // Go scans the aggregate into a string, which fails on a NULL SUM.
                $err = new \RuntimeException('sql: Scan error on column index 0, name "total_amount": converting NULL to string is unsupported');
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to get total committed transactions', $err);
            }
            $totalAmountStr = (string) $value;

            try {
                $total = TransactionRowMapper::parseBigInt($totalAmountStr);
            } catch (\RuntimeException) {
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to parse precise amount', null);
            }

            // Log the successful retrieval of the total amount
            $span->setAttribute('Total committed transactions retrieved', [
                'parent_transaction.id' => $parentID,
                'total_amount' => (string) $total,
            ]);

            // Return the total amount
            return $total;
        } finally {
            $span->end();
        }
    }
}
