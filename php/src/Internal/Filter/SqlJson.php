<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * Port of internal/filter/sql_json.go: JSON path conditions on the meta_data
 * jsonb column.
 *
 * PDO note (documented divergence): the jsonb key-exists operator `?` collides
 * with PDO's positional placeholder, so the isnull/isnotnull fragments emit the
 * PDO escape `??`, which PDO::prepare() converts back to a single literal `?`
 * before the SQL reaches PostgreSQL. The SQL executed by the server is
 * identical to the Go output.
 */
final class SqlJson
{
    /**
     * @return array{0: string, 1: array<int, mixed>, 2: int} [condition, args, newArgPosition]
     *
     * @internal Used by SqlBuilder::build().
     */
    public static function buildJSONPathCondition(QueryFilter $f, string $tableAlias, int $argPosition): array
    {
        $parts = explode('.', $f->field, 2);
        if (count($parts) !== 2) {
            return ['', [], $argPosition];
        }

        // Resolve jsonCol to safe column name (breaks taint chain)
        // Currently only "meta_data" is supported for JSON path queries
        $jsonCol = self::resolveJSONColumn($parts[0]);
        if ($jsonCol === '') {
            return ['', [], $argPosition];
        }

        // Sanitize jsonKey - already validated by regex in Validation::validate(),
        // but we need to break the taint chain for static analyzers by copying
        // through validation
        $jsonKey = self::sanitizeJSONKey($parts[1]);
        if ($jsonKey === '') {
            return ['', [], $argPosition];
        }

        if ($tableAlias !== '') {
            $jsonCol = sprintf('%s.%s', $tableAlias, $jsonCol);
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
                        sprintf(
                            "(%s->>'%s')::timestamp >= ? AND (%s->>'%s')::timestamp < ?",
                            $jsonCol,
                            $jsonKey,
                            $jsonCol,
                            $jsonKey
                        ),
                        [Helpers::extractValueForSQL($floor), Helpers::extractValueForSQL($ceiling)],
                        $argPosition + 2,
                    ];
                }

                try {
                    $jsonBytes = Helpers::buildContainmentJSON($jsonKey, $f->value);
                } catch (\JsonException) {
                    return ['', [], $argPosition];
                }

                return [
                    sprintf('%s @> ?::jsonb', $jsonCol),
                    [$jsonBytes],
                    $argPosition + 1,
                ];

            case Operator::OpNotEqual:
                if (is_bool($f->value)) {
                    return [
                        sprintf("%s->>'%s' != ?", $jsonCol, $jsonKey),
                        [$f->value ? 'true' : 'false'],
                        $argPosition + 1,
                    ];
                }

                return [
                    sprintf("%s->>'%s' != ?", $jsonCol, $jsonKey),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpGreaterThan:
                return [
                    sprintf("(%s->>'%s')::numeric > ?", $jsonCol, $jsonKey),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpGreaterThanOrEqual:
                return [
                    sprintf("(%s->>'%s')::numeric >= ?", $jsonCol, $jsonKey),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpLessThan:
                return [
                    sprintf("(%s->>'%s')::numeric < ?", $jsonCol, $jsonKey),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpLessThanOrEqual:
                return [
                    sprintf("(%s->>'%s')::numeric <= ?", $jsonCol, $jsonKey),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpLike:
                return [
                    sprintf("%s->>'%s' LIKE ?", $jsonCol, $jsonKey),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpILike:
                return [
                    sprintf("%s->>'%s' ILIKE ?", $jsonCol, $jsonKey),
                    [Helpers::extractValueForSQL($f->value)],
                    $argPosition + 1,
                ];

            case Operator::OpIn:
                if ($f->values !== null && count($f->values) > 0) {
                    $placeholders = [];
                    $args = [];
                    foreach ($f->values as $val) {
                        $placeholders[] = '?';
                        if (is_bool($val)) {
                            $args[] = $val ? 'true' : 'false';
                        } else {
                            $args[] = Helpers::extractValueForSQL($val);
                        }
                    }

                    return [
                        sprintf("%s->>'%s' IN (%s)", $jsonCol, $jsonKey, implode(', ', $placeholders)),
                        $args,
                        $argPosition + count($f->values),
                    ];
                }

                return ['', [], $argPosition];

            case Operator::OpBetween:
                if ($f->values !== null && count($f->values) === 2) {
                    return [
                        sprintf("(%s->>'%s')::numeric BETWEEN ? AND ?", $jsonCol, $jsonKey),
                        [Helpers::extractValueForSQL($f->values[0]), Helpers::extractValueForSQL($f->values[1])],
                        $argPosition + 2,
                    ];
                }

                return ['', [], $argPosition];

            case Operator::OpIsNull:
                // `??` is PDO's escape for the literal jsonb `?` operator.
                return [
                    sprintf("(%s->>'%s' IS NULL OR %s ?? '%s' = false)", $jsonCol, $jsonKey, $jsonCol, $jsonKey),
                    [],
                    $argPosition,
                ];

            case Operator::OpIsNotNull:
                return [
                    sprintf("(%s->>'%s' IS NOT NULL AND %s ?? '%s' = true)", $jsonCol, $jsonKey, $jsonCol, $jsonKey),
                    [],
                    $argPosition,
                ];

            default:
                return ['', [], $argPosition];
        }
    }

    /**
     * resolveJSONColumn maps a JSON column name to a safe column using only string literals.
     * This breaks the taint chain for static analyzers.
     */
    private static function resolveJSONColumn(string $col): string
    {
        switch ($col) {
            case 'meta_data':
                return 'meta_data';
            default:
                return '';
        }
    }

    /**
     * sanitizeJSONKey validates and returns a safe JSON key.
     * The key is validated against a regex pattern in Validation::validate(), but we
     * need to explicitly break the taint chain by rebuilding the string character
     * by character.
     */
    private static function sanitizeJSONKey(string $key): string
    {
        if ($key === '') {
            return '';
        }

        // Validate: must start with letter, contain only alphanumeric and underscore
        if (!self::isLetter($key[0])) {
            return '';
        }

        // Build a new string with only safe characters (breaks taint chain)
        $result = '';
        $len = strlen($key);
        for ($i = 0; $i < $len; $i++) {
            $c = $key[$i];
            if (self::isLetter($c) || self::isDigit($c) || $c === '_') {
                $result .= $c;
            } else {
                return '';
            }
        }

        return $result;
    }

    private static function isLetter(string $c): bool
    {
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z');
    }

    private static function isDigit(string $c): bool
    {
        return $c >= '0' && $c <= '9';
    }
}
