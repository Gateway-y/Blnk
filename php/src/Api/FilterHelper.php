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

use Blnk\Internal\Filter\LogicalOperator;
use Blnk\Internal\Filter\ParseError;
use Blnk\Internal\Filter\ParseOptions;
use Blnk\Internal\Filter\Parser;
use Blnk\Internal\Filter\QueryFilterSet;
use Blnk\Internal\Filter\QueryOptions;
use Blnk\Internal\Filter\Validation;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/filter_helper.go: the package-level helpers that turn a request
 * (query string or JSON body) into the filter package's types.
 */
final class FilterHelper
{
    private function __construct()
    {
    }

    /**
     * ParseFiltersFromContext parses query parameters into a QueryFilterSet using the filter package.
     * It handles all filter parameters in the format: field_operator=value
     *
     * Supported operators:
     *   - eq: Equal (e.g., status_eq=APPLIED)
     *   - ne: Not equal (e.g., status_ne=VOID)
     *   - gt: Greater than (e.g., amount_gt=1000)
     *   - gte: Greater than or equal (e.g., amount_gte=1000)
     *   - lt: Less than (e.g., amount_lt=5000)
     *   - lte: Less than or equal (e.g., amount_lte=5000)
     *   - in: In set (e.g., currency_in=USD,EUR,GBP)
     *   - between: Between range (e.g., created_at_between=2024-01-01|2024-12-31)
     *   - like: Pattern match (e.g., name_like=%USD%)
     *   - ilike: Case-insensitive pattern match (e.g., name_ilike=%usd%)
     *   - isnull: Is null (e.g., identity_id_isnull=true)
     *   - isnotnull: Is not null (e.g., identity_id_isnotnull=true)
     *   - logical_operator: Top-level combinator for multiple filters (e.g., logical_operator=or)
     *
     * Parameters:
     * - $request: The request (Go: the gin context)
     * - $opts: Optional parse options to limit filters, values, etc.
     *
     * Returns `[$filters, $errors]`:
     * - QueryFilterSet|null: The parsed filters, or null if no filters found
     * - ParseError[]: Any errors encountered during parsing
     *
     * @return array{0: QueryFilterSet|null, 1: ParseError[]}
     */
    public static function parseFiltersFromContext(ServerRequestInterface $request, ?ParseOptions $opts): array
    {
        $result = Parser::parseFromQuery(Query::values($request), $opts);

        return [$result->filters, $result->errors];
    }

    /**
     * HasFilters checks if the request contains any filter parameters.
     * It returns true if filters were provided, false otherwise.
     */
    public static function hasFilters(ServerRequestInterface $request): bool
    {
        $result = Parser::parseFromQuery(Query::values($request), null);

        return ($result->filters !== null && count($result->filters->filters) > 0) || count($result->errors) > 0;
    }

    /**
     * ParseFiltersFromBody parses filters from a JSON request body.
     * The table parameter is used to validate sort fields against the allowed fields for that table.
     * Returns the QueryFilterSet, QueryOptions, limit, offset (Go: plus an error, thrown here).
     *
     * @return array{0: QueryFilterSet, 1: QueryOptions, 2: int, 3: int}
     * @throws BindingException on a JSON binding failure (also thrown as a plain
     *                          \RuntimeException by the field readers for type mismatches)
     * @throws \RuntimeException "invalid logical_operator: must be 'and' or 'or'"
     */
    public static function parseFiltersFromBody(ServerRequestInterface $request, string $table): array
    {
        $req = FilterRequest::fromArray(Binding::shouldBindJSON($request, 'api.FilterRequest'));

        // Apply defaults
        $limit = $req->limit;
        if ($limit <= 0 || $limit > 100) {
            $limit = 20;
        }

        $offset = $req->offset;
        if ($offset < 0) {
            $offset = 0;
        }

        // Sanitize sort order: normalize to lowercase, only allow "asc" or "desc"
        $sortOrder = strtolower(trim($req->sortOrder));
        if ($sortOrder !== 'asc' && $sortOrder !== 'desc') {
            $sortOrder = 'desc';
        }

        // Sanitize sort field: normalize to lowercase, validate against table's allowed fields
        $sortBy = strtolower(trim($req->sortBy));
        if ($sortBy !== '') {
            $allowedFields = Validation::getValidFieldsForTable($table);
            if (!($allowedFields[$sortBy] ?? false)) {
                // Invalid field: coerce to empty string so default sort is used
                $sortBy = '';
            }
        }

        $filterSet = new QueryFilterSet($req->filters ?? [], LogicalOperator::LogicalAnd);

        if (trim($req->logicalOperator) !== '') {
            $logicalOperator = LogicalOperator::resolveLogicalOperator($req->logicalOperator);
            if ($logicalOperator === '') {
                throw new \RuntimeException("invalid logical_operator: must be 'and' or 'or'");
            }
            $filterSet->logicalOperator = $logicalOperator;
        }

        $opts = new QueryOptions($sortBy, $sortOrder, $req->includeCount);

        return [$filterSet, $opts, $limit, $offset];
    }

    /**
     * ParseQueryOptions extracts sorting options from query parameters.
     * The table parameter is used to validate sort fields against the allowed fields for that table.
     * Used for GET endpoints with query param filters.
     */
    public static function parseQueryOptions(ServerRequestInterface $request, string $table): QueryOptions
    {
        // Sanitize sort order: normalize to lowercase, only allow "asc" or "desc"
        $sortOrder = strtolower(trim(Query::defaultQuery($request, 'sort_order', 'desc')));
        if ($sortOrder !== 'asc' && $sortOrder !== 'desc') {
            $sortOrder = 'desc';
        }

        // Sanitize sort field: normalize to lowercase, validate against table's allowed fields
        $sortBy = strtolower(trim(Query::defaultQuery($request, 'sort_by', '')));
        if ($sortBy !== '') {
            $allowedFields = Validation::getValidFieldsForTable($table);
            if (!($allowedFields[$sortBy] ?? false)) {
                // Invalid field: coerce to empty string so default sort is used
                $sortBy = '';
            }
        }

        return new QueryOptions(
            $sortBy,
            $sortOrder,
            Query::defaultQuery($request, 'include_count', '') === 'true'
        );
    }
}
