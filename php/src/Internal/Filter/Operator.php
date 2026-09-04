<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * Operator represents supported filter operators.
 *
 * Port of the Go `Operator` string type and its constants (internal/filter/types.go)
 * together with the operator helpers from internal/filter/operators.go.
 */
final class Operator
{
    public const OpEqual = 'eq';
    public const OpNotEqual = 'ne';
    public const OpGreaterThan = 'gt';
    public const OpGreaterThanOrEqual = 'gte';
    public const OpLessThan = 'lt';
    public const OpLessThanOrEqual = 'lte';
    public const OpIn = 'in';
    public const OpBetween = 'between';
    public const OpLike = 'like';
    public const OpILike = 'ilike';
    public const OpIsNull = 'isnull';
    public const OpIsNotNull = 'isnotnull';

    /**
     * Resolves a raw operator string (case-insensitive, including aliases such as
     * "neq"/"gteq"/"lteq") to a canonical operator constant. Returns '' when the
     * string is not a recognized operator.
     */
    public static function resolveOperator(string $s): string
    {
        switch (strtolower($s)) {
            case 'eq':
                return self::OpEqual;
            case 'ne':
            case 'neq':
                return self::OpNotEqual;
            case 'gt':
                return self::OpGreaterThan;
            case 'gte':
            case 'gteq':
                return self::OpGreaterThanOrEqual;
            case 'lt':
                return self::OpLessThan;
            case 'lte':
            case 'lteq':
                return self::OpLessThanOrEqual;
            case 'in':
                return self::OpIn;
            case 'between':
                return self::OpBetween;
            case 'like':
                return self::OpLike;
            case 'ilike':
                return self::OpILike;
            case 'isnull':
                return self::OpIsNull;
            case 'isnotnull':
                return self::OpIsNotNull;
            default:
                return '';
        }
    }

    /**
     * isValidOperator reports whether op is one of the supported filter operators.
     */
    public static function isValidOperator(string $op): bool
    {
        switch ($op) {
            case self::OpEqual:
            case self::OpNotEqual:
            case self::OpGreaterThan:
            case self::OpGreaterThanOrEqual:
            case self::OpLessThan:
            case self::OpLessThanOrEqual:
            case self::OpIn:
            case self::OpBetween:
            case self::OpLike:
            case self::OpILike:
            case self::OpIsNull:
            case self::OpIsNotNull:
                return true;
        }

        return false;
    }
}
