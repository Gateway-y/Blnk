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

namespace Blnk\Cmd;

/**
 * indexData represents the data structure used for indexing data in the system.
 * It includes the collection name and the payload which is the data to be indexed.
 *
 * (Go: `indexData` struct, cmd/workers.go; JSON tags "collection" and "payload".)
 */
final class IndexData implements \JsonSerializable
{
    /** JSON: "collection". */
    public string $collection = '';

    /**
     * JSON: "payload" (Go: `map[string]interface{}`; null is Go's nil map).
     *
     * @var array<string, mixed>|null
     */
    public ?array $payload = null;

    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(string $collection = '', ?array $payload = null)
    {
        $this->collection = $collection;
        $this->payload = $payload;
    }

    /**
     * fromArray is the analogue of `json.Unmarshal(t.Payload(), &data)`: it
     * rejects the same shapes encoding/json rejects for this struct (a
     * non-string collection, a payload that is neither an object nor null).
     *
     * @param array<string, mixed> $data
     * @throws \RuntimeException when a field has the wrong JSON type
     */
    public static function fromArray(array $data): self
    {
        $collection = $data['collection'] ?? '';
        if (!\is_string($collection)) {
            throw new \RuntimeException('json: cannot unmarshal collection into Go struct field indexData.collection of type string');
        }
        $payload = $data['payload'] ?? null;
        if ($payload !== null && !\is_array($payload)) {
            throw new \RuntimeException('json: cannot unmarshal payload into Go struct field indexData.payload of type map[string]interface {}');
        }
        return new self($collection, $payload);
    }

    /**
     * @return array{collection: string, payload: array<string, mixed>|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'collection' => $this->collection,
            'payload' => $this->payload,
        ];
    }
}
