<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * ChainedTransaction is a transaction read back in chain order for
 * verification: its canonical fields plus the stored chain linkage
 * (Go: model.ChainedTransaction, chain.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 *
 * The Go struct has no JSON tags; serialization uses the Go field names.
 */
final class ChainedTransaction implements \JsonSerializable
{
    /**
     * Go: value struct `Row ChainRow` — always present; a null here stands for
     * the zero-value row.
     */
    public ?ChainRow $row = null;

    public int $chainSeq = 0;

    public string $chainPrevHash = '';

    public string $chainHash = '';

    public static function fromArray(array $data): self
    {
        $t = new self();
        $t->row = isset($data['Row']) && \is_array($data['Row']) ? ChainRow::fromArray($data['Row']) : null;
        $t->chainSeq = (int) ($data['ChainSeq'] ?? 0);
        $t->chainPrevHash = (string) ($data['ChainPrevHash'] ?? '');
        $t->chainHash = (string) ($data['ChainHash'] ?? '');
        return $t;
    }

    public function jsonSerialize(): array
    {
        return [
            'Row' => $this->row ?? new ChainRow(), // Go value struct: always serialized
            'ChainSeq' => $this->chainSeq,
            'ChainPrevHash' => $this->chainPrevHash,
            'ChainHash' => $this->chainHash,
        ];
    }
}
