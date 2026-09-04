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

use Blnk\Api\Model\JsonBinding;
use Blnk\Model\ModelHelpers;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Binding ports Gin's request-level JSON binding (`c.ShouldBindJSON` /
 * `c.BindJSON`) onto PSR-7 requests. The decoding semantics of encoding/json
 * (empty body → "EOF", non-object documents, field type mismatches, the
 * `binding:"required"` validator messages) live in
 * {@see \Blnk\Api\Model\JsonBinding}, which the api models' fromArray()
 * methods use; this class only adds the request plumbing plus the two field
 * readers JsonBinding does not provide (Go `int` fields and nested value
 * structs).
 *
 * Every failure is thrown as a {@see BindingException} whose message is what
 * the Go handlers echo to the client.
 */
final class Binding
{
    /** Go's RFC 3339 layout, as printed in time parsing errors. */
    public const TimeLayout = JsonBinding::RFC3339;

    private function __construct()
    {
    }

    /**
     * rawBody reads the whole request body (Go: the decoder consuming
     * c.Request.Body).
     *
     * @throws BindingException when the body cannot be read — e.g. the
     *                          RequestSizeLimit middleware's "http: request body too large"
     */
    public static function rawBody(ServerRequestInterface $request): string
    {
        $body = $request->getBody();
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            return $body->getContents();
        } catch (\Throwable $e) {
            throw new BindingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * shouldBindJSON is the port of `c.ShouldBindJSON(&v)` for a struct
     * target: decodes the request body as one JSON object and returns it as an
     * associative array for the model's fromArray().
     *
     * @param string $goType the Go type name reported for a non-object document, e.g. "model.CreateLedger"
     * @return array<string, mixed>
     * @throws BindingException on an empty body ("EOF"), malformed JSON, a non-object document or an unreadable body
     */
    public static function shouldBindJSON(ServerRequestInterface $request, string $goType): array
    {
        try {
            return JsonBinding::decode(self::rawBody($request), $goType);
        } catch (BindingException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new BindingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * shouldBindJSONValue decodes a body whose Go target is not a struct (any
     * JSON value is accepted and returned as decoded).
     *
     * @throws BindingException on an empty body ("EOF"), malformed JSON or an unreadable body
     */
    public static function shouldBindJSONValue(ServerRequestInterface $request): mixed
    {
        $raw = self::rawBody($request);
        if (trim($raw) === '') {
            throw new BindingException(JsonBinding::EOF);
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new BindingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * bindJSON is the port of `c.BindJSON(&v)`: like shouldBindJSON, but a
     * failure is also recorded in the request error list (Gin's
     * `c.AbortWithError(400, err)`); the caller still writes the response.
     *
     * @return array<string, mixed>
     * @throws BindingException
     */
    public static function bindJSON(ServerRequestInterface $request, string $goType): array
    {
        try {
            return self::shouldBindJSON($request, $goType);
        } catch (BindingException $e) {
            RequestErrors::add();
            throw $e;
        }
    }

    /**
     * isEOF reports whether a binding error is the io.EOF of an empty body
     * (Go: `errors.Is(err, io.EOF)`).
     */
    public static function isEOF(\Throwable $err): bool
    {
        return JsonBinding::isEOF($err);
    }

    /**
     * typeError builds encoding/json's UnmarshalTypeError message.
     */
    public static function typeError(mixed $value, string $struct, string $field, string $goType): BindingException
    {
        return new BindingException(sprintf('json: cannot unmarshal %s into Go struct field %s.%s of type %s', JsonBinding::jsonTypeName($value), $struct, $field, $goType));
    }

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
     * int binds a Go `int` field: only integer literals are accepted (`1.5`
     * fails with Go's "cannot unmarshal number 1.5 into ... of type int");
     * absent or null → 0.
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
        if (is_float($value) || (is_string($value) && JsonBinding::jsonTypeName($value) === 'number')) {
            throw new BindingException(sprintf('json: cannot unmarshal number %s into Go struct field %s.%s of type int', is_float($value) ? ModelHelpers::goFloatString($value) : $value, $struct, $key));
        }
        throw self::typeError($value, $struct, $key, 'int');
    }

    /**
     * object binds a nested Go value struct: the JSON object as an associative
     * array, or null for an absent/null key (which leaves the zero struct).
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
            throw self::typeError($value, $struct, $key, $goType);
        }

        return $value;
    }

    /**
     * parseRFC3339 is `time.Parse(time.RFC3339, s)`; null stands for Go's
     * error return.
     */
    public static function parseRFC3339(string $value): ?\DateTimeImmutable
    {
        try {
            return JsonBinding::parseRFC3339($value);
        } catch (\RuntimeException) {
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
