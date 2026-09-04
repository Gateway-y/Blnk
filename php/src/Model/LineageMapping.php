<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * LineageMapping links a balance to its provider shadow/aggregate balances for
 * fund-lineage tracking (Go: model.LineageMapping, balance.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class LineageMapping implements \JsonSerializable
{
    public int $id = 0;

    public string $balanceID = '';

    public string $provider = '';

    public string $shadowBalanceID = '';

    public string $aggregateBalanceID = '';

    public string $identityID = '';

    public ?\DateTimeImmutable $createdAt = null;

    public static function fromArray(array $data): self
    {
        $m = new self();
        $m->id = (int) ($data['id'] ?? 0);
        $m->balanceID = (string) ($data['balance_id'] ?? '');
        $m->provider = (string) ($data['provider'] ?? '');
        $m->shadowBalanceID = (string) ($data['shadow_balance_id'] ?? '');
        $m->aggregateBalanceID = (string) ($data['aggregate_balance_id'] ?? '');
        $m->identityID = (string) ($data['identity_id'] ?? '');
        $m->createdAt = ModelHelpers::parseTime($data['created_at'] ?? null);
        return $m;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'balance_id' => $this->balanceID,
            'provider' => $this->provider,
            'shadow_balance_id' => $this->shadowBalanceID,
            'aggregate_balance_id' => $this->aggregateBalanceID,
            'identity_id' => $this->identityID,
            'created_at' => ModelHelpers::goTimeString($this->createdAt),
        ];
    }
}
