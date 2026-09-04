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
 * NotificationPayload represents the payload structure for notifications,
 * containing the table and data.
 *
 * Port of the Go `NotificationPayload` struct (internal/search/search.go).
 */
final class NotificationPayload implements \JsonSerializable
{
    /** JSON: "table". */
    public string $table = '';

    /**
     * JSON: "data".
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $table = '', array $data = [])
    {
        $this->table = $table;
        $this->data = $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['table'] ?? ''),
            is_array($data['data'] ?? null) ? $data['data'] : []
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'table' => $this->table,
            'data' => $this->data === [] ? new \stdClass() : $this->data,
        ];
    }
}
