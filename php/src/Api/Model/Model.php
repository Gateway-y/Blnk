<?php

/*
Copyright 2024 Blnk Finance Authors.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

declare(strict_types=1);

namespace Blnk\Api\Model;

use Blnk\Api\Binding;

/**
 * Port of the package-level declarations of api/model/model.go: the
 * `ErrPrecisionMustBeInteger` sentinel (see {@see PrecisionMustBeIntegerException})
 * and the rule functions shared by the request models' Validate* methods.
 * The methods of the individual structs live on their own classes.
 */
final class Model
{
    /** Message of the `ErrPrecisionMustBeInteger` sentinel. */
    public const ErrPrecisionMustBeInteger = 'precision must be an integer value';

    /** Go layout used for scheduled/inflight dates: `2006-01-02T15:04:05Z07:00` (RFC 3339). */
    public const DateLayout = '2006-01-02T15:04:05Z07:00';

    private function __construct()
    {
    }

    /**
     * validatePrecisionIsInteger is the `validation.By` rule that rejects a
     * fractional precision.
     */
    public static function validatePrecisionIsInteger(mixed $value): ?\Throwable
    {
        if (!is_float($value) && !is_int($value)) {
            return new \RuntimeException('invalid precision type');
        }
        $precision = (float) $value;

        if (self::trunc($precision) !== $precision) {
            return new PrecisionMustBeIntegerException();
        }

        return null;
    }

    /**
     * trunc is Go's math.Trunc: the integer value toward zero (NaN/Inf pass through).
     */
    private static function trunc(float $f): float
    {
        if (is_nan($f) || is_infinite($f)) {
            return $f;
        }

        return $f < 0 ? ceil($f) : floor($f);
    }

    /**
     * sourceOrSourcesValidation: exactly one of `source` / `sources` must be given.
     *
     * @return \Closure(mixed): ?\Throwable
     */
    public static function sourceOrSourcesValidation(RecordTransaction $t): \Closure
    {
        return static function (mixed $value) use ($t): ?\Throwable {
            $sources = $t->sources ?? [];
            if (($t->source === '' && count($sources) === 0) || ($t->source !== '' && count($sources) > 0)) {
                return new \RuntimeException('either source or sources is required, not both');
            }

            return null;
        };
    }

    /**
     * destinationOrDestinationsValidation: exactly one of `destination` / `destinations` must be given.
     *
     * @return \Closure(mixed): ?\Throwable
     */
    public static function destinationOrDestinationsValidation(RecordTransaction $t): \Closure
    {
        return static function (mixed $value) use ($t): ?\Throwable {
            $destinations = $t->destinations ?? [];
            if (($t->destination === '' && count($destinations) === 0) || ($t->destination !== '' && count($destinations) > 0)) {
                return new \RuntimeException('either destination or destinations is required, not both');
            }

            return null;
        };
    }

    /**
     * validateDateFormat checks that a value parses with the given Go time
     * layout (only the RFC 3339 layout is used by the models).
     */
    public static function validateDateFormat(string $format, string $value): ?\Throwable
    {
        if (self::parseTime($format, $value) === null) {
            return new \RuntimeException("please format the scheduled date as 'YYYY-MM-DDTHH:MM:SS+00:00' (e.g., 2024-04-22T15:28:03+00:00)");
        }

        return null;
    }

    /**
     * parseTime is `time.Parse(layout, value)` for the layouts the api models
     * use (RFC 3339); null stands for Go's error return.
     */
    public static function parseTime(string $layout, string $value): ?\DateTimeImmutable
    {
        if ($layout === self::DateLayout || $layout === Binding::TimeLayout) {
            return Binding::parseRFC3339($value);
        }
        try {
            $parsed = \DateTimeImmutable::createFromFormat($layout, $value);
        } catch (\Throwable) {
            return null;
        }

        return $parsed === false ? null : $parsed;
    }

    /**
     * formatFloat is `strconv.FormatFloat(f, 'f', -1, 64)`: the shortest
     * decimal representation that round-trips, without an exponent.
     */
    public static function formatFloat(float $f): string
    {
        if (is_nan($f)) {
            return 'NaN';
        }
        if (is_infinite($f)) {
            return $f > 0 ? '+Inf' : '-Inf';
        }
        // json_encode (serialize_precision = -1) yields the shortest
        // round-trip form, possibly with an exponent.
        $s = json_encode($f);
        if ($s === false) {
            $s = (string) $f;
        }
        $s = strtolower($s);
        if (!str_contains($s, 'e')) {
            return $s;
        }
        [$mantissa, $exponent] = explode('e', $s, 2);
        $exp = (int) $exponent;
        $negative = str_starts_with($mantissa, '-');
        $mantissa = ltrim($mantissa, '-');
        $dot = strpos($mantissa, '.');
        $intPart = $dot === false ? $mantissa : substr($mantissa, 0, $dot);
        $fracPart = $dot === false ? '' : substr($mantissa, $dot + 1);
        $digits = $intPart . $fracPart;
        $pointPos = strlen($intPart) + $exp;
        if ($pointPos <= 0) {
            $out = '0.' . str_repeat('0', -$pointPos) . $digits;
        } elseif ($pointPos >= strlen($digits)) {
            $out = $digits . str_repeat('0', $pointPos - strlen($digits));
        } else {
            $out = substr($digits, 0, $pointPos) . '.' . substr($digits, $pointPos);
        }
        $out = rtrim(rtrim($out, '0'), '.');
        if ($out === '' || $out === '-') {
            $out = '0';
        }
        if (str_contains($out, '.')) {
            $out = rtrim(rtrim($out, '0'), '.');
        }

        return ($negative ? '-' : '') . $out;
    }
}
