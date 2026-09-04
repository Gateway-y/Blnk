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

use Blnk\Model\BulkTransactionRequest as ModelBulkTransactionRequest;

/**
 * BulkTransactionRequest is the public API request shape for creating a batch
 * of transactions. Each item mirrors the single transaction create payload.
 * (Go: api/model/transaction.go `BulkTransactionRequest`.)
 */
final class BulkTransactionRequest implements \JsonSerializable
{
    /**
     * Go: `[]*RecordTransaction` — null for a nil slice; a null element is a
     * nil pointer (a JSON `null` item), which the handler rejects.
     *
     * @var array<int, RecordTransaction|null>|null
     */
    public ?array $transactions = null;

    public bool $inflight = false;

    public bool $atomic = false;

    public bool $runAsync = false;

    public bool $skipQueue = false;

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'BulkTransactionRequest'): self
    {
        $r = new self();
        $items = JsonBinding::objectList($data, 'transactions', $struct, 'model.RecordTransaction', true);
        if ($items !== null) {
            $r->transactions = [];
            foreach ($items as $item) {
                $r->transactions[] = $item === null ? null : RecordTransaction::fromArray($item, $struct . '.transactions');
            }
        }
        $r->inflight = JsonBinding::bool($data, 'inflight', $struct);
        $r->atomic = JsonBinding::bool($data, 'atomic', $struct);
        $r->runAsync = JsonBinding::bool($data, 'run_async', $struct);
        $r->skipQueue = JsonBinding::bool($data, 'skip_queue', $struct);
        return $r;
    }

    /**
     * ToBulkTransactionRequest converts the API payload into the domain
     * request (nil items stay null, as in Go).
     */
    public function toBulkTransactionRequest(): ModelBulkTransactionRequest
    {
        $transactions = [];
        foreach ($this->transactions ?? [] as $i => $transaction) {
            $transactions[$i] = $transaction !== null ? $transaction->toTransaction() : null;
        }

        $req = new ModelBulkTransactionRequest();
        $req->transactions = $transactions;
        $req->inflight = $this->inflight;
        $req->atomic = $this->atomic;
        $req->runAsync = $this->runAsync;
        $req->skipQueue = $this->skipQueue;
        return $req;
    }

    public function jsonSerialize(): array
    {
        return [
            'transactions' => $this->transactions === null ? null : array_values($this->transactions),
            'inflight' => $this->inflight,
            'atomic' => $this->atomic,
            'run_async' => $this->runAsync,
            'skip_queue' => $this->skipQueue,
        ];
    }
}
