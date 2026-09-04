<?php

declare(strict_types=1);

namespace Blnk\Model;

use Brick\Math\BigInteger;

/**
 * AlertCondition is the trigger condition of a BalanceMonitor
 * (Go: model.AlertCondition, balance.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class AlertCondition implements \JsonSerializable
{
    public float $value = 0.0;

    public float $precision = 0.0;

    /** Go: *big.Int `json:"precise_value"`. */
    public ?BigInteger $preciseValue = null;

    public string $field = '';

    public string $operator = '';

    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->value = (float) ($data['value'] ?? 0.0);
        $c->precision = (float) ($data['precision'] ?? 0.0);
        $c->preciseValue = ModelHelpers::bigIntegerFromJson($data['precise_value'] ?? null);
        $c->field = (string) ($data['field'] ?? '');
        $c->operator = (string) ($data['operator'] ?? '');
        return $c;
    }

    public function jsonSerialize(): array
    {
        return [
            'value' => $this->value,
            'precision' => $this->precision,
            'precise_value' => ModelHelpers::bigIntegerToJson($this->preciseValue),
            'field' => $this->field,
            'operator' => $this->operator,
        ];
    }
}
