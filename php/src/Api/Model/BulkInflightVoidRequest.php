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
 * BulkInflightVoidRequest voids many independently-created inflight
 * transactions in one call. Each id is processed independently; partial
 * failures are reported per-item in the response and do not abort the rest
 * of the batch.
 * (Go: api/model/transaction.go `BulkInflightVoidRequest`.)
 */
final class BulkInflightVoidRequest implements \JsonSerializable
{
    /**
     * Go: `[]string` — null for a nil slice.
     *
     * @var string[]|null
     */
    public ?array $transactionIDs = null;

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
    public static function fromArray(array $data, string $struct = 'BulkInflightVoidRequest'): self
    {
        $r = new self();
        $r->transactionIDs = JsonBinding::stringList($data, 'transaction_ids', $struct);
        $r->skipQueue = JsonBinding::bool($data, 'skip_queue', $struct);
        return $r;
    }

    public function jsonSerialize(): array
    {
        return [
            'transaction_ids' => $this->transactionIDs === null ? null : array_values($this->transactionIDs),
            'skip_queue' => $this->skipQueue,
        ];
    }
}
