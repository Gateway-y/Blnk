<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * QueryFilterSet mirrors the Go `QueryFilterSet` struct (internal/filter/types.go):
 * a list of filters plus the logical operator used to combine them.
 */
final class QueryFilterSet implements \JsonSerializable
{
    /** @var QueryFilter[] (JSON: "filters") */
    public array $filters = [];

    /** One of the LogicalOperator::* constants (JSON: "logical_operator", omitempty). */
    public string $logicalOperator = '';

    /**
     * @param QueryFilter[] $filters
     */
    public function __construct(array $filters = [], string $logicalOperator = '')
    {
        $this->filters = $filters;
        $this->logicalOperator = $logicalOperator;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $set = new self();
        foreach ((array) ($data['filters'] ?? []) as $filter) {
            if (is_array($filter)) {
                $set->filters[] = QueryFilter::fromArray($filter);
            }
        }
        $set->logicalOperator = (string) ($data['logical_operator'] ?? '');

        return $set;
    }

    public function jsonSerialize(): array
    {
        $out = ['filters' => $this->filters];
        if ($this->logicalOperator !== '') {
            $out['logical_operator'] = $this->logicalOperator;
        }

        return $out;
    }
}
