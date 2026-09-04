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

namespace Blnk\Api;

use Blnk\Api\Model\CreateAccount;
use Blnk\Api\Model\ValidationErrors;
use Blnk\Internal\ApiError\ErrorCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/accounts.go: the account handlers of the Go `Api` struct,
 * composed into {@see Api}.
 */
trait AccountHandlers
{
    /**
     * CreateAccount handles the creation of a new account.
     * It binds the incoming JSON request body to a CreateAccount model, validates it,
     * and creates the account if the input is valid.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding the JSON or validation fails.
     * - 201 Created: If the account is successfully created.
     * - 500 Internal Server Error: If there's an error in account creation.
     */
    public function createAccount(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $newAccount = CreateAccount::fromArray(Binding::shouldBindJSON($request, 'model.CreateAccount'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        try {
            $newAccount->validateCreateAccount();
        } catch (ValidationErrors $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, $err->getMessage(), null, Errors::withLegacyKey('errors'));
        }

        try {
            $resp = $this->blnk->createAccount($newAccount->toAccount());
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenConflict, ErrorCode::ErrAccDuplicate));
        }

        return Json::write($response, 201, $resp);
    }

    /**
     * GetAccount retrieves an account by its ID.
     * It uses the provided account ID and optional query parameters to fetch the account.
     *
     * Parameters:
     * - id: The unique identifier of the account to retrieve.
     * - includes: Optional query parameters to include related data.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in fetching the account.
     * - 200 OK: If the account is successfully retrieved.
     */
    public function getAccount(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = Query::param($args, 'id');

        $includes = Query::queryArray($request, 'include');

        try {
            $account = $this->blnk->getAccount($id, $includes);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrAccNotFound));
        }

        return Json::write($response, 200, $account);
    }

    /**
     * GetAllAccounts retrieves all accounts.
     * It fetches a list of all accounts in the system.
     * Supports advanced filtering via query parameters in the format: field_operator=value
     * Example filters:
     *   - name_eq=Main Account
     *   - currency_in=USD,EUR
     *   - created_at_gte=2024-01-01
     *
     * Responses:
     * - 400 Bad Request: If there's an error in fetching the accounts or invalid filters.
     * - 200 OK: If the accounts are successfully retrieved.
     */
    public function getAllAccounts(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Extract limit and offset from query parameters
        $limitStr = Query::defaultQuery($request, 'limit', '20');
        $offsetStr = Query::defaultQuery($request, 'offset', '0');

        $limitInt = Query::atoi($limitStr);
        if ($limitInt === null || $limitInt <= 0) {
            $limitInt = 20;
        }

        $offsetInt = Query::atoi($offsetStr);
        if ($offsetInt === null || $offsetInt < 0) {
            $offsetInt = 0;
        }

        // Check if advanced filters are present
        if (FilterHelper::hasFilters($request)) {
            [$filters, $parseErrors] = FilterHelper::parseFiltersFromContext($request, null);
            if (count($parseErrors) > 0) {
                return Errors::respondCode(
                    $response,
                    ErrorCode::ErrGenValidation,
                    'invalid filter parameters',
                    $parseErrors,
                    Errors::withLegacyKey('errors'),
                    Errors::withLegacyValue($parseErrors)
                );
            }

            // Use the new filter method
            try {
                $resp = $this->blnk->getAllAccountsWithFilter($filters, $limitInt, $offsetInt);
            } catch (\Throwable $err) {
                return Errors::respondError($response, $err);
            }

            return Json::write($response, 200, $resp);
        }

        // Fall back to the legacy method when no filters are present
        try {
            $accounts = $this->blnk->getAllAccounts();
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 200, $accounts);
    }

    /**
     * FilterAccounts filters accounts using a JSON request body.
     * This endpoint accepts a POST request with filters specified in JSON format.
     *
     * Request body format:
     *
     *	{
     *	  "filters": [
     *	    {"field": "currency", "operator": "eq", "value": "USD"},
     *	    {"field": "name", "operator": "ilike", "value": "%main%"}
     *	  ],
     *	  "limit": 20,
     *	  "offset": 0
     *	}
     *
     * Responses:
     * - 400 Bad Request: If there's an error parsing the filters or retrieving accounts.
     * - 200 OK: If the accounts are successfully retrieved.
     */
    public function filterAccounts(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            [$filters, $opts, $limit, $offset] = FilterHelper::parseFiltersFromBody($request, 'accounts');
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, $err->getMessage(), null);
        }

        try {
            [$resp, $count] = $this->blnk->getAllAccountsWithFilterAndOptions($filters, $opts, $limit, $offset);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        if ($opts->includeCount) {
            return Json::write($response, 200, new FilterResponse($resp, $count));
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * generateMockAccount generates and returns a mock account for testing purposes.
     * It provides a mock bank name and account number.
     *
     * Responses:
     * - 200 OK: Returns a mock account with bank name and account number.
     */
    public function generateMockAccount(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, 200, [
            'bank_name' => 'Blnk Bank',
            'account_number' => self::achAccount(),
        ]);
    }

    /**
     * achAccount is `gofakeit.AchAccount()`: a random 12-digit ACH account number.
     */
    private static function achAccount(): string
    {
        $digits = '';
        for ($i = 0; $i < 12; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }
}
