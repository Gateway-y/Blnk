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

use Brick\Math\BigInteger;

/**
 * RowScanner converts the raw column values pdo_pgsql hands back into the PHP
 * types the model classes use — the counterpart of Go's `rows.Scan(&field)`
 * conversions (database/sql + lib/pq decoding).
 *
 * pdo_pgsql returns int2/int4/int8 as int, bool as bool, float as float and
 * everything else (NUMERIC, TEXT, JSONB, TIMESTAMP, DATE, arrays) as strings;
 * the helpers accept any of those forms.
 *
 * Divergence (documented): Go's `Scan` fails when a NULL is read into a
 * non-nullable string/int/time field ("converting NULL to string is
 * unsupported"). The PHP port maps NULL to the Go zero value instead ('' / 0 /
 * null time), so rows with NULLs in LEFT JOIN'd columns read back gracefully.
 */
final class RowScanner
{
    /** Not instantiable: static helpers only. */
    private function __construct()
    {
    }

    /**
     * Scans a column into a Go `string` (NULL → '').
     */
    public static function toString(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (\is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return (string) $v;
    }

    /**
     * Scans a column into a Go `int64`/`int` (NULL → 0).
     */
    public static function toInt(mixed $v): int
    {
        if ($v === null) {
            return 0;
        }
        if (\is_int($v)) {
            return $v;
        }
        if (\is_bool($v)) {
            return $v ? 1 : 0;
        }
        return (int) $v;
    }

    /**
     * Scans a column into a Go `float64` (NULL → 0).
     */
    public static function toFloat(mixed $v): float
    {
        if ($v === null) {
            return 0.0;
        }
        if (\is_float($v)) {
            return $v;
        }
        return (float) $v;
    }

    /**
     * Scans a column into a Go `bool` (NULL → false). Accepts pdo_pgsql's
     * native bool as well as the textual forms 't'/'f', 'true'/'false', '1'/'0'.
     */
    public static function toBool(mixed $v): bool
    {
        if (\is_bool($v)) {
            return $v;
        }
        if ($v === null) {
            return false;
        }
        if (\is_int($v)) {
            return $v !== 0;
        }
        $s = strtolower(trim((string) $v));
        return \in_array($s, ['t', 'true', '1', 'yes', 'on', 'y'], true);
    }

    /**
     * parseBigInt parses a string into a *big.Int, returning an error if parsing fails.
     * This ensures we don't silently get nil values when database returns malformed data.
     *
     * Go: `new(big.Int).SetString(s, 10)` — an optional sign followed by decimal
     * digits only (no whitespace, no fraction, no underscores in base 10).
     *
     * @throws \InvalidArgumentException `invalid big.Int value: "<s>"` (the Go
     *                                   `fmt.Errorf("invalid big.Int value: %q", s)`).
     */
    public static function toBigInteger(mixed $v): BigInteger
    {
        $s = $v === null ? '' : (string) $v;
        if (preg_match('/^[+-]?[0-9]+$/', $s) !== 1) {
            throw new \InvalidArgumentException(sprintf('invalid big.Int value: "%s"', $s));
        }
        return BigInteger::of($s);
    }

    /**
     * Scans a TIMESTAMP / TIMESTAMPTZ / DATE column into a UTC
     * \DateTimeImmutable (PORTING.md: DB timestamps are read as UTC — lib/pq
     * likewise returns `timestamp` values in a zero-offset location).
     *
     * A SQL NULL and the Go zero time (0001-01-01 00:00:00) both become null,
     * which is how the model classes represent `time.Time{}`
     * ({@see \Blnk\Model\ModelHelpers::goTimeString()} renders null as
     * "0001-01-01T00:00:00Z", exactly as Go marshals the zero time).
     *
     * @throws \InvalidArgumentException when the value is not a timestamp.
     */
    public static function toTime(mixed $v): ?\DateTimeImmutable
    {
        if ($v === null || $v === '') {
            return null;
        }

        $utc = new \DateTimeZone('UTC');
        if ($v instanceof \DateTimeInterface) {
            $t = \DateTimeImmutable::createFromInterface($v);
        } else {
            $s = trim((string) $v);
            if (str_ends_with($s, ' BC')) {
                // Years before 1 AD cannot be represented; lib/pq would return a
                // negative year. Treat as the zero time.
                return null;
            }
            try {
                $t = new \DateTimeImmutable($s, $utc);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException(sprintf('invalid timestamp value: "%s"', $s), 0, $e);
            }
        }

        $t = $t->setTimezone($utc);
        if ($t->format('Y-m-d H:i:s.u') === '0001-01-01 00:00:00.000000') {
            return null; // Go zero time
        }
        return $t;
    }

    /**
     * Unmarshals a JSONB `meta_data` column the way
     * `json.Unmarshal(metaDataJSON, &x.MetaData)` fills a
     * `map[string]interface{}`: a JSON object becomes an array, a JSON `null`
     * becomes null, and anything else (array, scalar) is an error, as it is in Go.
     *
     * Divergence (documented): a SQL NULL column (nil `[]byte`) makes Go's
     * `json.Unmarshal` fail with "unexpected end of JSON input"; the PHP port
     * reads it as null, like a JSON null.
     *
     * @return array<string, mixed>|null
     *
     * @throws \InvalidArgumentException when the column is not a JSON object or null.
     */
    public static function toMetaData(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        if (\is_array($v)) {
            return $v;
        }
        $s = (string) $v;
        $trimmed = ltrim($s);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('unexpected end of JSON input');
        }

        try {
            $decoded = json_decode($s, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        if ($decoded === null) {
            return null;
        }
        if ($trimmed[0] !== '{' || !\is_array($decoded)) {
            throw new \InvalidArgumentException('json: cannot unmarshal non-object into Go value of type map[string]interface {}');
        }
        return $decoded;
    }

    /**
     * Scans a PostgreSQL `text[]` column into a list of strings, replicating
     * `pq.StringArray.Scan` (`{"a b",c}` → ['a b', 'c']; NULL → null).
     *
     * @return string[]|null
     *
     * @throws \InvalidArgumentException on a malformed literal or a NULL element
     *                                   (pq: "cannot convert nil to string").
     */
    public static function toStringList(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        if (\is_array($v)) {
            return array_map(strval(...), array_values($v));
        }

        $s = trim((string) $v);
        if ($s === '') {
            throw new \InvalidArgumentException('pq: unable to parse array; expected \'{\' at offset 0');
        }
        if (!str_starts_with($s, '{') || !str_ends_with($s, '}')) {
            throw new \InvalidArgumentException(sprintf('pq: unable to parse array; expected \'{\' at offset %d', 0));
        }

        $inner = substr($s, 1, -1);
        if ($inner === '') {
            return [];
        }

        $out = [];
        $i = 0;
        $n = \strlen($inner);
        while ($i < $n) {
            if ($inner[$i] === '"') {
                // Quoted element: backslash escapes the next character.
                $i++;
                $buf = '';
                $closed = false;
                while ($i < $n) {
                    $c = $inner[$i];
                    if ($c === '\\') {
                        $buf .= $inner[$i + 1] ?? '';
                        $i += 2;
                        continue;
                    }
                    if ($c === '"') {
                        $i++;
                        $closed = true;
                        break;
                    }
                    $buf .= $c;
                    $i++;
                }
                if (!$closed) {
                    throw new \InvalidArgumentException('pq: unable to parse array; unterminated quoted element');
                }
                $out[] = $buf;
            } else {
                // Unquoted element: runs up to the next comma.
                $start = $i;
                while ($i < $n && $inner[$i] !== ',') {
                    $i++;
                }
                $tok = substr($inner, $start, $i - $start);
                if ($tok === 'NULL') {
                    throw new \InvalidArgumentException(sprintf('pq: parsing array element index %d: cannot convert nil to string', \count($out)));
                }
                $out[] = $tok;
            }

            if ($i < $n) {
                if ($inner[$i] !== ',') {
                    throw new \InvalidArgumentException(sprintf('pq: unable to parse array; unexpected "%s" at offset %d', $inner[$i], $i + 1));
                }
                $i++;
            }
        }

        return $out;
    }
}
