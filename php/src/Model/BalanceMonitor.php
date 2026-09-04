<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * BalanceMonitor watches a balance and fires when its condition holds
 * (Go: model.BalanceMonitor, balance.go; CheckCondition in model.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class BalanceMonitor implements \JsonSerializable
{
    public string $monitorID = '';

    public string $balanceID = '';

    public string $description = '';

    /** Go json:"-". */
    public string $callBackURL = '';

    public ?\DateTimeImmutable $createdAt = null;

    /**
     * Go: value struct `Condition AlertCondition json:"condition"` — always
     * present; a null here stands for the zero-value condition.
     */
    public ?AlertCondition $condition = null;

    /**
     * CheckCondition checks if a balance meets the condition specified by a BalanceMonitor.
     * It compares various balance fields (e.g., debit balance, credit balance) against the precise value.
     *
     * As in Go — where a nil *big.Int would panic inside compare — a null
     * compared balance field or precise value fails hard (\TypeError from the
     * typed compare() parameters).
     */
    public function checkCondition(Balance $b): bool
    {
        $condition = $this->condition ?? new AlertCondition();
        switch ($condition->field) {
            case 'debit_balance':
                return ModelHelpers::compare($b->debitBalance, $condition->operator, $condition->preciseValue);
            case 'credit_balance':
                return ModelHelpers::compare($b->creditBalance, $condition->operator, $condition->preciseValue);
            case 'balance':
                return ModelHelpers::compare($b->balance, $condition->operator, $condition->preciseValue);
            case 'inflight_debit_balance':
                return ModelHelpers::compare($b->inflightDebitBalance, $condition->operator, $condition->preciseValue);
            case 'inflight_credit_balance':
                return ModelHelpers::compare($b->inflightCreditBalance, $condition->operator, $condition->preciseValue);
            case 'inflight_balance':
                return ModelHelpers::compare($b->inflightBalance, $condition->operator, $condition->preciseValue);
        }
        return false;
    }

    public static function fromArray(array $data): self
    {
        $m = new self();
        $m->monitorID = (string) ($data['monitor_id'] ?? '');
        $m->balanceID = (string) ($data['balance_id'] ?? '');
        $m->description = (string) ($data['description'] ?? '');
        $m->createdAt = ModelHelpers::parseTime($data['created_at'] ?? null);
        $m->condition = isset($data['condition']) && \is_array($data['condition'])
            ? AlertCondition::fromArray($data['condition'])
            : null;
        return $m;
    }

    public function jsonSerialize(): array
    {
        $out = [];
        $out['monitor_id'] = $this->monitorID;
        $out['balance_id'] = $this->balanceID;
        if ($this->description !== '') { // omitempty
            $out['description'] = $this->description;
        }
        $out['created_at'] = ModelHelpers::goTimeString($this->createdAt);
        $out['condition'] = $this->condition ?? new AlertCondition(); // Go value struct: always serialized
        return $out;
    }
}
