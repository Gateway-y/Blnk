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

namespace Blnk\Core;

use Blnk\Config\Configuration;
use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\Filter\QueryFilterSet;
use Blnk\Internal\Filter\QueryOptions;
use Blnk\Internal\Request\Request;
use Blnk\Model\Account;
use GuzzleHttp\Psr7\Request as HttpRequest;

/**
 * Port of account.go: the account methods of the Go `Blnk` struct (plus the
 * package-level `applyExternalAccount`), composed into {@see Blnk}.
 */
trait AccountService
{
    /**
     * applyExternalAccount applies external account details to the given account.
     * It fetches the configuration, checks if auto-generation is enabled, and makes an HTTP request to get account details.
     * If the account details are successfully retrieved, they are applied to the account.
     *
     * Parameters:
     * - $account: The Account model to which external details will be applied.
     *
     * The Go local type `accountDetails{AccountNumber "account_number"; BankName "bank_name"}`
     * is read from the decoded JSON response array.
     *
     * @throws \Throwable if the operation fails (config not loaded, request or JSON decoding failure).
     */
    protected static function applyExternalAccount(Account $account): void
    {
        $cnf = Configuration::fetch();

        if ($cnf->accountNumberGeneration->enableAutoGeneration) {
            $req = new HttpRequest('GET', $cnf->accountNumberGeneration->httpService->url);

            // Set the Authorization header for the HTTP request using the configuration.
            $req = $req->withHeader('Authorization', $cnf->accountNumberGeneration->httpService->headers->authorization);
            $response = null;
            Request::call($req, $response);

            $accountNumber = \is_array($response) ? (string) ($response['account_number'] ?? '') : '';
            $bankName = \is_array($response) ? (string) ($response['bank_name'] ?? '') : '';

            if ($accountNumber !== '' && $bankName !== '') {
                $account->number = $accountNumber;
                $account->bankName = $bankName;
            }
        }
    }

    /**
     * applyAccountName applies a name to the given account based on its identity.
     * If the account name is empty, it fetches the identity and sets the account name
     * based on the identity type (organization or individual).
     *
     * Parameters:
     * - $account: The Account model to which the name will be applied.
     *
     * @throws ApiErrorException if the identity could not be retrieved.
     */
    protected function applyAccountName(Account $account): void
    {
        if ($account->name === '') {
            $identity = $this->getIdentity($account->identityID);
            if ($identity->identityType === 'organization') {
                $account->name = $identity->organizationName;
            } else {
                $account->name = sprintf('%s %s', $identity->firstName, $identity->lastName);
            }
        }
    }

    /**
     * overrideLedgerAndIdentity overrides the ledger and identity details of the given account
     * based on the balance information. It fetches the balance by its ID and updates the account
     * with the balance's identity ID, ledger ID, and currency if they are not empty.
     *
     * Parameters:
     * - $account: The Account model to be updated.
     *
     * @throws ApiErrorException if the balance could not be retrieved.
     */
    protected function overrideLedgerAndIdentity(Account $account): void
    {
        $balance = $this->datasource->getBalanceByIDLite($account->balanceID);

        if ($balance->identityID !== '') {
            $account->identityID = $balance->identityID;
        }

        if ($balance->ledgerID !== '') {
            $account->ledgerID = $balance->ledgerID;
        }

        if ($balance->currency !== '') {
            $account->currency = $balance->currency;
        }
    }

    /**
     * CreateAccount creates a new account in the database.
     * It overrides the ledger and identity details, applies the account name, and fetches external account details.
     *
     * Parameters:
     * - $account: The Account model to be created.
     *
     * Returns the created Account model.
     *
     * @throws \Throwable if the account could not be created.
     */
    public function createAccount(Account $account): Account
    {
        $this->overrideLedgerAndIdentity($account);

        $this->applyAccountName($account);

        self::applyExternalAccount($account);

        return $this->datasource->createAccount($account);
    }

    /**
     * GetAccount retrieves an account by its ID.
     * It fetches the account from the datasource and includes additional data as specified.
     *
     * Parameters:
     * - $id: The ID of the account to retrieve.
     * - $include: A slice of strings specifying additional data to include.
     *
     * @param string[] $include
     * @throws ApiErrorException if the account could not be retrieved.
     */
    public function getAccount(string $id, array $include): Account
    {
        return $this->datasource->getAccountByID($id, $include);
    }

    /**
     * GetAccountByNumber retrieves an account from the database by its account number.
     *
     * Parameters:
     * - $id: The account number of the account to retrieve.
     *
     * @throws ApiErrorException if the account could not be retrieved.
     */
    public function getAccountByNumber(string $id): Account
    {
        return $this->datasource->getAccountByNumber($id);
    }

    /**
     * GetAllAccounts retrieves all accounts from the database.
     *
     * @return Account[]
     * @throws ApiErrorException if the accounts could not be retrieved.
     */
    public function getAllAccounts(): array
    {
        return $this->datasource->getAllAccounts();
    }

    /**
     * GetAllAccountsWithFilter retrieves accounts using advanced filters.
     *
     * Parameters:
     * - $filters: Filter conditions to apply.
     * - $limit: Maximum number of accounts to return.
     * - $offset: Offset for pagination.
     *
     * @return Account[] Account models matching the filter criteria.
     * @throws ApiErrorException if the accounts could not be retrieved.
     */
    public function getAllAccountsWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array
    {
        return $this->datasource->getAllAccountsWithFilter($filters, $limit, $offset);
    }

    /**
     * GetAllAccountsWithFilterAndOptions retrieves accounts with filters, sorting, and optional count.
     *
     * Parameters:
     * - $filters: Filter conditions to apply.
     * - $opts: Query options including sorting and count settings.
     * - $limit: Maximum number of accounts to return.
     * - $offset: Offset for pagination.
     *
     * Go returns `([]model.Account, *int64, error)`.
     *
     * @return array{0: Account[], 1: int|null} `[$accounts, $totalCount]`; the count is null unless `$opts->includeCount`.
     * @throws ApiErrorException if the accounts could not be retrieved.
     */
    public function getAllAccountsWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array
    {
        return $this->datasource->getAllAccountsWithFilterAndOptions($filters, $opts, $limit, $offset);
    }
}
