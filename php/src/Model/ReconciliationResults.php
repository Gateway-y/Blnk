<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * ReconciliationResults is the reportable outcome of a reconciliation run
 * (Go: model.ReconciliationResults, reconciliation_model.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class ReconciliationResults implements \JsonSerializable
{
    public string $reconciliationID = '';

    public string $status = '';

    public ?\DateTimeImmutable $startedAt = null;

    /** Go: *time.Time `json:"completed_at,omitempty"`. */
    public ?\DateTimeImmutable $completedAt = null;

    /**
     * Go: `[]Match` — a nil slice marshals as null, so the property is nullable.
     * (Match is ported as ReconciliationMatch: `match` is reserved in PHP 8.)
     *
     * @var ReconciliationMatch[]|null
     */
    public ?array $matchedTransactions = null;

    /**
     * Go: `[]string` — a nil slice marshals as null, so the property is nullable.
     *
     * @var string[]|null
     */
    public ?array $unmatchedTransactions = null;

    public static function fromArray(array $data): self
    {
        $r = new self();
        $r->reconciliationID = (string) ($data['reconciliation_id'] ?? '');
        $r->status = (string) ($data['status'] ?? '');
        $r->startedAt = ModelHelpers::parseTime($data['started_at'] ?? null);
        $r->completedAt = ModelHelpers::parseTime($data['completed_at'] ?? null);
        if (isset($data['matched_transactions']) && \is_array($data['matched_transactions'])) {
            $r->matchedTransactions = [];
            foreach ($data['matched_transactions'] as $match) {
                if (\is_array($match)) {
                    $r->matchedTransactions[] = ReconciliationMatch::fromArray($match);
                }
            }
        }
        if (isset($data['unmatched_transactions']) && \is_array($data['unmatched_transactions'])) {
            $r->unmatchedTransactions = array_map(strval(...), array_values($data['unmatched_transactions']));
        }
        return $r;
    }

    public function jsonSerialize(): array
    {
        $out = [];
        $out['reconciliation_id'] = $this->reconciliationID;
        $out['status'] = $this->status;
        $out['started_at'] = ModelHelpers::goTimeString($this->startedAt);
        if ($this->completedAt !== null) { // omitempty (*time.Time)
            $out['completed_at'] = ModelHelpers::goTimeString($this->completedAt);
        }
        $out['matched_transactions'] = $this->matchedTransactions === null ? null : array_values($this->matchedTransactions);
        $out['unmatched_transactions'] = $this->unmatchedTransactions === null ? null : array_values($this->unmatchedTransactions);
        return $out;
    }
}
