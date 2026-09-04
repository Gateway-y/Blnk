<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * LedgerFilter carries query filters for ledger listings
 * (Go: model.LedgerFilter, ledger.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class LedgerFilter implements \JsonSerializable
{
    public int $id = 0;

    public ?\DateTimeImmutable $from = null;

    public ?\DateTimeImmutable $to = null;

    public static function fromArray(array $data): self
    {
        $f = new self();
        $f->id = (int) ($data['id'] ?? 0);
        $f->from = ModelHelpers::parseTime($data['from'] ?? null);
        $f->to = ModelHelpers::parseTime($data['to'] ?? null);
        return $f;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'from' => ModelHelpers::goTimeString($this->from),
            'to' => ModelHelpers::goTimeString($this->to),
        ];
    }
}
