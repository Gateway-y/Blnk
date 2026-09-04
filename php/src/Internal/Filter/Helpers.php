<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * Port of internal/filter/helpers.go: small shared helpers used by the SQL
 * builders.
 */
final class Helpers
{
    /**
     * @param array<int, mixed> $values
     */
    public static function isStringArray(array $values): bool
    {
        foreach ($values as $v) {
            if (!is_string($v)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return string[]
     */
    public static function convertToStringArray(array $values): array
    {
        $result = [];
        foreach ($values as $v) {
            $result[] = self::stringify($v);
        }

        return $result;
    }

    /**
     * extractValueForSQL unwraps a filter value into a form bindable as a SQL
     * argument.
     *
     * Go returns the inner time.Time for TimestampValue and passes it to lib/pq,
     * which serializes it with fractional seconds and offset. PDO cannot bind
     * DateTime objects, so the PHP port serializes timestamps to the equivalent
     * "Y-m-d H:i:s.u P"-style string here, and booleans to the 'true'/'false'
     * literals lib/pq would send (PDO's native bool-to-string conversion is
     * lossy for false).
     */
    public static function extractValueForSQL(mixed $value): mixed
    {
        if ($value instanceof TimestampValue) {
            return self::formatTimestampArg($value->time);
        }
        if ($value instanceof \DateTimeInterface) {
            return self::formatTimestampArg($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }

    /**
     * buildContainmentJSON builds the `{"key": value}` JSON document used with
     * the jsonb containment operator (@>).
     *
     * @throws \JsonException when the value cannot be encoded (mirrors the Go
     *                        json.Marshal error, which the caller swallows).
     */
    public static function buildContainmentJSON(string $key, mixed $value): string
    {
        return json_encode([$key => self::jsonable($value)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * toPgTextArray encodes a list of strings as a PostgreSQL text[] literal
     * (e.g. {"a","b"}), replicating github.com/lib/pq's pq.Array encoding so
     * `field = ANY(?)` receives the same wire value the Go code sends.
     *
     * @param string[] $values
     */
    public static function toPgTextArray(array $values): string
    {
        $parts = [];
        foreach ($values as $v) {
            $parts[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Timestamp bind format: microsecond precision plus UTC offset, understood
     * by PostgreSQL for both timestamp and timestamptz columns (see PORTING.md
     * "DB timestamps").
     */
    private static function formatTimestampArg(\DateTimeInterface $t): string
    {
        return $t->format('Y-m-d H:i:s.uP');
    }

    private static function jsonable(mixed $value): mixed
    {
        if ($value instanceof TimestampValue) {
            return self::formatTimestampArg($value->time);
        }
        if ($value instanceof \DateTimeInterface) {
            return self::formatTimestampArg($value);
        }

        return $value;
    }

    /**
     * fmt.Sprintf("%v", v) equivalent for the value kinds parseValue produces.
     */
    private static function stringify(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v instanceof TimestampValue) {
            return $v->original !== '' ? $v->original : self::formatTimestampArg($v->time);
        }
        if ($v instanceof \DateTimeInterface) {
            return self::formatTimestampArg($v);
        }
        if (is_scalar($v)) {
            return (string) $v;
        }

        return json_encode($v) ?: '';
    }
}
