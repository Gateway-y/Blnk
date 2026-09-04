<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * ChainState mirrors the blnk.chain_state bookmark row
 * (Go: model.ChainState, chain.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 *
 * The Go struct has no JSON tags; serialization uses the Go field names.
 */
final class ChainState implements \JsonSerializable
{
    public string $chainKey = '';

    public int $lastSeq = 0;

    public string $headHash = '';

    public string $genesisHash = '';

    public ?\DateTimeImmutable $genesisAt = null;

    public ?\DateTimeImmutable $updatedAt = null;

    public static function fromArray(array $data): self
    {
        $s = new self();
        $s->chainKey = (string) ($data['ChainKey'] ?? '');
        $s->lastSeq = (int) ($data['LastSeq'] ?? 0);
        $s->headHash = (string) ($data['HeadHash'] ?? '');
        $s->genesisHash = (string) ($data['GenesisHash'] ?? '');
        $s->genesisAt = ModelHelpers::parseTime($data['GenesisAt'] ?? null);
        $s->updatedAt = ModelHelpers::parseTime($data['UpdatedAt'] ?? null);
        return $s;
    }

    public function jsonSerialize(): array
    {
        return [
            'ChainKey' => $this->chainKey,
            'LastSeq' => $this->lastSeq,
            'HeadHash' => $this->headHash,
            'GenesisHash' => $this->genesisHash,
            'GenesisAt' => ModelHelpers::goTimeString($this->genesisAt),
            'UpdatedAt' => ModelHelpers::goTimeString($this->updatedAt),
        ];
    }
}
