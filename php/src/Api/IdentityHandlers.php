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

use Blnk\Api\Model\DetokenizeRequest;
use Blnk\Api\Model\TokenizeRequest;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Model\Identity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/identity.go: the identity handlers of the Go `Api` struct,
 * composed into {@see Api} (which holds the service as `$this->blnk`).
 *
 * `gin.H` maps marshal with sorted keys in Go; the literal payloads below
 * are written in that order.
 */
trait IdentityHandlers
{
    /**
     * CreateIdentity creates a new identity record in the system.
     * It binds the incoming JSON request to an Identity object, validates it,
     * and then creates the identity record. If any errors occur during validation
     * or creation, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding JSON or creating the identity.
     * - 201 Created: If the identity is successfully created.
     */
    public function createIdentity(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $identity = Identity::fromArray(Binding::shouldBindJSON($request, 'model.Identity'));
        } catch (\Throwable $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        try {
            $resp = $this->blnk->createIdentity($identity);
        } catch (\Throwable $err) {
            return Errors::respondError(
                $response,
                $err,
                Errors::withUpgrade(ErrorCode::ErrGenConflict, ErrorCode::ErrIdtValidation),
                Errors::withUpgrade(ErrorCode::ErrGenBadRequest, ErrorCode::ErrIdtValidation)
            );
        }

        return Json::write($response, 201, $resp);
    }

    /**
     * GetIdentity retrieves an identity record by its ID.
     * It extracts the ID from the route parameters and fetches the identity record.
     * If the ID is missing or there's an error retrieving the identity, it responds
     * with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If the ID is missing or there's an error retrieving the identity.
     * - 200 OK: If the identity is successfully retrieved.
     */
    public function getIdentity(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $resp = $this->blnk->getIdentity($id);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrIdtNotFound));
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * UpdateIdentity updates an existing identity record by its ID.
     * It binds the incoming JSON request to an Identity object, updates the record,
     * and responds with a success message. If any errors occur during binding,
     * validation, or update, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding JSON, updating the identity, or missing ID.
     * - 200 OK: If the identity is successfully updated.
     */
    public function updateIdentity(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $identity = Identity::fromArray(Binding::shouldBindJSON($request, 'model.Identity'));
        } catch (\Throwable $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        $identity->identityID = $id;
        try {
            $this->blnk->updateIdentity($identity);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrIdtNotFound));
        }

        return Json::write($response, 200, ['message' => 'Identity updated successfully']);
    }

    /**
     * DeleteIdentity deletes an existing identity record by its ID.
     * It extracts the ID from the route parameters and deletes the record. If the ID is missing
     * or there's an error deleting the identity, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If the ID is missing or there's an error deleting the identity.
     * - 200 OK: If the identity is successfully deleted.
     */
    public function deleteIdentity(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'id is required. pass id in the route /:id', null);
        }
        $id = Query::param($args, 'id');

        try {
            $this->blnk->deleteIdentity($id);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrIdtNotFound));
        }

        return Json::write($response, 200, ['message' => 'Identity deleted successfully']);
    }

    /**
     * GetAllIdentities retrieves all identity records in the system.
     * It fetches the identity records and responds with the list of identities.
     * Supports advanced filtering via query parameters in the format: field_operator=value
     * Example filters:
     *   - first_name_eq=John
     *   - category_in=individual,corporate
     *   - created_at_gte=2024-01-01
     *
     * If there's an error retrieving the identities, it responds with an appropriate error message.
     *
     * Responses:
     * - 400 Bad Request: If there's an error retrieving the identities or invalid filters.
     * - 200 OK: If the identities are successfully retrieved.
     */
    public function getAllIdentities(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
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
            if (\count($parseErrors) > 0) {
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
                $resp = $this->blnk->getAllIdentitiesWithFilter($filters, $limitInt, $offsetInt);
            } catch (\Throwable $err) {
                return Errors::respondError($response, $err);
            }

            return Json::write($response, 200, $resp);
        }

        // Fall back to the legacy method when no filters are present
        try {
            $identities = $this->blnk->getAllIdentities();
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 200, $identities);
    }

    /**
     * FilterIdentities filters identities using a JSON request body.
     * This endpoint accepts a POST request with filters specified in JSON format.
     *
     * Request body format:
     *
     *	{
     *	  "filters": [
     *	    {"field": "first_name", "operator": "eq", "value": "John"},
     *	    {"field": "category", "operator": "in", "values": ["individual", "corporate"]}
     *	  ],
     *	  "limit": 20,
     *	  "offset": 0
     *	}
     *
     * Responses:
     * - 400 Bad Request: If there's an error parsing the filters or retrieving identities.
     * - 200 OK: If the identities are successfully retrieved.
     */
    public function filterIdentities(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            [$filters, $opts, $limit, $offset] = FilterHelper::parseFiltersFromBody($request, 'identity');
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, $err->getMessage(), null);
        }

        try {
            [$resp, $count] = $this->blnk->getAllIdentitiesWithFilterAndOptions($filters, $opts, $limit, $offset);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        if ($opts->includeCount) {
            return Json::write($response, 200, new FilterResponse($resp, $count));
        }

        return Json::write($response, 200, $resp);
    }

    /**
     * TokenizeIdentityField tokenizes a specific field in an identity.
     * It extracts the identity ID and field name from the route parameters,
     * tokenizes the field, and responds with a success message.
     *
     * Responses:
     * - 400 Bad Request: If the ID or field is missing, or there's an error tokenizing the field.
     * - 200 OK: If the field is successfully tokenized.
     */
    public function tokenizeIdentityField(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'identity ID is required', null);
        }

        if (!isset($args['field'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'field name is required', null);
        }
        $id = Query::param($args, 'id');
        $field = Query::param($args, 'field');

        try {
            $this->blnk->tokenizeIdentityField($id, $field);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrIdtNotFound));
        }

        return Json::write($response, 200, ['message' => 'Field tokenized successfully']);
    }

    /**
     * DetokenizeIdentityField detokenizes a specific field in an identity.
     * It extracts the identity ID and field name from the route parameters,
     * detokenizes the field, and responds with the original value.
     *
     * Responses:
     * - 400 Bad Request: If the ID or field is missing, or there's an error detokenizing the field.
     * - 200 OK: If the field is successfully detokenized, returning the original value.
     */
    public function detokenizeIdentityField(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'identity ID is required', null);
        }

        if (!isset($args['field'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'field name is required', null);
        }
        $id = Query::param($args, 'id');
        $field = Query::param($args, 'field');

        try {
            $originalValue = $this->blnk->detokenizeIdentityField($id, $field);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrIdtNotFound));
        }

        return Json::write($response, 200, ['field' => $field, 'value' => $originalValue]);
    }

    /**
     * TokenizeIdentity tokenizes multiple fields in an identity.
     * It binds the incoming JSON request containing the list of fields to tokenize,
     * tokenizes each field, and responds with a success message.
     *
     * Responses:
     * - 400 Bad Request: If the ID is missing, there's an error binding JSON, or there's an error tokenizing fields.
     * - 200 OK: If the fields are successfully tokenized.
     */
    public function tokenizeIdentity(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'identity ID is required', null);
        }
        $id = Query::param($args, 'id');

        try {
            $tokenizeRequest = TokenizeRequest::fromArray(Binding::shouldBindJSON($request, 'model.TokenizeRequest'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        if (\count($tokenizeRequest->fields) === 0) {
            return Errors::respondCode($response, ErrorCode::ErrGenValidation, 'at least one field must be specified', null);
        }

        try {
            $this->blnk->tokenizeIdentity($id, $tokenizeRequest->fields);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrIdtNotFound));
        }

        return Json::write($response, 200, ['message' => 'Fields tokenized successfully']);
    }

    /**
     * DetokenizeIdentity detokenizes multiple fields in an identity.
     * It binds the incoming JSON request containing the list of fields to detokenize,
     * detokenizes each field, and responds with the original values.
     *
     * Responses:
     * - 400 Bad Request: If the ID is missing, there's an error binding JSON, or there's an error detokenizing fields.
     * - 200 OK: If the fields are successfully detokenized, returning the original values.
     */
    public function detokenizeIdentity(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'identity ID is required', null);
        }
        $id = Query::param($args, 'id');

        try {
            $detokenizeRequest = DetokenizeRequest::fromArray(Binding::shouldBindJSON($request, 'model.DetokenizeRequest'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        // If no specific fields are provided, detokenize all tokenized fields
        if (\count($detokenizeRequest->fields) === 0) {
            try {
                $detokenizedFields = $this->blnk->detokenizeIdentity($id);
            } catch (\Throwable $err) {
                return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrIdtNotFound));
            }

            // A Go map[string]string marshals as a JSON object even when empty.
            return Json::write($response, 200, ['fields' => (object) $detokenizedFields]);
        }

        // Detokenize specific fields
        $result = [];
        foreach ($detokenizeRequest->fields as $field) {
            try {
                $value = $this->blnk->detokenizeIdentityField($id, $field);
            } catch (\Throwable $err) {
                return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrIdtNotFound));
            }
            $result[$field] = $value;
        }

        return Json::write($response, 200, ['fields' => (object) $result]);
    }

    /**
     * identityGoTypeName renders a decoded metadata value the way Go's
     * `fmt.Sprintf("%T", v)` names the dynamic type of a JSON-decoded
     * interface{} value.
     */
    private static function identityGoTypeName(mixed $value): string
    {
        if ($value === null) {
            return '<nil>';
        }
        if (\is_bool($value)) {
            return 'bool';
        }
        if (\is_int($value) || \is_float($value)) {
            return 'float64';
        }
        if (\is_string($value)) {
            return 'string';
        }
        if (\is_array($value)) {
            return array_is_list($value) ? '[]interface {}' : 'map[string]interface {}';
        }

        return \get_debug_type($value);
    }

    /**
     * GetTokenizedFields returns a list of fields that are currently tokenized for an identity.
     *
     * Responses:
     * - 400 Bad Request: If the ID is missing or there's an error retrieving the identity.
     * - 200 OK: Returns the list of tokenized fields.
     */
    public function getTokenizedFields(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'identity ID is required', null);
        }
        $id = Query::param($args, 'id');

        try {
            $identity = $this->blnk->getIdentity($id);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrIdtNotFound));
        }

        $tokenizedFields = [];

        // Debug the metadata structure
        if ($identity->metaData !== null) {
            if (\array_key_exists('tokenized_fields', $identity->metaData)) {
                $tokenizedFieldsRaw = $identity->metaData['tokenized_fields'];
                // Go tries map[string]bool first, then map[string]interface{}
                // with boolean values; after JSON decoding both are PHP
                // associative arrays (an empty array is taken as an empty map).
                if (\is_array($tokenizedFieldsRaw) && (\count($tokenizedFieldsRaw) === 0 || !array_is_list($tokenizedFieldsRaw))) {
                    foreach ($tokenizedFieldsRaw as $field => $val) {
                        if (\is_bool($val) && $val) {
                            $tokenizedFields[] = (string) $field;
                        }
                    }
                } else {
                    return Json::write($response, 200, [
                        'debug_info' => [
                            'has_metadata' => true,
                            'raw_metadata' => $identity->metaData,
                            'tokenized_field_type' => self::identityGoTypeName($tokenizedFieldsRaw),
                        ],
                        'tokenized_fields' => $tokenizedFields,
                    ]);
                }
            }
        }

        return Json::write($response, 200, ['tokenized_fields' => $tokenizedFields]);
    }
}
