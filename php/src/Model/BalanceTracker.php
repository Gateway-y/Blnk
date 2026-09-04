<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * BalanceTracker accumulates balances and their access frequencies
 * (Go: model.BalanceTracker, balance.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 *
 * The Go struct embeds a sync.Mutex; PHP's request model is single-threaded so
 * the mutex is dropped per PORTING.md ("sync.Mutex guarding in-process caches
 * → plain code"). The Go struct has no JSON tags, so serialization uses the Go
 * field names (a marshalled sync.Mutex is an empty object).
 */
final class BalanceTracker implements \JsonSerializable
{
    /** @var array<string, Balance> */
    public array $balances = [];

    /** @var array<string, int> */
    public array $frequencies = [];

    public static function fromArray(array $data): self
    {
        $t = new self();
        foreach (($data['Balances'] ?? []) as $key => $balance) {
            if (\is_array($balance)) {
                $t->balances[(string) $key] = Balance::fromArray($balance);
            }
        }
        foreach (($data['Frequencies'] ?? []) as $key => $frequency) {
            $t->frequencies[(string) $key] = (int) $frequency;
        }
        return $t;
    }

    public function jsonSerialize(): array
    {
        return [
            'Balances' => \count($this->balances) === 0 ? new \stdClass() : $this->balances,
            'Frequencies' => \count($this->frequencies) === 0 ? new \stdClass() : $this->frequencies,
            'Mutex' => new \stdClass(), // Go marshals the embedded sync.Mutex as {}
        ];
    }
}
