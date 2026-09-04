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

namespace Blnk\Api;

use Blnk\Model\ModelHelpers;
use Brick\Math\BigInteger;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Binding ports Gin's JSON request binding (`c.ShouldBindJSON` /
 * `c.BindJSON`) onto PSR-7 requests, together with the typed field readers
 * the api models use in their fromArray() methods.
 *
 * Gin binds with encoding/json and then runs the go-playground validator for
 * `binding:"required"` tags. The error messages produced here follow the same
 * formats so the API responses (which echo them verbatim) stay compatible:
 *
 * - empty body:            `EOF`
 * - malformed JSON:        the json_decode() error message (Go reports
 *                          `invalid character ... looking for beginning of
 *                          value`; the exact wording cannot be reproduced)
 * - wrong JSON kind:       `json: cannot unmarshal <kind> into Go struct field
 *                          <Struct>.<json.path> of type <gotype>`
 * - required tag:          `Key: '<Struct>.<Field>' Error:Field validation for
 *                          '<Field>' failed on the 'required' tag`
 *
 * JSON objects and arrays both decode to PHP arrays; a non-empty list is
 * reported as kind "array", everything else as "object" (an empty JSON array
 * is therefore accepted where Go expects an object — documented divergence).
 * Integer literals beyond the int64 range decode as strings
 * (JSON_BIGINT_AS_STRING) and are reported as kind "number" so `*big.Int`
 * fields keep their full precision, as they do in Go.
 */
final class Binding
{
    /** Go's RFC 3339 layout, as printed in time parsing errors. */
    public const TimeLayout = '2006-01-02T15:04:05Z07:00';

    private function __construct()
    {
    }

    /**
     * shouldBindJSON is the port of `c.ShouldBindJSON(&v)`: decodes the request
     * body as one JSON document and returns the decoded value (associative
     * arrays for objects, lists for arrays, scalars otherwise).
     *
     * @throws BindingException on an empty body, malformed JSON, or an
     *                          unreadable (oversized) body.
     */
    public static function shouldBindJSON(ServerRequestInterface $request): mixed
    {
        $body = $request->getBody();
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $raw = $body->getContents();
        } catch (\Throwable $e) {
            // e.g. the RequestSizeLimit middleware's "http: request body too large"
            throw new BindingException($e->getMessage(), 0, $e);
        }

        if (trim($raw) === '') {
            // encoding/json returns io.EOF for an empty document.
            throw new BindingException('EOF');
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new BindingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * shouldBindJSONObject binds a body whose Go target is a struct: the
     * document must be a JSON object.
     *
     * @param string $goType the Go type name reported in the error, e.g. "model.CreateLedger"
     * @return array<string, mixed>
     * @throws BindingException
     */
    public static function shouldBindJSONObject(ServerRequestInterface $request, string $goType): array
    {
        $decoded = self::shouldBindJSON($request);
        if (!is_array($decoded) || (count($decoded) > 0 && array_is_list($decoded))) {
            throw new BindingException(sprintf('json: cannot unmarshal %s into Go value of type %s', self::kindOf($decoded), $goType));
        }

        return $decoded;
    }

    /**
     * bindJSON is the port of `c.BindJSON(&v)`: like shouldBindJSON, but a
     * failure is also recorded in the request error list (Gin's
     * `c.AbortWithError(400, err)`); the caller still writes the response.
     *
     * @throws BindingException
     */
    public static function bindJSON(ServerRequestInterface $request): mixed
    {
        try {
            return self::shouldBindJSON($request);
        } catch (BindingException $e) {
            RequestErrors::add();
            throw $e;
        }
    }

    /**
     * bindJSONObject is bindJSON for a struct target (see shouldBindJSONObject).
     *
     * @return array<string, mixed>
     * @throws BindingException
     */
    public static function bindJSONObject(ServerRequestInterface $request, string $goType): array
    {
        try {
            return self::shouldBindJSONObject($request, $goType);
        } catch (BindingException $e) {
            RequestErrors::add();
            throw $e;
        }
    }

    /**
     * kindOf names a decoded JSON value the way encoding/json does in its
     * UnmarshalTypeError messages.
     */
    public static function kindOf(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return 'bool';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }
        if (is_string($value)) {
            return self::isBigIntLiteral($value) ? 'number' : 'string';
        }
        if (is_array($value)) {
            return (count($value) > 0 && array_is_list($value)) ? 'array' : 'object';
        }

        return 'object';
    }

    /**
     * isBigIntLiteral reports whether a decoded string is an integer literal
     * that json_decode turned into a string because it exceeds the int64
     * range (JSON_BIGINT_AS_STRING).
     */
    private static function isBigIntLiteral(string $value): bool
    {
        if (preg_match('/^-?[0-9]{19,}$/', $value) !== 1) {
            return false;
        }
        // Anything json_decode could have represented as an int is a real string.
        return !is_numeric($value) || (string) (int) $value !== $value;
    }

    /**
     * typeError builds encoding/json's UnmarshalTypeError message.
     */
    public static function typeError(string $kind, string $struct, string $field, string $goType): BindingException
    {
        return new BindingException(sprintf('json: cannot unmarshal %s into Go struct field %s.%s of type %s', $kind, $struct, $field, $goType));
    }

    /**
     * requiredError builds the go-playground validator message for fields
     * tagged `binding:"required"` — one line per failed field, joined with
     * newlines as validator.ValidationErrors.Error() does.
     *
     * @param list<array{0: string, 1: string}> $failures [struct, GoFieldName] pairs
     */
    public static function requiredError(array $failures): BindingException
    {
        $lines = [];
        foreach ($failures as [$struct, $goField]) {
            $lines[] = sprintf("Key: '%s.%s' Error:Field validation for '%s' failed on the 'required' tag", $struct, $goField, $goField);
        }

        return new BindingException(implode("\n", $lines));
    }

    // ------------------------------------------------------------------
    // Typed field readers (Go struct field semantics: a missing or null
    // key leaves the zero value; a value of the wrong JSON kind is an error).
    // ------------------------------------------------------------------

    /**
     * has reports whether the key is present with a non-null value — the
     * distinction the `required` validator makes for strings/maps/slices.
     *
     * @param array<string, mixed> $data
     */
    public static function has(array $data, string $key): bool
    {
        return array_key_exists($key, $data) && $data[$key] !== null;
    }

    /**
     * @param array<string, mixed> $data
     * @throws BindingException
     */
    public static function string(array $data, string $key, string $struct): string
    {
        if (!self::has($data, $key)) {
            return '';
        }
        $value = $data[$key];
        if (!is_string($value) || self::isBigIntLiteral($value)) {
            throw self::typeError(self::kindOf($value), $struct, $key, 'string');
        }

        return $value;
    }

    /**
     * Go `float64`.
     *
     * @param array<string, mixed> $data
     * @throws BindingException
     */
    public static function float(array $data, string $key, string $struct): float
    {
        if (!self::has($data, $key)) {
            return 0.0;
        }
        $value = $data[$key];
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && self::isBigIntLiteral($value)) {
            return (float) $value;
        }
        throw self::typeError(self::kindOf($value), $struct, $key, 'float64');
    }

    /**
     * Go `int`: only integer literals are accepted (`1.5` fails as in Go).
     *
     * @param array<string, mixed> $data
     * @throws BindingException
     */
    public static function int(array $data, string $key, string $struct): int
    {
        if (!self::has($data, $key)) {
            return 0;
        }
        $value = $data[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || (is_string($value) && self::isBigIntLiteral($value))) {
            throw new BindingException(sprintf('json: cannot unmarshal number %s into Go struct field %s.%s of type int', is_float($value) ? ModelHelpers::goFloatString($value) : $value, $struct, $key));
        }
        throw self::typeError(self::kindOf($value), $struct, $key, 'int');
    }

    /**
     * @param array<string, mixed> $data
     * @throws BindingException
     */
    public static function bool(array $data, string $key, string $struct): bool
    {
        if (!self::has($data, $key)) {
            return false;
        }
        $value = $data[$key];
        if (!is_bool($value)) {
            throw self::typeError(self::kindOf($value), $struct, $key, 'bool');
        }

        return $value;
    }

    /**
     * Go `map[string]interface{}`: null for a missing/null key (nil map).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     * @throws BindingException
     */
    public static function map(array $data, string $key, string $struct): ?array
    {
        if (!self::has($data, $key)) {
            return null;
        }
        $value = $data[$key];
        if (!is_array($value) || (count($value) > 0 && array_is_list($value))) {
            throw self::typeError(self::kindOf($value), $struct, $key, 'map[string]interface {}');
        }

        return $value;
    }

    /**
     * Go `[]string`: null for a missing/null key (nil slice).
     *
     * @param array<string, mixed> $data
     * @return string[]|null
     * @throws BindingException
     */
    public static function stringArray(array $data, string $key, string $struct): ?array
    {
        if (!self::has($data, $key)) {
            return null;
        }
        $value = $data[$key];
        if (!is_array($value) || (count($value) > 0 && !array_is_list($value))) {
            throw self::typeError(self::kindOf($value), $struct, $key, '[]string');
        }
        $out = [];
        foreach ($value as $item) {
            if ($item === null) {
                $out[] = '';
                continue;
            }
            if (!is_string($item) || self::isBigIntLiteral($item)) {
                throw self::typeError(self::kindOf($item), $struct, $key, 'string');
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Go `[]Struct` / `[]*Struct`: a list of objects, null for a missing/null
     * key (nil slice). Null elements are kept as null (nil pointers).
     *
     * @param array<string, mixed> $data
     * @param string $elemGoType the element type reported in errors, e.g. "model.Distribution"
     * @return list<array<string, mixed>|null>|null
     * @throws BindingException
     */
    public static function objectArray(array $data, string $key, string $struct, string $elemGoType): ?array
    {
        if (!self::has($data, $key)) {
            return null;
        }
        $value = $data[$key];
        if (!is_array($value) || (count($value) > 0 && !array_is_list($value))) {
            throw self::typeError(self::kindOf($value), $struct, $key, '[]' . $elemGoType);
        }
        $out = [];
        foreach ($value as $item) {
            if ($item === null) {
                $out[] = null;
                continue;
            }
            if (!is_array($item) || (count($item) > 0 && array_is_list($item))) {
                throw self::typeError(self::kindOf($item), $struct, $key, $elemGoType);
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Go nested value struct: the object, or null for a missing/null key
     * (which leaves the zero struct).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     * @throws BindingException
     */
    public static function object(array $data, string $key, string $struct, string $goType): ?array
    {
        if (!self::has($data, $key)) {
            return null;
        }
        $value = $data[$key];
        if (!is_array($value) || (count($value) > 0 && array_is_list($value))) {
            throw self::typeError(self::kindOf($value), $struct, $key, $goType);
        }

        return $value;
    }

    /**
     * Go `time.Time` / `*time.Time`: an RFC 3339 string; null for a
     * missing/null key (zero time / nil pointer).
     *
     * @param array<string, mixed> $data
     * @throws BindingException
     */
    public static function time(array $data, string $key, string $struct): ?\DateTimeImmutable
    {
        if (!self::has($data, $key)) {
            return null;
        }
        $value = $data[$key];
        if (!is_string($value)) {
            throw self::typeError(self::kindOf($value), $struct, $key, 'time.Time');
        }
        $parsed = self::parseRFC3339($value);
        if ($parsed === null) {
            throw new BindingException(sprintf('parsing time "\\"%s\\"" as "\\"%s\\"": cannot parse "%s\\"" as "%s"', $value, self::TimeLayout, $value, self::TimeLayout));
        }

        return $parsed;
    }

    /**
     * Go `*big.Int`: a JSON integer literal (big.Int.UnmarshalJSON rejects
     * quoted strings and fractions); null for a missing/null key.
     *
     * @param array<string, mixed> $data
     * @throws BindingException
     */
    public static function bigInt(array $data, string $key, string $struct): ?BigInteger
    {
        if (!self::has($data, $key)) {
            return null;
        }
        $value = $data[$key];
        if (is_int($value)) {
            return BigInteger::of($value);
        }
        if (is_string($value) && self::isBigIntLiteral($value)) {
            return BigInteger::of($value);
        }
        if (is_float($value)) {
            throw new BindingException(sprintf('math/big: cannot unmarshal "%s" into a *big.Int', ModelHelpers::goFloatString($value)));
        }
        if (is_string($value)) {
            throw new BindingException(sprintf('math/big: cannot unmarshal "\\"%s\\"" into a *big.Int', $value));
        }
        throw self::typeError(self::kindOf($value), $struct, $key, '*big.Int');
    }

    /**
     * parseRFC3339 is `time.Parse(time.RFC3339, s)`: the layout is strict
     * (date, "T", time, optional fraction, "Z" or a numeric offset) and the
     * calendar fields must be in range — PHP's lenient date rollover is
     * rejected. Returns null when Go would return an error.
     */
    public static function parseRFC3339(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value, $m) !== 1) {
            return null;
        }
        [, $year, $month, $day, $hour, $minute, $second, $fraction, $zone] = $m + [7 => '', 8 => ''];
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }
        if ((int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            return null;
        }
        if ($zone !== 'Z') {
            $zh = (int) substr($zone, 1, 2);
            $zm = (int) substr($zone, 4, 2);
            if ($zh > 23 || $zm > 59) {
                return null;
            }
        }

        // Go keeps nanoseconds; DateTimeImmutable keeps microseconds.
        $normalized = $value;
        if ($fraction !== '') {
            $digits = substr($fraction, 1, 6);
            $normalized = sprintf('%s-%s-%sT%s:%s:%s.%s%s', $year, $month, $day, $hour, $minute, $second, str_pad($digits, 6, '0'), $zone);
        }
        try {
            return new \DateTimeImmutable($normalized);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * formatRFC3339 is `t.Format(time.RFC3339)`: no fractional seconds, "Z"
     * for UTC, the parsed offset otherwise.
     */
    public static function formatRFC3339(\DateTimeImmutable $t): string
    {
        $offset = $t->format('P');
        if ($offset === '+00:00') {
            $offset = 'Z';
        }

        return $t->format('Y-m-d\TH:i:s') . $offset;
    }
}
