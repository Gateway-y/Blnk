<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * BalanceFilter carries query filters for balance listings
 * (Go: model.BalanceFilter, balance.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class BalanceFilter implements \JsonSerializable
{
    public int $id = 0;

    public string $balanceRange = '';

    public string $creditBalanceRange = '';

    public string $debitBalanceRange = '';

    public string $currency = '';

    public string $ledgerID = '';

    public ?\DateTimeImmutable $from = null;

    public ?\DateTimeImmutable $to = null;

    public static function fromArray(array $data): self
    {
        $f = new self();
        $f->id = (int) ($data['id'] ?? 0);
        $f->balanceRange = (string) ($data['balance_range'] ?? '');
        $f->creditBalanceRange = (string) ($data['credit_balance_range'] ?? '');
        $f->debitBalanceRange = (string) ($data['debit_balance_range'] ?? '');
        $f->currency = (string) ($data['currency'] ?? '');
        $f->ledgerID = (string) ($data['ledger_id'] ?? '');
        $f->from = ModelHelpers::parseTime($data['from'] ?? null);
        $f->to = ModelHelpers::parseTime($data['to'] ?? null);
        return $f;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'balance_range' => $this->balanceRange,
            'credit_balance_range' => $this->creditBalanceRange,
            'debit_balance_range' => $this->debitBalanceRange,
            'currency' => $this->currency,
            'ledger_id' => $this->ledgerID,
            'from' => ModelHelpers::goTimeString($this->from),
            'to' => ModelHelpers::goTimeString($this->to),
        ];
    }
}
