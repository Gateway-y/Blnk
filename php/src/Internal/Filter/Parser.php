<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * Port of internal/filter/parser.go: the query-string filter parser.
 */
final class Parser
{
    public const defaultMaxFilters = 20;
    public const defaultMaxInValues = 100;
    public const defaultMaxCharLen = 1000;

    /**
     * parseFromQuery parses URL query parameters into a ParseResult.
     * Returns errors for invalid params rather than silently dropping them.
     *
     * $queryParams mirrors Go's url.Values: an associative array of parameter
     * name to value. Values may be strings or arrays of strings (e.g. from
     * PSR-7's getQueryParams() / parse_str()); only the first value of each
     * parameter is used, matching Go's `values[0]`.
     *
     * @param array<string, mixed> $queryParams
     */
    public static function parseFromQuery(array $queryParams, ?ParseOptions $opts = null): ParseResult
    {
        $maxFilters = self::defaultMaxFilters;
        $maxInValues = self::defaultMaxInValues;
        $maxCharLen = self::defaultMaxCharLen;
        if ($opts !== null) {
            if ($opts->maxFilters > 0) {
                $maxFilters = $opts->maxFilters;
            }
            if ($opts->maxInValues > 0) {
                $maxInValues = $opts->maxInValues;
            }
            if ($opts->maxCharLen > 0) {
                $maxCharLen = $opts->maxCharLen;
            }
        }

        $result = new ParseResult();
        $result->filters = new QueryFilterSet([], LogicalOperator::LogicalAnd);
        $result->errors = [];

        // Parse top-level logical operator for combining filters.
        $logicalOperatorRaw = trim(self::firstValue($queryParams['logical_operator'] ?? ''));
        if ($logicalOperatorRaw !== '') {
            $logicalOperator = LogicalOperator::resolveLogicalOperator($logicalOperatorRaw);
            if ($logicalOperator === '') {
                $result->errors[] = new ParseError(
                    'logical_operator',
                    "invalid logical_operator: must be 'and' or 'or'"
                );
            } else {
                $result->filters->logicalOperator = $logicalOperator;
            }
        }

        $filterCount = 0;
        foreach ($queryParams as $key => $values) {
            $key = (string) $key;
            if (is_array($values) && count($values) === 0) {
                continue;
            }

            if (Reserved::isReservedParam($key)) {
                continue;
            }

            // Parse field_operator format
            $parts = explode('_', $key);
            if (count($parts) < 2) {
                continue;
            }

            // Extract operator (last part) and field (everything before)
            $operatorStr = $parts[count($parts) - 1];
            $field = implode('_', array_slice($parts, 0, count($parts) - 1));

            $operator = Operator::resolveOperator($operatorStr);
            if ($operator === '') {
                // Not a recognized operator suffix — skip silently
                // (could be a regular query param like "some_param=value")
                continue;
            }

            // Enforce max filters
            if ($filterCount >= $maxFilters) {
                $result->errors[] = new ParseError(
                    $key,
                    sprintf('exceeded maximum number of filters (%d)', $maxFilters)
                );
                continue;
            }

            $value = self::firstValue($values);

            // Enforce max char length
            if (strlen($value) > $maxCharLen) {
                $result->errors[] = new ParseError(
                    $key,
                    sprintf('value exceeds maximum length (%d chars)', $maxCharLen)
                );
                continue;
            }

            $f = new QueryFilter($field, $operator);

            switch ($operator) {
                case Operator::OpBetween:
                    $betweenValues = explode('|', $value);
                    if (count($betweenValues) !== 2) {
                        $result->errors[] = new ParseError(
                            $key,
                            'between operator requires exactly 2 pipe-separated values (value1|value2)'
                        );
                        continue 2;
                    }
                    $f->values = [
                        Values::parseValue($betweenValues[0]),
                        Values::parseValue($betweenValues[1]),
                    ];
                    break;

                case Operator::OpIn:
                    $inValues = explode(',', $value);
                    if (count($inValues) > $maxInValues) {
                        $result->errors[] = new ParseError(
                            $key,
                            sprintf('IN operator exceeds maximum values (%d)', $maxInValues)
                        );
                        continue 2;
                    }
                    $f->values = [];
                    foreach ($inValues as $v) {
                        $f->values[] = Values::parseValue(trim($v));
                    }
                    break;

                case Operator::OpIsNull:
                case Operator::OpIsNotNull:
                    // No value needed for null checks
                    break;

                default:
                    $f->value = Values::parseValue($value);
                    break;
            }

            $result->filters->filters[] = $f;
            $filterCount++;
        }

        return $result;
    }

    /**
     * Mirrors url.Values semantics: a parameter may carry several values; the
     * parser only consumes the first one.
     */
    private static function firstValue(mixed $values): string
    {
        if (is_array($values)) {
            $first = reset($values);

            return is_scalar($first) ? (string) $first : '';
        }

        return is_scalar($values) ? (string) $values : '';
    }
}
