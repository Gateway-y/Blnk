<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * Reconciliation is one reconciliation run over an upload
 * (Go: model.Reconciliation, reconciliation_model.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class Reconciliation implements \JsonSerializable
{
    /** Go json:"-" (database serial id). */
    public int $id = 0;

    public string $reconciliationID = '';

    public string $uploadID = '';

    public string $status = '';

    public int $matchedTransactions = 0;

    public int $unmatchedTransactions = 0;

    public bool $isDryRun = false;

    public ?\DateTimeImmutable $startedAt = null;

    /** Go: *time.Time `json:"completed_at"` (no omitempty → null when nil). */
    public ?\DateTimeImmutable $completedAt = null;

    public static function fromArray(array $data): self
    {
        $r = new self();
        $r->reconciliationID = (string) ($data['reconciliation_id'] ?? '');
        $r->uploadID = (string) ($data['upload_id'] ?? '');
        $r->status = (string) ($data['status'] ?? '');
        $r->matchedTransactions = (int) ($data['matched_transactions'] ?? 0);
        $r->unmatchedTransactions = (int) ($data['unmatched_transactions'] ?? 0);
        $r->isDryRun = (bool) ($data['is_dry_run'] ?? false);
        $r->startedAt = ModelHelpers::parseTime($data['started_at'] ?? null);
        $r->completedAt = ModelHelpers::parseTime($data['completed_at'] ?? null);
        return $r;
    }

    public function jsonSerialize(): array
    {
        return [
            'reconciliation_id' => $this->reconciliationID,
            'upload_id' => $this->uploadID,
            'status' => $this->status,
            'matched_transactions' => $this->matchedTransactions,
            'unmatched_transactions' => $this->unmatchedTransactions,
            'is_dry_run' => $this->isDryRun,
            'started_at' => ModelHelpers::goTimeString($this->startedAt),
            'completed_at' => $this->completedAt === null ? null : ModelHelpers::goTimeString($this->completedAt),
        ];
    }
}
