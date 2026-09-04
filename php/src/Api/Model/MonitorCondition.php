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
 * MonitorCondition is the `condition` object of a CreateBalanceMonitor
 * payload (Go: api/model/balance.go `MonitorCondition`).
 */
final class MonitorCondition implements \JsonSerializable
{
    /** The balance fields a monitor may watch (ozzo `validation.In(...)`). */
    public const FIELDS = ['debit_balance', 'credit_balance', 'balance', 'inflight_debit_balance', 'inflight_credit_balance', 'inflight_balance'];

    public float $precision = 0.0;

    public string $field = '';

    public string $operator = '';

    public float $value = 0.0;

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'MonitorCondition'): self
    {
        $c = new self();
        $c->precision = JsonBinding::float($data, 'precision', $struct);
        $c->field = JsonBinding::string($data, 'field', $struct);
        $c->operator = JsonBinding::string($data, 'operator', $struct);
        $c->value = JsonBinding::float($data, 'value', $struct);

        return $c;
    }

    /**
     * ValidateMonitorCondition runs the ozzo-validation rules of model.go:
     * field is required and must be a known balance field; operator,
     * precision and value are required.
     *
     * @throws ValidationErrors
     */
    public function validateMonitorCondition(): void
    {
        ModelHelpers::validateStruct([
            'field' => function (): void {
                ModelHelpers::required($this->field);
                // validation.In(...): "must be a valid value"
                if (!in_array($this->field, self::FIELDS, true)) {
                    throw new \RuntimeException('must be a valid value');
                }
            },
            'operator' => function (): void {
                ModelHelpers::required($this->operator);
            },
            'precision' => function (): void {
                ModelHelpers::required($this->precision);
            },
            'value' => function (): void {
                ModelHelpers::required($this->value);
            },
        ]);
    }

    public function jsonSerialize(): array
    {
        return [
            'precision' => $this->precision,
            'field' => $this->field,
            'operator' => $this->operator,
            'value' => $this->value,
        ];
    }
}
