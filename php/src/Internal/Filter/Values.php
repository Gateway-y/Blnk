<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * Port of internal/filter/values.go: raw query-string value coercion.
 */
final class Values
{
    /**
     * parseValue coerces a raw string value in the same order as Go:
     * int64 -> float64 -> bool ("true"/"false") -> timestamp -> string.
     *
     * Divergence (documented): Go's strconv.ParseFloat also accepts "Inf",
     * "NaN" and hexadecimal float literals; PHP's is_numeric does not, so such
     * strings fall through to the plain-string case.
     *
     * @internal Used by Parser::parseFromQuery().
     */
    public static function parseValue(string $value): mixed
    {
        // strconv.ParseInt(value, 10, 64): optional sign, decimal digits, must
        // fit in an int64 (PHP int). filter_var alone would accept surrounding
        // whitespace, which Go rejects, hence the regex guard.
        if (preg_match('/^[+-]?[0-9]+$/', $value) === 1) {
            $intVal = filter_var($value, FILTER_VALIDATE_INT);
            if ($intVal !== false) {
                return $intVal;
            }
        }

        // strconv.ParseFloat(value, 64) — reject surrounding whitespace, which
        // is_numeric tolerates but Go does not.
        if (trim($value) === $value && is_numeric($value)) {
            return (float) $value;
        }

        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        try {
            $timeVal = DateTimeHelpers::parseDateTime($value);

            return new TimestampValue(
                $timeVal,
                $value,
                DateTimeHelpers::getDatePrecisionFromString($value)
            );
        } catch (FilterValidationException) {
            // Not a date — fall through to the raw string.
        }

        return $value;
    }
}
