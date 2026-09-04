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
use Blnk\Model\Ledger;
use Blnk\Model\ModelHelpers;

/**
 * Port of database/ledger.go: the ledger methods of `Datasource`.
 *
 * Composed into {@see Datasource}; uses `$this->conn` (\PDO).
 */
trait LedgerRepository
{
    /**
     * CreateLedger inserts a new ledger record into the database, ensuring metadata is properly marshaled into JSON format.
     * It assigns a unique ledger ID with a suffix and captures the current timestamp as the creation time.
     *
     * Parameters:
     * - ledger: The ledger data to be inserted into the database.
     *
     * Returns:
     * - model.Ledger: The created ledger object including the generated LedgerID and creation timestamp.
     * - error: An error if the ledger creation fails, including specific database error handling for conflicts.
     *
     * (Go receives the ledger by value and returns a modified copy; PHP objects
     * are handles, so the passed object is updated in place and returned.)
     *
     * @throws ApiErrorException
     */
    public function createLedger(Ledger $ledger): Ledger
    {
        // Marshal the metadata into JSON format
        try {
            $metaDataJSON = PqEncoder::json($ledger->metaData);
        } catch (\JsonException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to marshal metadata', $e->getMessage());
        }

        // Assign a unique ledger ID and record the creation time
        $ledger->ledgerID = ModelHelpers::generateUUIDWithSuffix('ldg');
        $ledger->createdAt = PqEncoder::now();

        // Insert the ledger into the database
        try {
            PgStatement::execute($this->conn, '
		INSERT INTO blnk.ledgers (meta_data, name, ledger_id)
		VALUES (?, ?, ?)
	', [$metaDataJSON, $ledger->name, $ledger->ledgerID]);
        } catch (\PDOException $e) {
            // Handle database errors, specifically unique constraint violations
            if (PgStatement::isServerError($e)) {
                switch (PgStatement::conditionName($e)) {
                    case 'unique_violation':
                        throw ApiErrorException::newApiError(ErrorCode::ErrConflict, 'Ledger with this name or ID already exists', $e->getMessage());
                    default:
                        throw PgStatement::wrap($e, 'Database error occurred');
                }
            }
            throw PgStatement::wrap($e, 'Failed to create ledger');
        }

        return $ledger;
    }

    /**
     * GetAllLedgers retrieves a paginated list of ledger records from the database, unmarshaling their metadata from JSON format.
     * This method supports pagination and can be used to efficiently retrieve all ledgers over multiple requests.
     *
     * Parameters:
     * - limit: The maximum number of ledgers to return (e.g., 20).
     * - offset: The offset to start fetching ledgers from (for pagination).
     *
     * Returns:
     * - []model.Ledger: A slice of ledgers retrieved from the database.
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * @return Ledger[]
     *
     * @throws ApiErrorException
     */
    public function getAllLedgers(int $limit, int $offset): array
    {
        if ($limit <= 0 || $limit > 100) {
            $limit = 20; // Default limit to 20 if the provided limit is invalid or too large
        }

        // Execute a paginated query to select ledgers from the database
        $query = '
		SELECT ledger_id, name, created_at, meta_data
		FROM blnk.ledgers
		ORDER BY created_at DESC
		LIMIT ? OFFSET ?
	';

        try {
            $stmt = PgStatement::execute($this->conn, $query, [$limit, $offset]);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, $e->getMessage());
        }

        $ledgers = [];

        // Iterate through the query results, scanning each row into a ledger object
        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $ledgers[] = $this->rowToLedger($row);
            }
        } catch (\PDOException $e) {
            // Check for any errors that occurred during the iteration of the rows
            throw PgStatement::wrap($e, 'Error occurred while iterating over ledgers');
        }

        return $ledgers;
    }

    /**
     * GetLedgerByID retrieves a ledger record from the database by its ID.
     * It handles cases where the ledger is not found and unmarshals the metadata from JSON format.
     *
     * Parameters:
     * - id: The unique ID of the ledger to retrieve.
     *
     * Returns:
     * - *model.Ledger: The ledger object, if found.
     * - error: An error if the ledger is not found or if the query fails.
     *
     * @throws NotFoundException "Ledger not found"
     * @throws ApiErrorException
     */
    public function getLedgerByID(string $id): Ledger
    {
        // Query the database to find the ledger by its ID
        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT ledger_id, name, created_at, meta_data
		FROM blnk.ledgers
		WHERE ledger_id = ?
	', [$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to retrieve ledger');
        }

        if ($row === false) {
            // Handle case where the ledger is not found
            throw new NotFoundException('Ledger not found', 'sql: no rows in result set');
        }

        // Unmarshal the metadata JSON into the ledger's MetaData field
        return $this->rowToLedger($row);
    }

    /**
     * UpdateLedger updates an existing ledger's name in the database.
     * It validates that the ledger exists and updates only the name field.
     *
     * Parameters:
     * - id: The unique ID of the ledger to update.
     * - name: The new name for the ledger.
     *
     * Returns:
     * - *model.Ledger: The updated ledger object.
     * - error: An error if the ledger is not found or if the update fails.
     *
     * @throws NotFoundException when the ledger does not exist
     * @throws ApiErrorException
     */
    public function updateLedger(string $id, string $name): Ledger
    {
        // First, check if the ledger exists
        $existingLedger = $this->getLedgerByID($id);

        // Update the ledger name
        try {
            PgStatement::execute($this->conn, '
		UPDATE blnk.ledgers
		SET name = ?
		WHERE ledger_id = ?
	', [$name, $id]);
        } catch (\PDOException $e) {
            if (PgStatement::isServerError($e)) {
                switch (PgStatement::conditionName($e)) {
                    case 'unique_violation':
                        throw ApiErrorException::newApiError(ErrorCode::ErrConflict, 'Ledger with this name already exists', $e->getMessage());
                    default:
                        throw PgStatement::wrap($e, 'Database error occurred');
                }
            }
            throw PgStatement::wrap($e, 'Failed to update ledger');
        }

        // Update the existing ledger object with the new name
        $existingLedger->name = $name;

        return $existingLedger;
    }

    /**
     * GetAllLedgersWithFilter retrieves ledgers with advanced filtering support.
     * It delegates to GetAllLedgersWithFilterAndOptions with nil options.
     *
     * Parameters:
     * - filters: A QueryFilterSet containing the filter conditions.
     * - limit: The maximum number of ledgers to return.
     * - offset: The offset to start fetching ledgers from (for pagination).
     *
     * Returns:
     * - []model.Ledger: A slice of ledgers matching the filter criteria.
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * @return Ledger[]
     *
     * @throws ApiErrorException
     */
    public function getAllLedgersWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array
    {
        [$ledgers] = $this->getAllLedgersWithFilterAndOptions($filters, null, $limit, $offset);
        return $ledgers;
    }

    /**
     * GetAllLedgersWithFilterAndOptions retrieves ledgers with filtering, sorting, and optional count.
     * It uses the filter package to build SQL WHERE and ORDER BY conditions.
     *
     * Parameters:
     * - filters: A QueryFilterSet containing the filter conditions.
     * - opts: Query options including sorting and count settings.
     * - limit: The maximum number of ledgers to return.
     * - offset: The offset to start fetching ledgers from (for pagination).
     *
     * Returns:
     * - []model.Ledger: A slice of ledgers matching the filter criteria.
     * - *int64: Optional total count of matching records (if opts.IncludeCount is true).
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * @return array{0: Ledger[], 1: int|null} `[$ledgers, $totalCount]`
     *
     * @throws ApiErrorException
     */
    public function getAllLedgersWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array
    {
        if ($limit <= 0 || $limit > 100) {
            $limit = 20;
        }

        if ($opts === null) {
            $opts = new QueryOptions();
        }
        try {
            Validation::validateSortByForTable($opts, 'ledgers');
        } catch (FilterValidationException) {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, 'Invalid sort_by field', null);
        }

        try {
            $result = SqlBuilder::buildWithOptions($filters, 'ledgers', '', 1, $opts);
        } catch (FilterValidationException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, sprintf('Invalid filter: %s', $e->getMessage()), $e->getMessage());
        }

        // Determine select fields based on whether count is requested
        $selectFields = 'ledger_id, name, created_at, meta_data';
        if ($opts->includeCount) {
            $selectFields = 'ledger_id, name, created_at, meta_data, COUNT(*) OVER() AS total_count';
        }

        // Build base query
        $baseQuery = sprintf('
		SELECT %s
		FROM blnk.ledgers
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
            throw PgStatement::wrap($e, 'Failed to retrieve ledgers');
        }

        $ledgers = [];
        $totalCount = null;

        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if ($opts->includeCount) {
                    $count = RowScanner::toInt($row['total_count'] ?? null);
                    if ($totalCount === null) {
                        $totalCount = $count;
                    }
                }

                $ledgers[] = $this->rowToLedger($row);
            }
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Error occurred while iterating over ledgers');
        }

        return [$ledgers, $totalCount];
    }

    /**
     * Maps a `ledger_id, name, created_at, meta_data` row into a Ledger — the
     * `rows.Scan(&ledger.LedgerID, &ledger.Name, &ledger.CreatedAt, &metaDataJSON)`
     * plus `json.Unmarshal(metaDataJSON, &ledger.MetaData)` pair of the Go code.
     *
     * @param array<string, mixed> $row
     *
     * @throws ApiErrorException "Failed to scan ledger data" / "Failed to unmarshal metadata"
     */
    private function rowToLedger(array $row): Ledger
    {
        $ledger = new Ledger();
        try {
            $ledger->ledgerID = RowScanner::toString($row['ledger_id'] ?? null);
            $ledger->name = RowScanner::toString($row['name'] ?? null);
            $ledger->createdAt = RowScanner::toTime($row['created_at'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to scan ledger data', $e->getMessage());
        }

        // Unmarshal the metadata JSON into the ledger's MetaData field
        try {
            $ledger->metaData = RowScanner::toMetaData($row['meta_data'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $e->getMessage());
        }

        return $ledger;
    }
}
