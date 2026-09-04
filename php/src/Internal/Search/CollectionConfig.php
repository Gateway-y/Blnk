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
 * CollectionConfig holds configuration for a specific collection.
 *
 * Port of the Go `CollectionConfig` struct (internal/search/search.go). The
 * Typesense schema is represented as the associative array the Typesense API
 * expects (Go: *api.CollectionSchema).
 */
final class CollectionConfig
{
    /** @var array<string, mixed> */
    public array $schema = [];

    public string $idField = '';

    /** @var string[] */
    public array $timeFields = [];

    /** @var string[] */
    public array $bigIntFields = [];

    public string $defaultSortField = '';

    /**
     * @param array<string, mixed> $schema
     * @param string[]             $timeFields
     * @param string[]             $bigIntFields
     */
    public function __construct(array $schema, string $idField, array $timeFields = [], array $bigIntFields = [], string $defaultSortField = '')
    {
        $this->schema = $schema;
        $this->idField = $idField;
        $this->timeFields = $timeFields;
        $this->bigIntFields = $bigIntFields;
        $this->defaultSortField = $defaultSortField;
    }
}
