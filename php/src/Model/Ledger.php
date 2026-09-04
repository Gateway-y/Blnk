<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * Ledger groups balances (Go: model.Ledger, ledger.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class Ledger implements \JsonSerializable
{
    /** Go json:"-" (database serial id). */
    public int $id = 0;

    public string $ledgerID = '';

    public string $name = '';

    public ?\DateTimeImmutable $createdAt = null;

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    public static function fromArray(array $data): self
    {
        $l = new self();
        $l->ledgerID = (string) ($data['ledger_id'] ?? '');
        $l->name = (string) ($data['name'] ?? '');
        $l->createdAt = ModelHelpers::parseTime($data['created_at'] ?? null);
        $metaData = $data['meta_data'] ?? null;
        $l->metaData = \is_array($metaData) ? $metaData : null;
        return $l;
    }

    public function jsonSerialize(): array
    {
        return [
            'ledger_id' => $this->ledgerID,
            'name' => $this->name,
            'created_at' => ModelHelpers::goTimeString($this->createdAt),
            'meta_data' => ModelHelpers::mapToJson($this->metaData),
        ];
    }
}
