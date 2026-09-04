<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * ReconciliationProgress tracks how far a reconciliation run has processed
 * (Go: model.ReconciliationProgress, reconciliation_model.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class ReconciliationProgress implements \JsonSerializable
{
    public string $lastProcessedExternalTxnID = '';

    public int $processedCount = 0;

    public static function fromArray(array $data): self
    {
        $p = new self();
        $p->lastProcessedExternalTxnID = (string) ($data['last_processed_external_txn_id'] ?? '');
        $p->processedCount = (int) ($data['processed_count'] ?? 0);
        return $p;
    }

    public function jsonSerialize(): array
    {
        return [
            'last_processed_external_txn_id' => $this->lastProcessedExternalTxnID,
            'processed_count' => $this->processedCount,
        ];
    }
}
