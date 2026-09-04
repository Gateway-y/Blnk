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

namespace Blnk\Internal\Search;

/**
 * IndexItem represents a single item to be indexed in Typesense.
 *
 * Port of the Go `IndexItem` struct (internal/search/search.go).
 */
final class IndexItem implements \JsonSerializable
{
    /** Collection name (e.g., "balances", "transactions") (JSON: "collection"). */
    public string $collection = '';

    /** JSON: "document_id". */
    public string $documentId = '';

    /** JSON: "data". */
    public mixed $data = null;

    public function __construct(string $collection = '', string $documentId = '', mixed $data = null)
    {
        $this->collection = $collection;
        $this->documentId = $documentId;
        $this->data = $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['collection'] ?? ''),
            (string) ($data['document_id'] ?? ''),
            $data['data'] ?? null
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'collection' => $this->collection,
            'document_id' => $this->documentId,
            'data' => $this->data,
        ];
    }
}
