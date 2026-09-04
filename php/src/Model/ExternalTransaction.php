<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * ExternalTransaction is a transaction imported from an external source for
 * reconciliation (Go: model.ExternalTransaction, reconciliation_model.go;
 * ToInternalTransaction in model.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class ExternalTransaction implements \JsonSerializable
{
    public string $id = '';

    public float $amount = 0.0;

    public string $reference = '';

    public string $currency = '';

    public string $description = '';

    public ?\DateTimeImmutable $date = null;

    public string $source = '';

    /**
     * ToInternalTransaction converts an ExternalTransaction to an InternalTransaction.
     * This is useful when reconciling external transactions with internal records.
     */
    public function toInternalTransaction(): Transaction
    {
        $t = new Transaction();
        $t->transactionID = $this->id;
        $t->amount = $this->amount;
        $t->reference = $this->reference;
        $t->currency = $this->currency;
        $t->createdAt = $this->date;
        $t->description = $this->description;
        return $t;
    }

    public static function fromArray(array $data): self
    {
        $e = new self();
        $e->id = (string) ($data['id'] ?? '');
        $e->amount = (float) ($data['amount'] ?? 0.0);
        $e->reference = (string) ($data['reference'] ?? '');
        $e->currency = (string) ($data['currency'] ?? '');
        $e->description = (string) ($data['description'] ?? '');
        $e->date = ModelHelpers::parseTime($data['date'] ?? null);
        $e->source = (string) ($data['source'] ?? '');
        return $e;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'currency' => $this->currency,
            'description' => $this->description,
            'date' => ModelHelpers::goTimeString($this->date),
            'source' => $this->source,
        ];
    }
}
