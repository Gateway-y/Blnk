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

use Brick\Math\BigInteger;
use Psr\Http\Message\ResponseInterface;

/**
 * JsonBinding is the porting support class behind the api/model request
 * classes: it reproduces the parts of Gin's `ShouldBindJSON` / `BindJSON`
 * (Go `encoding/json` decoding into the request structs, plus the
 * `binding:"required"` tag of go-playground/validator) that the handlers'
 * error messages depend on, and the `c.JSON(status, obj)` response writer.
 *
 * It has no Go counterpart of its own — Gin performed all of this
 * implicitly — so it lives next to the models it binds (see PORTING.md
 * "HTTP layer": request binding → fromArray on the api model with the same
 * validations and error messages).
 *
 * Documented approximations (encoding/json cannot be reproduced exactly):
 *  - JSON syntax error texts follow Go's wording only for the common
 *    "invalid character 'x' looking for beginning of value" case; other
 *    syntax errors carry json_last_error_msg().
 *  - Integer literals beyond PHP's int range are decoded with
 *    JSON_BIGINT_AS_STRING so `precise_amount` keeps its exact digits (Go's
 *    *big.Int); as a consequence a *quoted* digit string that overflows the
 *    int range is indistinguishable from such a literal and is accepted as a
 *    number where Go would reject the quoted string.
 *  - A JSON `[]` and `{}` both decode to an empty PHP array; the empty object
 *    form is assumed for map/struct fields and the empty list form for slices.
 *  - encoding/json accepts trailing data after the first JSON value; PHP does not.
 */
final class JsonBinding
{
    /** Go time.RFC3339 layout — the format used by encoding/json for time.Time. */
    public const RFC3339 = '2006-01-02T15:04:05Z07:00';

    /** The error message Go's decoder returns for an empty body (io.EOF). */
    public const EOF = 'EOF';

    /** Not instantiable: static helpers only. */
    private function __construct()
    {
    }

    // ---------------------------------------------------------------------
    // Decoding (Gin ShouldBindJSON / BindJSON)
    // ---------------------------------------------------------------------

    /**
     * decode decodes a request body into the associative array a request
     * model's fromArray() consumes, with the error semantics of
     * `json.NewDecoder(body).Decode(&obj)`:
     *  - an empty body is io.EOF (message "EOF", see {@see isEOF()});
     *  - a JSON null leaves the target untouched (an empty array is returned);
     *  - any non-object value is "json: cannot unmarshal <kind> into Go value of type <goType>".
     *
     * @param string $goType the Go type name used in the mismatch message (e.g. "model.RecordTransaction")
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public static function decode(string $body, string $goType): array
    {
        $trimmed = ltrim($body, " \t\r\n");
        if ($trimmed === '') {
            throw new \RuntimeException(self::EOF);
        }

        $decoded = json_decode($trimmed, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(self::syntaxErrorMessage($trimmed));
        }

        if ($trimmed[0] === '[') {
            throw new \RuntimeException(sprintf('json: cannot unmarshal array into Go value of type %s', $goType));
        }
        if ($decoded === null) {
            // "null" is a no-op for encoding/json: the struct keeps its zero values.
            return [];
        }
        if (\is_array($decoded)) {
            return $decoded;
        }

        throw new \RuntimeException(sprintf(
            'json: cannot unmarshal %s into Go value of type %s',
            self::jsonTypeName($decoded),
            $goType
        ));
    }

    /**
     * isEOF reports whether a decode error is the io.EOF of an empty body
     * (Go: `errors.Is(err, io.EOF)`).
     */
    public static function isEOF(\Throwable $err): bool
    {
        return $err->getMessage() === self::EOF;
    }

    /**
     * syntaxErrorMessage approximates encoding/json's SyntaxError text.
     */
    private static function syntaxErrorMessage(string $trimmed): string
    {
        $first = $trimmed[0];
        if (!str_contains('{["tfn-0123456789', $first)) {
            return sprintf("invalid character '%s' looking for beginning of value", $first);
        }
        return json_last_error_msg();
    }

    /**
     * jsonTypeName names a decoded value the way encoding/json does in its
     * UnmarshalTypeError ("string", "number", "bool", "array", "object").
     */
    public static function jsonTypeName(mixed $value): string
    {
        if (\is_string($value)) {
            return self::isBigIntLiteral($value) ? 'number' : 'string';
        }
        if (\is_bool($value)) {
            return 'bool';
        }
        if (\is_int($value) || \is_float($value)) {
            return 'number';
        }
        if (\is_array($value)) {
            return (\count($value) > 0 && array_is_list($value)) ? 'array' : 'object';
        }
        return 'null';
    }

    /**
     * isBigIntLiteral reports whether a decoded string is an integer literal
     * that json_decode stringified because it overflows PHP's int range
     * (JSON_BIGINT_AS_STRING).
     */
    private static function isBigIntLiteral(mixed $value): bool
    {
        return \is_string($value)
            && is_numeric($value)
            && preg_match('/^-?[0-9]+$/', $value) === 1
            && !\is_int($value + 0);
    }

    /**
     * lookup mirrors encoding/json's key matching: an exact match wins,
     * otherwise the first case-insensitive match is used.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: bool, 1: mixed} [found, value]
     */
    private static function lookup(array $data, string $key): array
    {
        if (\array_key_exists($key, $data)) {
            return [true, $data[$key]];
        }
        foreach ($data as $k => $v) {
            if (\is_string($k) && strcasecmp($k, $key) === 0) {
                return [true, $v];
            }
        }
        return [false, null];
    }

    /**
     * fieldError builds encoding/json's UnmarshalTypeError message for a
     * struct field: "json: cannot unmarshal <kind> into Go struct field
     * <Struct>.<key> of type <goType>".
     */
    private static function fieldError(mixed $value, string $struct, string $key, string $goType): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'json: cannot unmarshal %s into Go struct field %s.%s of type %s',
            self::jsonTypeName($value),
            $struct,
            $key,
            $goType
        ));
    }

    /**
     * string binds a Go `string` field: absent or null → "" (zero value).
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch
     */
    public static function string(array $data, string $key, string $struct): string
    {
        [$found, $v] = self::lookup($data, $key);
        if (!$found || $v === null) {
            return '';
        }
        if (\is_string($v) && !self::isBigIntLiteral($v)) {
            return $v;
        }
        throw self::fieldError($v, $struct, $key, 'string');
    }

    /**
     * float binds a Go `float64` field (JSON numbers only): absent or null → 0.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch
     */
    public static function float(array $data, string $key, string $struct): float
    {
        [$found, $v] = self::lookup($data, $key);
        if (!$found || $v === null) {
            return 0.0;
        }
        if (\is_int($v) || \is_float($v)) {
            return (float) $v;
        }
        if (self::isBigIntLiteral($v)) {
            return (float) $v;
        }
        throw self::fieldError($v, $struct, $key, 'float64');
    }

    /**
     * bool binds a Go `bool` field: absent or null → false.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch
     */
    public static function bool(array $data, string $key, string $struct): bool
    {
        [$found, $v] = self::lookup($data, $key);
        if (!$found || $v === null) {
            return false;
        }
        if (\is_bool($v)) {
            return $v;
        }
        throw self::fieldError($v, $struct, $key, 'bool');
    }

    /**
     * bigInt binds a Go `*big.Int` field. big.Int's UnmarshalJSON ignores
     * null and otherwise requires an integer literal: fractions, exponents
     * and quoted strings all fail with
     * `math/big: cannot unmarshal "<text>" into a *big.Int`.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException
     */
    public static function bigInt(array $data, string $key, string $struct): ?BigInteger
    {
        [$found, $v] = self::lookup($data, $key);
        if (!$found || $v === null) {
            return null;
        }
        if (\is_int($v)) {
            return BigInteger::of($v);
        }
        if (self::isBigIntLiteral($v)) {
            return BigInteger::of($v);
        }
        $text = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        throw new \RuntimeException(sprintf('math/big: cannot unmarshal "%s" into a *big.Int', $text === false ? '' : $text));
    }

    /**
     * stringList binds a Go `[]string` field: absent or null → null (a nil
     * slice), a JSON array of strings → list (null elements decode to "").
     *
     * @param array<string, mixed> $data
     *
     * @return string[]|null
     *
     * @throws \RuntimeException on a JSON type mismatch
     */
    public static function stringList(array $data, string $key, string $struct): ?array
    {
        [$found, $v] = self::lookup($data, $key);
        if (!$found || $v === null) {
            return null;
        }
        if (!\is_array($v) || !array_is_list($v)) {
            throw self::fieldError($v, $struct, $key, '[]string');
        }
        $out = [];
        foreach ($v as $item) {
            if ($item === null) {
                $out[] = '';
                continue;
            }
            if (\is_string($item) && !self::isBigIntLiteral($item)) {
                $out[] = $item;
                continue;
            }
            throw self::fieldError($item, $struct, $key, 'string');
        }
        return $out;
    }

    /**
     * objectList binds a Go slice-of-structs field: absent or null → null (a
     * nil slice); each JSON object element is returned as an associative
     * array and a null element as an empty array (the zero struct). A null
     * element of a slice of *pointers* is reported as null when $nullable is true.
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, array<string, mixed>|null>|null
     *
     * @throws \RuntimeException on a JSON type mismatch
     */
    public static function objectList(array $data, string $key, string $struct, string $elemType, bool $nullable = false): ?array
    {
        [$found, $v] = self::lookup($data, $key);
        if (!$found || $v === null) {
            return null;
        }
        if (!\is_array($v) || !array_is_list($v)) {
            throw self::fieldError($v, $struct, $key, '[]' . $elemType);
        }
        $out = [];
        foreach ($v as $item) {
            if ($item === null) {
                $out[] = $nullable ? null : [];
                continue;
            }
            if (\is_array($item) && (\count($item) === 0 || !array_is_list($item))) {
                $out[] = $item;
                continue;
            }
            throw self::fieldError($item, $struct, $key, $elemType);
        }
        return $out;
    }

    /**
     * map binds a Go `map[string]interface{}` field: absent or null → null (a
     * nil map), a JSON object → associative array.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     *
     * @throws \RuntimeException on a JSON type mismatch
     */
    public static function map(array $data, string $key, string $struct): ?array
    {
        [$found, $v] = self::lookup($data, $key);
        if (!$found || $v === null) {
            return null;
        }
        if (\is_array($v) && (\count($v) === 0 || !array_is_list($v))) {
            return $v;
        }
        throw self::fieldError($v, $struct, $key, 'map[string]interface {}');
    }

    /**
     * time binds a Go `*time.Time` field: absent or null → null; otherwise an
     * RFC 3339 string (time.Time.UnmarshalJSON is strict about the layout).
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch or an unparseable timestamp
     */
    public static function time(array $data, string $key, string $struct): ?\DateTimeImmutable
    {
        [$found, $v] = self::lookup($data, $key);
        if (!$found || $v === null) {
            return null;
        }
        if (!\is_string($v) || self::isBigIntLiteral($v)) {
            throw self::fieldError($v, $struct, $key, 'time.Time');
        }
        try {
            return self::parseRFC3339($v);
        } catch (\RuntimeException) {
            // time.Time.UnmarshalJSON parses the quoted value against the quoted layout.
            throw new \RuntimeException(sprintf(
                'parsing time "\"%s\"" as "\"%s\"": cannot parse "\"%s\"" as "\"%s\""',
                $v,
                self::RFC3339,
                $v,
                self::RFC3339
            ));
        }
    }

    /**
     * parseRFC3339 is the port of Go `time.Parse(time.RFC3339, value)`: a
     * strict RFC 3339 timestamp ("2006-01-02T15:04:05Z07:00", an optional
     * fractional second is accepted as Go does when parsing). Fractions are
     * kept at microsecond resolution (PHP's DateTimeImmutable limit).
     *
     * @throws \RuntimeException with Go's ParseError wording
     */
    public static function parseRFC3339(string $value): \DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})$/', $value, $m) !== 1) {
            throw self::parseError($value);
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $hour = (int) $m[4];
        $minute = (int) $m[5];
        $second = (int) $m[6];
        $frac = $m[7] ?? '';
        $tz = $m[8];

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59 || $second > 59) {
            throw self::parseError($value);
        }
        if ($year >= 1 && !checkdate($month, $day, $year)) {
            throw self::parseError($value);
        }
        if ($tz !== 'Z') {
            $tzHour = (int) substr($tz, 1, 2);
            $tzMinute = (int) substr($tz, 4, 2);
            if ($tzHour > 23 || $tzMinute > 59) {
                throw self::parseError($value);
            }
        }

        $iso = sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        if ($frac !== '') {
            $iso .= '.' . str_pad(substr($frac, 0, 6), 6, '0');
        }
        $iso .= $tz === 'Z' ? '+00:00' : $tz;

        try {
            return new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            throw self::parseError($value);
        }
    }

    private static function parseError(string $value): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'parsing time "%s" as "%s": cannot parse "%s" as "%s"',
            $value,
            self::RFC3339,
            $value,
            self::RFC3339
        ));
    }

    /**
     * requiredError builds the go-playground/validator message Gin returns
     * for a `binding:"required"` failure:
     * "Key: '<Struct>.<Field>' Error:Field validation for '<Field>' failed on the 'required' tag"
     * (for an anonymous struct the namespace is just the field name).
     */
    public static function requiredError(string $struct, string $field): \RuntimeException
    {
        return new \RuntimeException(self::requiredMessage($struct, $field));
    }

    /**
     * requiredErrors joins several required-tag failures the way
     * validator.ValidationErrors.Error() does (one per line, field order).
     *
     * @param string[] $fields
     */
    public static function requiredErrors(string $struct, array $fields): \RuntimeException
    {
        $lines = [];
        foreach ($fields as $field) {
            $lines[] = self::requiredMessage($struct, $field);
        }
        return new \RuntimeException(implode("\n", $lines));
    }

    private static function requiredMessage(string $struct, string $field): string
    {
        $ns = $struct === '' ? $field : $struct . '.' . $field;
        return sprintf("Key: '%s' Error:Field validation for '%s' failed on the 'required' tag", $ns, $field);
    }

    // ---------------------------------------------------------------------
    // Encoding (Gin c.JSON)
    // ---------------------------------------------------------------------

    /**
     * encode marshals a payload like encoding/json: slashes and non-ASCII
     * characters are left as-is while `<`, `>` and `&` are escaped
     * (Go's HTML-safe default).
     *
     * @throws \JsonException
     */
    public static function encode(mixed $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
    }

    /**
     * json is the port of Gin's `c.JSON(status, obj)`: writes the encoded
     * payload with Gin's "application/json; charset=utf-8" content type.
     */
    public static function json(ResponseInterface $response, int $status, mixed $payload): ResponseInterface
    {
        $response->getBody()->write(self::encode($payload));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }
}
