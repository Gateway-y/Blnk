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

namespace Blnk\Api\Model;

/**
 * BulkInflightResponse is the envelope returned by both bulk endpoints.
 * Succeeded + Failed == len(Results).
 * (Go: api/model/transaction.go `BulkInflightResponse`.)
 */
final class BulkInflightResponse implements \JsonSerializable
{
    public int $succeeded = 0;

    public int $failed = 0;

    /** @var BulkInflightResult[] */
    public array $results = [];

    /**
     * @param BulkInflightResult[] $results
     */
    public function __construct(int $succeeded = 0, int $failed = 0, array $results = [])
    {
        $this->succeeded = $succeeded;
        $this->failed = $failed;
        $this->results = $results;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $r = new self((int) ($data['succeeded'] ?? 0), (int) ($data['failed'] ?? 0));
        foreach (($data['results'] ?? []) as $item) {
            if (\is_array($item)) {
                $r->results[] = BulkInflightResult::fromArray($item);
            }
        }
        return $r;
    }

    public function jsonSerialize(): array
    {
        return [
            'succeeded' => $this->succeeded,
            'failed' => $this->failed,
            'results' => array_values($this->results),
        ];
    }
}
