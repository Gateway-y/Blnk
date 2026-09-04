<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * MatchingRule groups the criteria used to match external and internal
 * transactions (Go: model.MatchingRule, reconciliation_model.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class MatchingRule implements \JsonSerializable
{
    /** Go json:"-" (database serial id). */
    public int $id = 0;

    public string $ruleID = '';

    public ?\DateTimeImmutable $createdAt = null;

    public ?\DateTimeImmutable $updatedAt = null;

    public string $name = '';

    public string $description = '';

    /**
     * Go: `[]MatchingCriteria` — a nil slice marshals as null, so the property
     * is nullable.
     *
     * @var MatchingCriteria[]|null
     */
    public ?array $criteria = null;

    public static function fromArray(array $data): self
    {
        $r = new self();
        $r->ruleID = (string) ($data['rule_id'] ?? '');
        $r->createdAt = ModelHelpers::parseTime($data['created_at'] ?? null);
        $r->updatedAt = ModelHelpers::parseTime($data['updated_at'] ?? null);
        $r->name = (string) ($data['name'] ?? '');
        $r->description = (string) ($data['description'] ?? '');
        if (isset($data['criteria']) && \is_array($data['criteria'])) {
            $r->criteria = [];
            foreach ($data['criteria'] as $criterion) {
                if (\is_array($criterion)) {
                    $r->criteria[] = MatchingCriteria::fromArray($criterion);
                }
            }
        }
        return $r;
    }

    public function jsonSerialize(): array
    {
        return [
            'rule_id' => $this->ruleID,
            'created_at' => ModelHelpers::goTimeString($this->createdAt),
            'updated_at' => ModelHelpers::goTimeString($this->updatedAt),
            'name' => $this->name,
            'description' => $this->description,
            'criteria' => $this->criteria === null ? null : array_values($this->criteria),
        ];
    }
}
