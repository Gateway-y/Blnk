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
use Blnk\Model\AlertCondition;
use Blnk\Model\BalanceMonitor;
use Blnk\Model\ModelHelpers as CoreModelHelpers;

/**
 * CreateBalanceMonitor is the request payload of POST /balance-monitors
 * (Go: api/model/balance.go `CreateBalanceMonitor`).
 */
final class CreateBalanceMonitor implements \JsonSerializable
{
    public string $balanceId = '';

    /** Go value struct: always present (the zero condition when absent). */
    public MonitorCondition $condition;

    public string $callBackURL = '';

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    public function __construct()
    {
        $this->condition = new MonitorCondition();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'CreateBalanceMonitor'): self
    {
        $b = new self();
        $b->balanceId = JsonBinding::string($data, 'balance_id', $struct);
        $condition = Binding::object($data, 'condition', $struct, 'model.MonitorCondition');
        if ($condition !== null) {
            $b->condition = MonitorCondition::fromArray($condition, $struct . '.condition');
        }
        $b->callBackURL = JsonBinding::string($data, 'call_back_url', $struct);
        $b->metaData = JsonBinding::map($data, 'meta_data', $struct);

        return $b;
    }

    /**
     * ValidateCreateBalanceMonitor runs the ozzo-validation rules of model.go:
     * balance_id is required and the condition must pass
     * {@see MonitorCondition::validateMonitorCondition()} (its errors are
     * nested under "condition").
     *
     * @throws ValidationErrors
     */
    public function validateCreateBalanceMonitor(): void
    {
        ModelHelpers::validateStruct([
            'balance_id' => function (): void {
                ModelHelpers::required($this->balanceId);
            },
            'condition' => function (): void {
                // validation.Required never fails for a struct value; validation.By(...)
                // then calls ValidateMonitorCondition on the MonitorCondition.
                $this->condition->validateMonitorCondition();
            },
        ]);
    }

    /**
     * ToBalanceMonitor converts the request payload into the domain BalanceMonitor.
     */
    public function toBalanceMonitor(): BalanceMonitor
    {
        $condition = new AlertCondition();
        $condition->field = $this->condition->field;
        $condition->operator = $this->condition->operator;
        $condition->value = $this->condition->value;
        $condition->precision = $this->condition->precision;

        $monitor = new BalanceMonitor();
        $monitor->balanceID = $this->balanceId;
        $monitor->condition = $condition;
        $monitor->callBackURL = $this->callBackURL;

        return $monitor;
    }

    public function jsonSerialize(): array
    {
        return [
            'balance_id' => $this->balanceId,
            'condition' => $this->condition,
            'call_back_url' => $this->callBackURL,
            'meta_data' => CoreModelHelpers::mapToJson($this->metaData),
        ];
    }
}
