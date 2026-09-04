<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * Port of internal/filter/sql_special.go: special-cased virtual fields on the
 * transactions table (`balance_id` matches source/destination, `indicator`
 * matches via a balances CTE).
 *
 * Placeholder divergence (see BuildResult): Go reuses one `$n` placeholder for
 * both the source and destination occurrence; the PHP `?` fragments repeat the
 * placeholder and duplicate the argument, advancing the arg position by the
 * number of arguments actually appended.
 */
final class SqlSpecial
{
    /**
     * @return array{0: string, 1: array<int, mixed>, 2: int, 3: string[]} [condition, args, newArgPosition, ctes]
     *
     * @internal Used by SqlBuilder::build().
     */
    public static function buildBalanceIdCondition(QueryFilter $f, string $tableAlias, int $argPosition): array
    {
        $srcField = 'source';
        $dstField = 'destination';
        if ($tableAlias !== '') {
            $srcField = $tableAlias . '.source';
            $dstField = $tableAlias . '.destination';
        }

        switch ($f->operator) {
            case Operator::OpEqual:
                // Go: (source = $n OR destination = $n) with one arg; here the value is bound twice.
                $v = Helpers::extractValueForSQL($f->value);

                return [
                    sprintf('(%s = ? OR %s = ?)', $srcField, $dstField),
                    [$v, $v],
                    $argPosition + 2,
                    [],
                ];

            case Operator::OpNotEqual:
                $v = Helpers::extractValueForSQL($f->value);

                return [
                    sprintf('(%s != ? AND %s != ?)', $srcField, $dstField),
                    [$v, $v],
                    $argPosition + 2,
                    [],
                ];

            case Operator::OpIn:
                if ($f->values !== null && count($f->values) > 0) {
                    if (Helpers::isStringArray($f->values)) {
                        $arr = Helpers::toPgTextArray(Helpers::convertToStringArray($f->values));

                        return [
                            sprintf('(%s = ANY(?) OR %s = ANY(?))', $srcField, $dstField),
                            [$arr, $arr],
                            $argPosition + 2,
                            [],
                        ];
                    }

                    $placeholders = [];
                    $args = [];
                    foreach ($f->values as $val) {
                        $placeholders[] = '?';
                        $args[] = Helpers::extractValueForSQL($val);
                    }
                    $ph = implode(', ', $placeholders);

                    return [
                        sprintf('(%s IN (%s) OR %s IN (%s))', $srcField, $ph, $dstField, $ph),
                        array_merge($args, $args),
                        $argPosition + 2 * count($f->values),
                        [],
                    ];
                }

                return ['', [], $argPosition, []];

            case Operator::OpIsNull:
                return [
                    sprintf('(%s IS NULL AND %s IS NULL)', $srcField, $dstField),
                    [],
                    $argPosition,
                    [],
                ];

            case Operator::OpIsNotNull:
                return [
                    sprintf('(%s IS NOT NULL OR %s IS NOT NULL)', $srcField, $dstField),
                    [],
                    $argPosition,
                    [],
                ];

            default:
                return ['', [], $argPosition, []];
        }
    }

    /**
     * @return array{0: string, 1: array<int, mixed>, 2: int, 3: string[]} [condition, args, newArgPosition, ctes]
     *
     * @internal Used by SqlBuilder::build().
     */
    public static function buildIndicatorCondition(QueryFilter $f, string $tableAlias, int $argPosition): array
    {
        $subqueryCondition = '';
        $subqueryArgs = [];
        $newArgPosition = $argPosition;

        switch ($f->operator) {
            case Operator::OpEqual:
                $subqueryCondition = 'b.indicator = ?';
                $subqueryArgs = [Helpers::extractValueForSQL($f->value)];
                $newArgPosition = $argPosition + 1;
                break;

            case Operator::OpNotEqual:
                $subqueryCondition = 'b.indicator != ?';
                $subqueryArgs = [Helpers::extractValueForSQL($f->value)];
                $newArgPosition = $argPosition + 1;
                break;

            case Operator::OpGreaterThan:
                $subqueryCondition = 'b.indicator > ?';
                $subqueryArgs = [Helpers::extractValueForSQL($f->value)];
                $newArgPosition = $argPosition + 1;
                break;

            case Operator::OpGreaterThanOrEqual:
                $subqueryCondition = 'b.indicator >= ?';
                $subqueryArgs = [Helpers::extractValueForSQL($f->value)];
                $newArgPosition = $argPosition + 1;
                break;

            case Operator::OpLessThan:
                $subqueryCondition = 'b.indicator < ?';
                $subqueryArgs = [Helpers::extractValueForSQL($f->value)];
                $newArgPosition = $argPosition + 1;
                break;

            case Operator::OpLessThanOrEqual:
                $subqueryCondition = 'b.indicator <= ?';
                $subqueryArgs = [Helpers::extractValueForSQL($f->value)];
                $newArgPosition = $argPosition + 1;
                break;

            case Operator::OpLike:
                $subqueryCondition = 'b.indicator LIKE ?';
                $subqueryArgs = [Helpers::extractValueForSQL($f->value)];
                $newArgPosition = $argPosition + 1;
                break;

            case Operator::OpILike:
                $subqueryCondition = 'b.indicator ILIKE ?';
                $subqueryArgs = [Helpers::extractValueForSQL($f->value)];
                $newArgPosition = $argPosition + 1;
                break;

            case Operator::OpIn:
                if ($f->values !== null && count($f->values) > 0) {
                    if (Helpers::isStringArray($f->values)) {
                        $subqueryCondition = 'b.indicator = ANY(?)';
                        $subqueryArgs = [Helpers::toPgTextArray(Helpers::convertToStringArray($f->values))];
                        $newArgPosition = $argPosition + 1;
                    } else {
                        $placeholders = [];
                        $subqueryArgs = [];
                        foreach ($f->values as $val) {
                            $placeholders[] = '?';
                            $subqueryArgs[] = Helpers::extractValueForSQL($val);
                        }
                        $subqueryCondition = sprintf('b.indicator IN (%s)', implode(', ', $placeholders));
                        $newArgPosition = $argPosition + count($f->values);
                    }
                } else {
                    return ['', [], $argPosition, []];
                }
                break;

            case Operator::OpBetween:
                if ($f->values !== null && count($f->values) === 2) {
                    $subqueryCondition = 'b.indicator BETWEEN ? AND ?';
                    $subqueryArgs = [
                        Helpers::extractValueForSQL($f->values[0]),
                        Helpers::extractValueForSQL($f->values[1]),
                    ];
                    $newArgPosition = $argPosition + 2;
                } else {
                    return ['', [], $argPosition, []];
                }
                break;

            case Operator::OpIsNull:
                $subqueryCondition = 'b.indicator IS NULL';
                $subqueryArgs = [];
                $newArgPosition = $argPosition;
                break;

            case Operator::OpIsNotNull:
                $subqueryCondition = 'b.indicator IS NOT NULL';
                $subqueryArgs = [];
                $newArgPosition = $argPosition;
                break;

            default:
                return ['', [], $argPosition, []];
        }

        $cte = sprintf('_indicator_matches AS (SELECT b.balance_id FROM blnk.balances b WHERE %s)', $subqueryCondition);
        $ctes = [$cte];

        $srcField = 'source';
        $dstField = 'destination';
        if ($tableAlias !== '') {
            $srcField = $tableAlias . '.source';
            $dstField = $tableAlias . '.destination';
        }
        $condition = sprintf(
            '(%s IN (SELECT balance_id FROM _indicator_matches) OR %s IN (SELECT balance_id FROM _indicator_matches))',
            $srcField,
            $dstField
        );

        return [$condition, $subqueryArgs, $newArgPosition, $ctes];
    }
}
