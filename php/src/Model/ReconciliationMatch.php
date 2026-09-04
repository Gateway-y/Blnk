<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * ReconciliationMatch pairs an external transaction with an internal one
 * during reconciliation (Go: model.Match, reconciliation_model.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 *
 * NOTE — documented divergence: the Go type is named `Match`, but `match` is a
 * reserved word in PHP >= 8.0 and cannot be used as a class name, so the class
 * is `ReconciliationMatch` here. The Go struct has no JSON tags; serialization
 * still uses the original Go field names (ExternalTransactionID, ...).
 */
final class ReconciliationMatch implements \JsonSerializable
{
    public string $externalTransactionID = '';

    public string $internalTransactionID = '';

    public string $reconciliationID = '';

    public float $amount = 0.0;

    public ?\DateTimeImmutable $date = null;

    public static function fromArray(array $data): self
    {
        $m = new self();
        $m->externalTransactionID = (string) ($data['ExternalTransactionID'] ?? '');
        $m->internalTransactionID = (string) ($data['InternalTransactionID'] ?? '');
        $m->reconciliationID = (string) ($data['ReconciliationID'] ?? '');
        $m->amount = (float) ($data['Amount'] ?? 0.0);
        $m->date = ModelHelpers::parseTime($data['Date'] ?? null);
        return $m;
    }

    public function jsonSerialize(): array
    {
        return [
            'ExternalTransactionID' => $this->externalTransactionID,
            'InternalTransactionID' => $this->internalTransactionID,
            'ReconciliationID' => $this->reconciliationID,
            'Amount' => $this->amount,
            'Date' => ModelHelpers::goTimeString($this->date),
        ];
    }
}
