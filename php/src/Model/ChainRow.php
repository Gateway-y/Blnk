<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * ChainRow carries the canonical, immutable fields of a transaction that the
 * hash chain seals. The chainer and the verifier both build it from the same DB
 * columns so a recomputed hash is always reproducible. NULL columns are empty
 * strings. Every field here is enforced immutable by the transactions
 * immutability trigger, so a sealed row's hash can never change under a normal
 * write.
 *
 * (Go: model.ChainRow, chain.go.)
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 *
 * The Go struct has no JSON tags; serialization uses the Go field names.
 */
final class ChainRow implements \JsonSerializable
{
    public string $transactionID = '';

    public string $source = '';

    public string $destination = '';

    /** NUMERIC rendered as text */
    public string $amount = '';

    /** NUMERIC rendered as text (the authoritative ledger amount) */
    public string $preciseAmount = '';

    public string $currency = '';

    public string $status = '';

    public string $reference = '';

    public ?\DateTimeImmutable $createdAt = null;

    public static function fromArray(array $data): self
    {
        $r = new self();
        $r->transactionID = (string) ($data['TransactionID'] ?? '');
        $r->source = (string) ($data['Source'] ?? '');
        $r->destination = (string) ($data['Destination'] ?? '');
        $r->amount = (string) ($data['Amount'] ?? '');
        $r->preciseAmount = (string) ($data['PreciseAmount'] ?? '');
        $r->currency = (string) ($data['Currency'] ?? '');
        $r->status = (string) ($data['Status'] ?? '');
        $r->reference = (string) ($data['Reference'] ?? '');
        $r->createdAt = ModelHelpers::parseTime($data['CreatedAt'] ?? null);
        return $r;
    }

    public function jsonSerialize(): array
    {
        return [
            'TransactionID' => $this->transactionID,
            'Source' => $this->source,
            'Destination' => $this->destination,
            'Amount' => $this->amount,
            'PreciseAmount' => $this->preciseAmount,
            'Currency' => $this->currency,
            'Status' => $this->status,
            'Reference' => $this->reference,
            'CreatedAt' => ModelHelpers::goTimeString($this->createdAt),
        ];
    }
}
