<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * QueryOptions contains sorting and pagination options for queries.
 *
 * Port of the Go `QueryOptions` struct (internal/filter/types.go).
 */
final class QueryOptions implements \JsonSerializable
{
    /** JSON: "sort_by", omitempty. */
    public string $sortBy = '';

    /** One of the SortOrder::* constants (JSON: "sort_order", omitempty). */
    public string $sortOrder = '';

    /** JSON: "include_count", omitempty. */
    public bool $includeCount = false;

    public function __construct(string $sortBy = '', string $sortOrder = '', bool $includeCount = false)
    {
        $this->sortBy = $sortBy;
        $this->sortOrder = $sortOrder;
        $this->includeCount = $includeCount;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['sort_by'] ?? ''),
            (string) ($data['sort_order'] ?? ''),
            (bool) ($data['include_count'] ?? false)
        );
    }

    /**
     * defaultSortOrder returns desc if empty, otherwise validates and returns the order.
     */
    public function defaultSortOrder(): string
    {
        if ($this->sortOrder === '' || ($this->sortOrder !== SortOrder::SortAsc && $this->sortOrder !== SortOrder::SortDesc)) {
            return SortOrder::SortDesc;
        }

        return $this->sortOrder;
    }

    public function jsonSerialize(): array
    {
        $out = [];
        if ($this->sortBy !== '') {
            $out['sort_by'] = $this->sortBy;
        }
        if ($this->sortOrder !== '') {
            $out['sort_order'] = $this->sortOrder;
        }
        if ($this->includeCount) {
            $out['include_count'] = $this->includeCount;
        }

        return $out;
    }
}
