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
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\Filter\FilterValidationException;
use Blnk\Internal\Filter\LogicalOperator;
use Blnk\Internal\Filter\QueryFilterSet;
use Blnk\Internal\Filter\QueryOptions;
use Blnk\Internal\Filter\SqlBuilder;
use Blnk\Internal\Filter\Validation;
use Blnk\Model\Identity;
use Blnk\Model\ModelHelpers;

/**
 * Port of database/identity.go: the identity methods of `Datasource`.
 *
 * Composed into {@see Datasource}; uses `$this->conn` (\PDO).
 *
 * `identity.DOB` is a Go `time.Time` value: an unset DOB is the zero time and
 * is stored as such (never SQL NULL); the PHP null stands for it on both
 * sides ({@see PqEncoder::time()} / {@see RowScanner::toTime()}).
 */
trait IdentityRepository
{
    /**
     * CreateIdentity inserts a new identity record into the database.
     *
     * IdentityID handling:
     *   - If the caller supplies identity.IdentityID, it is preserved as-is.
     *     The value must carry the canonical "idt_" prefix followed by a valid
     *     UUID; otherwise the request is rejected with a 400. This lets callers
     *     derive deterministic identity ids (e.g. UUIDv5 of an external holder
     *     key) and rely on the UNIQUE constraint on identity_id for safe
     *     concurrent creation: parallel requests for the same external holder
     *     produce identical ids, one wins the insert, the others receive 409
     *     Conflict and can fetch the existing row.
     *   - If identity.IdentityID is empty, a fresh id is generated (existing
     *     behaviour).
     *
     * Parameters:
     * - identity: The identity object to be inserted.
     * Returns:
     * - The created identity object, or an error if the creation fails.
     *
     * (Go receives the identity by value and returns a modified copy; PHP
     * objects are handles, so the passed object is updated in place and returned.)
     *
     * @throws ApiErrorException
     */
    public function createIdentity(Identity $identity): Identity
    {
        // Marshal metadata into JSON format
        try {
            $metaDataJSON = PqEncoder::json($identity->metaData);
        } catch (\JsonException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to marshal metadata', $e->getMessage());
        }

        // Honor a caller-supplied id when present; otherwise generate one.
        if ($identity->identityID === '') {
            $identity->identityID = ModelHelpers::generateUUIDWithSuffix('idt');
        } else {
            if (!str_starts_with($identity->identityID, 'idt_')) {
                throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, "identity_id must start with the 'idt_' prefix", null);
            }
            $suffix = substr($identity->identityID, \strlen('idt_'));
            if (!$this->isValidUUID($suffix)) {
                throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, "identity_id suffix after 'idt_' must be a valid UUID", sprintf('invalid UUID: %s', $suffix));
            }
        }
        $identity->createdAt = PqEncoder::now();

        // Insert the identity record into the database
        try {
            PgStatement::execute($this->conn, '
		INSERT INTO blnk.identity (identity_id, identity_type, first_name, last_name, other_names, gender, dob, email_address, phone_number, nationality, organization_name, category, street, country, state, post_code, city, created_at, meta_data)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
	', [
                $identity->identityID,
                $identity->identityType,
                $identity->firstName,
                $identity->lastName,
                $identity->otherNames,
                $identity->gender,
                PqEncoder::time($identity->dob),
                $identity->emailAddress,
                $identity->phoneNumber,
                $identity->nationality,
                $identity->organizationName,
                $identity->category,
                $identity->street,
                $identity->country,
                $identity->state,
                $identity->postCode,
                $identity->city,
                $identity->createdAt,
                $metaDataJSON,
            ]);
        } catch (\PDOException $e) {
            // Surface unique_violation on identity_id as 409 so callers using
            // deterministic ids can detect "already created by a concurrent
            // request" and fetch the existing row.
            if (PgStatement::isServerError($e) && PgStatement::conditionName($e) === 'unique_violation') {
                throw ApiErrorException::newApiError(ErrorCode::ErrConflict, sprintf('Identity already exists: %s', $identity->identityID), $e->getMessage());
            }
            throw PgStatement::wrap($e, 'Failed to create identity');
        }

        // Return the created identity
        return $identity;
    }

    /**
     * `uuid.Parse` (github.com/google/uuid) acceptance check: the canonical
     * 36-character form, the same with a `urn:uuid:` prefix or wrapped in
     * braces (the closing brace is not verified, as in the Go code), or the
     * 32-hex-digit form; hex digits are case-insensitive.
     */
    private function isValidUUID(string $s): bool
    {
        switch (\strlen($s)) {
            // xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
            case 36:
                break;
            // urn:uuid:xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
            case 36 + 9:
                if (strcasecmp(substr($s, 0, 9), 'urn:uuid:') !== 0) {
                    return false;
                }
                $s = substr($s, 9);
                break;
            // {xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx}
            case 36 + 2:
                $s = substr($s, 1, 36);
                break;
            // xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
            case 32:
                return ctype_xdigit($s);
            default:
                return false;
        }

        // s is now 36 bytes long; it must be of the form xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $s) === 1;
    }

    /**
     * GetIdentityByID retrieves an identity from the database based on the given identity ID.
     * It starts a transaction, executes a query to fetch the identity details, and commits the transaction upon success.
     * Parameters:
     * - id: The ID of the identity to be retrieved.
     * Returns:
     * - A pointer to the Identity object if found, or an error if the identity is not found or the query fails.
     *
     * (Go's 1-minute context timeout is dropped with the context.)
     *
     * @throws NotFoundException "Identity with ID '<id>' not found"
     * @throws ApiErrorException
     */
    public function getIdentityByID(string $id): Identity
    {
        // Begin a transaction
        try {
            $this->conn->beginTransaction();
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to begin transaction');
        }

        // Query the database for the identity by ID
        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT identity_id, identity_type, first_name, last_name, other_names, gender, dob, email_address, phone_number, nationality, organization_name, category, street, country, state, post_code, city, created_at, meta_data
		FROM blnk.identity
		WHERE identity_id = ?
	', [$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            PgStatement::rollbackQuietly($this->conn);
            throw PgStatement::wrap($e, 'Failed to retrieve identity');
        }

        // Handle potential errors during the scan
        if ($row === false) {
            PgStatement::rollbackQuietly($this->conn);
            throw new NotFoundException(sprintf("Identity with ID '%s' not found", $id), 'sql: no rows in result set');
        }

        // Scan the row into the identity object and unmarshal the metadata JSON
        try {
            $identity = $this->rowToIdentity($row);
        } catch (ApiErrorException $e) {
            PgStatement::rollbackQuietly($this->conn);
            throw $e;
        }

        // Commit the transaction
        try {
            $this->conn->commit();
        } catch (\PDOException $e) {
            PgStatement::rollbackQuietly($this->conn);
            throw PgStatement::wrap($e, 'Failed to commit transaction');
        }

        // Return the retrieved identity
        return $identity;
    }

    /**
     * Maps a full `blnk.identity` row (the 19 columns every identity SELECT
     * lists) into an Identity — the `rows.Scan(&identity.IdentityID, ...)`
     * plus `json.Unmarshal(metaDataJSON, &identity.MetaData)` pair of the Go code.
     *
     * @param array<string, mixed> $row
     *
     * @throws ApiErrorException "Failed to scan identity data" / "Failed to unmarshal metadata"
     */
    private function rowToIdentity(array $row): Identity
    {
        $identity = new Identity();
        try {
            $identity->identityID = RowScanner::toString($row['identity_id'] ?? null);
            $identity->identityType = RowScanner::toString($row['identity_type'] ?? null);
            $identity->firstName = RowScanner::toString($row['first_name'] ?? null);
            $identity->lastName = RowScanner::toString($row['last_name'] ?? null);
            $identity->otherNames = RowScanner::toString($row['other_names'] ?? null);
            $identity->gender = RowScanner::toString($row['gender'] ?? null);
            $identity->dob = RowScanner::toTime($row['dob'] ?? null);
            $identity->emailAddress = RowScanner::toString($row['email_address'] ?? null);
            $identity->phoneNumber = RowScanner::toString($row['phone_number'] ?? null);
            $identity->nationality = RowScanner::toString($row['nationality'] ?? null);
            $identity->organizationName = RowScanner::toString($row['organization_name'] ?? null);
            $identity->category = RowScanner::toString($row['category'] ?? null);
            $identity->street = RowScanner::toString($row['street'] ?? null);
            $identity->country = RowScanner::toString($row['country'] ?? null);
            $identity->state = RowScanner::toString($row['state'] ?? null);
            $identity->postCode = RowScanner::toString($row['post_code'] ?? null);
            $identity->city = RowScanner::toString($row['city'] ?? null);
            $identity->createdAt = RowScanner::toTime($row['created_at'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to scan identity data', $e->getMessage());
        }

        // Unmarshal the metadata JSON into the identity's MetaData field
        try {
            $identity->metaData = RowScanner::toMetaData($row['meta_data'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $e->getMessage());
        }

        return $identity;
    }

    /**
     * GetAllIdentities retrieves all identities from the database.
     * It executes a query to fetch all identity records, parses the result into Identity structs, and handles metadata unmarshalling.
     * Returns:
     * - A slice of Identity objects if successful, or an error if any operation fails.
     *
     * @return Identity[]
     *
     * @throws ApiErrorException
     */
    public function getAllIdentities(): array
    {
        // Execute query to retrieve all identities, ordered by creation date
        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT identity_id, identity_type, first_name, last_name, other_names, gender, dob, email_address, phone_number, nationality, organization_name, category, street, country, state, post_code, city, created_at, meta_data
		FROM blnk.identity
		ORDER BY created_at DESC
	');
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to retrieve identities');
        }

        $identities = [];

        // Iterate through the result set
        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                // Scan the row into the identity object and unmarshal its metadata
                $identities[] = $this->rowToIdentity($row);
            }
        } catch (\PDOException $e) {
            // Check for any errors encountered during row iteration
            throw PgStatement::wrap($e, 'Error occurred while iterating over identities');
        }

        // Return the slice of identities
        return $identities;
    }

    /**
     * UpdateIdentity updates a specific identity record in the database.
     * It marshals the identity metadata, constructs an SQL update query, and checks the result.
     * Parameters:
     * - identity: A pointer to the Identity object containing the updated details.
     * Returns:
     * - An error if the update fails, or nil if successful.
     *
     * @throws NotFoundException "Identity with ID '<id>' not found"
     * @throws ApiErrorException
     */
    public function updateIdentity(Identity $identity): void
    {
        $setFields = [];
        $args = [];

        // Helper function to add a field to the update query if it has a value
        // (Go switches on the value's type: a non-zero time.Time, a non-empty
        // string, or any other non-nil value. A null PHP timestamp is the zero time.)
        $addField = function (mixed $value, string $fieldName) use (&$setFields, &$args): void {
            if ($value instanceof \DateTimeInterface) {
                if (!PqEncoder::isZeroTime($value)) {
                    $setFields[] = sprintf('%s = ?', $fieldName);
                    $args[] = $value;
                }
            } elseif (\is_string($value)) {
                if ($value !== '') {
                    $setFields[] = sprintf('%s = ?', $fieldName);
                    $args[] = $value;
                }
            } else {
                if ($value !== null) {
                    $setFields[] = sprintf('%s = ?', $fieldName);
                    $args[] = $value;
                }
            }
        };

        // Add fields to update only if they have values
        $addField($identity->identityType, 'identity_type');
        $addField($identity->firstName, 'first_name');
        $addField($identity->lastName, 'last_name');
        $addField($identity->otherNames, 'other_names');
        $addField($identity->gender, 'gender');
        $addField($identity->dob, 'dob');
        $addField($identity->emailAddress, 'email_address');
        $addField($identity->phoneNumber, 'phone_number');
        $addField($identity->nationality, 'nationality');
        $addField($identity->organizationName, 'organization_name');
        $addField($identity->category, 'category');
        $addField($identity->street, 'street');
        $addField($identity->country, 'country');
        $addField($identity->state, 'state');
        $addField($identity->postCode, 'post_code');
        $addField($identity->city, 'city');

        // Always update metadata if it exists
        if ($identity->metaData !== null) {
            try {
                $metaDataJSON = PqEncoder::json($identity->metaData);
            } catch (\JsonException $e) {
                throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to marshal metadata', $e->getMessage());
            }
            $setFields[] = 'meta_data = ?';
            $args[] = $metaDataJSON;
        }

        // If no fields to update, return early
        if (\count($setFields) === 0) {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, 'No fields provided for update', null);
        }

        // Build the SQL query
        $query = sprintf('
		UPDATE blnk.identity
		SET %s
		WHERE identity_id = ?
	', implode(', ', $setFields));

        // Add identity ID as the last argument
        $args[] = $identity->identityID;

        // Execute the update query
        try {
            $result = PgStatement::execute($this->conn, $query, $args);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to update identity');
        }

        $rowsAffected = $result->rowCount();

        if ($rowsAffected === 0) {
            throw new NotFoundException(sprintf("Identity with ID '%s' not found", $identity->identityID));
        }
    }

    /**
     * DeleteIdentity deletes a specific identity record from the database.
     * It executes the SQL delete query based on the provided identity ID.
     * Parameters:
     * - id: The ID of the identity to be deleted.
     * Returns:
     * - An error if the deletion fails, or nil if successful.
     *
     * @throws NotFoundException "Identity with ID '<id>' not found"
     * @throws ApiErrorException
     */
    public function deleteIdentity(string $id): void
    {
        // Execute the SQL delete query
        try {
            $result = PgStatement::execute($this->conn, '
		DELETE FROM blnk.identity
		WHERE identity_id = ?
	', [$id]);
        } catch (\PDOException $e) {
            // Handle any errors that occur during execution
            throw PgStatement::wrap($e, 'Failed to delete identity');
        }

        // Check how many rows were affected by the delete query
        $rowsAffected = $result->rowCount();

        // If no rows were deleted, return a "not found" error
        if ($rowsAffected === 0) {
            throw new NotFoundException(sprintf("Identity with ID '%s' not found", $id));
        }
    }

    /**
     * GetAllIdentitiesPaginated retrieves identities from the database with pagination support.
     * Parameters:
     * - limit: The maximum number of identities to return.
     * - offset: The offset to start fetching identities from (for pagination).
     * Returns:
     * - A slice of Identity objects if successful, or an error if any operation fails.
     *
     * @return Identity[]
     *
     * @throws ApiErrorException
     */
    public function getAllIdentitiesPaginated(int $limit, int $offset): array
    {
        if ($limit <= 0 || $limit > 100) {
            $limit = 20;
        }

        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT identity_id, identity_type, first_name, last_name, other_names, gender, dob, email_address, phone_number, nationality, organization_name, category, street, country, state, post_code, city, created_at, meta_data
		FROM blnk.identity
		ORDER BY created_at DESC
		LIMIT ? OFFSET ?
	', [$limit, $offset]);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to retrieve identities');
        }

        $identities = [];

        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $identities[] = $this->rowToIdentity($row);
            }
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Error occurred while iterating over identities');
        }

        return $identities;
    }

    /**
     * GetAllIdentitiesWithFilter retrieves identities with advanced filtering support.
     * It delegates to GetAllIdentitiesWithFilterAndOptions with nil options.
     *
     * Parameters:
     * - filters: A QueryFilterSet containing the filter conditions.
     * - limit: The maximum number of identities to return.
     * - offset: The offset to start fetching identities from (for pagination).
     *
     * Returns:
     * - []model.Identity: A slice of identities matching the filter criteria.
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * @return Identity[]
     *
     * @throws ApiErrorException
     */
    public function getAllIdentitiesWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array
    {
        [$identities] = $this->getAllIdentitiesWithFilterAndOptions($filters, null, $limit, $offset);
        return $identities;
    }

    /**
     * GetAllIdentitiesWithFilterAndOptions retrieves identities with filtering, sorting, and optional count.
     * It uses the filter package to build SQL WHERE and ORDER BY conditions.
     *
     * Parameters:
     * - filters: A QueryFilterSet containing the filter conditions.
     * - opts: Query options including sorting and count settings.
     * - limit: The maximum number of identities to return.
     * - offset: The offset to start fetching identities from (for pagination).
     *
     * Returns:
     * - []model.Identity: A slice of identities matching the filter criteria.
     * - *int64: Optional total count of matching records (if opts.IncludeCount is true).
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * @return array{0: Identity[], 1: int|null} `[$identities, $totalCount]`
     *
     * @throws ApiErrorException
     */
    public function getAllIdentitiesWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array
    {
        if ($limit <= 0 || $limit > 100) {
            $limit = 20;
        }

        if ($opts === null) {
            $opts = new QueryOptions();
        }
        try {
            Validation::validateSortByForTable($opts, 'identity');
        } catch (FilterValidationException) {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, 'Invalid sort_by field', null);
        }

        try {
            $result = SqlBuilder::buildWithOptions($filters, 'identity', '', 1, $opts);
        } catch (FilterValidationException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, sprintf('Invalid filter: %s', $e->getMessage()), $e->getMessage());
        }

        // Determine select fields based on whether count is requested
        $selectFields = 'identity_id, identity_type, first_name, last_name, other_names, gender, dob, email_address, phone_number, nationality, organization_name, category, street, country, state, post_code, city, created_at, meta_data';
        if ($opts->includeCount) {
            $selectFields .= ', COUNT(*) OVER() AS total_count';
        }

        // Build base query
        $baseQuery = sprintf('
		SELECT %s
		FROM blnk.identity
	', $selectFields);

        $args = $result->args;

        // Add WHERE clause if filters are provided
        if (\count($result->conditions) > 0) {
            $logicalOperator = LogicalOperator::LogicalAnd;
            if ($filters !== null) {
                $logicalOperator = $filters->logicalOperator;
            }
            $baseQuery .= ' WHERE ' . SqlBuilder::buildConditionExpression($result->conditions, $logicalOperator);
        }

        // Add ORDER BY clause
        $baseQuery .= ' ORDER BY ' . $result->orderBy;

        // Add pagination (Go: " LIMIT $%d OFFSET $%d" at result.NextArgPos)
        $baseQuery .= ' LIMIT ? OFFSET ?';
        $args[] = $limit;
        $args[] = $offset;

        try {
            $stmt = PgStatement::execute($this->conn, $baseQuery, $args);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to retrieve identities');
        }

        $identities = [];
        $totalCount = null;

        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if ($opts->includeCount) {
                    $count = RowScanner::toInt($row['total_count'] ?? null);
                    if ($totalCount === null) {
                        $totalCount = $count;
                    }
                }

                $identities[] = $this->rowToIdentity($row);
            }
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Error occurred while iterating over identities');
        }

        return [$identities, $totalCount];
    }
}
