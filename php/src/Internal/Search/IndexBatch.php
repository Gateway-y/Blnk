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
 * IndexBatch represents a batch of items to be indexed in dependency order.
 * Dependencies are indexed first, then the primary item is indexed.
 * This ensures referential integrity in the search index.
 *
 * Port of the Go `IndexBatch` struct (internal/search/search.go); the Go
 * NewIndexBatch constructor is the PHP constructor.
 */
final class IndexBatch implements \JsonSerializable
{
    /** JSON: "id". */
    public string $id = '';

    /**
     * Items that must be indexed first (e.g., balances) (JSON: "dependencies").
     *
     * @var IndexItem[]
     */
    public array $dependencies = [];

    /** Primary item indexed after dependencies (e.g., transaction) (JSON: "primary"). */
    public ?IndexItem $primary = null;

    /** JSON: "created_at". */
    public ?\DateTimeImmutable $createdAt = null;

    /**
     * newIndexBatch creates a new IndexBatch with the given ID.
     */
    public function __construct(string $id = '')
    {
        $this->id = $id;
        $this->dependencies = [];
        $this->createdAt = new \DateTimeImmutable('now');
    }

    /**
     * addDependency adds a dependency item to the batch.
     * Dependencies are indexed before the primary item.
     */
    public function addDependency(string $collection, string $documentID, mixed $data): void
    {
        $this->dependencies[] = new IndexItem($collection, $documentID, $data);
    }

    /**
     * setPrimary sets the primary item to be indexed after all dependencies.
     */
    public function setPrimary(string $collection, string $documentID, mixed $data): void
    {
        $this->primary = new IndexItem($collection, $documentID, $data);
    }

    /**
     * deduplicate removes duplicate dependencies based on collection and document ID.
     */
    public function deduplicate(): void
    {
        $seen = [];
        $unique = [];

        foreach ($this->dependencies as $dep) {
            $key = $dep->collection . ':' . $dep->documentId;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $dep;
            }
        }
        $this->dependencies = $unique;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $batch = new self((string) ($data['id'] ?? ''));
        $batch->dependencies = [];
        foreach ((array) ($data['dependencies'] ?? []) as $dep) {
            if (is_array($dep)) {
                $batch->dependencies[] = IndexItem::fromArray($dep);
            }
        }
        if (is_array($data['primary'] ?? null)) {
            $batch->primary = IndexItem::fromArray($data['primary']);
        }
        if (is_string($data['created_at'] ?? null) && $data['created_at'] !== '') {
            try {
                $batch->createdAt = new \DateTimeImmutable($data['created_at']);
            } catch (\Exception) {
                $batch->createdAt = null;
            }
        }

        return $batch;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'dependencies' => $this->dependencies,
            'primary' => $this->primary,
            // time.Time marshals as RFC3339Nano ("Z" for UTC); the zero value as 0001-01-01T00:00:00Z.
            'created_at' => \Blnk\Model\ModelHelpers::goTimeString($this->createdAt),
        ];
    }
}
