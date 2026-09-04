<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * Port of internal/filter/sql_builder.go: turns a QueryFilterSet into SQL
 * condition fragments, bind arguments, CTEs and an ORDER BY clause.
 *
 * Placeholders (documented divergence): the Go builder emits PostgreSQL `$n`
 * placeholders; per PORTING.md ("$1,$2 placeholders become PDO positional `?`
 * in the same order") this port emits `?`. Where Go reuses one numbered
 * placeholder for several occurrences, the PHP fragment repeats `?` and the
 * argument is duplicated — see BuildResult. The generated SQL is otherwise
 * byte-identical to the Go output.
 */
final class SqlBuilder
{
    /**
     * build compiles the filter set for a table into a BuildResult.
     *
     * Go signature: Build(filters, table, alias, startArgPos) (*BuildResult, error).
     *
     * @throws FilterValidationException when validation fails.
     */
    public static function build(?QueryFilterSet $filters, string $table, string $alias, int $startArgPos): BuildResult
    {
        if ($filters === null || count($filters->filters) === 0) {
            $result = new BuildResult();
            $result->ctes = null;
            $result->conditions = [];
            $result->args = [];
            $result->nextArgPos = $startArgPos;

            return $result;
        }

        Validation::validate($filters, $table);

        $result = new BuildResult();
        $result->ctes = null;
        $result->conditions = [];
        $result->args = [];
        $result->nextArgPos = $startArgPos;

        $argPos = $startArgPos;

        foreach ($filters->filters as $f) {
            if (str_contains($f->field, '.') && str_starts_with($f->field, 'meta_data')) {
                [$cond, $args, $nextArgPos] = SqlJson::buildJSONPathCondition($f, $alias, $argPos);
                if ($cond !== '') {
                    $result->conditions[] = $cond;
                    $result->conditionArgs = array_merge($result->conditionArgs, $args);
                    $argPos = $nextArgPos;
                }
                continue;
            }

            if ($f->field === 'balance_id' && $table === 'transactions') {
                [$cond, $args, $nextArgPos, $ctes] = SqlSpecial::buildBalanceIdCondition($f, $alias, $argPos);
                if ($cond !== '') {
                    $result->conditions[] = $cond;
                    $result->conditionArgs = array_merge($result->conditionArgs, $args);
                    $argPos = $nextArgPos;
                    if (count($ctes) > 0) {
                        $result->ctes = array_merge($result->ctes ?? [], $ctes);
                    }
                }
                continue;
            }

            if ($f->field === 'indicator' && $table === 'transactions') {
                [$cond, $args, $nextArgPos, $ctes] = SqlSpecial::buildIndicatorCondition($f, $alias, $argPos);
                if ($cond !== '') {
                    $result->conditions[] = $cond;
                    // The indicator placeholders live inside the CTE fragment,
                    // which precedes the WHERE clause in the assembled query;
                    // with positional binding those args must come first.
                    $result->cteArgs = array_merge($result->cteArgs, $args);
                    $argPos = $nextArgPos;
                    if (count($ctes) > 0) {
                        $result->ctes = array_merge($result->ctes ?? [], $ctes);
                    }
                }
                continue;
            }

            [$cond, $args, $nextArgPos] = self::buildStandardCondition($f, $table, $alias, $argPos);
            if ($cond !== '') {
                $result->conditions[] = $cond;
                $result->conditionArgs = array_merge($result->conditionArgs, $args);
                $argPos = $nextArgPos;
            }
        }

        $result->args = array_merge($result->cteArgs, $result->conditionArgs);
        $result->nextArgPos = $argPos;

        return $result;
    }

    /**
     * buildConditionExpression joins individual condition fragments with the requested logical operator.
     * Defaults to AND when no operator is specified.
     *
     * @param string[] $conditions
     */
    public static function buildConditionExpression(array $conditions, string $logicalOperator): string
    {
        if (count($conditions) === 0) {
            return '';
        }

        if ($logicalOperator === LogicalOperator::LogicalOr) {
            $wrapped = [];
            foreach ($conditions as $cond) {
                $wrapped[] = sprintf('(%s)', $cond);
            }

            return implode(' OR ', $wrapped);
        }

        return implode(' AND ', $conditions);
    }

    /**
     * @return array{0: string, 1: array<int, mixed>, 2: int} [condition, args, newArgPosition]
     */
    private static function buildStandardCondition(QueryFilter $f, string $table, string $tableAlias, int $argPosition): array
    {
        // Resolve field to safe column name (breaks taint chain for static analyzers)
        $safeField = self::safeColumnForTableAndField($table, $f->field);
        if ($safeField === '') {
            return ['', [], $argPosition];
        }

        $fieldName = $safeField;
        if ($tableAlias !== '') {
            $fieldName = sprintf('%s.%s', $tableAlias, $safeField);
        }

        switch ($f->operator) {
            case Operator::OpEqual:
                $tsVal = null;
                if ($f->value instanceof TimestampValue) {
                    $tsVal = $f->value;
                } elseif ($f->value instanceof \DateTimeInterface) {
                    $timeVal = \DateTimeImmutable::createFromInterface($f->value);
                    $tsVal = new TimestampValue($timeVal, '', DateTimeHelpers::getDatePrecisionFromTime($timeVal));
                }
                if ($tsVal !== null) {
                    [$floor, $ceiling] = DateTimeHelpers::computeTimestampRange($tsVal);

                    return [
                        sprintf('%s >= ? AND %s < ?', $fieldName, $fieldName),
                        [Helpers::extractValueForSQL($floor), Helpers::extractValueForSQL($ceiling)],
                        $argPosition + 2,
                    ];
                }

                return [
                    sprintf('%s = ?', $fieldName),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpNotEqual:
                return [
                    sprintf('%s != ?', $fieldName),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpGreaterThan:
                return [
                    sprintf('%s > ?', $fieldName),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpGreaterThanOrEqual:
                return [
                    sprintf('%s >= ?', $fieldName),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpLessThan:
                return [
                    sprintf('%s < ?', $fieldName),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpLessThanOrEqual:
                return [
                    sprintf('%s <= ?', $fieldName),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpLike:
                return [
                    sprintf('%s LIKE ?', $fieldName),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpILike:
                return [
                    sprintf('%s ILIKE ?', $fieldName),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpIn:
                if ($f->values !== null && count($f->values) > 0) {
                    if (Helpers::isStringArray($f->values)) {
                        return [
                            sprintf('%s = ANY(?)', $fieldName),
                            [Helpers::toPgTextArray(Helpers::convertToStringArray($f->values))],
                            $argPosition + 1,
                        ];
                    }
                    $placeholders = [];
                    $args = [];
                    foreach ($f->values as $val) {
                        $placeholders[] = '?';
                        $args[] = Helpers::extractValueForSQL($val);
                    }

                    return [
                        sprintf('%s IN (%s)', $fieldName, implode(', ', $placeholders)),
                        $args,
                        $argPosition + count($f->values),
                    ];
                }

                return ['', [], $argPosition];

            case Operator::OpBetween:
                if ($f->values !== null && count($f->values) === 2) {
                    return [
                        sprintf('%s BETWEEN ? AND ?', $fieldName),
                        [Helpers::extractValueForSQL($f->values[0]), Helpers::extractValueForSQL($f->values[1])],
                        $argPosition + 2,
                    ];
                }

                return ['', [], $argPosition];

            case Operator::OpIsNull:
                return [sprintf('%s IS NULL', $fieldName), [], $argPosition];

            case Operator::OpIsNotNull:
                return [sprintf('%s IS NOT NULL', $fieldName), [], $argPosition];

            default:
                return ['', [], $argPosition];
        }
    }

    /**
     * buildWithOptions builds filter conditions and includes sorting options.
     * It validates both filters and sort options, throwing if either is invalid.
     *
     * @throws FilterValidationException
     */
    public static function buildWithOptions(?QueryFilterSet $filters, string $table, string $alias, int $startArgPos, ?QueryOptions $opts): BuildResult
    {
        // First build the filter conditions
        $result = self::build($filters, $table, $alias, $startArgPos);

        $order = SortOrder::SortDesc;
        $sortBy = '';
        if ($opts !== null) {
            $order = $opts->defaultSortOrder();
            $sortBy = $opts->sortBy;
            if ($sortBy !== '') {
                Validation::validateSortField($sortBy, $table);
            }
        }
        $result->orderBy = self::buildOrderBy($sortBy, $order, $table, $alias);

        return $result;
    }

    /**
     * resolveSortField maps a requested sort field to a safe column name.
     * It returns only string literals from a switch; user input selects which constant to return.
     * This breaks the taint chain for static analyzers.
     */
    public static function resolveSortField(string $table, string $sortBy): string
    {
        $normalized = strtolower(trim($sortBy));
        if ($normalized === '') {
            return Validation::getDefaultSortField($table);
        }
        $allowed = Validation::getValidFieldsForTable($table);
        if (count($allowed) === 0 || !($allowed[$normalized] ?? false)) {
            return Validation::getDefaultSortField($table);
        }

        return self::safeColumnForSort($table, $normalized);
    }

    /**
     * safeColumnForTableAndField maps a field name to a safe column name using only string literals.
     * Returns empty string for unknown fields to break the taint chain for static analyzers.
     *
     * @internal Also used by the JSON/special builders' shared allowlist.
     */
    public static function safeColumnForTableAndField(string $table, string $logicalName): string
    {
        switch ($table) {
            case 'transactions':
                switch ($logicalName) {
                    case 'transaction_id':
                        return 'transaction_id';
                    case 'parent_transaction':
                        return 'parent_transaction';
                    case 'amount':
                        return 'amount';
                    case 'currency':
                        return 'currency';
                    case 'source':
                        return 'source';
                    case 'destination':
                        return 'destination';
                    case 'balance_id':
                        return 'balance_id';
                    case 'reference':
                        return 'reference';
                    case 'status':
                        return 'status';
                    case 'created_at':
                        return 'created_at';
                    case 'effective_date':
                        return 'effective_date';
                    case 'indicator':
                        return 'indicator';
                    case 'description':
                        return 'description';
                    case 'precision':
                        return 'precision';
                    case 'meta_data':
                        return 'meta_data';
                }
                break;
            case 'balances':
                switch ($logicalName) {
                    case 'balance_id':
                        return 'balance_id';
                    case 'ledger_id':
                        return 'ledger_id';
                    case 'identity_id':
                        return 'identity_id';
                    case 'indicator':
                        return 'indicator';
                    case 'currency':
                        return 'currency';
                    case 'balance':
                        return 'balance';
                    case 'credit_balance':
                        return 'credit_balance';
                    case 'debit_balance':
                        return 'debit_balance';
                    case 'inflight_balance':
                        return 'inflight_balance';
                    case 'inflight_credit_balance':
                        return 'inflight_credit_balance';
                    case 'inflight_debit_balance':
                        return 'inflight_debit_balance';
                    case 'created_at':
                        return 'created_at';
                    case 'meta_data':
                        return 'meta_data';
                }
                break;
            case 'ledgers':
                switch ($logicalName) {
                    case 'ledger_id':
                        return 'ledger_id';
                    case 'name':
                        return 'name';
                    case 'created_at':
                        return 'created_at';
                    case 'meta_data':
                        return 'meta_data';
                }
                break;
            case 'identity':
                switch ($logicalName) {
                    case 'identity_id':
                        return 'identity_id';
                    case 'first_name':
                        return 'first_name';
                    case 'last_name':
                        return 'last_name';
                    case 'other_names':
                        return 'other_names';
                    case 'gender':
                        return 'gender';
                    case 'dob':
                        return 'dob';
                    case 'email_address':
                        return 'email_address';
                    case 'phone_number':
                        return 'phone_number';
                    case 'nationality':
                        return 'nationality';
                    case 'street':
                        return 'street';
                    case 'country':
                        return 'country';
                    case 'state':
                        return 'state';
                    case 'organization_name':
                        return 'organization_name';
                    case 'category':
                        return 'category';
                    case 'identity_type':
                        return 'identity_type';
                    case 'post_code':
                        return 'post_code';
                    case 'city':
                        return 'city';
                    case 'created_at':
                        return 'created_at';
                    case 'meta_data':
                        return 'meta_data';
                }
                break;
            case 'accounts':
                switch ($logicalName) {
                    case 'account_id':
                        return 'account_id';
                    case 'name':
                        return 'name';
                    case 'number':
                        return 'number';
                    case 'bank_name':
                        return 'bank_name';
                    case 'currency':
                        return 'currency';
                    case 'ledger_id':
                        return 'ledger_id';
                    case 'identity_id':
                        return 'identity_id';
                    case 'balance_id':
                        return 'balance_id';
                    case 'created_at':
                        return 'created_at';
                    case 'meta_data':
                        return 'meta_data';
                }
                break;
            case 'reconciliations':
                switch ($logicalName) {
                    case 'reconciliation_id':
                        return 'reconciliation_id';
                    case 'upload_id':
                        return 'upload_id';
                    case 'status':
                        return 'status';
                    case 'matched_transactions':
                        return 'matched_transactions';
                    case 'unmatched_transactions':
                        return 'unmatched_transactions';
                    case 'started_at':
                        return 'started_at';
                    case 'completed_at':
                        return 'completed_at';
                }
                break;
            case 'matching_rules':
                switch ($logicalName) {
                    case 'rule_id':
                        return 'rule_id';
                    case 'name':
                        return 'name';
                    case 'description':
                        return 'description';
                    case 'created_at':
                        return 'created_at';
                    case 'updated_at':
                        return 'updated_at';
                }
                break;
            case 'external_transactions':
                switch ($logicalName) {
                    case 'id':
                        return 'id';
                    case 'amount':
                        return 'amount';
                    case 'reference':
                        return 'reference';
                    case 'currency':
                        return 'currency';
                    case 'description':
                        return 'description';
                    case 'date':
                        return 'date';
                    case 'source':
                        return 'source';
                    case 'upload_id':
                        return 'upload_id';
                }
                break;
        }

        return '';
    }

    /**
     * safeColumnForSort returns a safe column for sorting, with fallback to default.
     */
    private static function safeColumnForSort(string $table, string $logicalName): string
    {
        $col = self::safeColumnForTableAndField($table, $logicalName);
        if ($col !== '') {
            return $col;
        }

        return Validation::getDefaultSortField($table);
    }

    /**
     * buildOrderBy constructs an ORDER BY clause using only safe, constant column names.
     */
    public static function buildOrderBy(string $sortBy, string $sortOrder, string $table, string $alias): string
    {
        $safeField = self::resolveSortField($table, $sortBy);
        $fieldName = $safeField;
        if ($alias !== '') {
            $fieldName = sprintf('%s.%s', $alias, $safeField);
        }

        $direction = 'DESC';
        if ($sortOrder === SortOrder::SortAsc) {
            $direction = 'ASC';
        }

        return sprintf('%s %s', $fieldName, $direction);
    }
}
