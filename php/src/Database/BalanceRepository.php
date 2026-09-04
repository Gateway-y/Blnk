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
use Blnk\Internal\Filter\Helpers as FilterHelpers;
use Blnk\Internal\Filter\LogicalOperator;
use Blnk\Internal\Filter\QueryFilterSet;
use Blnk\Internal\Filter\QueryOptions;
use Blnk\Internal\Filter\SqlBuilder;
use Blnk\Internal\Filter\Validation;
use Blnk\Internal\Log;
use Blnk\Model\AlertCondition;
use Blnk\Model\Balance;
use Blnk\Model\BalanceMonitor;
use Blnk\Model\Identity;
use Blnk\Model\Ledger;
use Blnk\Model\ModelHelpers;
use Brick\Math\BigInteger;

/**
 * Port of database/balance.go: the balance, balance-monitor and
 * balance-snapshot methods of `Datasource`, plus the file's package-level
 * helpers (`contains`, `parseBigInt`, `prepareQueries`, `scanRow`,
 * `updateBalance`, `updateBalanceSet`, `updateBalanceChunk`,
 * `validateBalanceTimeParams`, `fetchTransactions`, `applyTransaction`) as
 * private methods.
 *
 * Composed into {@see Datasource}; uses `$this->conn` (\PDO). Go's `*sql.Tx`
 * helpers take the \PDO connection whose `beginTransaction()` is open — the
 * standalone Go `updateBalance(ctx, tx, balance)` is {@see updateBalanceInTx()}
 * because PHP method names are case-insensitive and would collide with the
 * exported `UpdateBalance`.
 *
 * Big-integer columns (NUMERIC) are scanned as strings and parsed into
 * {@see BigInteger}, exactly as the Go code scans them into strings and
 * `parseBigInt`s them.
 */
trait BalanceRepository
{
    /** Go: `const maxBalancesPerUpdateChunk = 1000`. */
    private const maxBalancesPerUpdateChunk = 1000;

    /**
     * The six NUMERIC amount columns and the Balance properties they scan into,
     * in the order the Go code parses them (`failed to parse <column>`).
     *
     * @var array<string, string>
     */
    private const BALANCE_AMOUNT_COLUMNS = [
        'balance' => 'balance',
        'credit_balance' => 'creditBalance',
        'debit_balance' => 'debitBalance',
        'inflight_balance' => 'inflightBalance',
        'inflight_credit_balance' => 'inflightCreditBalance',
        'inflight_debit_balance' => 'inflightDebitBalance',
    ];

    // ------------------------------------------------------------------
    // Package-level helpers of balance.go
    // ------------------------------------------------------------------

    /**
     * Helper function to check if a slice contains a value.
     *
     * @param string[] $slice
     */
    private function contains(array $slice, string $val): bool
    {
        foreach ($slice as $s) {
            if ($s === $val) {
                return true;
            }
        }
        return false;
    }

    /**
     * parseBigInt parses a string into a *big.Int, returning an error if parsing fails.
     * This ensures we don't silently get nil values when database returns malformed data.
     *
     * @throws \InvalidArgumentException `invalid big.Int value: "<s>"`
     */
    private function parseBigInt(string $s): BigInteger
    {
        return RowScanner::toBigInteger($s);
    }

    /**
     * `balance.Balance.String()` for a bind argument: a nil `*big.Int` renders as
     * "<nil>" in Go (and then fails the NUMERIC cast server-side), which is
     * mirrored here rather than silently substituting zero.
     */
    private function bigIntArg(?BigInteger $n): string
    {
        return $n === null ? '<nil>' : (string) $n;
    }

    /**
     * Prepares a dynamic SQL query based on the fields to be included.
     * This query fetches balance details, including optional joins for identity and ledger.
     *
     * (Go passes a strings.Builder by value; it is a local here.)
     *
     * @param string[] $include
     */
    private function prepareQueries(array $include): string
    {
        $selectFields = [];

        // Default fields for balances
        array_push(
            $selectFields,
            'b.balance_id',
            'b.balance',
            'b.credit_balance',
            'b.debit_balance',
            'b.currency',
            'b.ledger_id',
            "COALESCE(b.identity_id, '') as identity_id",
            'b.created_at',
            'b.meta_data',
            'b.inflight_balance',
            'b.inflight_credit_balance',
            'b.inflight_debit_balance',
            'b.version',
            'b.indicator',
            'b.track_fund_lineage',
            "COALESCE(b.allocation_strategy, 'FIFO') as allocation_strategy"
        );

        // Conditionally include identity fields
        if ($this->contains($include, 'identity')) {
            array_push(
                $selectFields,
                'i.identity_id',
                'i.first_name',
                'i.organization_name',
                'i.category',
                'i.last_name',
                'i.other_names',
                'i.gender',
                'i.dob',
                'i.email_address',
                'i.phone_number',
                'i.nationality',
                'i.street',
                'i.country',
                'i.state',
                'i.post_code',
                'i.city',
                'i.created_at'
            );
        }

        // Conditionally include ledger fields
        if ($this->contains($include, 'ledger')) {
            array_push($selectFields, 'l.ledger_id', 'l.name', 'l.created_at');
        }

        // Construct the SQL query
        $queryBuilder = 'SELECT ';
        $queryBuilder .= implode(', ', $selectFields);
        $queryBuilder .= '
        FROM (
            SELECT * FROM blnk.balances WHERE balance_id = ?
        ) AS b
    ';

        // Add optional joins for identity and ledger
        if ($this->contains($include, 'identity')) {
            $queryBuilder .= '
            LEFT JOIN blnk.identity i ON b.identity_id = i.identity_id
        ';
        }
        if ($this->contains($include, 'ledger')) {
            $queryBuilder .= '
            LEFT JOIN blnk.ledgers l ON b.ledger_id = l.ledger_id
        ';
        }

        return $queryBuilder;
    }

    /**
     * Scans a SQL row result and maps it into a Balance object, including optional identity and ledger data.
     * Converts string representations of big.Int fields into actual big.Int objects.
     *
     * The row is positional (\PDO::FETCH_NUM) because the joined SELECT repeats
     * column names (identity_id, created_at); the offsets follow the
     * {@see prepareQueries()} field order exactly as Go's `row.Scan(scanArgs...)`.
     *
     * @param array<int, mixed> $row
     * @param string[] $include
     *
     * @throws \InvalidArgumentException on a malformed amount / timestamp / metadata value
     *                                   (Go: the raw error, wrapped by the caller)
     */
    private function scanRow(array $row, array $include): Balance
    {
        $balance = new Balance();
        $identity = new Identity();
        $ledger = new Ledger();

        $i = 0;
        // Add scan arguments for default balance fields
        $balance->balanceID = RowScanner::toString($row[$i++] ?? null);
        $balanceStr = RowScanner::toString($row[$i++] ?? null);
        $creditBalanceStr = RowScanner::toString($row[$i++] ?? null);
        $debitBalanceStr = RowScanner::toString($row[$i++] ?? null);
        $balance->currency = RowScanner::toString($row[$i++] ?? null);
        $balance->ledgerID = RowScanner::toString($row[$i++] ?? null);
        $balance->identityID = RowScanner::toString($row[$i++] ?? null);
        $balance->createdAt = RowScanner::toTime($row[$i++] ?? null);
        $metaDataJSON = $row[$i++] ?? null;
        $inflightBalanceStr = RowScanner::toString($row[$i++] ?? null);
        $inflightCreditBalanceStr = RowScanner::toString($row[$i++] ?? null);
        $inflightDebitBalanceStr = RowScanner::toString($row[$i++] ?? null);
        $balance->version = RowScanner::toInt($row[$i++] ?? null);
        $indicator = $row[$i++] ?? null; // sql.NullString
        $balance->trackFundLineage = RowScanner::toBool($row[$i++] ?? null);
        $balance->allocationStrategy = RowScanner::toString($row[$i++] ?? null);

        // Conditionally scan for identity fields
        if ($this->contains($include, 'identity')) {
            $identity->identityID = RowScanner::toString($row[$i++] ?? null);
            $identity->firstName = RowScanner::toString($row[$i++] ?? null);
            $identity->organizationName = RowScanner::toString($row[$i++] ?? null);
            $identity->category = RowScanner::toString($row[$i++] ?? null);
            $identity->lastName = RowScanner::toString($row[$i++] ?? null);
            $identity->otherNames = RowScanner::toString($row[$i++] ?? null);
            $identity->gender = RowScanner::toString($row[$i++] ?? null);
            $identity->dob = RowScanner::toTime($row[$i++] ?? null);
            $identity->emailAddress = RowScanner::toString($row[$i++] ?? null);
            $identity->phoneNumber = RowScanner::toString($row[$i++] ?? null);
            $identity->nationality = RowScanner::toString($row[$i++] ?? null);
            $identity->street = RowScanner::toString($row[$i++] ?? null);
            $identity->country = RowScanner::toString($row[$i++] ?? null);
            $identity->state = RowScanner::toString($row[$i++] ?? null);
            $identity->postCode = RowScanner::toString($row[$i++] ?? null);
            $identity->city = RowScanner::toString($row[$i++] ?? null);
            $identity->createdAt = RowScanner::toTime($row[$i++] ?? null);
        }

        // Conditionally scan for ledger fields
        if ($this->contains($include, 'ledger')) {
            $ledger->ledgerID = RowScanner::toString($row[$i++] ?? null);
            $ledger->name = RowScanner::toString($row[$i++] ?? null);
            $ledger->createdAt = RowScanner::toTime($row[$i++] ?? null);
        }

        // Convert string representations to big.Int
        $parse = function (string $value, string $column): BigInteger {
            try {
                return $this->parseBigInt($value);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(sprintf('failed to parse %s: %s', $column, $e->getMessage()), 0, $e);
            }
        };
        $balance->balance = $parse($balanceStr, 'balance');
        $balance->creditBalance = $parse($creditBalanceStr, 'credit_balance');
        $balance->debitBalance = $parse($debitBalanceStr, 'debit_balance');
        $balance->inflightBalance = $parse($inflightBalanceStr, 'inflight_balance');
        $balance->inflightCreditBalance = $parse($inflightCreditBalanceStr, 'inflight_credit_balance');
        $balance->inflightDebitBalance = $parse($inflightDebitBalanceStr, 'inflight_debit_balance');

        // Handle null indicator field
        if ($indicator !== null) {
            $balance->indicator = RowScanner::toString($indicator);
        } else {
            $balance->indicator = '';
        }

        // Unmarshal metadata JSON
        $balance->metaData = RowScanner::toMetaData($metaDataJSON);

        // Attach identity and ledger objects if included
        if ($this->contains($include, 'identity')) {
            $balance->identity = $identity;
        }
        if ($this->contains($include, 'ledger')) {
            $balance->ledger = $ledger;
        }

        return $balance;
    }

    /**
     * Scans the scalar columns of the 15-column "lite" balance SELECT
     * (`balance_id, indicator, currency, ledger_id, ..., created_at, version,
     * track_fund_lineage, allocation_strategy, identity_id`) — everything except
     * the six NUMERIC amounts, which each Go call site parses with its own error
     * wording (see {@see parseBalanceAmounts()}).
     *
     * @param array<string, mixed> $row
     */
    private function rowToBalanceLite(array $row): Balance
    {
        $balance = new Balance();
        $balance->balanceID = RowScanner::toString($row['balance_id'] ?? null);

        // Handle null indicator field
        $indicator = $row['indicator'] ?? null;
        if ($indicator !== null) {
            $balance->indicator = RowScanner::toString($indicator);
        } else {
            $balance->indicator = '';
        }

        $balance->currency = RowScanner::toString($row['currency'] ?? null);
        $balance->ledgerID = RowScanner::toString($row['ledger_id'] ?? null);
        $balance->createdAt = RowScanner::toTime($row['created_at'] ?? null);
        $balance->version = RowScanner::toInt($row['version'] ?? null);
        $balance->trackFundLineage = RowScanner::toBool($row['track_fund_lineage'] ?? null);

        // Handle null allocation_strategy field
        $allocationStrategy = $row['allocation_strategy'] ?? null;
        if ($allocationStrategy !== null) {
            $balance->allocationStrategy = RowScanner::toString($allocationStrategy);
        } else {
            $balance->allocationStrategy = 'FIFO';
        }

        $balance->identityID = RowScanner::toString($row['identity_id'] ?? null);

        return $balance;
    }

    /**
     * Parses the NUMERIC amount columns present in $row into $balance, in Go's
     * order, failing with `fmt.Errorf("failed to parse <column>: %w", err)`.
     *
     * @param array<string, mixed> $row
     *
     * @throws DatabaseException
     */
    private function parseBalanceAmounts(Balance $balance, array $row): void
    {
        foreach (self::BALANCE_AMOUNT_COLUMNS as $column => $property) {
            if (!\array_key_exists($column, $row)) {
                continue;
            }
            try {
                $balance->{$property} = $this->parseBigInt(RowScanner::toString($row[$column]));
            } catch (\InvalidArgumentException $e) {
                throw new DatabaseException(sprintf('failed to parse %s: %s', $column, $e->getMessage()), null, null, $e);
            }
        }
    }

    // ------------------------------------------------------------------
    // Balances
    // ------------------------------------------------------------------

    /**
     * CreateBalance inserts a new balance record into the `blnk.balances` table in the database.
     * It handles the generation of a unique balance ID, default values for fields, and any necessary error handling.
     *
     * Parameters:
     * - balance: A model.Balance object containing the balance information to be created.
     *
     * Returns:
     * - model.Balance: The created balance with its ID and timestamp populated.
     * - error: Returns an APIError in case of failures such as database conflicts or other issues.
     *
     * Note (mirrored from Go): a unique violation on `unique_indicator_currency`
     * is swallowed and an EMPTY Balance is returned without error.
     *
     * @throws ApiErrorException
     */
    public function createBalance(Balance $balance): Balance
    {
        // Marshal metadata into JSON
        try {
            $metaDataJSON = PqEncoder::json($balance->metaData);
        } catch (\JsonException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to marshal metadata', $e->getMessage());
        }

        // Generate a unique balance ID and set the creation timestamp
        $balance->balanceID = ModelHelpers::generateUUIDWithSuffix('bln');
        $balance->createdAt = PqEncoder::now();

        // Handle nullable fields
        $identityID = $balance->identityID;
        if ($balance->identityID === '') {
            $identityID = null;
        }

        $indicator = $balance->indicator;
        if ($balance->indicator === '') {
            $indicator = null;
        }

        // Set default values for balance fields if they are nil
        if ($balance->balance === null) {
            $balance->balance = BigInteger::zero();
        }
        if ($balance->creditBalance === null) {
            $balance->creditBalance = BigInteger::zero();
        }
        if ($balance->debitBalance === null) {
            $balance->debitBalance = BigInteger::zero();
        }
        if ($balance->inflightBalance === null) {
            $balance->inflightBalance = BigInteger::zero();
        }
        if ($balance->inflightCreditBalance === null) {
            $balance->inflightCreditBalance = BigInteger::zero();
        }
        if ($balance->inflightDebitBalance === null) {
            $balance->inflightDebitBalance = BigInteger::zero();
        }

        // Default allocation strategy to FIFO if not set
        $allocationStrategy = $balance->allocationStrategy;
        if ($allocationStrategy === '') {
            $allocationStrategy = 'FIFO';
        }

        // Insert the balance into the database
        try {
            PgStatement::execute($this->conn, '
		INSERT INTO blnk.balances (balance_id, balance, credit_balance, debit_balance, currency, ledger_id, identity_id, indicator, created_at, meta_data, track_fund_lineage, allocation_strategy)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
	', [
                $balance->balanceID,
                (string) $balance->balance,
                (string) $balance->creditBalance,
                (string) $balance->debitBalance,
                $balance->currency,
                $balance->ledgerID,
                $identityID,
                $indicator,
                $balance->createdAt,
                $metaDataJSON,
                $balance->trackFundLineage,
                $allocationStrategy,
            ]);
        } catch (\PDOException $e) {
            // Handle specific PostgreSQL errors (e.g., unique or foreign key violations)
            if (PgStatement::isServerError($e)) {
                switch (PgStatement::conditionName($e)) {
                    case 'unique_violation':
                        if (str_contains($e->getMessage(), 'unique_indicator_currency')) {
                            return new Balance();
                        }
                        throw ApiErrorException::newApiError(ErrorCode::ErrConflict, sprintf('Balance already exists: %s', $balance->balanceID), $e->getMessage());
                    case 'foreign_key_violation':
                        throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, 'Invalid ledger ID', $e->getMessage());
                    default:
                        throw PgStatement::wrap($e, 'Database error occurred');
                }
            }
            // Return a generic error if the specific type couldn't be determined
            throw PgStatement::wrap($e, 'Failed to create balance');
        }

        // Return the created balance
        return $balance;
    }

    /**
     * GetBalanceByID retrieves a balance by its ID from the database, along with optional related data such as identity or ledger, based on the `include` parameter.
     * The method starts a transaction, executes the query, and processes the result.
     *
     * Parameters:
     * - id: The unique ID of the balance to retrieve.
     * - include: A slice of strings that specifies which related data to include in the result. Possible values include "identity" and "ledger".
     * - withQueued: A boolean that specifies whether to include queued amounts in the result.
     *
     * Returns:
     * - *model.Balance: A pointer to the retrieved Balance object.
     * - error: Returns an APIError in case of errors such as database failures or if the balance is not found.
     *
     * (Go's 1-minute context timeout is dropped with the context.)
     *
     * @param string[] $include
     *
     * @throws NotFoundException "Balance with ID '<id>' not found"
     * @throws ApiErrorException
     */
    public function getBalanceByID(string $id, array $include, bool $withQueued): Balance
    {
        // Prepare and execute the query. No explicit DB transaction is needed for
        // these independent reads; avoiding one keeps pool connections free.
        $query = $this->prepareQueries($include);
        try {
            $stmt = PgStatement::execute($this->conn, $query, [$id]);
            $row = $stmt->fetch(\PDO::FETCH_NUM);
        } catch (\PDOException $e) {
            // Handle other errors
            throw PgStatement::wrap($e, 'Failed to scan balance data');
        }

        if ($row === false) {
            // If no balance is found
            throw new NotFoundException(sprintf("Balance with ID '%s' not found", $id), 'sql: no rows in result set');
        }

        // Scan the result into a Balance object
        try {
            $balance = $this->scanRow($row, $include);
        } catch (\InvalidArgumentException $e) {
            throw new DatabaseException('Failed to scan balance data', null, $e->getMessage(), $e);
        }

        // Get queued amounts only if requested
        if ($withQueued) {
            [$queuedDebit, $queuedCredit] = $this->getQueuedAmounts($id);
            $balance->queuedDebitBalance = $queuedDebit;
            $balance->queuedCreditBalance = $queuedCredit;
        }

        return $balance;
    }

    /**
     * GetBalanceByIDLite retrieves a balance by its unique ID with a lighter set of fields.
     * This version avoids loading additional related data like identity and ledger.
     *
     * Parameters:
     * - id: The ID of the balance to retrieve.
     *
     * Returns:
     * - *model.Balance: A pointer to the retrieved Balance object.
     * - error: Returns an APIError in case of errors such as database failures or if the balance is not found.
     *
     * @throws NotFoundException "Balance with ID '<id>' not found"
     * @throws ApiErrorException
     */
    public function getBalanceByIDLite(string $id): Balance
    {
        // Execute the query
        try {
            $stmt = PgStatement::execute($this->conn, '
       SELECT balance_id, indicator, currency, ledger_id, balance, credit_balance, debit_balance, inflight_balance, inflight_credit_balance, inflight_debit_balance, created_at, version, track_fund_lineage, COALESCE(allocation_strategy, \'FIFO\') as allocation_strategy, COALESCE(identity_id, \'\') as identity_id
       FROM blnk.balances
       WHERE balance_id = ?
    ', [$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            Log::get()->error('balance lite lookup failed', ['error' => $e->getMessage()]);
            throw PgStatement::wrap($e, 'Failed to scan balance data');
        }

        if ($row === false) {
            Log::get()->error('balance lite lookup failed', ['error' => 'sql: no rows in result set']);
            throw new NotFoundException(sprintf("Balance with ID '%s' not found", $id), 'sql: no rows in result set');
        }

        // Scan the result into variables (indicator / allocation_strategy NULL handling included)
        try {
            $balance = $this->rowToBalanceLite($row);
        } catch (\InvalidArgumentException $e) {
            Log::get()->error('balance lite lookup failed', ['error' => $e->getMessage()]);
            throw new DatabaseException('Failed to scan balance data', null, $e->getMessage(), $e);
        }

        // Parse string values to big.Int
        $this->parseBalanceAmounts($balance, $row);

        return $balance;
    }

    /**
     * GetBalancesByIDsLite retrieves multiple balances by their IDs in a single query.
     * Returns a map of balance_id to Balance for easy lookup.
     * Balances that are not found are simply not included in the result map.
     *
     * Parameters:
     * - ids []string: The list of balance IDs to retrieve.
     *
     * Returns:
     * - map[string]*model.Balance: A map of balance_id to Balance.
     * - error: Returns an error in case of database failures.
     *
     * @param string[] $ids
     *
     * @return array<string, Balance>
     *
     * @throws ApiErrorException
     */
    public function getBalancesByIDsLite(array $ids): array
    {
        if (\count($ids) === 0) {
            return [];
        }

        // (Go: pq.Array(ids) — the text[] literal, cast server-side by ANY($1).)
        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT balance_id, indicator, currency, ledger_id, balance, credit_balance, debit_balance, inflight_balance, inflight_credit_balance, inflight_debit_balance, created_at, version, track_fund_lineage, COALESCE(allocation_strategy, \'FIFO\') as allocation_strategy, COALESCE(identity_id, \'\') as identity_id
		FROM blnk.balances
		WHERE balance_id = ANY(?)
	', [FilterHelpers::toPgTextArray(array_map(strval(...), array_values($ids)))]);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to query balances');
        }

        $result = [];

        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                try {
                    $balance = $this->rowToBalanceLite($row);
                } catch (\InvalidArgumentException $e) {
                    Log::get()->error('balance batch scan failed', ['error' => $e->getMessage()]);
                    continue;
                }

                // Parse big.Int values
                foreach (self::BALANCE_AMOUNT_COLUMNS as $column => $property) {
                    try {
                        $balance->{$property} = $this->parseBigInt(RowScanner::toString($row[$column] ?? null));
                    } catch (\InvalidArgumentException $parseErr) {
                        throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, sprintf('balance %s: failed to parse %s', $balance->balanceID, $column), $parseErr->getMessage());
                    }
                }

                $result[$balance->balanceID] = $balance;
            }
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Error iterating over balances');
        }

        return $result;
    }

    /**
     * GetBalanceByIndicator retrieves a balance from the database using the specified indicator and currency.
     * The function scans the query result into a Balance object and converts various fields from int64 to big.Int.
     * It returns the balance if found, or an error if the balance does not exist.
     *
     * Parameters:
     * - indicator: A unique identifier associated with the balance (e.g., an account identifier).
     * - currency: The currency in which the balance is denominated.
     *
     * Returns:
     * - *model.Balance: The retrieved balance object or an empty Balance object if not found.
     * - error: An error if any issues occur during the query execution or data retrieval.
     *
     * @throws NotFoundException "balance with indicator '<indicator>' not found" (Go: a plain error)
     * @throws ApiErrorException
     */
    public function getBalanceByIndicator(string $indicator, string $currency): Balance
    {
        // Execute query to find the balance with the given indicator and currency
        try {
            $stmt = PgStatement::execute($this->conn, '
       SELECT balance_id, indicator, currency, ledger_id, balance, credit_balance, debit_balance, inflight_balance, inflight_credit_balance, inflight_debit_balance, created_at, version, track_fund_lineage, COALESCE(allocation_strategy, \'FIFO\') as allocation_strategy, COALESCE(identity_id, \'\') as identity_id
       FROM blnk.balances
       WHERE indicator = ? AND currency = ?
    ', [$indicator, $currency]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Return other types of errors, such as query execution failures
            throw DatabaseException::fromPDOException($e);
        }

        if ($row === false) {
            // Handle the case where no balance was found with the given indicator and currency
            throw new NotFoundException(sprintf("balance with indicator '%s' not found", $indicator), 'sql: no rows in result set');
        }

        // Scan the result into the Balance object
        try {
            $balance = $this->rowToBalanceLite($row);
        } catch (\InvalidArgumentException $e) {
            throw new DatabaseException($e->getMessage(), null, null, $e);
        }

        // Parse string values to big.Int
        $this->parseBalanceAmounts($balance, $row);

        // Return the populated Balance object
        return $balance;
    }

    /**
     * GetAllBalances retrieves a limited set of balances from the database, up to 20 records.
     * It processes each balance by scanning the query result, converting numerical fields to big.Int, and parsing metadata from JSON format.
     * The function returns a slice of Balance objects or an error if any issues occur during the database query or data processing.
     *
     * Parameters:
     * - limit: The maximum number of balances to return (e.g., 20).
     * - offset: The offset to start fetching balances from (for pagination).
     *
     * Returns:
     * - []model.Balance: A slice of Balance objects containing balance information such as balance amount, credit balance, debit balance, and metadata.
     * - error: An error if any occurs during the query execution, data retrieval, or JSON parsing.
     *
     * @return Balance[]
     *
     * @throws ApiErrorException
     */
    public function getAllBalances(int $limit, int $offset): array
    {
        try {
            $stmt = PgStatement::execute($this->conn, '
        SELECT balance_id, indicator, balance, credit_balance, debit_balance, inflight_balance, inflight_credit_balance, inflight_debit_balance, currency, ledger_id, COALESCE(identity_id, \'\') as identity_id, created_at, meta_data
        FROM blnk.balances
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ', [$limit, $offset]);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        $balances = [];

        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $balances[] = $this->rowToBalanceListEntry($row, false);
            }
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        return $balances;
    }

    /**
     * Scans one row of the 13-column list SELECT (`balance_id, indicator,
     * <six amounts>, currency, ledger_id, identity_id, created_at, meta_data`)
     * shared by GetAllBalances and GetAllBalancesWithFilterAndOptions.
     *
     * @param array<string, mixed> $row
     * @param bool $apiMetadataError true → a metadata unmarshal failure is the
     *                               APIError "Failed to unmarshal metadata"
     *                               (WithFilterAndOptions); false → the raw
     *                               error (GetAllBalances).
     *
     * @throws ApiErrorException
     */
    private function rowToBalanceListEntry(array $row, bool $apiMetadataError): Balance
    {
        $balance = new Balance();
        try {
            $balance->balanceID = RowScanner::toString($row['balance_id'] ?? null);
            $balance->currency = RowScanner::toString($row['currency'] ?? null);
            $balance->ledgerID = RowScanner::toString($row['ledger_id'] ?? null);
            $balance->identityID = RowScanner::toString($row['identity_id'] ?? null);
            $balance->createdAt = RowScanner::toTime($row['created_at'] ?? null);
        } catch (\InvalidArgumentException $e) {
            if ($apiMetadataError) {
                throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to scan balance data', $e->getMessage());
            }
            throw new DatabaseException($e->getMessage(), null, null, $e);
        }

        $indicator = $row['indicator'] ?? null;
        if ($indicator !== null) {
            $balance->indicator = RowScanner::toString($indicator);
        } else {
            $balance->indicator = '';
        }

        $this->parseBalanceAmounts($balance, $row);

        try {
            $balance->metaData = RowScanner::toMetaData($row['meta_data'] ?? null);
        } catch (\InvalidArgumentException $e) {
            if ($apiMetadataError) {
                throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $e->getMessage());
            }
            throw new DatabaseException($e->getMessage(), null, null, $e);
        }

        return $balance;
    }

    /**
     * GetSourceDestination retrieves balances for both the source and destination by their IDs.
     * It queries the database using a stored procedure `blnk.get_balances_by_id`, which takes the sourceId and destinationId as inputs.
     * The function processes each balance, converting balance fields to big.Int and parsing the metadata from JSON format.
     * It returns a slice of pointers to Balance objects or an error if any issues occur during the query or data processing.
     *
     * Parameters:
     * - sourceId: The ID of the source balance to retrieve.
     * - destinationId: The ID of the destination balance to retrieve.
     *
     * Returns:
     * - []*model.Balance: A slice of pointers to Balance objects containing the source and destination balances with their details such as balance amount, credit balance, debit balance, and metadata.
     * - error: An error if any occurs during the query execution, data retrieval, or JSON parsing.
     *
     * @return Balance[]
     *
     * @throws ApiErrorException
     */
    public function getSourceDestination(string $sourceId, string $destinationId): array
    {
        // Execute SQL query to select balances for source and destination using a stored procedure
        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT balance_id, balance, credit_balance, debit_balance, currency, ledger_id, created_at, meta_data
		FROM blnk.balances
		WHERE balance_id IN (?, ?)
	', [$sourceId, $destinationId]);
        } catch (\PDOException $e) {
            // Return an error if the query execution fails
            throw DatabaseException::fromPDOException($e);
        }

        // Slice to store the retrieved balances
        $balances = [];

        // Iterate through the result set and scan each row into a Balance object
        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $balance = new Balance();

                // Scan the values from the current row into the balance object and temporary variables
                try {
                    $balance->balanceID = RowScanner::toString($row['balance_id'] ?? null);
                    $balanceValue = RowScanner::toString($row['balance'] ?? null);
                    $creditBalanceValue = RowScanner::toString($row['credit_balance'] ?? null);
                    $debitBalanceValue = RowScanner::toString($row['debit_balance'] ?? null);
                    $balance->currency = RowScanner::toString($row['currency'] ?? null);
                    $balance->ledgerID = RowScanner::toString($row['ledger_id'] ?? null);
                    $balance->createdAt = RowScanner::toTime($row['created_at'] ?? null);
                } catch (\InvalidArgumentException $e) {
                    // Return an error if scanning the row fails
                    throw new DatabaseException($e->getMessage(), null, null, $e);
                }
                $metaDataJSON = $row['meta_data'] ?? null;

                // Parse string values to big.Int
                try {
                    $balance->balance = $this->parseBigInt($balanceValue);
                } catch (\InvalidArgumentException $e) {
                    throw new DatabaseException(sprintf('failed to parse balance: %s', $e->getMessage()), null, null, $e);
                }
                try {
                    $balance->creditBalance = $this->parseBigInt($creditBalanceValue);
                } catch (\InvalidArgumentException $e) {
                    throw new DatabaseException(sprintf('failed to parse credit balance: %s', $e->getMessage()), null, null, $e);
                }
                try {
                    $balance->debitBalance = $this->parseBigInt($debitBalanceValue);
                } catch (\InvalidArgumentException $e) {
                    throw new DatabaseException(sprintf('failed to parse debit balance: %s', $e->getMessage()), null, null, $e);
                }

                // Parse the metadata JSON into the MetaData map field (may be NULL)
                if ($metaDataJSON !== null && $metaDataJSON !== '') {
                    try {
                        $balance->metaData = RowScanner::toMetaData($metaDataJSON);
                    } catch (\InvalidArgumentException $e) {
                        // Return an error if JSON parsing fails
                        throw new DatabaseException($e->getMessage(), null, null, $e);
                    }
                }

                // Append the balance to the slice of balances
                $balances[] = $balance;
            }
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        // Return the slice of balances containing the source and destination balances
        return $balances;
    }

    /**
     * UpdateBalances updates both the source and destination balances in a single transaction.
     * The function begins a database transaction, updates the balances, and commits the transaction if all updates succeed.
     * In case of any failure, the transaction is rolled back to ensure data integrity.
     *
     * Parameters:
     * - sourceBalance: A pointer to the source balance object that needs to be updated.
     * - destinationBalance: A pointer to the destination balance object that needs to be updated.
     *
     * Returns:
     * - error: Returns an error if there is a failure to start the transaction, update any of the balances, or commit the transaction.
     *
     * @throws ApiErrorException
     */
    public function updateBalances(Balance $sourceBalance, Balance $destinationBalance): void
    {
        // Begin a new transaction
        try {
            $this->conn->beginTransaction();
        } catch (\PDOException $e) {
            // Return an error if the transaction cannot be initiated
            throw PgStatement::wrap($e, 'Failed to begin transaction');
        }

        try {
            // Attempt to update the source balance
            $this->updateBalanceInTx($this->conn, $sourceBalance);

            // Attempt to update the destination balance
            $this->updateBalanceInTx($this->conn, $destinationBalance);

            // Commit the transaction if both updates succeed
            try {
                $this->conn->commit();
            } catch (\PDOException $e) {
                // Return an error if the commit fails
                throw PgStatement::wrap($e, 'Failed to commit transaction');
            }
        } catch (\Throwable $e) {
            // Ensure that the transaction is rolled back if an error occurs during execution
            PgStatement::rollbackQuietly($this->conn);
            throw $e;
        }
    }

    /**
     * updateBalance updates a balance entry in the database.
     * This function handles the logic of updating all balance-related fields while ensuring data consistency using optimistic locking.
     * The version field is incremented after a successful update to maintain control over concurrent modifications.
     *
     * Parameters:
     * - tx: The database transaction in which the update is performed.
     * - balance: A pointer to the balance object containing the updated balance information.
     *
     * Returns:
     * - error: Returns an error if the update operation fails at any point, including issues with metadata marshalling, query execution, or optimistic locking.
     *
     * (Go: the package-level `updateBalance(ctx, tx, balance)`; renamed because
     * PHP method names are case-insensitive and `UpdateBalance` is exported.)
     *
     * @param \PDO $tx the connection whose `beginTransaction()` is open
     *
     * @throws ApiErrorException CONFLICT on an optimistic-locking failure
     */
    private function updateBalanceInTx(\PDO $tx, Balance $balance): void
    {
        // SQL query to update the balance
        // (Go numbers the placeholders $2..$10 in SET and $1, $11 in WHERE; with
        // positional placeholders the arguments follow the textual order.)
        $query = '
        UPDATE blnk.balances
        SET balance = ?, credit_balance = ?, debit_balance = ?, inflight_balance = ?, inflight_credit_balance = ?, inflight_debit_balance = ?, currency = ?, ledger_id = ?, created_at = ?, version = version + 1
        WHERE balance_id = ? AND version = ?
    ';

        // Execute the update query within the provided transaction context
        try {
            $result = PgStatement::execute($tx, $query, [
                $this->bigIntArg($balance->balance),
                $this->bigIntArg($balance->creditBalance),
                $this->bigIntArg($balance->debitBalance),
                $this->bigIntArg($balance->inflightBalance),
                $this->bigIntArg($balance->inflightCreditBalance),
                $this->bigIntArg($balance->inflightDebitBalance),
                $balance->currency,
                $balance->ledgerID,
                PqEncoder::time($balance->createdAt),
                $balance->balanceID,
                $balance->version,
            ]);
        } catch (\PDOException $e) {
            // Return an error if the query execution fails
            throw PgStatement::wrap($e, 'Failed to update balance');
        }

        // Check if any rows were affected by the update
        $rowsAffected = $result->rowCount();

        // If no rows were updated, return an optimistic locking error
        if ($rowsAffected === 0) {
            throw ApiErrorException::newApiError(ErrorCode::ErrConflict, sprintf("Optimistic locking failure: balance with ID '%s' may have been updated or deleted by another transaction", $balance->balanceID), null);
        }

        // Increment the version number after a successful update
        $balance->version++;
    }

    /**
     * updateBalanceSet updates a set of balances inside the open transaction
     * with optimistic locking, de-duplicating by balance ID, skipping nil /
     * empty-ID entries, and chunking by maxBalancesPerUpdateChunk. Every
     * balance's Version is incremented once all chunks have been written.
     *
     * @param \PDO $tx the connection whose `beginTransaction()` is open
     * @param (Balance|null)[] $balances
     *
     * @throws ApiErrorException
     */
    private function updateBalanceSet(\PDO $tx, array $balances): void
    {
        $seen = [];
        $uniqueBalances = [];
        foreach ($balances as $balance) {
            if ($balance === null || $balance->balanceID === '') {
                continue;
            }
            if (isset($seen[$balance->balanceID])) {
                continue;
            }
            $seen[$balance->balanceID] = true;
            $uniqueBalances[] = $balance;
        }

        if (\count($uniqueBalances) === 0) {
            return;
        }

        for ($start = 0; $start < \count($uniqueBalances); $start += self::maxBalancesPerUpdateChunk) {
            $end = $start + self::maxBalancesPerUpdateChunk;
            if ($end > \count($uniqueBalances)) {
                $end = \count($uniqueBalances);
            }

            $this->updateBalanceChunk($tx, \array_slice($uniqueBalances, $start, $end - $start));
        }

        foreach ($uniqueBalances as $balance) {
            $balance->version++;
        }
    }

    /**
     * updateBalanceChunk writes one chunk of balances with a single
     * `UPDATE ... FROM (VALUES ...)` statement and verifies through RETURNING
     * that every balance matched its expected version.
     *
     * @param \PDO $tx the connection whose `beginTransaction()` is open
     * @param Balance[] $balances
     *
     * @throws ApiErrorException
     */
    private function updateBalanceChunk(\PDO $tx, array $balances): void
    {
        if (\count($balances) === 0) {
            return;
        }

        $query = '
		UPDATE blnk.balances AS b
		SET balance = v.balance,
		    credit_balance = v.credit_balance,
		    debit_balance = v.debit_balance,
		    inflight_balance = v.inflight_balance,
		    inflight_credit_balance = v.inflight_credit_balance,
		    inflight_debit_balance = v.inflight_debit_balance,
		    currency = v.currency,
		    ledger_id = v.ledger_id,
		    created_at = v.created_at,
		    version = b.version + 1
		FROM (VALUES
	';

        $args = [];
        $i = 0;
        foreach ($balances as $balance) {
            if ($i > 0) {
                $query .= ',';
            }
            // (Go: "($base,$base+1,...,$base+10)" with base = i*11 + 1)
            $query .= '(?,?,?,?,?,?,?,?,?,?,?)';
            array_push(
                $args,
                $balance->balanceID,
                $this->bigIntArg($balance->balance),
                $this->bigIntArg($balance->creditBalance),
                $this->bigIntArg($balance->debitBalance),
                $this->bigIntArg($balance->inflightBalance),
                $this->bigIntArg($balance->inflightCreditBalance),
                $this->bigIntArg($balance->inflightDebitBalance),
                $balance->currency,
                $balance->ledgerID,
                PqEncoder::time($balance->createdAt),
                $balance->version
            );
            $i++;
        }

        $query .= '
		) AS raw(
			balance_id,
			balance,
			credit_balance,
			debit_balance,
			inflight_balance,
			inflight_credit_balance,
			inflight_debit_balance,
			currency,
			ledger_id,
			created_at,
			expected_version
		)
		CROSS JOIN LATERAL (
			SELECT
				raw.balance_id::text AS balance_id,
				raw.balance::numeric AS balance,
				raw.credit_balance::numeric AS credit_balance,
				raw.debit_balance::numeric AS debit_balance,
				raw.inflight_balance::numeric AS inflight_balance,
				raw.inflight_credit_balance::numeric AS inflight_credit_balance,
				raw.inflight_debit_balance::numeric AS inflight_debit_balance,
				raw.currency::text AS currency,
				raw.ledger_id::text AS ledger_id,
				raw.created_at::timestamp AS created_at,
				raw.expected_version::integer AS expected_version
		) AS v
		WHERE b.balance_id = v.balance_id
		  AND b.version = v.expected_version
		RETURNING b.balance_id
	';

        try {
            $rows = PgStatement::execute($tx, $query, $args);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to update balance set');
        }

        $updated = [];
        try {
            while (($row = $rows->fetch(\PDO::FETCH_NUM)) !== false) {
                $balanceID = RowScanner::toString($row[0] ?? null);
                $updated[$balanceID] = true;
            }
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed while iterating updated balances');
        }

        foreach ($balances as $balance) {
            if (!isset($updated[$balance->balanceID])) {
                throw ApiErrorException::newApiError(ErrorCode::ErrConflict, sprintf("Optimistic locking failure: balance with ID '%s' may have been updated or deleted by another transaction", $balance->balanceID), null);
            }
        }
    }

    /**
     * UpdateBalance updates an existing balance entry in the database.
     * This method takes a balance object and updates the corresponding fields in the database, based on the provided balance ID.
     * It handles both the balance data and the associated metadata.
     *
     * Parameters:
     * - balance: A pointer to the balance object containing the updated balance information. This includes fields such as `balance`, `credit_balance`, `debit_balance`, `currency`, and `meta_data`.
     *
     * Returns:
     * - error: If the update operation encounters an error, such as a database failure or if the balance ID is not found, an `APIError` is returned.
     *
     * @throws NotFoundException "Balance with ID '<id>' not found"
     * @throws ApiErrorException
     */
    public function updateBalance(Balance $balance): void
    {
        // Marshal the MetaData into JSON format
        try {
            $metaDataJSON = PqEncoder::json($balance->metaData);
        } catch (\JsonException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to marshal metadata', $e->getMessage());
        }

        // Execute the SQL query to update the balance in the database
        // (Go: SET ... = $2..$8 WHERE balance_id = $1 — arguments follow textual order here.)
        try {
            $result = PgStatement::execute($this->conn, '
		UPDATE blnk.balances
		SET balance = ?, credit_balance = ?, debit_balance = ?, currency = ?, ledger_id = ?, created_at = ?, meta_data = ?
		WHERE balance_id = ?
	', [
                $this->bigIntArg($balance->balance),
                $this->bigIntArg($balance->creditBalance),
                $this->bigIntArg($balance->debitBalance),
                $balance->currency,
                $balance->ledgerID,
                PqEncoder::time($balance->createdAt),
                $metaDataJSON,
                $balance->balanceID,
            ]);
        } catch (\PDOException $e) {
            // Handle SQL execution errors
            throw PgStatement::wrap($e, 'Failed to update balance');
        }

        // Check if any rows were affected by the update
        $rowsAffected = $result->rowCount();

        // If no rows were updated, return a not-found error
        if ($rowsAffected === 0) {
            throw new NotFoundException(sprintf("Balance with ID '%s' not found", $balance->balanceID));
        }
    }

    // ------------------------------------------------------------------
    // Balance monitors
    // ------------------------------------------------------------------

    /**
     * CreateMonitor creates a new BalanceMonitor record in the database.
     * This function generates a unique MonitorID for the monitor, sets the creation timestamp,
     * and inserts the monitor's data into the `blnk.balance_monitors` table.
     *
     * Parameters:
     *   - monitor: A model.BalanceMonitor object containing details of the monitor to be created.
     *     It includes fields like balance ID, field to monitor, operator, value, precision, precise_value, description, callback URL, etc.
     *
     * Returns:
     * - model.BalanceMonitor: The newly created BalanceMonitor object with updated MonitorID and CreatedAt timestamp.
     * - error: If any errors occur during the creation process, an `APIError` is returned.
     *
     * @throws ApiErrorException
     */
    public function createMonitor(BalanceMonitor $monitor): BalanceMonitor
    {
        // Generate a unique MonitorID and set the current timestamp for CreatedAt
        $monitor->monitorID = ModelHelpers::generateUUIDWithSuffix('mon');
        $monitor->createdAt = PqEncoder::now();

        // (Go's Condition is a value struct; a null condition is the zero value.)
        if ($monitor->condition === null) {
            $monitor->condition = new AlertCondition();
        }

        // If PreciseValue is nil, initialize it to 0
        if ($monitor->condition->preciseValue === null) {
            $monitor->condition->preciseValue = BigInteger::zero();
        }

        // Insert the monitor data into the balance_monitors table
        try {
            PgStatement::execute($this->conn, '
		INSERT INTO blnk.balance_monitors (monitor_id, balance_id, field, operator, value, precision, precise_value, description, call_back_url, created_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
	', [
                $monitor->monitorID,
                $monitor->balanceID,
                $monitor->condition->field,
                $monitor->condition->operator,
                $monitor->condition->value,
                $monitor->condition->precision,
                (string) $monitor->condition->preciseValue,
                $monitor->description,
                $monitor->callBackURL,
                $monitor->createdAt,
            ]);
        } catch (\PDOException $e) {
            // Handle database errors
            if (PgStatement::isServerError($e)) {
                switch (PgStatement::conditionName($e)) {
                    // Handle unique violation error
                    case 'unique_violation':
                        throw ApiErrorException::newApiError(ErrorCode::ErrConflict, 'Monitor with this ID already exists', $e->getMessage());
                    // Handle foreign key violation error
                    case 'foreign_key_violation':
                        throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, 'Invalid balance ID', $e->getMessage());
                    // Handle other database errors
                    default:
                        throw PgStatement::wrap($e, 'Database error occurred');
                }
            }
            // Return a generic internal server error if no specific error is matched
            throw PgStatement::wrap($e, 'Failed to create monitor');
        }

        // Return the successfully created BalanceMonitor object
        return $monitor;
    }

    /**
     * GetMonitorByID retrieves a BalanceMonitor by its unique MonitorID from the database.
     * It queries the `blnk.balance_monitors` table and maps the result into a model.BalanceMonitor object.
     *
     * Parameters:
     * - id: The MonitorID of the monitor to retrieve.
     *
     * Returns:
     * - *model.BalanceMonitor: A pointer to the BalanceMonitor object if found.
     * - error: If the monitor is not found or if any errors occur during the query, an `APIError` is returned.
     *
     * @throws NotFoundException "Monitor with ID '<id>' not found"
     * @throws ApiErrorException
     */
    public function getMonitorByID(string $id): BalanceMonitor
    {
        // Query the database to get the monitor details by MonitorID
        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT monitor_id, balance_id, field, operator, value, precision, precise_value, description, call_back_url, created_at
		FROM blnk.balance_monitors WHERE monitor_id = ?
	', [$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Return an internal server error for other query issues
            throw PgStatement::wrap($e, 'Failed to retrieve monitor');
        }

        if ($row === false) {
            // Handle the case where the monitor with the specified ID is not found
            throw new NotFoundException(sprintf("Monitor with ID '%s' not found", $id), 'sql: no rows in result set');
        }

        // Scan the result into the monitor and condition fields;
        // populate the PreciseValue field in the condition (convert from int64 to big.Int)
        try {
            return $this->rowToBalanceMonitor($row, true, true);
        } catch (\InvalidArgumentException $e) {
            throw new DatabaseException('Failed to retrieve monitor', null, $e->getMessage(), $e);
        }
    }

    /**
     * Maps a `blnk.balance_monitors` row into a BalanceMonitor with its
     * AlertCondition — the `rows.Scan(&monitor.MonitorID, ..., &condition.Field, ...)`
     * of the Go code.
     *
     * @param array<string, mixed> $row
     * @param bool $withPrecision whether the SELECT carried `precision`
     * @param bool $withPreciseValue whether the SELECT carried `precise_value`
     *                               (scanned as int64 → big.NewInt)
     *
     * @throws \InvalidArgumentException on a malformed timestamp
     */
    private function rowToBalanceMonitor(array $row, bool $withPrecision, bool $withPreciseValue): BalanceMonitor
    {
        $monitor = new BalanceMonitor();
        $condition = new AlertCondition();

        $monitor->monitorID = RowScanner::toString($row['monitor_id'] ?? null);
        $monitor->balanceID = RowScanner::toString($row['balance_id'] ?? null);
        $condition->field = RowScanner::toString($row['field'] ?? null);
        $condition->operator = RowScanner::toString($row['operator'] ?? null);
        $condition->value = RowScanner::toFloat($row['value'] ?? null);
        if ($withPrecision) {
            $condition->precision = RowScanner::toFloat($row['precision'] ?? null);
        }
        $monitor->description = RowScanner::toString($row['description'] ?? null);
        $monitor->callBackURL = RowScanner::toString($row['call_back_url'] ?? null);
        $monitor->createdAt = RowScanner::toTime($row['created_at'] ?? null);

        // Assign the scanned AlertCondition to the monitor
        $monitor->condition = $condition;
        if ($withPreciseValue) {
            $monitor->condition->preciseValue = BigInteger::of(RowScanner::toInt($row['precise_value'] ?? null));
        }

        return $monitor;
    }

    /**
     * GetAllMonitors retrieves all balance monitors from the database.
     * It queries the `blnk.balance_monitors` table and returns a list of all monitors.
     *
     * Returns:
     * - []model.BalanceMonitor: A slice of BalanceMonitor objects if the query is successful.
     * - error: If an error occurs during the query or while scanning the result set, an `APIError` is returned.
     *
     * @return BalanceMonitor[]
     *
     * @throws ApiErrorException
     */
    public function getAllMonitors(): array
    {
        // Query the database for all balance monitors
        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT monitor_id, balance_id, field, operator, value, description, call_back_url, created_at
		FROM blnk.balance_monitors
	');
        } catch (\PDOException $e) {
            // Return an internal server error if the query fails
            throw PgStatement::wrap($e, 'Failed to retrieve monitors');
        }

        // Initialize an empty slice to store the retrieved monitors
        $monitors = [];

        // Iterate through each row in the result set
        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                // Scan the row into the monitor and condition fields
                try {
                    $monitors[] = $this->rowToBalanceMonitor($row, false, false);
                } catch (\InvalidArgumentException $e) {
                    // Return an error if scanning fails
                    throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to scan monitor data', $e->getMessage());
                }
            }
        } catch (\PDOException $e) {
            // Return an error if there were issues iterating over the result set
            throw PgStatement::wrap($e, 'Error occurred while iterating over monitors');
        }

        // Return the slice of monitors
        return $monitors;
    }

    /**
     * GetBalanceMonitors retrieves all balance monitors associated with a specific balance ID from the database.
     * It queries the `blnk.balance_monitors` table to find all monitors linked to the provided `balanceID`.
     *
     * Parameters:
     * - balanceID: The ID of the balance for which monitors are being retrieved.
     *
     * Returns:
     * - []model.BalanceMonitor: A slice of BalanceMonitor objects associated with the balance ID.
     * - error: If an error occurs during the query or while scanning the result set, an `APIError` is returned.
     *
     * @return BalanceMonitor[]
     *
     * @throws ApiErrorException
     */
    public function getBalanceMonitors(string $balanceID): array
    {
        // Query the database for monitors associated with the given balance ID
        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT monitor_id, balance_id, field, operator, value, description, call_back_url, created_at, precision, precise_value
		FROM blnk.balance_monitors WHERE balance_id = ?
	', [$balanceID]);
        } catch (\PDOException $e) {
            // Return an internal server error if the query fails
            throw PgStatement::wrap($e, 'Failed to retrieve balance monitors');
        }

        // Initialize an empty slice to store the retrieved monitors
        $monitors = [];

        // Iterate through each row in the result set
        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                // Scan the row into the monitor and condition fields
                try {
                    $monitors[] = $this->rowToBalanceMonitor($row, true, true);
                } catch (\InvalidArgumentException $e) {
                    // Return an error if scanning fails
                    throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to scan monitor data', $e->getMessage());
                }
            }
        } catch (\PDOException $e) {
            // Return an error if there were issues iterating over the result set
            throw PgStatement::wrap($e, 'Error occurred while iterating over balance monitors');
        }

        // Return the slice of monitors
        return $monitors;
    }

    /**
     * UpdateMonitor updates an existing balance monitor in the database.
     * It updates fields such as `balance_id`, `field`, `operator`, `value`, `description`, and `call_back_url`
     * for the monitor identified by `monitor_id`.
     *
     * Parameters:
     * - monitor: A pointer to the `BalanceMonitor` object containing the updated values.
     *
     * Returns:
     * - error: If the update fails, an appropriate `APIError` is returned.
     *
     * @throws NotFoundException "Monitor with ID '<id>' not found"
     * @throws ApiErrorException
     */
    public function updateMonitor(BalanceMonitor $monitor): void
    {
        $condition = $monitor->condition ?? new AlertCondition();

        // Execute the SQL update statement, replacing the placeholder values with the monitor's data
        // (Go: SET ... = $2..$7 WHERE monitor_id = $1 — arguments follow textual order here.)
        try {
            $result = PgStatement::execute($this->conn, '
		UPDATE blnk.balance_monitors
		SET balance_id = ?, field = ?, operator = ?, value = ?, description = ?, call_back_url = ?
		WHERE monitor_id = ?
	', [
                $monitor->balanceID,
                $condition->field,
                $condition->operator,
                $condition->value,
                $monitor->description,
                $monitor->callBackURL,
                $monitor->monitorID,
            ]);
        } catch (\PDOException $e) {
            // If an error occurred during execution, return an internal server error
            throw PgStatement::wrap($e, 'Failed to update monitor');
        }

        // Check how many rows were affected by the update
        $rowsAffected = $result->rowCount();

        // If no rows were affected, return a not found error indicating the monitor does not exist
        if ($rowsAffected === 0) {
            throw new NotFoundException(sprintf("Monitor with ID '%s' not found", $monitor->monitorID));
        }
    }

    /**
     * DeleteMonitor deletes a balance monitor from the database by its monitor ID.
     * It removes the monitor from the `blnk.balance_monitors` table.
     *
     * Parameters:
     * - id: The ID of the monitor to be deleted.
     *
     * Returns:
     * - error: If the deletion fails or the monitor is not found, an appropriate `APIError` is returned.
     *
     * @throws NotFoundException "Monitor with ID '<id>' not found"
     * @throws ApiErrorException
     */
    public function deleteMonitor(string $id): void
    {
        // Execute the SQL DELETE statement, removing the monitor by its ID
        try {
            $result = PgStatement::execute($this->conn, '
		DELETE FROM blnk.balance_monitors WHERE monitor_id = ?
	', [$id]);
        } catch (\PDOException $e) {
            // If an error occurred during execution, return an internal server error
            throw PgStatement::wrap($e, 'Failed to delete monitor');
        }

        // Check how many rows were affected by the delete operation
        $rowsAffected = $result->rowCount();

        // If no rows were affected, return a not found error indicating the monitor does not exist
        if ($rowsAffected === 0) {
            throw new NotFoundException(sprintf("Monitor with ID '%s' not found", $id));
        }
    }

    // ------------------------------------------------------------------
    // Snapshots and balance-at-time
    // ------------------------------------------------------------------

    /**
     * TakeBalanceSnapshots creates daily snapshots of balances in batches.
     * It uses the PostgreSQL function to process balances in chunks to avoid memory issues
     * with large datasets.
     *
     * Parameters:
     * - batchSize: The number of balances to process in each batch
     *
     * Returns:
     * - int: The total number of snapshots created
     * - error: Returns an APIError if the operation fails
     *
     * @throws ApiErrorException
     */
    public function takeBalanceSnapshots(int $batchSize): int
    {
        // Call the PostgreSQL function to take snapshots in batches
        try {
            $stmt = PgStatement::execute($this->conn, '
        SELECT blnk.take_daily_balance_snapshots_batched(?)
    ', [$batchSize]);
            $totalProcessed = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to take balance snapshots');
        }

        if ($totalProcessed === false) {
            throw new DatabaseException('Failed to take balance snapshots', null, 'sql: no rows in result set');
        }

        return RowScanner::toInt($totalProcessed);
    }

    /**
     * validateBalanceTimeParams validates the input parameters for GetBalanceAtTime
     *
     * @throws ApiErrorException BAD_REQUEST
     */
    private function validateBalanceTimeParams(string $balanceID, ?\DateTimeImmutable $targetTime): void
    {
        if ($balanceID === '') {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, 'Balance ID cannot be empty', null);
        }

        if (PqEncoder::isZeroTime($targetTime)) {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, 'Target time cannot be zero', null);
        }
    }

    /**
     * getBalanceInfo retrieves basic information about a balance
     *
     * @param \PDO $tx the connection whose `beginTransaction()` is open
     *
     * @return array{0: string, 1: \DateTimeImmutable|null} [currency, createdAt]
     *
     * @throws NotFoundException "Balance '<id>' not found"
     * @throws ApiErrorException
     */
    private function getBalanceInfo(\PDO $tx, string $balanceID): array
    {
        try {
            $stmt = PgStatement::execute($tx, '
		SELECT currency, created_at FROM blnk.balances WHERE balance_id = ?
	', [$balanceID]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to get balance information');
        }

        if ($row === false) {
            throw new NotFoundException(sprintf("Balance '%s' not found", $balanceID), 'sql: no rows in result set');
        }

        try {
            return [RowScanner::toString($row['currency'] ?? null), RowScanner::toTime($row['created_at'] ?? null)];
        } catch (\InvalidArgumentException $e) {
            throw new DatabaseException('Failed to get balance information', null, $e->getMessage(), $e);
        }
    }

    /**
     * getMostRecentSnapshot finds the most recent balance snapshot before the target time
     *
     * @param \PDO $tx the connection whose `beginTransaction()` is open
     *
     * @return array{0: BigInteger, 1: BigInteger, 2: \DateTimeImmutable|null} [creditBalance, debitBalance, snapshotTime]
     *                                                                         (snapshotTime null = Go zero time when no snapshot exists)
     *
     * @throws ApiErrorException
     */
    private function getMostRecentSnapshot(\PDO $tx, string $balanceID, \DateTimeImmutable $targetTime): array
    {
        try {
            $snapshot = PgStatement::execute($tx, '
		SELECT
			balance,
			credit_balance,
			debit_balance,
			snapshot_time
		FROM blnk.balance_snapshots
		WHERE balance_id = ?
		AND snapshot_time <= ?
		ORDER BY snapshot_time DESC
		LIMIT 1
	', [$balanceID, $targetTime]);
            $row = $snapshot->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Other error occurred
            throw PgStatement::wrap($e, 'Failed to get balance snapshot');
        }

        if ($row !== false) {
            // Snapshot found, use it as starting point
            $snapshotCredit = RowScanner::toString($row['credit_balance'] ?? null);
            $snapshotDebit = RowScanner::toString($row['debit_balance'] ?? null);
            try {
                $creditBalance = $this->parseBigInt($snapshotCredit);
            } catch (\InvalidArgumentException $e) {
                throw new DatabaseException(sprintf('failed to parse snapshot credit_balance: %s', $e->getMessage()), null, null, $e);
            }
            try {
                $debitBalance = $this->parseBigInt($snapshotDebit);
            } catch (\InvalidArgumentException $e) {
                throw new DatabaseException(sprintf('failed to parse snapshot debit_balance: %s', $e->getMessage()), null, null, $e);
            }
            try {
                $snapshotTime = RowScanner::toTime($row['snapshot_time'] ?? null);
            } catch (\InvalidArgumentException $e) {
                throw new DatabaseException('Failed to get balance snapshot', null, $e->getMessage(), $e);
            }
            Log::get()->debug('found snapshot for balance', [
                'balance_id' => $balanceID,
                'time' => ModelHelpers::goTimeString($snapshotTime),
                'credit' => $snapshotCredit,
                'debit' => $snapshotDebit,
            ]);
            return [$creditBalance, $debitBalance, $snapshotTime];
        }

        // No snapshot found, calculate from genesis (all transactions)
        Log::get()->debug('no snapshot found, calculating from genesis', ['balance_id' => $balanceID]);
        return [BigInteger::zero(), BigInteger::zero(), null];
    }

    /**
     * fetchTransactions retrieves transactions for a balance within a specific time range
     * using effective_date if available, otherwise falling back to created_at
     *
     * (Go binds $1 twice; the balance ID is bound twice for the positional placeholders.
     * A null $startTime is the Go zero time, sent as "0001-01-01 00:00:00Z".)
     *
     * @param \PDO $tx the connection whose `beginTransaction()` is open
     *
     * @throws ApiErrorException
     */
    private function fetchTransactions(\PDO $tx, string $balanceID, ?\DateTimeImmutable $startTime, \DateTimeImmutable $targetTime): \PDOStatement
    {
        Log::get()->debug('querying transactions', [
            'balance_id' => $balanceID,
            'start_time' => ModelHelpers::goTimeString($startTime),
            'end_time' => ModelHelpers::goTimeString($targetTime),
        ]);
        try {
            return PgStatement::execute($tx, '
        SELECT precise_amount, source, destination, created_at,
               COALESCE(effective_date, created_at) as effective_date
        FROM blnk.transactions
        WHERE (source = ? OR destination = ?)
        AND COALESCE(effective_date, created_at) > ?
        AND COALESCE(effective_date, created_at) <= ?
        AND status = \'APPLIED\'
        ORDER BY COALESCE(effective_date, created_at) ASC
    ', [$balanceID, $balanceID, PqEncoder::time($startTime), $targetTime]);
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Failed to get transactions');
        }
    }

    /**
     * applyTransaction applies a single transaction to update balance totals
     *
     * @param array{preciseAmount: string, source: string, destination: string, createdAt: \DateTimeImmutable|null, effectiveDate: \DateTimeImmutable|null} $txn
     *
     * @return array{0: BigInteger, 1: BigInteger} [creditBalance, debitBalance]
     *
     * @throws ApiErrorException "Invalid transaction amount"
     */
    private function applyTransaction(array $txn, string $balanceID, BigInteger $creditBalance, BigInteger $debitBalance): array
    {
        try {
            $txAmount = RowScanner::toBigInteger($txn['preciseAmount']);
        } catch (\InvalidArgumentException) {
            throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Invalid transaction amount', null);
        }

        // Use the transaction amount to update the appropriate balance
        if ($txn['source'] === $balanceID) {
            $debitBalance = $debitBalance->plus($txAmount);
        }
        if ($txn['destination'] === $balanceID) {
            $creditBalance = $creditBalance->plus($txAmount);
        }

        return [$creditBalance, $debitBalance];
    }

    /**
     * calculateBalanceFromTransactions applies transactions to calculate the balance at a specific time
     *
     * @param \PDO $tx the connection whose `beginTransaction()` is open
     *
     * @return array{0: BigInteger, 1: BigInteger} [creditBalance, debitBalance]
     *
     * @throws ApiErrorException
     */
    private function calculateBalanceFromTransactions(\PDO $tx, string $balanceID, ?\DateTimeImmutable $startTime, \DateTimeImmutable $targetTime, BigInteger $initialCredit, BigInteger $initialDebit): array
    {
        // (BigInteger is immutable, so the copies Go makes are implicit.)
        $creditBalance = $initialCredit;
        $debitBalance = $initialDebit;

        // Fetch relevant transactions
        $rows = $this->fetchTransactions($tx, $balanceID, $startTime, $targetTime);

        // Count of processed transactions for debugging
        $transactionCount = 0;

        // Apply transactions
        try {
            while (($row = $rows->fetch(\PDO::FETCH_ASSOC)) !== false) {
                try {
                    $txn = [
                        'preciseAmount' => RowScanner::toString($row['precise_amount'] ?? null),
                        'source' => RowScanner::toString($row['source'] ?? null),
                        'destination' => RowScanner::toString($row['destination'] ?? null),
                        'createdAt' => RowScanner::toTime($row['created_at'] ?? null),
                        'effectiveDate' => RowScanner::toTime($row['effective_date'] ?? null),
                    ];
                } catch (\InvalidArgumentException $e) {
                    throw new DatabaseException('Failed to scan transaction', null, $e->getMessage(), $e);
                }

                // Apply this transaction to update balances
                [$creditBalance, $debitBalance] = $this->applyTransaction($txn, $balanceID, $creditBalance, $debitBalance);

                $transactionCount++;
            }
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Error processing transactions');
        } finally {
            $rows->closeCursor();
        }

        Log::get()->debug('processed transactions', [
            'balance_id' => $balanceID,
            'transaction_count' => $transactionCount,
        ]);

        return [$creditBalance, $debitBalance];
    }

    /**
     * GetBalanceAtTime retrieves the balance state at a specific point in time.
     * It finds the most recent snapshot before the target time and applies any subsequent
     * transactions to calculate the exact balance state. If fromSource is true, it skips
     * using snapshots and calculates directly from all transactions.
     *
     * Parameters:
     * - balanceID: The ID of the balance to query
     * - targetTime: The point in time for which to get the balance state
     * - fromSource: If true, calculation is done from all transactions rather than using snapshots
     *
     * Returns:
     * - *Balance: The calculated balance state at the target time
     * - error: An APIError if any issues occur during the operation
     *
     * (Go's 30-second context timeout is dropped with the context. The
     * read-only REPEATABLE READ transaction is opened with an explicit
     * SET TRANSACTION, which is what database/sql's BeginTx options amount to.)
     *
     * @throws ApiErrorException
     */
    public function getBalanceAtTime(string $balanceID, \DateTimeImmutable $targetTime, bool $fromSource): Balance
    {
        // Validate inputs
        $this->validateBalanceTimeParams($balanceID, $targetTime);

        // Start transaction
        try {
            $this->conn->beginTransaction();
            $this->conn->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        } catch (\PDOException $e) {
            PgStatement::rollbackQuietly($this->conn);
            throw PgStatement::wrap($e, 'Failed to start transaction');
        }

        try {
            // Get basic balance information
            [$currency, $balanceCreatedAt] = $this->getBalanceInfo($this->conn, $balanceID);

            if ($fromSource) {
                // Skip snapshots and start from zero
                Log::get()->debug('skipping snapshots, calculating from genesis', ['balance_id' => $balanceID]);
                $creditBalance = BigInteger::zero();
                $debitBalance = BigInteger::zero();
                $startTime = null; // Use zero time to get all transactions
            } else {
                // Try to find the most recent snapshot
                [$creditBalance, $debitBalance, $startTime] = $this->getMostRecentSnapshot($this->conn, $balanceID, $targetTime);
            }

            // Calculate the balance by applying transactions since the snapshot (or from genesis)
            [$creditBalance, $debitBalance] = $this->calculateBalanceFromTransactions(
                $this->conn,
                $balanceID,
                $startTime,
                $targetTime,
                $creditBalance,
                $debitBalance
            );

            // Calculate final balance
            $balance = $creditBalance->minus($debitBalance);

            Log::get()->debug('final calculated balance', [
                'balance_id' => $balanceID,
                'target_time' => ModelHelpers::goTimeString($targetTime),
                'credit' => (string) $creditBalance,
                'debit' => (string) $debitBalance,
                'balance' => (string) $balance,
            ]);

            // Commit transaction
            try {
                $this->conn->commit();
            } catch (\PDOException $e) {
                throw PgStatement::wrap($e, 'Failed to commit transaction');
            }
        } catch (\Throwable $err) {
            if ($this->conn->inTransaction()) {
                try {
                    $this->conn->rollBack();
                } catch (\PDOException $rollbackErr) {
                    Log::get()->error('GetBalanceAtTime: failed to rollback transaction', [
                        'error' => $rollbackErr->getMessage(),
                        'original_error' => $err->getMessage(),
                    ]);
                }
            }
            throw $err;
        }

        // Construct result
        $result = new Balance();
        $result->balanceID = $balanceID;
        $result->balance = $balance;
        $result->creditBalance = $creditBalance;
        $result->debitBalance = $debitBalance;
        $result->currency = $currency;
        $result->createdAt = $balanceCreatedAt;

        return $result;
    }

    /**
     * UpdateBalanceIdentity updates the identity_id of a balance entry in the database.
     *
     * Parameters:
     * - balanceID: The unique identifier of the balance whose identity reference is to be updated.
     * - identityID: The identity ID to be associated with the balance.
     *
     * Returns:
     * - error: An error is returned if the balance or identity does not exist or the database operation fails.
     *
     * @throws NotFoundException "Balance with ID '<id>' not found"
     * @throws ApiErrorException
     */
    public function updateBalanceIdentity(string $balanceID, string $identityID): void
    {
        // Execute the SQL update statement to change the identity_id for the specified balance.
        // (Go: SET identity_id = $2 WHERE balance_id = $1 — arguments follow textual order here.)
        try {
            $result = PgStatement::execute($this->conn, '
		UPDATE blnk.balances
		SET identity_id = ?
		WHERE balance_id = ?
	', [$identityID, $balanceID]);
        } catch (\PDOException $e) {
            // Delegate to apierror for consistent error handling across the project
            throw PgStatement::wrap($e, 'Failed to update balance identity');
        }

        // Ensure a row was actually updated
        $rowsAffected = $result->rowCount();

        if ($rowsAffected === 0) {
            // No rows were updated – the balance record does not exist
            throw new NotFoundException(sprintf("Balance with ID '%s' not found", $balanceID));
        }
    }

    // ------------------------------------------------------------------
    // Advanced filtering
    // ------------------------------------------------------------------

    /**
     * GetAllBalancesWithFilter retrieves balances with advanced filtering support.
     * It delegates to GetAllBalancesWithFilterAndOptions with nil options.
     *
     * Parameters:
     * - filters: A QueryFilterSet containing the filter conditions.
     * - limit: The maximum number of balances to return.
     * - offset: The offset to start fetching balances from (for pagination).
     *
     * Returns:
     * - []model.Balance: A slice of balances matching the filter criteria.
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * @return Balance[]
     *
     * @throws ApiErrorException
     */
    public function getAllBalancesWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array
    {
        [$balances] = $this->getAllBalancesWithFilterAndOptions($filters, null, $limit, $offset);
        return $balances;
    }

    /**
     * GetAllBalancesWithFilterAndOptions retrieves balances with filtering, sorting, and optional count.
     * It uses the filter package to build SQL WHERE and ORDER BY conditions.
     *
     * Parameters:
     * - filters: A QueryFilterSet containing the filter conditions.
     * - opts: Query options including sorting and count settings.
     * - limit: The maximum number of balances to return.
     * - offset: The offset to start fetching balances from (for pagination).
     *
     * Returns:
     * - []model.Balance: A slice of balances matching the filter criteria.
     * - *int64: Optional total count of matching records (if opts.IncludeCount is true).
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * @return array{0: Balance[], 1: int|null} `[$balances, $totalCount]`
     *
     * @throws ApiErrorException
     */
    public function getAllBalancesWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array
    {
        if ($limit <= 0 || $limit > 1000) {
            $limit = 1000;
        }

        if ($opts === null) {
            $opts = new QueryOptions();
        }
        try {
            Validation::validateSortByForTable($opts, 'balances');
        } catch (FilterValidationException) {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, 'Invalid sort_by field', null);
        }

        try {
            $result = SqlBuilder::buildWithOptions($filters, 'balances', '', 1, $opts);
        } catch (FilterValidationException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, sprintf('Invalid filter: %s', $e->getMessage()), $e->getMessage());
        }

        // Determine select fields based on whether count is requested
        $selectFields = "balance_id, indicator, balance, credit_balance, debit_balance, inflight_balance, inflight_credit_balance, inflight_debit_balance, currency, ledger_id, COALESCE(identity_id, '') as identity_id, created_at, meta_data";
        if ($opts->includeCount) {
            $selectFields .= ', COUNT(*) OVER() AS total_count';
        }

        // Build base query
        $baseQuery = sprintf('
		SELECT %s
		FROM blnk.balances
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
            throw PgStatement::wrap($e, 'Failed to retrieve balances');
        }

        $balances = [];
        $totalCount = null;

        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if ($opts->includeCount) {
                    $count = RowScanner::toInt($row['total_count'] ?? null);
                    if ($totalCount === null) {
                        $totalCount = $count;
                    }
                }

                $balances[] = $this->rowToBalanceListEntry($row, true);
            }
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Error occurred while iterating over balances');
        }

        return [$balances, $totalCount];
    }
}
