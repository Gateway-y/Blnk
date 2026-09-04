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
 * ReindexProgress tracks the progress of a reindex operation.
 *
 * Port of the Go `ReindexProgress` struct (internal/search/reindex.go).
 */
final class ReindexProgress implements \JsonSerializable
{
    /** "pending", "in_progress", "completed", "failed" (JSON: "status"). */
    public string $status = '';

    /** "drop_collections", "indexing_ledgers", etc. (JSON: "phase"). */
    public string $phase = '';

    /** JSON: "total_records". */
    public int $totalRecords = 0;

    /** JSON: "processed_records". */
    public int $processedRecords = 0;

    /**
     * JSON: "errors", omitempty.
     *
     * @var string[]
     */
    public array $errors = [];

    /** JSON: "started_at". */
    public ?\DateTimeImmutable $startedAt = null;

    /** JSON: "completed_at", omitempty. */
    public ?\DateTimeImmutable $completedAt = null;

    public function jsonSerialize(): array
    {
        $out = [
            'status' => $this->status,
            'phase' => $this->phase,
            'total_records' => $this->totalRecords,
            'processed_records' => $this->processedRecords,
        ];
        if ($this->errors !== []) {
            $out['errors'] = $this->errors;
        }
        $out['started_at'] = $this->startedAt === null
            ? '0001-01-01T00:00:00Z'
            : $this->startedAt->format(\DateTimeInterface::RFC3339);
        if ($this->completedAt !== null) {
            $out['completed_at'] = $this->completedAt->format(\DateTimeInterface::RFC3339);
        }

        return $out;
    }
}
