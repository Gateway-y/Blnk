<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * Port of internal/filter/datetime.go: datetime parsing, precision detection
 * and timestamp range computation for filter values.
 */
final class DateTimeHelpers
{
    /**
     * parseDateTime parses a date/time string using the same format list as the
     * Go implementation (RFC3339[Nano], "T"/space separated timestamps with and
     * without fractional seconds, minute precision, and date-only forms).
     *
     * Go returns (time.Time, error); per the porting conventions this throws a
     * FilterValidationException with the message "unable to parse date: <value>"
     * when no format matches.
     *
     * Note: PHP's DateTimeImmutable is microsecond-precise, so fractional parts
     * longer than 6 digits (Go's RFC3339Nano) are truncated to microseconds for
     * parsing. Timestamps without a timezone are interpreted as UTC, matching
     * Go's time.Parse.
     */
    public static function parseDateTime(string $value): \DateTimeImmutable
    {
        $formats = [
            'Y-m-d\TH:i:s.uP', // RFC3339Nano / RFC3339 with fraction (2006-01-02T15:04:05.999999999Z07:00)
            'Y-m-d\TH:i:sP',   // RFC3339 (2006-01-02T15:04:05Z07:00)
            'Y-m-d\TH:i:s.u',  // Fractional seconds without timezone
            'Y-m-d\TH:i:s',    // Seconds without timezone
            'Y-m-d\TH:i',      // Minutes without timezone
            'Y-m-d H:i:s.u',   // Fractional seconds with space
            'Y-m-d H:i:s',     // Seconds with space
            'Y-m-d H:i',       // Minutes with space
            'Y-m-d',           // Date only
            'Y/m/d',           // Date only, slash separated
        ];

        // Go's ".999999999" fraction directives accept 1-9 digits; PHP's "u"
        // accepts at most 6, so truncate longer fractions (nanoseconds) to
        // microseconds before attempting the parse.
        $parseValue = preg_replace_callback(
            '/\.(\d{7,9})(?=$|[Z+\-])/',
            static fn (array $m): string => '.' . substr($m[1], 0, 6),
            $value
        );
        if (!is_string($parseValue)) {
            $parseValue = $value;
        }

        $utc = new \DateTimeZone('UTC');
        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $parseValue, $utc);
            if ($parsed === false) {
                continue;
            }
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                continue;
            }

            return $parsed;
        }

        throw new FilterValidationException(sprintf('unable to parse date: %s', $value));
    }

    /**
     * getDatePrecisionFromString determines the precision label ("microseconds",
     * "milliseconds", "second", "minute", "hour", "day") of a raw date string.
     *
     * @internal Used by Values::parseValue().
     */
    public static function getDatePrecisionFromString(string $dateStr): string
    {
        if (str_contains($dateStr, '.')) {
            $parts = explode('.', $dateStr);
            if (count($parts) > 1) {
                $fractionalPart = $parts[1];
                if (str_ends_with($fractionalPart, 'Z')) {
                    $fractionalPart = substr($fractionalPart, 0, -1);
                }
                $idx = strcspn($fractionalPart, '+-');
                if ($idx !== strlen($fractionalPart)) {
                    $fractionalPart = substr($fractionalPart, 0, $idx);
                }

                $digitCount = strlen($fractionalPart);
                if ($digitCount >= 6) {
                    return 'microseconds';
                } elseif ($digitCount >= 3) {
                    return 'milliseconds';
                } elseif ($digitCount > 0) {
                    return 'milliseconds';
                }
            }
        }

        if (str_contains($dateStr, ':')) {
            $colonCount = substr_count($dateStr, ':');
            if ($colonCount >= 2) {
                return 'second';
            } elseif ($colonCount === 1) {
                return 'minute';
            }
        }

        if (str_contains($dateStr, 'T') || str_contains($dateStr, ' ')) {
            return 'hour';
        }

        return 'day';
    }

    /**
     * getDatePrecisionFromTime determines the precision of a parsed timestamp
     * from its components, mirroring the Go implementation. PHP timestamps hold
     * microseconds at most, so the Go nanosecond checks reduce to microseconds.
     *
     * @internal Used by the SQL builders for plain \DateTimeInterface values.
     */
    public static function getDatePrecisionFromTime(\DateTimeInterface $t): string
    {
        $micro = (int) $t->format('u');
        // Go: t.Nanosecond()%1e6 != 0 -> microseconds (sub-millisecond detail).
        if ($micro % 1000 !== 0) {
            return 'microseconds';
        }
        if ($micro !== 0) {
            return 'milliseconds';
        }
        if ((int) $t->format('s') !== 0) {
            return 'second';
        }
        if ((int) $t->format('i') !== 0) {
            return 'minute';
        }
        if ((int) $t->format('G') !== 0) {
            return 'hour';
        }

        return 'day';
    }

    /**
     * computeTimestampRange computes the inclusive floor and exclusive ceiling
     * of the interval covered by a timestamp at its detected precision.
     *
     * @internal Used by the SQL builders.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} [floor, ceiling]
     */
    public static function computeTimestampRange(TimestampValue $ts): array
    {
        $t = $ts->time;
        switch ($ts->precision) {
            case 'microseconds':
                $floor = $t; // Already microsecond-truncated (PHP max precision).
                $ceiling = $floor->modify('+1 microsecond');
                break;
            case 'milliseconds':
                $floor = self::truncateMicroseconds($t, 1000);
                $ceiling = $floor->modify('+1 millisecond');
                break;
            case 'second':
                $floor = self::truncateMicroseconds($t, 1000000);
                $ceiling = $floor->modify('+1 second');
                break;
            case 'minute':
                $floor = $t->setTime((int) $t->format('G'), (int) $t->format('i'), 0, 0);
                $ceiling = $floor->modify('+1 minute');
                break;
            case 'hour':
                $floor = $t->setTime((int) $t->format('G'), 0, 0, 0);
                $ceiling = $floor->modify('+1 hour');
                break;
            case 'day':
                $floor = $t->setTime(0, 0, 0, 0);
                $ceiling = $floor->modify('+1 day');
                break;
            default:
                $floor = self::truncateMicroseconds($t, 1000000);
                $ceiling = $floor->modify('+1 second');
                break;
        }

        return [$floor, $ceiling];
    }

    private static function truncateMicroseconds(\DateTimeImmutable $t, int $unitMicros): \DateTimeImmutable
    {
        $micro = (int) $t->format('u');
        $truncated = $micro - ($micro % $unitMicros);

        return $t->setTime((int) $t->format('G'), (int) $t->format('i'), (int) $t->format('s'), $truncated);
    }
}
