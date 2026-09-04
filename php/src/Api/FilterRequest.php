<?php

/*
Copyright 2024 Blnk Finance Authors.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

declare(strict_types=1);

namespace Blnk\Api;

use Blnk\Api\Model\JsonBinding;
use Blnk\Internal\Filter\QueryFilter;

/**
 * FilterRequest represents the JSON body for filter endpoints.
 * It allows clients to pass filters directly as JSON instead of query parameters.
 */
final class FilterRequest implements \JsonSerializable
{
    /** @var QueryFilter[]|null JSON: "filters" (nil slice → null) */
    public ?array $filters = null;

    /** JSON: "logical_operator,omitempty" — "and" or "or" */
    public string $logicalOperator = '';

    /** JSON: "limit,omitempty" */
    public int $limit = 0;

    /** JSON: "offset,omitempty" */
    public int $offset = 0;

    /** JSON: "sort_by,omitempty" */
    public string $sortBy = '';

    /** JSON: "sort_order,omitempty" — "asc" or "desc" */
    public string $sortOrder = '';

    /** JSON: "include_count,omitempty" */
    public bool $includeCount = false;

    /**
     * @param array<string, mixed> $data
     * @throws \RuntimeException on a wrong JSON kind (encoding/json semantics)
     */
    public static function fromArray(array $data, string $struct = 'FilterRequest'): self
    {
        $req = new self();
        $items = JsonBinding::objectList($data, 'filters', $struct, 'filter.QueryFilter');
        if ($items !== null) {
            $req->filters = [];
            $filterStruct = $struct . '.filters';
            foreach ($items as $item) {
                $item = $item ?? []; // a JSON null element decodes to the zero QueryFilter
                $filter = new QueryFilter(
                    JsonBinding::string($item, 'field', $filterStruct),
                    JsonBinding::string($item, 'operator', $filterStruct)
                );
                $filter->value = $item['value'] ?? null;
                if (Binding::has($item, 'values')) {
                    if (!is_array($item['values']) || (count($item['values']) > 0 && !array_is_list($item['values']))) {
                        throw Binding::typeError($item['values'], $filterStruct, 'values', '[]interface {}');
                    }
                    $filter->values = array_values($item['values']);
                }
                $req->filters[] = $filter;
            }
        }
        $req->logicalOperator = JsonBinding::string($data, 'logical_operator', $struct);
        $req->limit = Binding::int($data, 'limit', $struct);
        $req->offset = Binding::int($data, 'offset', $struct);
        $req->sortBy = JsonBinding::string($data, 'sort_by', $struct);
        $req->sortOrder = JsonBinding::string($data, 'sort_order', $struct);
        $req->includeCount = JsonBinding::bool($data, 'include_count', $struct);

        return $req;
    }

    public function jsonSerialize(): array
    {
        $out = ['filters' => $this->filters === null ? null : array_values($this->filters)];
        if ($this->logicalOperator !== '') {
            $out['logical_operator'] = $this->logicalOperator;
        }
        if ($this->limit !== 0) {
            $out['limit'] = $this->limit;
        }
        if ($this->offset !== 0) {
            $out['offset'] = $this->offset;
        }
        if ($this->sortBy !== '') {
            $out['sort_by'] = $this->sortBy;
        }
        if ($this->sortOrder !== '') {
            $out['sort_order'] = $this->sortOrder;
        }
        if ($this->includeCount) {
            $out['include_count'] = true;
        }

        return $out;
    }
}
