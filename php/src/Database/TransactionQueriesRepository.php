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
 * Port of Go `database/transaction_queries.go`: single-transaction lookups,
 * existence checks and the legacy "all transactions" listing of the
 * `transaction` concern of {@see Datasource} (composed as a trait).
 *
 * The trailing doc comment of the Go file ("GetTotalCommittedTransactions
 * calculates the total committed transaction amounts ...") documents a method
 * that lives in transaction_inflight.go; it is carried by
 * {@see TransactionInflightRepository::getTotalCommittedTransactions()}.
 */
trait TransactionQueriesRepository
{
    /**
     * GetTransaction retrieves a transaction by its ID from the database.
     * It logs the transaction retrieval using OpenTelemetry tracing.
     * Parameters:
     * - id: The unique transaction ID.
     * Returns:
     * - The retrieved transaction if successful, or throws if retrieval fails.
     *
     * @throws NotFoundException "Transaction with ID '<id>' not found" (Go: sql.ErrNoRows → ErrNotFound)
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve transaction" / "Failed to unmarshal metadata"
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getTransaction(string $id): Transaction
    {
        // Start a new tracing span for the database operation
        $span = Tracer::get('transaction.database')->startSpan('GetTransaction');
        try {
            // Execute the SQL query to retrieve the transaction by its ID
            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT transaction_id, source, reference, amount, precise_amount, precision, currency, destination, description, status, created_at, meta_data, parent_transaction, hash
                    FROM blnk.transactions
                    WHERE transaction_id = ?
                    SQL);
                $stmt->execute([$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve transaction', $err);
            }

            // Handle errors, including no rows found
            if ($row === false) {
                $err = TransactionRowMapper::apiError(ErrorCode::ErrNotFound, sprintf("Transaction with ID '%s' not found", $id), TransactionRowMapper::ERR_NO_ROWS);
                $span->recordError($err);
                throw $err;
            }

            // Initialize a Transaction model and scan the result into it
            $txn = TransactionRowMapper::scanTransaction($row);

            // Unmarshal the metadata JSON into the transaction's MetaData field
            try {
                $txn->metaData = TransactionRowMapper::unmarshalMetaData($row['meta_data']);
            } catch (\JsonException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $err);
            }

            try {
                $txn->preciseAmount = TransactionRowMapper::parseBigInt((string) $row['precise_amount']);
            } catch (\RuntimeException $err) {
                $span->recordError($err);
                throw new \RuntimeException('failed to parse precise_amount: ' . $err->getMessage(), 0, $err);
            }

            // Log the successful transaction retrieval as an event in the tracing span
            $span->setAttribute('Transaction retrieved', [
                'transaction.id' => $txn->transactionID,
                'transaction.reference' => $txn->reference,
            ]);

            return $txn;
        } finally {
            $span->end();
        }
    }

    /**
     * IsParentTransactionVoid checks if a parent transaction has a status of 'VOID'.
     * It uses OpenTelemetry to trace the operation and returns a boolean indicating
     * whether any child transaction linked to the parent has a 'VOID' status.
     * Parameters:
     * - parentID: The unique ID of the parent transaction.
     * Returns:
     * - A boolean indicating whether the parent transaction is void; throws if the check fails.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to check if parent transaction is void"
     */
    public function isParentTransactionVoid(string $parentID): bool
    {
        // Start a new tracing span for the database operation
        $span = Tracer::get('transaction.database')->startSpan('IsParentTransactionVoid');
        try {
            // Execute the SQL query to check if any child transaction has a 'VOID' status
            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT EXISTS (
                        SELECT 1
                        FROM blnk.transactions
                        WHERE parent_transaction = ?
                        AND status = 'VOID'
                    )
                    SQL);
                $stmt->execute([$parentID]);
                // Variable to store whether the parent transaction is void
                $exists = TransactionRowMapper::toBool($stmt->fetchColumn());
            } catch (\PDOException $err) {
                // Handle errors from the query
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to check if parent transaction is void', $err);
            }

            // Log the void check result in the tracing span
            $span->setAttribute('Parent transaction void check', [
                'parent_transaction.id' => $parentID,
                'parent_transaction.void' => $exists,
            ]);

            return $exists;
        } finally {
            $span->end();
        }
    }

    /**
     * TransactionExistsByRef checks if a transaction with a given reference exists in the database.
     * It uses OpenTelemetry to trace the operation and returns a boolean indicating whether the transaction exists.
     * Parameters:
     * - reference: The reference of the transaction to check for existence.
     * Returns:
     * - A boolean indicating whether the transaction exists; throws if the check fails.
     *
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to check if transaction exists"
     */
    public function transactionExistsByRef(string $reference): bool
    {
        // Start a new tracing span for the database operation
        $span = Tracer::get('transaction.database')->startSpan('TransactionExistsByRef');
        try {
            // Execute the SQL query to check if the transaction exists by reference
            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT EXISTS(SELECT 1 FROM blnk.transactions WHERE reference = ?)
                    SQL);
                $stmt->execute([$reference]);
                // Variable to store whether the transaction exists
                $exists = TransactionRowMapper::toBool($stmt->fetchColumn());
            } catch (\PDOException $err) {
                // Handle errors from the query
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to check if transaction exists', $err);
            }

            // Log the transaction existence result in the tracing span
            $span->setAttribute('Transaction existence check', [
                'transaction.reference' => $reference,
                'transaction.exists' => $exists,
            ]);

            return $exists;
        } finally {
            $span->end();
        }
    }

    /**
     * GetExistingTransactionReferences retrieves the subset of the provided references that already
     * exist in the database. It returns an empty set when the input is empty.
     *
     * @param string[] $references
     * @return array<string, true> Go: map[string]struct{}
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    public function getExistingTransactionReferences(array $references): array
    {
        $span = Tracer::get('transaction.database')->startSpan('GetExistingTransactionReferences');
        try {
            $existing = [];
            if (\count($references) === 0) {
                return $existing;
            }

            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT reference
                    FROM blnk.transactions
                    WHERE reference = ANY(?)
                    SQL);
                $stmt->execute([TransactionRowMapper::pgTextArray($references)]); // pq.Array(references)
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve existing transaction references', $err);
            }

            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    if (!\array_key_exists('reference', $row)) {
                        $err = new \RuntimeException('missing column "reference"');
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to scan existing transaction reference', $err);
                    }
                    $existing[(string) $row['reference']] = true;
                }
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error iterating existing transaction references', $err);
            }

            $span->setAttribute('Existing transaction references retrieved', [
                'reference.requested_count' => \count($references),
                'reference.existing_count' => \count($existing),
            ]);

            return $existing;
        } finally {
            $span->end();
        }
    }

    /**
     * GetTransactionByRef retrieves a transaction from the database using the provided reference.
     * It traces the operation using OpenTelemetry and returns the transaction or throws.
     * Parameters:
     * - reference: The reference of the transaction to retrieve.
     * Returns:
     * - A Transaction representing the transaction; throws if the retrieval fails.
     *
     * @throws NotFoundException "Transaction with reference '<reference>' not found"
     * @throws \Blnk\Internal\ApiError\ApiErrorException "Failed to retrieve transaction" / "Failed to unmarshal metadata"
     * @throws \RuntimeException "failed to parse precise_amount: ..." (Go: fmt.Errorf wrapping)
     */
    public function getTransactionByRef(string $reference): Transaction
    {
        // Start a new tracing span for the database operation
        $span = Tracer::get('transaction.database')->startSpan('GetTransactionByRef');
        try {
            // Query the transaction by reference
            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT transaction_id, source, reference, amount, precise_amount, currency, destination, description, status, created_at, meta_data, parent_transaction
                    FROM blnk.transactions
                    WHERE reference = ?
                    SQL);
                $stmt->execute([$reference]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve transaction', $err);
            }

            if ($row === false) {
                $err = TransactionRowMapper::apiError(ErrorCode::ErrNotFound, sprintf("Transaction with reference '%s' not found", $reference), TransactionRowMapper::ERR_NO_ROWS);
                $span->recordError($err);
                throw $err;
            }

            // Initialize the transaction object and scan the query result into it
            $txn = TransactionRowMapper::scanTransaction($row);

            // Unmarshal the metadata JSON into the transaction's MetaData field
            try {
                $txn->metaData = TransactionRowMapper::unmarshalMetaData($row['meta_data']);
            } catch (\JsonException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $err);
            }

            try {
                $txn->preciseAmount = TransactionRowMapper::parseBigInt((string) $row['precise_amount']);
            } catch (\RuntimeException $err) {
                $span->recordError($err);
                throw new \RuntimeException('failed to parse precise_amount: ' . $err->getMessage(), 0, $err);
            }

            // Log the successful transaction retrieval in the tracing span
            $span->setAttribute('Transaction retrieved by reference', [
                'transaction.id' => $txn->transactionID,
                'transaction.reference' => $txn->reference,
            ]);

            return $txn;
        } finally {
            $span->end();
        }
    }

    /**
     * GetAllTransactions retrieves all transactions from the database, ordered by creation date in descending order.
     * It traces the operation using OpenTelemetry and throws if the retrieval or processing fails.
     * Returns:
     * - A list of transactions; throws if the retrieval fails.
     *
     * @return Transaction[]
     * @throws \Blnk\Internal\ApiError\ApiErrorException
     */
    public function getAllTransactions(int $limit, int $offset): array
    {
        // Start a new tracing span for the operation
        $span = Tracer::get('transaction.database')->startSpan('GetAllTransactions');
        try {
            // Execute the query to retrieve all transactions
            try {
                $stmt = $this->conn->prepare(<<<'SQL'
                    SELECT transaction_id, source, reference, amount, precise_amount, precision, currency, destination, description, status, hash, created_at, meta_data, parent_transaction
                    FROM blnk.transactions
                    ORDER BY created_at DESC
                    LIMIT ? OFFSET ?
                    SQL);
                $stmt->execute([$limit, $offset]);
            } catch (\PDOException $err) {
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to retrieve transactions', $err);
            }

            // Initialize a list to store the transactions
            $transactions = [];

            // Iterate through the result set
            try {
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    // Scan each row into the Transaction model
                    $transaction = TransactionRowMapper::scanTransaction($row);

                    // Parse the precise amount (NUMERIC) into a big integer, mirroring the other
                    // transaction row scans in this package.
                    try {
                        $transaction->preciseAmount = TransactionRowMapper::parseBigInt((string) $row['precise_amount']);
                    } catch (\RuntimeException $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to parse precise_amount', $err);
                    }

                    // Unmarshal metadata into the transaction's MetaData field
                    try {
                        $transaction->metaData = TransactionRowMapper::unmarshalMetaData($row['meta_data']);
                    } catch (\JsonException $err) {
                        $span->recordError($err);
                        throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $err);
                    }

                    // Append the transaction to the list
                    $transactions[] = $transaction;
                }
            } catch (\PDOException $err) {
                // Check for errors after the iteration
                $span->recordError($err);
                throw TransactionRowMapper::apiError(ErrorCode::ErrInternalServer, 'Error occurred while iterating over transactions', $err);
            }

            // Log the successful retrieval of transactions
            $span->setAttribute('All transactions retrieved', [
                'transaction.count' => \count($transactions),
            ]);

            // Return the list of transactions
            return $transactions;
        } finally {
            $span->end();
        }
    }
}
