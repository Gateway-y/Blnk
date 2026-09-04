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

use Blnk\Api\Model\CreateLedger;
use Blnk\Api\Model\UpdateLedger;
use Blnk\Api\Model\ValidationErrors;
use Blnk\Internal\ApiError\ErrorCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/ledger.go: the ledger handlers of the Go `Api` struct,
 * composed into {@see Api}.
 */
trait LedgerHandlers
{
    /**
     * CreateLedger creates a new ledger record in the system.
     * It binds the incoming JSON request to a CreateLedger object, validates it,
     * and then creates the ledger record. If any errors occur during validation
     * or creation, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding JSON or validating the ledger.
     * - 201 Created: If the ledger is successfully created.
     */
    public function createLedger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $newLedger = CreateLedger::fromArray(Binding::shouldBindJSON($request, 'model.CreateLedger'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null, Errors::withLegacyKey('errors'));
        }

        try {
            $newLedger->validateCreateLedger();
        } catch (ValidationErrors $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, $err->getMessage(), null, Errors::withLegacyKey('errors'));
        }

        try {
            $resp = $this->blnk->createLedger($newLedger->toLedger());
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenConflict, ErrorCode::ErrLgrDuplicate));
        }

        return Json::write($response, 201, $resp);
    }

    /**
     * GetLedger retrieves a ledger record by its ID.
     * It extracts the ID from the route parameters and fetches the ledger record.
     * If the ID is missing or there's an error retrieving the ledger, it responds
     * with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If the ID is missing or there's an error retrieving the ledger.
     * - 200 OK: If the ledger is successfully retrieved.
     */
    public function getLedger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. Pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $resp = $this->blnk->getLedgerByID($id);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrLgrNotFound));
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * GetAllLedgers retrieves all ledger records in the system.
     * It fetches the ledger records and responds with the list of ledgers.
     * Supports advanced filtering via query parameters in the format: field_operator=value
     * Example filters:
     *   - name_eq=USD Ledger
     *   - created_at_gte=2024-01-01
     *   - name_ilike=%savings%
     *
     * If there's an error retrieving the ledgers, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error retrieving the ledger records or invalid filters.
     * - 200 OK: If the ledger records are successfully retrieved.
     */
    public function getAllLedgers(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Extract limit and offset from query parameters
        $limit = Query::defaultQuery($request, 'limit', '10');   // Default limit is 10 if not provided
        $offset = Query::defaultQuery($request, 'offset', '0'); // Default offset is 0 if not provided

        // Convert limit and offset to integers
        $limitInt = Query::atoi($limit);
        if ($limitInt === null || $limitInt < 1) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, 'Invalid limit value', null);
        }

        $offsetInt = Query::atoi($offset);
        if ($offsetInt === null || $offsetInt < 0) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, 'Invalid offset value', null);
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
                $resp = $this->blnk->getAllLedgersWithFilter($filters, $limitInt, $offsetInt);
            } catch (\Throwable $err) {
                return Errors::respondError($response, $err);
            }

            return Json::write($response, 200, $resp);
        }

        // Fall back to the legacy method when no filters are present
        try {
            $resp = $this->blnk->getAllLedgers($limitInt, $offsetInt);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * FilterLedgers filters ledgers using a JSON request body.
     * This endpoint accepts a POST request with filters specified in JSON format.
     *
     * Request body format:
     *
     *	{
     *	  "filters": [
     *	    {"field": "name", "operator": "ilike", "value": "%savings%"}
     *	  ],
     *	  "limit": 20,
     *	  "offset": 0
     *	}
     *
     * Responses:
     * - 400 Bad Request: If there's an error parsing the filters or retrieving ledgers.
     * - 200 OK: If the ledgers are successfully retrieved.
     */
    public function filterLedgers(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            [$filters, $opts, $limit, $offset] = FilterHelper::parseFiltersFromBody($request, 'ledgers');
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, $err->getMessage(), null);
        }

        try {
            [$resp, $count] = $this->blnk->getAllLedgersWithFilterAndOptions($filters, $opts, $limit, $offset);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        if ($opts->includeCount) {
            return Json::write($response, 200, new FilterResponse($resp, $count));
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * UpdateLedger updates an existing ledger's name.
     * It binds the incoming JSON request to an UpdateLedger object, validates it,
     * and then updates the ledger record. If any errors occur during validation
     * or update, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding JSON, validating the ledger, or if the ID is missing.
     * - 200 OK: If the ledger is successfully updated.
     */
    public function updateLedger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. Pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $updateLedger = UpdateLedger::fromArray(Binding::shouldBindJSON($request, 'model.UpdateLedger'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null, Errors::withLegacyKey('errors'));
        }

        try {
            $updateLedger->validateUpdateLedger();
        } catch (ValidationErrors $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, $err->getMessage(), null, Errors::withLegacyKey('errors'));
        }

        try {
            $resp = $this->blnk->updateLedger($id, $updateLedger->name);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrLgrNotFound));
        }

        return Json::write($response, 200, $resp);
    }
}
