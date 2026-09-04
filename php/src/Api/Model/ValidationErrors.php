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

/**
 * ValidationErrors ports ozzo-validation's `validation.Errors` (the error
 * returned by `validation.ValidateStruct`): a map of JSON field name to the
 * error of that field. Its message is formatted exactly like
 * `Errors.Error()` — keys sorted, "key: message" joined by "; ", nested
 * Errors rendered as "key: (…)", and a trailing period — since the API
 * handlers echo it verbatim.
 *
 * As in Go, the map does not unwrap to its values (`errors.Is` on the map
 * never matches a field error); callers index the map instead, e.g.
 * `$errs['precision']` (Go: `validationErrors["precision"]`).
 *
 * @implements \ArrayAccess<string, \Throwable>
 */
final class ValidationErrors extends \RuntimeException implements \ArrayAccess, \Countable, \JsonSerializable
{
    /** @var array<string, \Throwable> */
    public array $errors;

    /**
     * @param array<string, \Throwable> $errors
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct(self::format($errors));
    }

    /**
     * format renders the map like ozzo's Errors.Error().
     *
     * @param array<string, \Throwable> $errors
     */
    public static function format(array $errors): string
    {
        if (count($errors) === 0) {
            return '';
        }
        $keys = array_keys($errors);
        sort($keys, SORT_STRING);
        $parts = [];
        foreach ($keys as $key) {
            $err = $errors[$key];
            if ($err instanceof self) {
                $parts[] = sprintf('%s: (%s)', $key, $err->getMessage());
            } else {
                $parts[] = sprintf('%s: %s', $key, $err->getMessage());
            }
        }

        return implode('; ', $parts) . '.';
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->errors[$offset]);
    }

    public function offsetGet(mixed $offset): ?\Throwable
    {
        return $this->errors[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->errors[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->errors[$offset]);
    }

    public function count(): int
    {
        return count($this->errors);
    }

    /**
     * MarshalJSON converts the Errors into a valid JSON: {"field": "message"}
     * (nested Errors become nested objects).
     */
    public function jsonSerialize(): array
    {
        $out = [];
        foreach ($this->errors as $key => $err) {
            $out[$key] = $err instanceof self ? $err->jsonSerialize() : $err->getMessage();
        }

        return $out;
    }
}
