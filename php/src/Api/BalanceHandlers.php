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

use Blnk\Api\Model\CreateBalance;
use Blnk\Api\Model\CreateBalanceMonitor;
use Blnk\Api\Model\UpdateBalanceIdentity;
use Blnk\Api\Model\ValidationErrors;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Model\BalanceMonitor;
use Blnk\Model\ModelHelpers;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/balance.go: the balance and balance-monitor handlers of the Go
 * `Api` struct, composed into {@see Api}.
 */
trait BalanceHandlers
{
    /**
     * CreateBalance creates a new balance record in the system.
     * It binds the incoming JSON request to a CreateBalance object, validates it,
     * and then creates the balance record. If any errors occur during validation
     * or creation, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding JSON or validating the balance.
     * - 201 Created: If the balance is successfully created.
     */
    public function createBalance(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $newBalance = CreateBalance::fromArray(Binding::shouldBindJSON($request, 'model.CreateBalance'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        try {
            $newBalance->validateCreateBalance();
        } catch (ValidationErrors $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, $err->getMessage(), null, Errors::withLegacyKey('errors'));
        }

        try {
            $resp = $this->blnk->createBalance($newBalance->toBalance());
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenValidation, ErrorCode::ErrBalValidation));
        }

        return Json::write($response, 201, $resp);
    }

    /**
     * GetBalance retrieves a balance record by its ID.
     * It extracts the ID from the route parameters and the 'include' query
     * parameter to fetch additional related information. If the ID is missing
     * or there's an error retrieving the balance, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If the ID is missing or there's an error retrieving the balance.
     * - 200 OK: If the balance is successfully retrieved.
     */
    public function getBalance(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        // Extract 'include' parameter from the query
        $includes = Query::queryArray($request, 'include');

        // Extract 'with_queued' parameter from the query, default to false
        $withQueued = Query::defaultQuery($request, 'with_queued', 'false') === 'true';

        try {
            $resp = $this->blnk->getBalanceByID($id, $includes, $withQueued);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrBalNotFound));
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * GetBalances retrieves a list of balance records with pagination.
     * It extracts the 'limit' and 'offset' query parameters to control pagination,
     * and the 'include' query parameter to fetch additional related information.
     * Supports advanced filtering via query parameters in the format: field_operator=value
     * Example filters:
     *   - currency_eq=USD
     *   - ledger_id_in=ldg_123,ldg_456
     *   - created_at_gte=2024-01-01
     *
     * Responses:
     * - 400 Bad Request: If there's an error retrieving the balances or invalid query parameters.
     * - 200 OK: If the balances are successfully retrieved.
     */
    public function getBalances(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Extract pagination parameters (limit and offset)
        $limit = Query::atoi(Query::defaultQuery($request, 'limit', '10')); // Default to 10 if not specified
        if ($limit === null || $limit <= 0) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, 'invalid limit value', null);
        }

        $offset = Query::atoi(Query::defaultQuery($request, 'offset', '0')); // Default to 0 if not specified
        if ($offset === null || $offset < 0) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, 'invalid offset value', null);
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
                $resp = $this->blnk->getAllBalancesWithFilter($filters, $limit, $offset);
            } catch (\Throwable $err) {
                return Errors::respondError($response, $err);
            }

            return Json::write($response, 200, $resp);
        }

        // Fetch balances with pagination
        try {
            $resp = $this->blnk->getAllBalances($limit, $offset);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * FilterBalances filters balances using a JSON request body.
     * This endpoint accepts a POST request with filters specified in JSON format.
     *
     * Request body format:
     *
     *	{
     *	  "filters": [
     *	    {"field": "currency", "operator": "eq", "value": "USD"},
     *	    {"field": "ledger_id", "operator": "in", "values": ["ldg_123", "ldg_456"]}
     *	  ],
     *	  "limit": 20,
     *	  "offset": 0
     *	}
     *
     * Responses:
     * - 400 Bad Request: If there's an error parsing the filters or retrieving balances.
     * - 200 OK: If the balances are successfully retrieved.
     */
    public function filterBalances(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            [$filters, $opts, $limit, $offset] = FilterHelper::parseFiltersFromBody($request, 'balances');
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, $err->getMessage(), null);
        }

        try {
            [$resp, $count] = $this->blnk->getAllBalancesWithFilterAndOptions($filters, $opts, $limit, $offset);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        if ($opts->includeCount) {
            return Json::write($response, 200, new FilterResponse($resp, $count));
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * CreateBalanceMonitor creates a new balance monitor record in the system.
     * It binds the incoming JSON request to a CreateBalanceMonitor object, validates it,
     * and then creates the monitor record. If any errors occur during validation
     * or creation, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding JSON or validating the balance monitor.
     * - 201 Created: If the balance monitor is successfully created.
     */
    public function createBalanceMonitor(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $newMonitor = CreateBalanceMonitor::fromArray(Binding::shouldBindJSON($request, 'model.CreateBalanceMonitor'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        try {
            $newMonitor->validateCreateBalanceMonitor();
        } catch (ValidationErrors $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, $err->getMessage(), null, Errors::withLegacyKey('errors'));
        }

        try {
            $resp = $this->blnk->createMonitor($newMonitor->toBalanceMonitor());
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 201, $resp);
    }

    /**
     * GetBalanceMonitor retrieves a balance monitor record by its ID.
     * It extracts the ID from the route parameters. If the ID is missing
     * or there's an error retrieving the monitor, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If the ID is missing or there's an error retrieving the balance monitor.
     * - 200 OK: If the balance monitor is successfully retrieved.
     */
    public function getBalanceMonitor(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $resp = $this->blnk->getMonitorByID($id);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrBalMonitorNotFound));
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * GetAllBalanceMonitors retrieves all balance monitor records in the system.
     * It fetches the monitor records and responds with the list of monitors.
     * If there's an error retrieving the monitors, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error retrieving the balance monitors.
     * - 200 OK: If the balance monitors are successfully retrieved.
     */
    public function getAllBalanceMonitors(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $monitors = $this->blnk->getAllMonitors();
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 200, $monitors);
    }

    /**
     * GetBalanceMonitorsByBalanceID retrieves all balance monitors associated with a specific balance ID.
     * It extracts the balance ID from the route parameters. If the balance ID is missing
     * or there's an error retrieving the monitors, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If the balance ID is missing or there's an error retrieving the balance monitors.
     * - 200 OK: If the balance monitors are successfully retrieved.
     */
    public function getBalanceMonitorsByBalanceID(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['balance_id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'balance_id is required. pass balance_id in the route /:balance_id', null);
        }
        $balanceID = Query::param($args, 'balance_id');

        try {
            $monitors = $this->blnk->getBalanceMonitors($balanceID);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 200, $monitors);
    }

    /**
     * UpdateBalanceMonitor updates an existing balance monitor record by its ID.
     * It binds the incoming JSON request to a BalanceMonitor object, updates the record,
     * and responds with a success message. If any errors occur during binding, validation,
     * or update, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding JSON, validating the balance monitor, or updating the record.
     * - 200 OK: If the balance monitor is successfully updated.
     */
    public function updateBalanceMonitor(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $monitor = BalanceMonitor::fromArray(Binding::shouldBindJSON($request, 'model.BalanceMonitor'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        $monitor->monitorID = $id;
        try {
            $this->blnk->updateMonitor($monitor);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrBalMonitorNotFound));
        }

        return Json::write($response, 200, ['message' => 'BalanceMonitor updated successfully']);
    }

    /**
     * DeleteBalanceMonitor deletes an existing balance monitor record by its ID.
     * It extracts the ID from the route parameters and deletes the record. If the ID is missing
     * or there's an error deleting the monitor, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If the ID is missing or there's an error deleting the balance monitor.
     * - 200 OK: If the balance monitor is successfully deleted.
     */
    public function deleteBalanceMonitor(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $this->blnk->deleteMonitor($id);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrBalMonitorNotFound));
        }

        return Json::write($response, 200, ['message' => 'BalanceMonitor deleted successfully']);
    }

    /**
     * TakeBalanceSnapshots creates daily snapshots of balances in batches.
     * It accepts an optional 'batch_size' query parameter to control the batch processing size.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in the batch size parameter or during snapshot creation.
     * - 200 OK: If the snapshots are successfully created, returns the total number of snapshots created.
     */
    public function takeBalanceSnapshots(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Get batch size from query parameter, default to 1000 if not specified
        $batchSize = Query::atoi(Query::defaultQuery($request, 'batch_size', '1000'));
        if ($batchSize === null || $batchSize <= 0) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, 'invalid batch_size value', null);
        }

        // Call the service to take snapshots (Go runs the pass in a goroutine;
        // here it runs after the response has been flushed, see Deferred)
        $blnk = $this->blnk;
        Deferred::defer(static function () use ($blnk, $batchSize): void {
            $blnk->takeBalanceSnapshots($batchSize);
        });

        return Json::write($response, 200, [
            'message' => 'Snapshotting in progress. should be completed shortly',
        ]);
    }

    /**
     * GetBalanceAtTime retrieves a balance's state at a specific point in time.
     * It extracts the balance ID from the route parameters and the timestamp from query parameters.
     * The timestamp should be provided in ISO 8601 format (e.g., "2024-01-01T15:04:05Z").
     * Optionally accepts a "from_source" query parameter to calculate balance from all transactions.
     *
     * Responses:
     * - 400 Bad Request: If the balance ID is missing, timestamp is invalid, or there's an error retrieving the balance.
     * - 200 OK: If the historical balance state is successfully retrieved.
     */
    public function getBalanceAtTime(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'balance ID is required', null);
        }
        $balanceID = Query::param($args, 'id');

        $timestampStr = Query::query($request, 'timestamp');
        if ($timestampStr === '') {
            // Use current time if no timestamp is provided
            $timestamp = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } else {
            $timestamp = Binding::parseRFC3339($timestampStr);
            if ($timestamp === null) {
                return Errors::respondCode($response, ErrorCode::ErrBalInvalidTimestamp, 'invalid timestamp format. Please use ISO 8601 format (e.g., 2024-01-01T15:04:05Z)', null);
            }
        }

        // Check if the request specifies to calculate from source transactions
        $fromSourceStr = Query::query($request, 'from_source');
        $fromSource = $fromSourceStr === 'true' || $fromSourceStr === '1';

        try {
            $balance = $this->blnk->getBalanceAtTime($balanceID, $timestamp, $fromSource);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrBalNotFound));
        }

        $balanceResult = [
            'balance' => ModelHelpers::bigIntegerToJson($balance->balance),
            'debit_balance' => ModelHelpers::bigIntegerToJson($balance->debitBalance),
            'credit_balance' => ModelHelpers::bigIntegerToJson($balance->creditBalance),
            'currency' => $balance->currency,
            'balance_id' => $balance->balanceID,
        ];

        return Json::write($response, 200, [
            'balance' => $balanceResult,
            'timestamp' => Binding::formatRFC3339($timestamp),
            'from_source' => $fromSource,
        ]);
    }

    /**
     * GetBalanceByIndicator retrieves a balance by its indicator and currency.
     * It extracts the indicator and currency from the route parameters.
     * If either parameter is missing or there's an error retrieving the balance,
     * it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If indicator or currency is missing or there's an error retrieving the balance.
     * - 200 OK: If the balance is successfully retrieved.
     */
    public function getBalanceByIndicator(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['indicator'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'indicator is required. pass indicator in the route /indicator/:indicator', null);
        }
        $indicator = Query::param($args, 'indicator');

        if (!isset($args['currency'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'currency is required. pass currency in the route /currency/:currency', null);
        }
        $currency = Query::param($args, 'currency');

        try {
            $resp = $this->blnk->getBalanceByIndicator($indicator, $currency);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrBalNotFound));
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * UpdateBalanceIdentity updates only the identity_id field of a balance.
     * Expected JSON payload: {"identity_id": "id_123"}
     */
    public function updateBalanceIdentity(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'balance id is required. pass id in the route /:id', null);
        }
        $balanceID = Query::param($args, 'id');

        try {
            $req = UpdateBalanceIdentity::fromArray(Binding::shouldBindJSON($request, 'model.UpdateBalanceIdentity'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        if ($req->identityId === '') {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, 'identity_id is required', null);
        }

        try {
            $this->blnk->updateBalanceIdentity($balanceID, $req->identityId);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrBalNotFound));
        }

        return Json::write($response, 200, ['message' => 'Balance identity updated successfully']);
    }

    public function getBalanceLineage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $lineage = $this->blnk->getBalanceLineage($id);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrBalNotFound));
        }

        return Json::write($response, 200, $lineage);
    }
}
