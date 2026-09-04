<?php

declare(strict_types=1);

namespace Blnk\Internal\Filter;

/**
 * QueryFilter mirrors the Go `QueryFilter` struct (internal/filter/types.go):
 * a single filter expression made of a field, an operator and a value (or a
 * list of values for `in`/`between`).
 */
final class QueryFilter implements \JsonSerializable
{
    /** Field name the filter applies to (JSON: "field"). */
    public string $field = '';

    /** One of the Operator::* constants (JSON: "operator"). */
    public string $operator = '';

    /** Single value operators payload (JSON: "value", omitempty). */
    public mixed $value = null;

    /**
     * Multi value operators payload (JSON: "values", omitempty).
     *
     * @var array<int, mixed>|null
     */
    public ?array $values = null;

    public function __construct(string $field = '', string $operator = '', mixed $value = null, ?array $values = null)
    {
        $this->field = $field;
        $this->operator = $operator;
        $this->value = $value;
        $this->values = $values;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $filter = new self();
        $filter->field = (string) ($data['field'] ?? '');
        $filter->operator = (string) ($data['operator'] ?? '');
        $filter->value = $data['value'] ?? null;
        $values = $data['values'] ?? null;
        $filter->values = is_array($values) ? array_values($values) : null;

        return $filter;
    }

    public function jsonSerialize(): array
    {
        $out = [
            'field' => $this->field,
            'operator' => $this->operator,
        ];
        if ($this->value !== null) {
            $out['value'] = $this->value;
        }
        if ($this->values !== null && $this->values !== []) {
            $out['values'] = $this->values;
        }

        return $out;
    }
}
