<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * LogicalOperator represents how multiple filters are combined.
 *
 * Port of the Go `LogicalOperator` string type and its constants
 * (internal/filter/types.go) plus the logical-operator helpers from
 * internal/filter/operators.go.
 */
final class LogicalOperator
{
    public const LogicalAnd = 'and';
    public const LogicalOr = 'or';

    /**
     * Resolves a raw logical operator string. An empty (or whitespace-only)
     * string resolves to "and"; unknown values resolve to ''.
     */
    public static function resolveLogicalOperator(string $s): string
    {
        switch (strtolower(trim($s))) {
            case '':
            case 'and':
                return self::LogicalAnd;
            case 'or':
                return self::LogicalOr;
            default:
                return '';
        }
    }

    public static function isValidLogicalOperator(string $op): bool
    {
        return $op === '' || $op === self::LogicalAnd || $op === self::LogicalOr;
    }
}
