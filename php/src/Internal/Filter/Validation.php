<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * Port of internal/filter/validation.go: allowed-fields validation for the
 * SQL builder.
 */
final class Validation
{
    private const JSON_KEY_REGEX = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

    /**
     * validate checks a filter set against the allowed fields of a table.
     *
     * Go returns an error; the PHP port throws FilterValidationException with
     * the same message text.
     */
    public static function validate(?QueryFilterSet $filters, string $table): void
    {
        if ($filters === null) {
            return;
        }

        if (!LogicalOperator::isValidLogicalOperator($filters->logicalOperator)) {
            throw new FilterValidationException(sprintf(
                "invalid logical_operator '%s': must be 'and' or 'or'",
                $filters->logicalOperator
            ));
        }

        $validFields = self::getValidFieldsForTable($table);
        if (count($validFields) === 0) {
            throw new FilterValidationException(sprintf('unsupported table for advanced filtering: %s', $table));
        }

        foreach ($filters->filters as $f) {
            if (!Operator::isValidOperator($f->operator)) {
                throw new FilterValidationException(sprintf(
                    "invalid operator '%s' for field '%s': must be one of eq, ne, gt, gte, lt, lte, in, between, like, ilike, isnull, isnotnull",
                    $f->operator,
                    $f->field
                ));
            }

            // Enforce operator arity here so malformed filters are rejected with
            // an error instead of being silently dropped by the SQL builders,
            // which would run the query unfiltered.
            switch ($f->operator) {
                case Operator::OpIn:
                    if ($f->values === null || count($f->values) === 0) {
                        throw new FilterValidationException(sprintf(
                            "operator 'in' for field '%s' requires at least one value in 'values'",
                            $f->field
                        ));
                    }
                    break;
                case Operator::OpBetween:
                    if ($f->values === null || count($f->values) !== 2) {
                        throw new FilterValidationException(sprintf(
                            "operator 'between' for field '%s' requires exactly two values in 'values', got %d",
                            $f->field,
                            $f->values === null ? 0 : count($f->values)
                        ));
                    }
                    break;
            }

            // balance_id on transactions is a virtual field matched against
            // source/destination; only a subset of operators is implemented.
            if ($table === 'transactions' && $f->field === 'balance_id') {
                switch ($f->operator) {
                    case Operator::OpEqual:
                    case Operator::OpNotEqual:
                    case Operator::OpIn:
                    case Operator::OpIsNull:
                    case Operator::OpIsNotNull:
                        break;
                    default:
                        throw new FilterValidationException(sprintf(
                            "operator '%s' is not supported for field 'balance_id' on transactions: must be one of eq, ne, in, isnull, isnotnull",
                            $f->operator
                        ));
                }
            }

            if (str_starts_with($f->field, 'meta_data.') && ($validFields['meta_data'] ?? false)) {
                $jsonKey = substr($f->field, strlen('meta_data.'));
                if (preg_match(self::JSON_KEY_REGEX, $jsonKey) !== 1) {
                    throw new FilterValidationException(sprintf(
                        "invalid JSON key '%s' in field '%s': must match pattern ^[a-zA-Z][a-zA-Z0-9_]*$",
                        $jsonKey,
                        $f->field
                    ));
                }
                continue;
            }

            if (!($validFields[$f->field] ?? false)) {
                throw new FilterValidationException(sprintf(
                    "invalid field '%s' for table '%s'",
                    $f->field,
                    $table
                ));
            }
        }
    }

    /**
     * @return array<string, bool>
     */
    public static function getValidFieldsForTable(string $table): array
    {
        switch ($table) {
            case 'transactions':
                return [
                    'transaction_id' => true,
                    'parent_transaction' => true,
                    'amount' => true,
                    'currency' => true,
                    'source' => true,
                    'destination' => true,
                    'balance_id' => true,
                    'reference' => true,
                    'status' => true,
                    'created_at' => true,
                    'effective_date' => true,
                    'indicator' => true,
                    'description' => true,
                    'precision' => true,
                    'meta_data' => true,
                ];
            case 'balances':
                return [
                    'balance_id' => true,
                    'ledger_id' => true,
                    'identity_id' => true,
                    'indicator' => true,
                    'currency' => true,
                    'balance' => true,
                    'credit_balance' => true,
                    'debit_balance' => true,
                    'inflight_balance' => true,
                    'inflight_credit_balance' => true,
                    'inflight_debit_balance' => true,
                    'created_at' => true,
                    'meta_data' => true,
                ];
            case 'ledgers':
                return [
                    'ledger_id' => true,
                    'name' => true,
                    'created_at' => true,
                    'meta_data' => true,
                ];
            case 'identity':
                return [
                    'identity_id' => true,
                    'first_name' => true,
                    'last_name' => true,
                    'other_names' => true,
                    'gender' => true,
                    'dob' => true,
                    'email_address' => true,
                    'phone_number' => true,
                    'nationality' => true,
                    'street' => true,
                    'country' => true,
                    'state' => true,
                    'organization_name' => true,
                    'category' => true,
                    'identity_type' => true,
                    'post_code' => true,
                    'city' => true,
                    'created_at' => true,
                    'meta_data' => true,
                ];
            case 'reconciliations':
                return [
                    'reconciliation_id' => true,
                    'upload_id' => true,
                    'status' => true,
                    'matched_transactions' => true,
                    'unmatched_transactions' => true,
                    'started_at' => true,
                    'completed_at' => true,
                ];
            case 'matching_rules':
                return [
                    'rule_id' => true,
                    'name' => true,
                    'description' => true,
                    'created_at' => true,
                    'updated_at' => true,
                ];
            case 'external_transactions':
                return [
                    'id' => true,
                    'amount' => true,
                    'reference' => true,
                    'currency' => true,
                    'description' => true,
                    'date' => true,
                    'source' => true,
                    'upload_id' => true,
                ];
            case 'accounts':
                return [
                    'account_id' => true,
                    'name' => true,
                    'number' => true,
                    'bank_name' => true,
                    'currency' => true,
                    'ledger_id' => true,
                    'identity_id' => true,
                    'balance_id' => true,
                    'created_at' => true,
                    'meta_data' => true,
                ];
            default:
                return [];
        }
    }

    /**
     * getSortableFieldsForTable returns fields that can be sorted.
     * All filterable fields are sortable. For optimal performance on large datasets,
     * consider adding indexes for frequently sorted fields. See docs/performance-tuning.mdx.
     *
     * @return array<string, bool>
     */
    public static function getSortableFieldsForTable(string $table): array
    {
        return self::getValidFieldsForTable($table);
    }

    /**
     * validateSortField validates that the sort field is allowed for the table.
     */
    public static function validateSortField(string $sortBy, string $table): void
    {
        if ($sortBy === '') {
            return;
        }

        $sortableFields = self::getSortableFieldsForTable($table);
        if (count($sortableFields) === 0) {
            throw new FilterValidationException(sprintf('sorting not supported for table: %s', $table));
        }

        if (!($sortableFields[$sortBy] ?? false)) {
            throw new FilterValidationException(sprintf(
                "cannot sort by '%s' for table '%s': field is not filterable",
                $sortBy,
                $table
            ));
        }
    }

    /**
     * getDefaultSortField returns the default sort field for a table.
     */
    public static function getDefaultSortField(string $table): string
    {
        return 'created_at';
    }

    /**
     * validateSortByForTable validates opts->sortBy against the table's allowed sort fields.
     * Normalizes sortBy (lowercase, trim) and throws if invalid.
     * Call this at the database boundary before SqlBuilder::buildWithOptions for defense-in-depth.
     */
    public static function validateSortByForTable(?QueryOptions $opts, string $table): void
    {
        if ($opts === null || $opts->sortBy === '') {
            return;
        }
        $sortBy = strtolower(trim($opts->sortBy));
        $allowed = self::getValidFieldsForTable($table);
        if (count($allowed) === 0 || !($allowed[$sortBy] ?? false)) {
            throw new FilterValidationException('invalid sort_by field');
        }
        $opts->sortBy = $sortBy;
    }
}
