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
 * ModelHelpers hosts the package-level symbols of the Go `api/model`
 * package (the standalone validation functions of model.go and the request
 * limits of transaction.go) as static members, following the same rule as
 * Blnk\Model\ModelHelpers for the `model` package (PORTING.md "Naming").
 *
 * The ozzo-validation rules used by model.go (`validation.Required`,
 * `validation.By`, `validation.When`) are reproduced here as throwing
 * helpers: a rule throws a \RuntimeException carrying ozzo's message and the
 * request models collect those into a {@see ValidationErrors} keyed by the
 * field's JSON tag name, exactly like `validation.ValidateStruct`.
 */
final class ModelHelpers
{
    /**
     * MaxBulkInflightItems caps the number of transactions accepted in a single
     * bulk commit or bulk void call. Bulk calls are processed synchronously, so
     * the cap exists to keep request latency and lock-holding bounded.
     */
    public const MaxBulkInflightItems = 100;

    /**
     * MaxBulkTransactionItems caps the number of transactions accepted in a single
     * CreateBulkTransactions request. The whole payload is held in memory, so the
     * cap bounds memory use; it is larger than the inflight cap because bulk
     * creates can run asynchronously.
     */
    public const MaxBulkTransactionItems = 10000;

    /**
     * MaxInstantReconciliationItems caps the number of external_transactions
     * accepted in a single instant-reconciliation request. The whole array is
     * held in memory, so the cap bounds memory use.
     */
    public const MaxInstantReconciliationItems = 10000;

    /** ozzo-validation `ErrRequired` message ("validation_required"). */
    public const ErrRequiredMessage = 'cannot be blank';

    /** Not instantiable: static helpers only. */
    private function __construct()
    {
    }

    /**
     * validatePrecisionIsInteger is the `validation.By` rule applied to
     * RecordTransaction.Precision (Go receives the field as interface{}).
     *
     * @throws \RuntimeException "invalid precision type" for a non-float value
     * @throws PrecisionMustBeIntegerException when the precision has a fractional part
     */
    public static function validatePrecisionIsInteger(mixed $value): void
    {
        if (\is_int($value)) {
            $value = (float) $value;
        }
        if (!\is_float($value)) {
            throw new \RuntimeException('invalid precision type');
        }
        $precision = $value;

        // Go: math.Trunc(precision) != precision (NaN never equals itself; ±Inf truncates to itself).
        if (is_nan($precision) || (is_finite($precision) && floor($precision) != $precision)) {
            throw new PrecisionMustBeIntegerException();
        }
    }

    /**
     * sourceOrSourcesValidation returns the rule enforcing that exactly one
     * of `source` / `sources` is supplied.
     *
     * @return \Closure(): void
     */
    public static function sourceOrSourcesValidation(RecordTransaction $t): \Closure
    {
        return static function () use ($t): void {
            if (($t->source === '' && \count($t->sources) === 0) || ($t->source !== '' && \count($t->sources) > 0)) {
                throw new \RuntimeException('either source or sources is required, not both');
            }
        };
    }

    /**
     * destinationOrDestinationsValidation returns the rule enforcing that
     * exactly one of `destination` / `destinations` is supplied.
     *
     * @return \Closure(): void
     */
    public static function destinationOrDestinationsValidation(RecordTransaction $t): \Closure
    {
        return static function () use ($t): void {
            if (($t->destination === '' && \count($t->destinations) === 0) || ($t->destination !== '' && \count($t->destinations) > 0)) {
                throw new \RuntimeException('either destination or destinations is required, not both');
            }
        };
    }

    /**
     * validateDateFormat checks that value parses with the Go time layout
     * (only time.RFC3339, the layout model.go uses, is supported).
     *
     * @throws \RuntimeException
     */
    public static function validateDateFormat(string $format, string $value): void
    {
        try {
            if ($format === JsonBinding::RFC3339) {
                JsonBinding::parseRFC3339($value);
                return;
            }
            // Other layouts are not used by the api/model package.
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed === false) {
                throw new \RuntimeException('invalid date');
            }
        } catch (\Throwable) {
            throw new \RuntimeException("please format the scheduled date as 'YYYY-MM-DDTHH:MM:SS+00:00' (e.g., 2024-04-22T15:28:03+00:00)");
        }
    }

    /**
     * required is ozzo's `validation.Required` rule for the value kinds the
     * api models validate: empty strings, zero numbers, empty arrays, null
     * values and unset dates fail with "cannot be blank".
     *
     * @throws \RuntimeException
     */
    public static function required(mixed $value): void
    {
        $empty = match (true) {
            $value === null => true,
            \is_string($value) => $value === '',
            \is_int($value), \is_float($value) => $value == 0,
            \is_bool($value) => $value === false,
            \is_array($value) => \count($value) === 0,
            default => false,
        };
        if ($empty) {
            throw new \RuntimeException(self::ErrRequiredMessage);
        }
    }

    /**
     * validateStruct mirrors `validation.ValidateStruct`: every rule is run,
     * failures are collected under their field's JSON tag name, and a
     * {@see ValidationErrors} is thrown when at least one rule failed.
     *
     * @param array<string, callable(): void> $fieldRules JSON field name → rule (throws \RuntimeException on failure)
     *
     * @throws ValidationErrors
     */
    public static function validateStruct(array $fieldRules): void
    {
        $errs = [];
        foreach ($fieldRules as $field => $rule) {
            try {
                $rule();
            } catch (\RuntimeException $e) {
                $errs[(string) $field] = $e;
            }
        }
        if (\count($errs) > 0) {
            throw new ValidationErrors($errs);
        }
    }
}
