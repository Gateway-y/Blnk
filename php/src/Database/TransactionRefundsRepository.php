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
 * Port of Go `database/transaction_refunds.go`: the refundable-transaction
 * listing of the `transaction` concern of {@see Datasource} (composed as a
 * trait).
 *
 * The trailing doc comment of the Go file ("GetQueuedAmounts retrieves the
 * total queued debit and credit amounts ...") documents a method that lives in
 * transaction_queue.go; it is carried by
 * {@see TransactionQueueRepository::getQueuedAmounts()}.
 */
trait TransactionRefundsRepository
{
    /**
     * GetRefundableTransactionsByParentID retrieves, page by page, the
     * transactions that can be refunded for a parent: the APPLIED parent
     * itself, its APPLIED/VOID children, and APPLIED transactions linked to it
     * through the `QUEUED_PARENT_TRANSACTION` metadata key.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getRefundableTransactionsByParentID(string $parentTransactionID, int $batchSize, int $offset): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetRefundableTransactionsByParentID');
        try {
            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT
                        t.transaction_id, t.parent_transaction, t.source, t.reference, t.amount, t.precise_amount,
                        t.precision, t.currency, t.destination, t.description, t.status, t.created_at,
                        t.meta_data, t.scheduled_for, t.hash
                    FROM
                        blnk.transactions t
                    WHERE
                        -- Case 1: The transaction is the parent itself and is APPLIED
                        (t.transaction_id = ? AND t.status = 'APPLIED')

                        -- Case 2: The transaction is a child and is APPLIED or VOID
                        OR (t.parent_transaction = ? AND t.status IN ('APPLIED', 'VOID'))

                        -- Case 3: Transaction is APPLIED and linked via metadata QUEUED_PARENT_TRANSACTION
                        OR (t.status = 'APPLIED' AND t.meta_data->>'QUEUED_PARENT_TRANSACTION' = ?)

                    ORDER BY
                        t.created_at DESC
                    LIMIT ? OFFSET ?
                    SQL);
                // Go binds $1 (three occurrences), $2 and $3.
                $stmt->execute([$parentTransactionID, $parentTransactionID, $parentTransactionID, $batchSize, $offset]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve refundable transactions', $err);
            }

            $transactions = [];

            // Iterate over the result set and map to transaction models
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

            $span->setAttribute('Refundable transactions retrieved', [
                'transaction.count' => \count($transactions),
            ]);
            return $transactions;
        } finally {
            $span->end();
        }
    }
}
