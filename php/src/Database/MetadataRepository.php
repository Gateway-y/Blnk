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

use Blnk\Internal\ApiError\ApiErrorException;

/**
 * Port of database/metadata.go: the metadata update methods of `Datasource`.
 *
 * Composed into {@see Datasource}; uses `$this->conn` (\PDO). The Go methods
 * return raw errors (no apierror wrapping): a JSON marshalling failure or a
 * PDO failure surfaces as a {@see DatabaseException} carrying the driver message.
 */
trait MetadataRepository
{
    /**
     * UpdateLedgerMetadata updates the metadata for a specific ledger in the database.
     * It marshals the metadata map to JSON before storing it.
     *
     * Parameters:
     * - id: The ID of the ledger to update.
     * - metadata: The new metadata to store.
     *
     * Returns:
     * - error: An error if the update operation fails.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws ApiErrorException
     */
    public function updateLedgerMetadata(string $id, array $metadata): void
    {
        $metadataJSON = $this->marshalMetadataForUpdate($metadata);

        try {
            PgStatement::execute($this->conn, '
		UPDATE blnk.ledgers
		SET meta_data = ?
		WHERE ledger_id = ?
	', [$metadataJSON, $id]);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }
    }

    /**
     * UpdateTransactionMetadata updates the metadata for a specific transaction in the database.
     * It merges the provided metadata with existing metadata for each matching transaction.
     * The update applies to both the transaction with the provided ID and any transactions
     * where this ID is set as the parent_transaction.
     *
     * Parameters:
     * - id: The ID of the transaction to update.
     * - metadata: The new metadata to merge with existing metadata.
     *
     * Returns:
     * - error: An error if the update operation fails.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws ApiErrorException
     */
    public function updateTransactionMetadata(string $id, array $metadata): void
    {
        $metadataJSON = $this->marshalMetadataForUpdate($metadata);

        // Merge into the existing metadata rather than replacing it. The left operand
        // is coerced to an object first: jsonb concatenation of a non-object (a JSON
        // null from a transaction stored without metadata, or a scalar) with an object
        // yields an array, which corrupts the column.
        // (Go binds $2 twice; with positional placeholders the id is bound twice.)
        try {
            PgStatement::execute($this->conn, '
		UPDATE blnk.transactions
		SET meta_data = (CASE WHEN jsonb_typeof(meta_data) = \'object\' THEN meta_data ELSE \'{}\'::jsonb END) || ?::jsonb
		WHERE transaction_id = ? OR parent_transaction = ?
	', [$metadataJSON, $id, $id]);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }
    }

    /**
     * UpdateBalanceMetadata updates the metadata for a specific balance in the database.
     * It marshals the metadata map to JSON before storing it.
     *
     * Parameters:
     * - id: The ID of the balance to update.
     * - metadata: The new metadata to store.
     *
     * Returns:
     * - error: An error if the update operation fails.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws ApiErrorException
     */
    public function updateBalanceMetadata(string $id, array $metadata): void
    {
        $metadataJSON = $this->marshalMetadataForUpdate($metadata);

        try {
            PgStatement::execute($this->conn, '
		UPDATE blnk.balances
		SET meta_data = ?
		WHERE balance_id = ?
	', [$metadataJSON, $id]);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }
    }

    /**
     * UpdateIdentityMetadata updates the metadata for a specific identity in the database.
     * It marshals the metadata map to JSON before storing it.
     *
     * Parameters:
     * - id: The ID of the identity to update.
     * - metadata: The new metadata to store.
     *
     * Returns:
     * - error: An error if the update operation fails.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws ApiErrorException
     */
    public function updateIdentityMetadata(string $id, array $metadata): void
    {
        $metadataJSON = $this->marshalMetadataForUpdate($metadata);

        try {
            PgStatement::execute($this->conn, '
		UPDATE blnk.identity
		SET meta_data = ?
		WHERE identity_id = ?
	', [$metadataJSON, $id]);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }
    }

    /**
     * `json.Marshal(metadata)` for the metadata update statements; the Go code
     * returns the marshalling error unwrapped.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws DatabaseException carrying the encoder message
     */
    private function marshalMetadataForUpdate(array $metadata): string
    {
        try {
            return PqEncoder::json($metadata);
        } catch (\JsonException $e) {
            throw new DatabaseException($e->getMessage(), null, null, $e);
        }
    }
}
