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
use Blnk\Internal\Log;
use Blnk\Model\Account;
use Blnk\Model\Balance;
use Blnk\Model\Identity;
use Blnk\Model\Ledger;
use Blnk\Model\ModelHelpers;

/**
 * Port of database/account.go: the account methods of `Datasource`, plus the
 * file's package-level helpers (`prepareAccountQueries`, `scanAccountRow`) as
 * private methods.
 *
 * Composed into {@see Datasource}; uses `$this->conn` (\PDO) and the
 * balance.go helpers `contains` / `parseBigInt` from {@see BalanceRepository}.
 * Most Go methods here return raw (non-apierror) errors: those surface as
 * {@see DatabaseException} carrying the driver message.
 */
trait AccountRepository
{
    /**
     * CreateAccount inserts a new Account into the database.
     * This function handles metadata serialization and database insertion.
     * Parameters:
     * - account: The account model containing fields such as name, number, bank name, currency, ledger ID, identity ID, and balance ID.
     * Returns:
     * - model.Account: The created account with the assigned account ID and creation timestamp.
     * - error: Returns an error if any issue occurs while marshalling metadata or executing the database query.
     *
     * (Go receives the account by value and returns a modified copy; PHP objects
     * are handles, so the passed object is updated in place and returned.)
     *
     * @throws ApiErrorException
     */
    public function createAccount(Account $account): Account
    {
        // Serialize metadata into JSON
        try {
            $metaDataJSON = PqEncoder::json($account->metaData);
        } catch (\JsonException $e) {
            throw new DatabaseException($e->getMessage(), null, null, $e); // Return error if metadata marshalling fails
        }

        // Generate a unique account ID and assign the current time for the account creation
        $account->accountID = ModelHelpers::generateUUIDWithSuffix('acc');
        $account->createdAt = PqEncoder::now();

        // Insert the new account into the database
        try {
            PgStatement::execute($this->conn, '
		INSERT INTO blnk.accounts (account_id, name, number, bank_name, currency, ledger_id, identity_id, balance_id, created_at, meta_data)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
	', [
                $account->accountID,
                $account->name,
                $account->number,
                $account->bankName,
                $account->currency,
                $account->ledgerID,
                $account->identityID,
                $account->balanceID,
                $account->createdAt,
                $metaDataJSON,
            ]);
        } catch (\PDOException $e) {
            // Return the account object and any error that occurred during the database operation
            throw DatabaseException::fromPDOException($e);
        }

        return $account;
    }

    /**
     * GetAccountByID retrieves an account by its ID from the database.
     * It uses a transaction to ensure consistency and can include additional
     * related entities like balance, identity, or ledger if specified in the `include` parameter.
     * Parameters:
     * - id: The ID of the account to retrieve.
     * - include: A list of related entities to include in the query result.
     * Returns:
     * - A pointer to the retrieved Account or an error if something goes wrong.
     *
     * (Go's 1-minute context timeout is dropped with the context.)
     *
     * @param string[] $include
     *
     * @throws NotFoundException "account with ID '<id>' not found" (Go: a plain error)
     * @throws ApiErrorException
     */
    public function getAccountByID(string $id, array $include): Account
    {
        // Start a transaction
        try {
            $this->conn->beginTransaction();
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        // Prepare the query with additional includes if needed
        $query = $this->prepareAccountQueries($include);

        // Execute the query
        try {
            $stmt = PgStatement::execute($this->conn, $query, [$id]);
            $row = $stmt->fetch(\PDO::FETCH_NUM);
        } catch (\PDOException $e) {
            Log::get()->error('Error: ' . $e->getMessage());
            PgStatement::rollbackQuietly($this->conn);
            throw DatabaseException::fromPDOException($e);
        }

        if ($row === false) {
            // No account found for the given ID
            Log::get()->error('Error: sql: no rows in result set');
            PgStatement::rollbackQuietly($this->conn);
            throw new NotFoundException(sprintf("account with ID '%s' not found", $id), 'sql: no rows in result set');
        }

        // Scan the result into the account object
        $account = $this->scanAccountRow($row, $this->conn, $include);

        // Commit the transaction
        try {
            $this->conn->commit();
        } catch (\PDOException $e) {
            PgStatement::rollbackQuietly($this->conn);
            throw DatabaseException::fromPDOException($e);
        }

        // Return the account object
        return $account;
    }

    /**
     * prepareAccountQueries constructs an SQL query for retrieving accounts, including
     * optional related entities such as balance, identity, and ledger if specified in the `include` parameter.
     * Parameters:
     * - include: A list of related entities (balance, identity, ledger) to be included in the query.
     * Returns:
     * - A constructed SQL query string.
     *
     * (Go passes a strings.Builder by value; it is a local here.)
     *
     * @param string[] $include
     */
    private function prepareAccountQueries(array $include): string
    {
        $selectFields = [];
        // Default fields for the account
        array_push(
            $selectFields,
            'a.account_id',
            'a.name',
            'a.number',
            'a.bank_name',
            'a.currency',
            'a.ledger_id',
            'a.identity_id',
            'a.balance_id',
            'a.created_at',
            'a.meta_data'
        );

        // Include balance fields if specified
        if ($this->contains($include, 'balance')) {
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
                'b.meta_data'
            );
        }

        // Include identity fields if specified
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
                'i.identity_type',
                'i.created_at',
                'i.meta_data'
            );
        }

        // Include ledger fields if specified
        if ($this->contains($include, 'ledger')) {
            array_push($selectFields, 'l.ledger_id', 'l.name', 'l.created_at');
        }

        // Construct the query
        $queryBuilder = 'SELECT ';
        $queryBuilder .= implode(', ', $selectFields);
        $queryBuilder .= '
        FROM (
            SELECT * FROM blnk.accounts WHERE account_id = ?
        ) AS a
    ';

        // Join identity if specified
        if ($this->contains($include, 'identity')) {
            $queryBuilder .= '
            LEFT JOIN blnk.identity i ON a.identity_id = i.identity_id
        ';
        }

        // Join ledger if specified
        if ($this->contains($include, 'ledger')) {
            $queryBuilder .= '
            LEFT JOIN blnk.ledgers l ON a.ledger_id = l.ledger_id
        ';
        }

        // Join balance if specified
        if ($this->contains($include, 'balance')) {
            $queryBuilder .= '
            LEFT JOIN blnk.balances b ON a.balance_id = b.balance_id
        ';
        }

        return $queryBuilder;
    }

    /**
     * scanAccountRow scans a row from the database into an Account object.
     * It can also scan related Balance, Identity, and Ledger data if specified in the `include` parameter.
     * Parameters:
     * - row: The SQL row containing the account data (positional, \PDO::FETCH_NUM — the joined SELECT repeats column names).
     * - tx: The active SQL transaction (rolled back on any failure, as in Go).
     * - include: A list of related entities (balance, identity, ledger) to be included in the scan.
     * Returns:
     * - A pointer to the populated Account object or an error if the scan fails.
     *
     * @param array<int, mixed> $row
     * @param \PDO $tx the connection whose `beginTransaction()` is open
     * @param string[] $include
     *
     * @throws DatabaseException
     */
    private function scanAccountRow(array $row, \PDO $tx, array $include): Account
    {
        $account = new Account();
        $balance = new Balance();
        $identity = new Identity();
        $ledger = new Ledger();

        try {
            $i = 0;
            // Default fields for the account
            $account->accountID = RowScanner::toString($row[$i++] ?? null);
            $account->name = RowScanner::toString($row[$i++] ?? null);
            $account->number = RowScanner::toString($row[$i++] ?? null);
            $account->bankName = RowScanner::toString($row[$i++] ?? null);
            $account->currency = RowScanner::toString($row[$i++] ?? null);
            $account->ledgerID = RowScanner::toString($row[$i++] ?? null);
            $account->identityID = RowScanner::toString($row[$i++] ?? null);
            $account->balanceID = RowScanner::toString($row[$i++] ?? null);
            $account->createdAt = RowScanner::toTime($row[$i++] ?? null);
            $accountMetaJSON = $row[$i++] ?? null;

            // Balance NUMERIC columns are scanned as strings and parsed into big.Int,
            // since database/sql cannot scan directly into *big.Int.
            $balanceStr = '';
            $creditBalanceStr = '';
            $debitBalanceStr = '';
            $balanceMetaJSON = null;
            if ($this->contains($include, 'balance')) {
                $balance->balanceID = RowScanner::toString($row[$i++] ?? null);
                $balanceStr = RowScanner::toString($row[$i++] ?? null);
                $creditBalanceStr = RowScanner::toString($row[$i++] ?? null);
                $debitBalanceStr = RowScanner::toString($row[$i++] ?? null);
                $balance->currency = RowScanner::toString($row[$i++] ?? null);
                $balance->ledgerID = RowScanner::toString($row[$i++] ?? null);
                $balance->identityID = RowScanner::toString($row[$i++] ?? null);
                $balance->createdAt = RowScanner::toTime($row[$i++] ?? null);
                $balanceMetaJSON = $row[$i++] ?? null;
            }

            // Add fields for identity if included
            $identityMetaJSON = null;
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
                $identity->identityType = RowScanner::toString($row[$i++] ?? null);
                $identity->createdAt = RowScanner::toTime($row[$i++] ?? null);
                $identityMetaJSON = $row[$i++] ?? null;
            }

            // Add fields for ledger if included
            if ($this->contains($include, 'ledger')) {
                $ledger->ledgerID = RowScanner::toString($row[$i++] ?? null);
                $ledger->name = RowScanner::toString($row[$i++] ?? null);
                $ledger->createdAt = RowScanner::toTime($row[$i++] ?? null);
            }
        } catch (\InvalidArgumentException $e) {
            Log::get()->error('Error: ' . $e->getMessage());
            PgStatement::rollbackQuietly($tx);
            throw new DatabaseException($e->getMessage(), null, null, $e);
        }

        // Unmarshal the account metadata from JSON
        try {
            $account->metaData = RowScanner::toMetaData($accountMetaJSON);
        } catch (\InvalidArgumentException $e) {
            PgStatement::rollbackQuietly($tx);
            throw new DatabaseException($e->getMessage(), null, null, $e);
        }

        if ($this->contains($include, 'balance')) {
            try {
                $balance->balance = $this->parseBigInt($balanceStr);
            } catch (\InvalidArgumentException $e) {
                PgStatement::rollbackQuietly($tx);
                throw new DatabaseException(sprintf('failed to parse balance: %s', $e->getMessage()), null, null, $e);
            }
            try {
                $balance->creditBalance = $this->parseBigInt($creditBalanceStr);
            } catch (\InvalidArgumentException $e) {
                PgStatement::rollbackQuietly($tx);
                throw new DatabaseException(sprintf('failed to parse credit_balance: %s', $e->getMessage()), null, null, $e);
            }
            try {
                $balance->debitBalance = $this->parseBigInt($debitBalanceStr);
            } catch (\InvalidArgumentException $e) {
                PgStatement::rollbackQuietly($tx);
                throw new DatabaseException(sprintf('failed to parse debit_balance: %s', $e->getMessage()), null, null, $e);
            }
            try {
                $balance->metaData = RowScanner::toMetaData($balanceMetaJSON);
            } catch (\InvalidArgumentException $e) {
                PgStatement::rollbackQuietly($tx);
                throw new DatabaseException($e->getMessage(), null, null, $e);
            }
        }

        if ($this->contains($include, 'identity')) {
            try {
                $identity->metaData = RowScanner::toMetaData($identityMetaJSON);
            } catch (\InvalidArgumentException $e) {
                PgStatement::rollbackQuietly($tx);
                throw new DatabaseException($e->getMessage(), null, null, $e);
            }
        }

        // Assign related entities if included
        if ($this->contains($include, 'identity')) {
            $account->identity = $identity;
        }
        if ($this->contains($include, 'balance')) {
            $account->balance = $balance;
        }
        if ($this->contains($include, 'ledger')) {
            $account->ledger = $ledger;
        }

        return $account;
    }

    /**
     * GetAllAccounts retrieves all accounts from the database.
     * It returns a list of Account objects, each populated with metadata and account details.
     * Returns:
     * - A slice of Account objects or an error if the query or scan fails.
     *
     * @return Account[]
     *
     * @throws ApiErrorException
     */
    public function getAllAccounts(): array
    {
        // Execute the SQL query to retrieve account data
        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT account_id, name, number, bank_name, currency, created_at, meta_data
		FROM blnk.accounts
		ORDER BY created_at DESC
	');
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        // Create a slice to store the account results
        $accounts = [];

        // Iterate through the rows
        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $account = new Account();

                // Scan the row into an Account object
                try {
                    $account->accountID = RowScanner::toString($row['account_id'] ?? null);
                    $account->name = RowScanner::toString($row['name'] ?? null);
                    $account->number = RowScanner::toString($row['number'] ?? null);
                    $account->bankName = RowScanner::toString($row['bank_name'] ?? null);
                    $account->currency = RowScanner::toString($row['currency'] ?? null);
                    $account->createdAt = RowScanner::toTime($row['created_at'] ?? null);

                    // Unmarshal the metadata JSON into the MetaData field
                    $account->metaData = RowScanner::toMetaData($row['meta_data'] ?? null);
                } catch (\InvalidArgumentException $e) {
                    throw new DatabaseException($e->getMessage(), null, null, $e);
                }

                // Append the account to the accounts slice
                $accounts[] = $account;
            }
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        // Return the list of accounts
        return $accounts;
    }

    /**
     * GetAccountByNumber retrieves an account based on its number.
     * It queries the database for an account with the given number and returns the account details if found.
     * Parameters:
     * - number: The account number to search for.
     * Returns:
     * - A pointer to the Account object if found, or an error if the account is not found or a query error occurs.
     *
     * @throws NotFoundException "account with number '<number>' not found" (Go: a plain error)
     * @throws ApiErrorException
     */
    public function getAccountByNumber(string $number): Account
    {
        // Query the database for the account with the given number
        try {
            $stmt = PgStatement::execute($this->conn, '
		SELECT account_id, name, number, bank_name, created_at, meta_data
		FROM blnk.accounts WHERE number = ?
	', [$number]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        if ($row === false) {
            throw new NotFoundException(sprintf("account with number '%s' not found", $number), 'sql: no rows in result set');
        }

        $account = new Account();

        // Scan the result into the Account object
        try {
            $account->accountID = RowScanner::toString($row['account_id'] ?? null);
            $account->name = RowScanner::toString($row['name'] ?? null);
            $account->number = RowScanner::toString($row['number'] ?? null);
            $account->bankName = RowScanner::toString($row['bank_name'] ?? null);
            $account->createdAt = RowScanner::toTime($row['created_at'] ?? null);

            // Unmarshal the metadata JSON into the MetaData field
            $account->metaData = RowScanner::toMetaData($row['meta_data'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new DatabaseException($e->getMessage(), null, null, $e);
        }

        // Return the account object
        return $account;
    }

    /**
     * UpdateAccount updates a specific account in the database.
     * It updates the account's name, number, bank name, and metadata based on the account ID.
     * Parameters:
     * - account: A pointer to the Account object containing the updated account information.
     * Returns:
     * - An error if the update fails, otherwise returns nil.
     *
     * @throws ApiErrorException
     */
    public function updateAccount(Account $account): void
    {
        // Marshal the MetaData field into JSON
        try {
            $metaDataJSON = PqEncoder::json($account->metaData);
        } catch (\JsonException $e) {
            throw new DatabaseException($e->getMessage(), null, null, $e);
        }

        // Execute the SQL update statement
        // (Go: SET ... = $2..$5 WHERE account_id = $1 — arguments follow textual order here.)
        try {
            PgStatement::execute($this->conn, '
		UPDATE blnk.accounts
		SET name = ?, number = ?, bank_name = ?, meta_data = ?
		WHERE account_id = ?
	', [$account->name, $account->number, $account->bankName, $metaDataJSON, $account->accountID]);
        } catch (\PDOException $e) {
            // Return any errors encountered during the update
            throw DatabaseException::fromPDOException($e);
        }
    }

    /**
     * DeleteAccount deletes a specific account from the database.
     * It removes the account with the given account ID from the accounts table.
     * Parameters:
     * - id: The unique ID of the account to be deleted.
     * Returns:
     * - An error if the deletion fails, otherwise returns nil.
     *
     * @throws ApiErrorException
     */
    public function deleteAccount(string $id): void
    {
        // Execute the SQL delete statement
        try {
            PgStatement::execute($this->conn, '
		DELETE FROM blnk.accounts WHERE account_id = ?
	', [$id]);
        } catch (\PDOException $e) {
            // Return any errors encountered during the deletion
            throw DatabaseException::fromPDOException($e);
        }
    }

    /**
     * GetAllAccountsWithFilter retrieves accounts with advanced filtering support.
     * It delegates to GetAllAccountsWithFilterAndOptions with nil options.
     *
     * Parameters:
     * - filters: A QueryFilterSet containing the filter conditions.
     * - limit: The maximum number of accounts to return.
     * - offset: The offset to start fetching accounts from (for pagination).
     *
     * Returns:
     * - []model.Account: A slice of accounts matching the filter criteria.
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * @return Account[]
     *
     * @throws ApiErrorException
     */
    public function getAllAccountsWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array
    {
        [$accounts] = $this->getAllAccountsWithFilterAndOptions($filters, null, $limit, $offset);
        return $accounts;
    }

    /**
     * GetAllAccountsWithFilterAndOptions retrieves accounts with filtering, sorting, and optional count.
     * It uses the filter package to build SQL WHERE and ORDER BY conditions.
     *
     * Parameters:
     * - filters: A QueryFilterSet containing the filter conditions.
     * - opts: Query options including sorting and count settings.
     * - limit: The maximum number of accounts to return.
     * - offset: The offset to start fetching accounts from (for pagination).
     *
     * Returns:
     * - []model.Account: A slice of accounts matching the filter criteria.
     * - *int64: Optional total count of matching records (if opts.IncludeCount is true).
     * - error: An error if the query fails or if there's an issue processing the results.
     *
     * @return array{0: Account[], 1: int|null} `[$accounts, $totalCount]`
     *
     * @throws ApiErrorException
     */
    public function getAllAccountsWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array
    {
        if ($limit <= 0 || $limit > 100) {
            $limit = 20;
        }

        if ($opts === null) {
            $opts = new QueryOptions();
        }
        try {
            Validation::validateSortByForTable($opts, 'accounts');
        } catch (FilterValidationException) {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, 'Invalid sort_by field', null);
        }

        try {
            $result = SqlBuilder::buildWithOptions($filters, 'accounts', '', 1, $opts);
        } catch (FilterValidationException $e) {
            throw ApiErrorException::newApiError(ErrorCode::ErrBadRequest, sprintf('Invalid filter: %s', $e->getMessage()), $e->getMessage());
        }

        // Determine select fields based on whether count is requested
        $selectFields = 'account_id, name, number, bank_name, currency, ledger_id, identity_id, balance_id, created_at, meta_data';
        if ($opts->includeCount) {
            $selectFields .= ', COUNT(*) OVER() AS total_count';
        }

        // Build base query
        $baseQuery = sprintf('
		SELECT %s
		FROM blnk.accounts
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
            throw PgStatement::wrap($e, 'Failed to retrieve accounts');
        }

        $accounts = [];
        $totalCount = null;

        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $account = new Account();

                try {
                    $account->accountID = RowScanner::toString($row['account_id'] ?? null);
                    $account->name = RowScanner::toString($row['name'] ?? null);
                    $account->number = RowScanner::toString($row['number'] ?? null);
                    $account->bankName = RowScanner::toString($row['bank_name'] ?? null);
                    $account->currency = RowScanner::toString($row['currency'] ?? null);
                    $account->ledgerID = RowScanner::toString($row['ledger_id'] ?? null);
                    $account->identityID = RowScanner::toString($row['identity_id'] ?? null);
                    $account->balanceID = RowScanner::toString($row['balance_id'] ?? null);
                    $account->createdAt = RowScanner::toTime($row['created_at'] ?? null);
                } catch (\InvalidArgumentException $e) {
                    throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to scan account data', $e->getMessage());
                }

                if ($opts->includeCount) {
                    $count = RowScanner::toInt($row['total_count'] ?? null);
                    if ($totalCount === null) {
                        $totalCount = $count;
                    }
                }

                try {
                    $account->metaData = RowScanner::toMetaData($row['meta_data'] ?? null);
                } catch (\InvalidArgumentException $e) {
                    throw ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'Failed to unmarshal metadata', $e->getMessage());
                }

                $accounts[] = $account;
            }
        } catch (\PDOException $e) {
            throw PgStatement::wrap($e, 'Error occurred while iterating over accounts');
        }

        return [$accounts, $totalCount];
    }
}
