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
 * Validation is a minimal port of the ozzo-validation (v4) surface the api
 * models use: `ValidateStruct`, `Field`, `Required`, `In`, `When` and `By`,
 * with the library's messages ("cannot be blank", "must be a valid value")
 * and its `IsEmpty` semantics.
 *
 * A rule is a closure `fn(mixed $value): ?\Throwable` returning the field
 * error or null. Rules run in order; the first failing rule decides the
 * field's error, and — like ozzo's ValidateStruct, which stores errors in a
 * map keyed by JSON field name — a later `Field` entry for the same name
 * overwrites an earlier one.
 */
final class Validation
{
    /** ozzo `ErrRequired` message. */
    public const RequiredMessage = 'cannot be blank';

    /** ozzo `ErrInInvalid` message. */
    public const InMessage = 'must be a valid value';

    private function __construct()
    {
    }

    /**
     * IsEmpty checks if a value is empty or not (ozzo `validation.IsEmpty`):
     * "" / [] / null / false / 0 / the zero time. Structs (objects other than
     * a time) are never empty.
     */
    public static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) || is_array($value)) {
            return count(is_array($value) ? $value : [$value]) === 0 || $value === '';
        }
        if (is_bool($value)) {
            return !$value;
        }
        if (is_int($value) || is_float($value)) {
            return $value == 0;
        }

        return false;
    }

    /**
     * ValidateStruct validates a struct's fields (ozzo `validation.ValidateStruct`).
     *
     * @param list<array{0: string, 1: mixed, 2?: callable, 3?: callable}> $fields
     *        each entry is [jsonFieldName, value, rule, rule, ...] (ozzo `validation.Field`)
     * @throws ValidationErrors when at least one field fails
     */
    public static function validateStruct(array $fields): void
    {
        $errs = [];
        foreach ($fields as $field) {
            $name = (string) $field[0];
            $value = $field[1];
            $rules = array_slice($field, 2);
            $err = self::validate($value, ...$rules);
            if ($err !== null) {
                $errs[$name] = $err;
            }
        }
        if (count($errs) > 0) {
            throw new ValidationErrors($errs);
        }
    }

    /**
     * Validate validates a value with the given rules (ozzo `validation.Validate`):
     * the first failing rule's error is returned, null when all pass.
     *
     * @param callable(mixed): ?\Throwable ...$rules
     */
    public static function validate(mixed $value, callable ...$rules): ?\Throwable
    {
        foreach ($rules as $rule) {
            $err = $rule($value);
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }

    /**
     * Required (ozzo `validation.Required`): fails when the value is empty.
     * `.Error(msg)` is the optional message override.
     *
     * @return \Closure(mixed): ?\Throwable
     */
    public static function required(?string $message = null): \Closure
    {
        $message ??= self::RequiredMessage;

        return static fn (mixed $value): ?\Throwable => self::isEmpty($value) ? new \RuntimeException($message) : null;
    }

    /**
     * In (ozzo `validation.In`): the value must be one of the given values.
     * An empty value is considered valid, as in ozzo.
     *
     * @param list<mixed> $values
     * @return \Closure(mixed): ?\Throwable
     */
    public static function in(array $values, ?string $message = null): \Closure
    {
        $message ??= self::InMessage;

        return static function (mixed $value) use ($values, $message): ?\Throwable {
            if (self::isEmpty($value)) {
                return null;
            }
            foreach ($values as $candidate) {
                if ($candidate === $value) {
                    return null;
                }
            }

            return new \RuntimeException($message);
        };
    }

    /**
     * When (ozzo `validation.When`): applies the rules only when the condition holds.
     *
     * @param callable(mixed): ?\Throwable ...$rules
     * @return \Closure(mixed): ?\Throwable
     */
    public static function when(bool $condition, callable ...$rules): \Closure
    {
        return static fn (mixed $value): ?\Throwable => $condition ? self::validate($value, ...$rules) : null;
    }

    /**
     * By (ozzo `validation.By`): wraps a custom rule function.
     *
     * @param callable(mixed): ?\Throwable $fn
     * @return \Closure(mixed): ?\Throwable
     */
    public static function by(callable $fn): \Closure
    {
        return static fn (mixed $value): ?\Throwable => $fn($value);
    }
}
