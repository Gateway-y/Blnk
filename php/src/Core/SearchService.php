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

namespace Blnk\Core;

use Blnk\Internal\Search\SearchException;

/**
 * Port of search.go: the Typesense search methods of the Go `Blnk` struct,
 * composed into {@see Blnk}.
 *
 * The typesense-go parameter structs (`api.SearchCollectionParams`,
 * `api.MultiSearchSearchesParameter`) are plain associative arrays carrying
 * the same JSON names, as accepted by {@see \Blnk\Internal\Search\TypesenseClient}.
 */
trait SearchService
{
    /**
     * Search performs a search on the specified collection using the provided query parameters.
     *
     * Parameters:
     * - $collection: The name of the collection to search.
     * - $query: The search query parameters.
     *
     * Returns the search results.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     * @throws SearchException if the search operation fails.
     */
    public function search(string $collection, array $query): array
    {
        return $this->search->search($collection, $query);
    }

    /**
     * MultiSearch performs a multi-search operation across collections.
     *
     * @param array<string, mixed> $searchParams `{"searches": [...]}` (Go: api.MultiSearchSearchesParameter)
     * @return array<string, mixed> the multi-search result
     * @throws SearchException if the search operation fails.
     */
    public function multiSearch(array $searchParams): array
    {
        return $this->search->multiSearch($searchParams);
    }
}
