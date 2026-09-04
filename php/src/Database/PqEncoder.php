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

namespace Blnk\Database;

use Blnk\Internal\Filter\Helpers;
use Blnk\Model\ModelHelpers;
use Brick\Math\BigInteger;

/**
 * PqEncoder renders PHP values into the textual wire form github.com/lib/pq
 * sends for the corresponding Go values (lib/pq encode.go), so the PHP port
 * binds exactly what the Go code binds:
 *
 * - `time.Time`     → "2006-01-02 15:04:05.999999999Z07:00" (microseconds in PHP);
 *                     the zero time is sent as "0001-01-01 00:00:00Z", which is
 *                     how a nil PHP timestamp (the port's zero time) is encoded.
 * - `bool`          → "true" / "false" (PDO would otherwise bind `false` as "").
 * - `float64`       → the shortest round-trip decimal (strconv.FormatFloat 'g', -1).
 * - `*big.Int`      → `String()`.
 * - `[]byte` (JSON) → the `json.Marshal` text of a `map[string]interface{}`.
 * - `pq.StringArray`→ the `{"a","b"}` array literal (nil → NULL, empty → "{}").
 */
final class PqEncoder
{
    /** lib/pq's rendering of Go's `time.Time{}` (the zero time). */
    public const ZERO_TIME = '0001-01-01 00:00:00Z';

    /** Not instantiable: static helpers only. */
    private function __construct()
    {
    }

    /**
     * Go `time.Now()`: the current instant, in UTC (PORTING.md: DB timestamps are
     * stored as UTC).
     */
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Encodes a Go `time.Time` value parameter. A null PHP timestamp stands for
     * the zero time and is sent as {@see ZERO_TIME}, exactly as Go sends
     * `time.Time{}` (e.g. an unset identity DOB or a fresh API key's
     * last_used_at); it is never sent as SQL NULL.
     */
    public static function time(?\DateTimeInterface $t): string
    {
        if ($t === null) {
            return self::ZERO_TIME;
        }
        return $t->format('Y-m-d H:i:s.uP');
    }

    /**
     * Encodes a Go `*time.Time` parameter: null is SQL NULL.
     */
    public static function nullableTime(?\DateTimeInterface $t): ?string
    {
        return $t === null ? null : self::time($t);
    }

    /**
     * Reports whether a PHP timestamp is the Go zero time (`t.IsZero()`).
     */
    public static function isZeroTime(?\DateTimeInterface $t): bool
    {
        if ($t === null) {
            return true;
        }
        $utc = \DateTimeImmutable::createFromInterface($t)->setTimezone(new \DateTimeZone('UTC'));
        return $utc->format('Y-m-d H:i:s.u') === '0001-01-01 00:00:00.000000';
    }

    /**
     * Encodes a Go `bool` (`strconv.AppendBool`).
     */
    public static function bool(bool $b): string
    {
        return $b ? 'true' : 'false';
    }

    /**
     * Encodes a Go `float64` (`strconv.FormatFloat(v, 'g', -1, 64)`): the
     * shortest decimal that round-trips, in plain notation.
     */
    public static function float(float $f): string
    {
        return ModelHelpers::goFloatString($f);
    }

    /**
     * Encodes a Go `*big.Int` (`n.String()`).
     */
    public static function bigInt(BigInteger $n): string
    {
        return (string) $n;
    }

    /**
     * `json.Marshal` of a `map[string]interface{}` metadata field: a nil map
     * marshals as `null`, an empty map as `{}`, otherwise as a JSON object.
     *
     * @param array<string, mixed>|null $map
     *
     * @throws \JsonException when the value cannot be encoded (Go: json.Marshal error).
     */
    public static function json(?array $map): string
    {
        if ($map === null) {
            return 'null';
        }
        return json_encode(
            (object) $map,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * `pq.StringArray.Value()`: a nil slice is SQL NULL, an empty slice is
     * `{}`, otherwise the `{"a","b"}` literal (elements quoted, `\` and `"`
     * escaped).
     *
     * @param string[]|null $list
     */
    public static function textArray(?array $list): ?string
    {
        if ($list === null) {
            return null;
        }
        if (\count($list) === 0) {
            return '{}';
        }
        return Helpers::toPgTextArray(array_map(strval(...), array_values($list)));
    }

    /**
     * Normalizes a positional argument list for `PDOStatement::execute()`:
     * timestamps, booleans, floats and big integers are rendered as lib/pq
     * would render the Go values; ints, strings and nulls pass through.
     *
     * A null stays SQL NULL — call {@see time()} explicitly where Go binds a
     * `time.Time` value that may be zero.
     *
     * @param array<int, mixed> $args
     *
     * @return array<int, int|string|null>
     */
    public static function args(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            $out[] = self::value($arg);
        }
        return $out;
    }

    /**
     * Normalizes one bind value (see {@see args()}).
     *
     * @throws \InvalidArgumentException for values with no lib/pq encoding.
     */
    public static function value(mixed $arg): int|string|null
    {
        if ($arg === null || \is_int($arg) || \is_string($arg)) {
            return $arg;
        }
        if ($arg instanceof \DateTimeInterface) {
            return self::time($arg);
        }
        if (\is_bool($arg)) {
            return self::bool($arg);
        }
        if (\is_float($arg)) {
            return self::float($arg);
        }
        if ($arg instanceof BigInteger) {
            return self::bigInt($arg);
        }
        if ($arg instanceof \Stringable) {
            return (string) $arg;
        }
        throw new \InvalidArgumentException(sprintf('unsupported bind value of type %s', get_debug_type($arg)));
    }
}
