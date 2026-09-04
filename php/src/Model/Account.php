<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * Account is a customer-facing account bound to a balance, identity and ledger
 * (Go: model.Account, accouunt.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class Account implements \JsonSerializable
{
    public string $accountID = '';

    public string $name = '';

    public string $number = '';

    public string $bankName = '';

    public string $currency = '';

    public string $balanceID = '';

    public string $identityID = '';

    public string $ledgerID = '';

    public ?Ledger $ledger = null;

    public ?Balance $balance = null;

    public ?Identity $identity = null;

    public ?\DateTimeImmutable $createdAt = null;

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    public static function fromArray(array $data): self
    {
        $a = new self();
        $a->accountID = (string) ($data['account_id'] ?? '');
        $a->name = (string) ($data['name'] ?? '');
        $a->number = (string) ($data['number'] ?? '');
        $a->bankName = (string) ($data['bank_name'] ?? '');
        $a->currency = (string) ($data['currency'] ?? '');
        $a->balanceID = (string) ($data['balance_id'] ?? '');
        $a->identityID = (string) ($data['identity_id'] ?? '');
        $a->ledgerID = (string) ($data['ledger_id'] ?? '');
        $a->ledger = isset($data['ledger']) && \is_array($data['ledger']) ? Ledger::fromArray($data['ledger']) : null;
        $a->balance = isset($data['balance']) && \is_array($data['balance']) ? Balance::fromArray($data['balance']) : null;
        $a->identity = isset($data['identity']) && \is_array($data['identity']) ? Identity::fromArray($data['identity']) : null;
        $a->createdAt = ModelHelpers::parseTime($data['created_at'] ?? null);
        $metaData = $data['meta_data'] ?? null;
        $a->metaData = \is_array($metaData) ? $metaData : null;
        return $a;
    }

    public function jsonSerialize(): array
    {
        return [
            'account_id' => $this->accountID,
            'name' => $this->name,
            'number' => $this->number,
            'bank_name' => $this->bankName,
            'currency' => $this->currency,
            'balance_id' => $this->balanceID,
            'identity_id' => $this->identityID,
            'ledger_id' => $this->ledgerID,
            'ledger' => $this->ledger,     // *Ledger, no omitempty → null when nil
            'balance' => $this->balance,   // *Balance, no omitempty → null when nil
            'identity' => $this->identity, // *Identity, no omitempty → null when nil
            'created_at' => ModelHelpers::goTimeString($this->createdAt),
            'meta_data' => ModelHelpers::mapToJson($this->metaData),
        ];
    }
}
