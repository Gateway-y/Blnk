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
 * BulkInflightCommitRequest commits many independently-created inflight
 * transactions in one call. Unlike the void variant, each item can carry
 * its own amount for partial commits.
 * (Go: api/model/transaction.go `BulkInflightCommitRequest`.)
 */
final class BulkInflightCommitRequest implements \JsonSerializable
{
    /**
     * Go: `[]BulkInflightCommitItem` — null for a nil slice; a JSON null item
     * decodes to a zero item.
     *
     * @var BulkInflightCommitItem[]|null
     */
    public ?array $transactions = null;

    /**
     * SkipQueue processes every item synchronously instead of routing them
     * through the inflight-commit queue (the default).
     */
    public bool $skipQueue = false;

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'BulkInflightCommitRequest'): self
    {
        $r = new self();
        $items = JsonBinding::objectList($data, 'transactions', $struct, 'model.BulkInflightCommitItem');
        if ($items !== null) {
            $r->transactions = [];
            foreach ($items as $item) {
                $r->transactions[] = BulkInflightCommitItem::fromArray($item ?? [], $struct . '.transactions');
            }
        }
        $r->skipQueue = JsonBinding::bool($data, 'skip_queue', $struct);
        return $r;
    }

    public function jsonSerialize(): array
    {
        return [
            'transactions' => $this->transactions === null ? null : array_values($this->transactions),
            'skip_queue' => $this->skipQueue,
        ];
    }
}
