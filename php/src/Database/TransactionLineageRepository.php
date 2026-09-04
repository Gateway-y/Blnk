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
 * Port of Go `database/transaction_lineage.go`: the shadow-transaction lookup
 * of the `transaction` concern of {@see Datasource} (composed as a trait).
 *
 * The Go file defines no standalone types or helpers. Its trailing doc comment
 * ("GetAllTransactionsWithFilter retrieves transactions with advanced
 * filtering support ...") documents a method that lives in
 * transaction_filters.go and is carried by {@see TransactionFiltersRepository}.
 */
trait TransactionLineageRepository
{
    /**
     * GetTransactionsByShadowFor retrieves the shadow transactions recorded for a parent
     * transaction (`meta_data->>'_shadow_for'`), oldest first.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve shadow transactions" / "Failed to scan shadow transaction" / "Failed to unmarshal metadata" / "Invalid shadow transaction precise amount" / "Error iterating shadow transactions"
     */
    public function getTransactionsByShadowFor(string $parentTransactionID): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetTransactionsByShadowFor');
        try {
            try {
                $stmt = PgStatement::execute($this->conn, <<<'SQL'
                    SELECT transaction_id, source, reference, amount, precise_amount, "precision", currency, destination, description, status, created_at, meta_data, scheduled_for, hash
                    FROM blnk.transactions
                    WHERE meta_data->>'_shadow_for' = ?
                    ORDER BY created_at ASC
                    SQL, [$parentTransactionID]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve shadow transactions', $err);
            }

            $transactions = [];
            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    try {
                        $transaction = TransactionRowMapper::scanTransaction($row);
                    } catch (\Exception $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan shadow transaction', $err);
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
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Invalid shadow transaction precise amount', $err);
                    }
                    $transactions[] = $transaction;
                }
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error iterating shadow transactions', $err);
            }

            $span->setAttribute('Shadow transactions retrieved', [
                'transaction.count' => \count($transactions),
                'parent_transaction_id' => $parentTransactionID,
            ]);
            return $transactions;
        } finally {
            $span->end();
        }
    }
}
