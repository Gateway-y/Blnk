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

/**
 * FilterResponse wraps the response with optional count.
 */
final class FilterResponse implements \JsonSerializable
{
    /** JSON: "data" */
    public mixed $data;

    /** JSON: "total_count,omitempty" — Go `*int64`: omitted only when nil. */
    public ?int $totalCount;

    public function __construct(mixed $data, ?int $totalCount = null)
    {
        $this->data = $data;
        $this->totalCount = $totalCount;
    }

    public function jsonSerialize(): array
    {
        $out = ['data' => $this->data];
        if ($this->totalCount !== null) {
            $out['total_count'] = $this->totalCount;
        }

        return $out;
    }
}
