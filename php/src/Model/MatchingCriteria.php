<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * MatchingCriteria is one criterion of a reconciliation MatchingRule
 * (Go: model.MatchingCriteria, reconciliation_model.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class MatchingCriteria implements \JsonSerializable
{
    public string $field = '';

    public string $operator = '';

    public string $value = '';

    public string $pattern = '';

    public float $allowableDrift = 0.0;

    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->field = (string) ($data['field'] ?? '');
        $c->operator = (string) ($data['operator'] ?? '');
        $c->value = (string) ($data['value'] ?? '');
        $c->pattern = (string) ($data['pattern'] ?? '');
        $c->allowableDrift = (float) ($data['allowable_drift'] ?? 0.0);
        return $c;
    }

    public function jsonSerialize(): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator,
            'value' => $this->value,
            'pattern' => $this->pattern,
            'allowable_drift' => $this->allowableDrift,
        ];
    }
}
