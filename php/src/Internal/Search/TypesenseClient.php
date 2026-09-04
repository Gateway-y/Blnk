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

use Blnk\Internal\Log;
use Brick\Math\BigInteger;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;

/**
 * TypesenseClient wraps a thin Guzzle-based Typesense HTTP client and provides
 * methods to interact with it.
 *
 * Port of internal/search/search.go (plus the DropCollection helpers from
 * reindex.go). Per PORTING.md the Go typesense-go client is replaced by this
 * thin client implementing exactly the endpoints Blnk uses:
 *
 * - POST   /collections                             (collection create)
 * - GET    /collections/{name}                      (collection retrieve)
 * - PATCH  /collections/{name}                      (collection schema update)
 * - DELETE /collections/{name}                      (collection drop)
 * - POST   /collections/{name}/documents?action=upsert (document upsert)
 * - GET    /collections/{name}/documents/search     (search)
 * - POST   /multi_search                            (multi search)
 *
 * The Go client's circuit breaker options (max requests 50, interval 2m,
 * timeout 1m) have no Guzzle equivalent and are not ported; the 5s connection
 * timeout is kept.
 */
final class TypesenseClient
{
    public const CollectionLedgers = 'ledgers';
    public const CollectionBalances = 'balances';
    public const CollectionTransactions = 'transactions';
    public const CollectionReconciliations = 'reconciliations';
    public const CollectionIdentities = 'identities';

    /** @var array<string, CollectionConfig>|null */
    private static ?array $collectionConfigs = null;

    private string $host;
    private string $apiKey;
    private ClientInterface $client;

    /**
     * newTypesenseClient initializes and returns a new Typesense client instance.
     *
     * @param string[] $hosts Typesense server URLs; like Go's
     *                        typesense.WithServer(hosts[0]) only the first is used.
     */
    public function __construct(string $apiKey, array $hosts, ?ClientInterface $httpClient = null)
    {
        $this->apiKey = $apiKey;
        $this->host = rtrim((string) ($hosts[0] ?? ''), '/');
        $this->client = $httpClient ?? new GuzzleClient([
            'connect_timeout' => 5,
        ]);
    }

    /**
     * @return array<string, CollectionConfig>
     */
    public static function collectionConfigs(): array
    {
        if (self::$collectionConfigs === null) {
            self::$collectionConfigs = [
                self::CollectionLedgers => new CollectionConfig(
                    self::getLedgerSchema(),
                    'ledger_id',
                    ['created_at'],
                    []
                ),
                self::CollectionBalances => new CollectionConfig(
                    self::getBalanceSchema(),
                    'balance_id',
                    ['created_at', 'inflight_expires_at'],
                    [
                        'balance', 'credit_balance', 'debit_balance',
                        'inflight_balance', 'inflight_credit_balance', 'inflight_debit_balance',
                    ]
                ),
                self::CollectionTransactions => new CollectionConfig(
                    self::getTransactionSchema(),
                    'transaction_id',
                    ['created_at', 'scheduled_for', 'inflight_expiry_date', 'effective_date'],
                    ['precise_amount']
                ),
                self::CollectionReconciliations => new CollectionConfig(
                    self::getReconciliationSchema(),
                    'reconciliation_id',
                    ['started_at', 'completed_at']
                ),
                self::CollectionIdentities => new CollectionConfig(
                    self::getIdentitySchema(),
                    'identity_id',
                    ['created_at', 'dob']
                ),
            ];
        }

        return self::$collectionConfigs;
    }

    /**
     * ensureCollectionsExist ensures that all the necessary collections exist in the Typesense schema.
     * If a collection doesn't exist, it will create the collection based on the latest schema.
     *
     * @throws SearchException
     */
    public function ensureCollectionsExist(): void
    {
        foreach (self::collectionConfigs() as $name => $config) {
            try {
                $this->createCollection($config->schema);
            } catch (\Throwable $e) {
                throw new SearchException(sprintf('failed to create collection %s: %s', $name, $e->getMessage()), 0, $e);
            }
        }

        try {
            $this->ensureDefaultGeneralLedger();
        } catch (\Throwable $e) {
            Log::get()->error(sprintf('failed to ensure default general ledger: %s', $e->getMessage()));
            throw $e;
        }
    }

    /**
     * ensureDefaultGeneralLedger ensures that the default general ledger exists in Typesense.
     */
    private function ensureDefaultGeneralLedger(): void
    {
        $data = [
            'ledger_id' => 'general_ledger_id',
            'name' => 'General Ledger',
            'created_at' => time(),
        ];

        $this->upsertDocument('ledgers', $data);
    }

    /**
     * createCollection creates a collection in Typesense based on the provided schema.
     * If the collection already exists, it will return without error (null).
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|null the created collection response, or null when it already exists
     *
     * @throws SearchException
     */
    public function createCollection(array $schema): ?array
    {
        try {
            return $this->request('POST', '/collections', null, $schema);
        } catch (SearchException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * retrieveCollection fetches a collection's current schema
     * (Go: client.Collection(name).Retrieve(ctx)).
     *
     * @return array<string, mixed>
     *
     * @throws SearchException
     */
    public function retrieveCollection(string $collectionName): array
    {
        return $this->request('GET', '/collections/' . rawurlencode($collectionName));
    }

    /**
     * updateCollection applies a partial schema update
     * (Go: client.Collection(name).Update(ctx, updateSchema)).
     *
     * @param array<string, mixed> $updateSchema
     *
     * @return array<string, mixed>
     *
     * @throws SearchException
     */
    public function updateCollection(string $collectionName, array $updateSchema): array
    {
        return $this->request('PATCH', '/collections/' . rawurlencode($collectionName), null, $updateSchema);
    }

    /**
     * search performs a search query on a specific collection with the provided search parameters.
     *
     * @param array<string, mixed> $searchParams Typesense search parameters
     *                                           (q, query_by, filter_by, ... — the same JSON names
     *                                           as Go's api.SearchCollectionParams)
     *
     * @return array<string, mixed> the Typesense search result
     *
     * @throws SearchException
     */
    public function search(string $collection, array $searchParams): array
    {
        return $this->request(
            'GET',
            '/collections/' . rawurlencode($collection) . '/documents/search',
            $searchParams
        );
    }

    /**
     * multiSearch performs a multi-search request
     * (Go: client.MultiSearch.Perform(ctx, &api.MultiSearchParams{}, searchRequests)).
     *
     * @param array<string, mixed> $searchRequests either {"searches": [...]} or the bare list of searches
     *
     * @return array<string, mixed> the Typesense multi-search result
     *
     * @throws SearchException
     */
    public function multiSearch(array $searchRequests): array
    {
        $body = array_key_exists('searches', $searchRequests)
            ? $searchRequests
            : ['searches' => array_values($searchRequests)];

        return $this->request('POST', '/multi_search', null, $body);
    }

    /**
     * handleNotification processes incoming notifications and updates Typesense collections
     * based on the table and data. It ensures the required fields exist and upserts the data
     * into Typesense.
     *
     * @param array<string, mixed> $data
     *
     * @throws SearchException
     */
    public function handleNotification(string $table, array $data): void
    {
        $configs = self::collectionConfigs();
        if (!isset($configs[$table])) {
            throw new SearchException(sprintf('unknown collection: %s', $table));
        }
        $config = $configs[$table];

        // Process and normalize the data
        $this->processMetadata($data);
        $this->convertLargeNumbers($config, $data);
        $this->ensureSchemaFields($config, $data);
        $this->normalizeTimeFields($config, $data);

        // Upsert the document
        $this->upsertDocument($table, $data);
    }

    /**
     * handleBatchNotification processes a batch of items and indexes them in dependency order.
     * It first indexes all dependencies (e.g., balances), then indexes the primary item
     * (e.g., transaction). This ensures referential integrity in the search index.
     *
     * @throws SearchException
     */
    public function handleBatchNotification(IndexBatch $batch): void
    {
        // Deduplicate dependencies to avoid redundant indexing
        $batch->deduplicate();

        // Step 1: Index all dependencies first (in order)
        foreach ($batch->dependencies as $dep) {
            try {
                $data = self::toMap($dep->data);
            } catch (\Throwable $e) {
                throw new SearchException(sprintf('failed to convert dependency %s/%s to map: %s', $dep->collection, $dep->documentId, $e->getMessage()), 0, $e);
            }
            try {
                $this->handleNotification($dep->collection, $data);
            } catch (\Throwable $e) {
                throw new SearchException(sprintf('failed to index dependency %s/%s: %s', $dep->collection, $dep->documentId, $e->getMessage()), 0, $e);
            }
        }

        // Step 2: Index primary entity after dependencies exist
        if ($batch->primary !== null) {
            $primary = $batch->primary;
            try {
                $data = self::toMap($primary->data);
            } catch (\Throwable $e) {
                throw new SearchException(sprintf('failed to convert primary %s/%s to map: %s', $primary->collection, $primary->documentId, $e->getMessage()), 0, $e);
            }
            try {
                $this->handleNotification($primary->collection, $data);
            } catch (\Throwable $e) {
                throw new SearchException(sprintf('failed to index primary %s/%s: %s', $primary->collection, $primary->documentId, $e->getMessage()), 0, $e);
            }
        }
    }

    /**
     * toMap converts a value to an associative array via JSON marshaling
     * (Go: json.Marshal + json.Unmarshal round trip on structs).
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException|SearchException
     *
     * @internal Also used by ReindexService.
     */
    public static function toMap(mixed $data): array
    {
        // If already a map, return it directly
        if (is_array($data) && !array_is_list($data)) {
            return $data;
        }
        if (is_array($data) && $data === []) {
            return [];
        }

        // Marshal and unmarshal to convert object to map
        $jsonBytes = json_encode($data, JSON_THROW_ON_ERROR);
        $result = json_decode($jsonBytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new SearchException('json: cannot unmarshal value into map');
        }

        return $result;
    }

    /**
     * processMetadata handles metadata field normalization for object schemas.
     *
     * @param array<string, mixed> $data
     *
     * @throws SearchException
     */
    private function processMetadata(array &$data): void
    {
        if (array_key_exists('meta_data', $data)) {
            $metaData = $data['meta_data'];
            if ($metaData === null) {
                // If metadata is null, provide an empty object for object type schemas
                $data['meta_data'] = [];
            } elseif (is_array($metaData) && !array_is_list($metaData)) {
                $data['meta_data'] = $metaData;
            } elseif (is_array($metaData) && $metaData === []) {
                $data['meta_data'] = [];
            } else {
                // For backward compatibility, convert to string for old schemas
                try {
                    $jsonString = json_encode($metaData, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new SearchException(sprintf('failed to marshal meta_data: %s', $e->getMessage()), 0, $e);
                }
                $data['meta_data'] = $jsonString;
            }
        }
    }

    /**
     * convertLargeNumbers converts big integer values to strings for Typesense compatibility.
     *
     * @param array<string, mixed> $data
     */
    private function convertLargeNumbers(CollectionConfig $config, array &$data): void
    {
        foreach ($config->bigIntFields as $field) {
            $this->convertNumberField($data, $field);
        }
    }

    /**
     * convertNumberField converts a single numeric field to string format.
     *
     * Go switches on *big.Int (-> String()) and float64 (-> "%.0f"); in PHP,
     * Brick\Math\BigInteger plays the *big.Int role and native ints (which the
     * Go JSON round trip would have surfaced as float64) are also stringified
     * so the resulting document matches.
     *
     * @param array<string, mixed> $data
     */
    private function convertNumberField(array &$data, string $field): void
    {
        if (array_key_exists($field, $data)) {
            $val = $data[$field];
            if ($val instanceof BigInteger) {
                $data[$field] = (string) $val;
            } elseif (is_float($val)) {
                // Convert scientific notation back to integer string
                $data[$field] = sprintf('%.0f', $val);
            } elseif (is_int($val)) {
                $data[$field] = (string) $val;
            }
        }
    }

    /**
     * ensureSchemaFields ensures all required schema fields are present with default values.
     *
     * @param array<string, mixed> $data
     */
    private function ensureSchemaFields(CollectionConfig $config, array &$data): void
    {
        $latestSchema = $config->schema;
        $fields = (array) ($latestSchema['fields'] ?? []);

        $optionalFieldMap = [];
        foreach ($fields as $field) {
            if (($field['optional'] ?? false) === true) {
                $optionalFieldMap[(string) $field['name']] = true;
            }
        }

        foreach ($fields as $field) {
            $name = (string) $field['name'];
            if (!array_key_exists($name, $data)) {
                $isOptional = ($field['optional'] ?? false) === true;
                if (!$isOptional) {
                    $data[$name] = self::getDefaultValue((string) $field['type']);
                }
            }
        }

        foreach (array_keys($data) as $key) {
            if ($optionalFieldMap[$key] ?? false) {
                if (is_string($data[$key]) && $data[$key] === '') {
                    unset($data[$key]);
                }
            }
        }
    }

    /**
     * normalizeTimeFields converts time fields to Unix timestamps.
     *
     * @param array<string, mixed> $data
     */
    private function normalizeTimeFields(CollectionConfig $config, array &$data): void
    {
        foreach ($config->timeFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $v = $data[$field];

            if ($v instanceof \DateTimeInterface) {
                $data[$field] = $v->getTimestamp();
            } elseif (is_int($v)) {
                // Time already in Unix format, no action needed
            } elseif (is_string($v)) {
                if ($v === '') {
                    unset($data[$field]);
                    continue;
                }

                $t = self::parseRFC3339($v);
                if ($t !== null) {
                    $data[$field] = $t->getTimestamp();
                } else {
                    Log::get()->warning(sprintf('Failed to parse time field %s with value %s, setting to 0', $field, $v));
                    $data[$field] = 0;
                }
            } elseif (is_float($v)) {
                $data[$field] = (int) $v;
            } else {
                // For truly unrecognized types, set to 0 and log
                Log::get()->warning(sprintf('Unrecognized time field type for %s: %s, setting to 0', $field, get_debug_type($v)));
                $data[$field] = 0;
            }
        }
    }

    /**
     * getIDField returns the primary ID field name for a given table.
     */
    private function getIDField(string $table): string
    {
        $configs = self::collectionConfigs();
        if (isset($configs[$table])) {
            return $configs[$table]->idField;
        }

        return '';
    }

    /**
     * upsertDocument handles the final upsert operation to Typesense.
     *
     * @param array<string, mixed> $data
     *
     * @throws SearchException
     */
    private function upsertDocument(string $table, array $data): void
    {
        $idField = $this->getIDField($table);

        if ($idField !== '') {
            $id = $data[$idField] ?? null;
            if (is_string($id) && $id !== '') {
                // Upsert the document in Typesense with the provided ID
                $data['id'] = $id;
                try {
                    $this->request(
                        'POST',
                        '/collections/' . rawurlencode($table) . '/documents',
                        ['action' => 'upsert'],
                        $data
                    );
                } catch (\Throwable $e) {
                    throw new SearchException(sprintf('failed to upsert document in Typesense: %s', $e->getMessage()), 0, $e);
                }

                return;
            }
        }

        // For other collections, perform a regular upsert
        try {
            $this->request(
                'POST',
                '/collections/' . rawurlencode($table) . '/documents',
                ['action' => 'upsert'],
                $data
            );
        } catch (\Throwable $e) {
            throw new SearchException(sprintf('failed to index document in Typesense: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * migrateTypeSenseSchema adds new fields and removes old fields from the existing
     * collection schema in Typesense. This is useful when the schema has been updated,
     * and fields need to be added or removed.
     *
     * @throws SearchException
     */
    public function migrateTypeSenseSchema(string $collectionName): void
    {
        try {
            $currentSchemaResponse = $this->retrieveCollection($collectionName);
        } catch (\Throwable $e) {
            throw new SearchException(sprintf('failed to retrieve current schema: %s', $e->getMessage()), 0, $e);
        }

        $currentSchema = [
            'name' => $currentSchemaResponse['name'] ?? $collectionName,
            'fields' => $currentSchemaResponse['fields'] ?? [],
        ];

        $configs = self::collectionConfigs();
        if (!isset($configs[$collectionName])) {
            throw new SearchException(sprintf('unknown collection: %s', $collectionName));
        }
        $latestSchema = $configs[$collectionName]->schema;

        [$newFields] = self::compareSchemas($currentSchema, $latestSchema);

        foreach ($newFields as $field) {
            $updateSchema = [
                'fields' => [$field],
            ];

            try {
                $this->updateCollection($collectionName, $updateSchema);
            } catch (\Throwable $e) {
                throw new SearchException(sprintf('failed to add field %s: %s', $field['name'] ?? '', $e->getMessage()), 0, $e);
            }
            Log::get()->info(sprintf('Added new field %s to collection %s', $field['name'] ?? '', $collectionName));
        }
    }

    /**
     * compareSchemas compares the old schema with the new schema and returns:
     * - newFields: fields present in new schema but not in old schema
     * - removedFields: field names present in old schema but not in new schema
     *
     * @param array<string, mixed> $oldSchema
     * @param array<string, mixed> $newSchema
     *
     * @return array{0: array<int, array<string, mixed>>, 1: string[]}
     */
    public static function compareSchemas(array $oldSchema, array $newSchema): array
    {
        $newFields = [];
        $removedFields = [];

        $oldFieldMap = [];
        $newFieldMap = [];

        foreach ((array) ($oldSchema['fields'] ?? []) as $field) {
            $oldFieldMap[(string) ($field['name'] ?? '')] = true;
        }

        foreach ((array) ($newSchema['fields'] ?? []) as $field) {
            $name = (string) ($field['name'] ?? '');
            $newFieldMap[$name] = true;
            if (!isset($oldFieldMap[$name])) {
                $newFields[] = $field;
            }
        }

        foreach ((array) ($oldSchema['fields'] ?? []) as $field) {
            $name = (string) ($field['name'] ?? '');
            if (!isset($newFieldMap[$name])) {
                $removedFields[] = $name;
            }
        }

        return [$newFields, $removedFields];
    }

    /**
     * getDefaultValue returns the default value for a given field type in Typesense.
     */
    public static function getDefaultValue(string $fieldType): mixed
    {
        switch ($fieldType) {
            case 'string':
                return '';
            case 'int32':
            case 'int64':
                return 0;
            case 'float':
                return 0.0;
            case 'bool':
                return false;
            case 'string[]':
                return [];
            default:
                return null;
        }
    }

    /**
     * dropCollection deletes a collection from Typesense. A missing collection is
     * not an error; Typesense reports it variously as "not found", "Not Found",
     * or a 404 with "No collection with name X found." depending on version.
     *
     * (Port of reindex.go's DropCollection method on TypesenseClient.)
     *
     * @throws SearchException
     */
    public function dropCollection(string $collectionName): void
    {
        try {
            $this->request('DELETE', '/collections/' . rawurlencode($collectionName));
        } catch (SearchException $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'not found')
                && !str_contains($msg, 'Not Found')
                && !str_contains($msg, 'status: 404')) {
                throw $e;
            }
        }
    }

    /**
     * dropAllCollections drops all known collections from Typesense.
     *
     * (Port of reindex.go's DropAllCollections method on TypesenseClient.)
     *
     * @throws SearchException
     */
    public function dropAllCollections(): void
    {
        $collections = [
            self::CollectionLedgers,
            self::CollectionIdentities,
            self::CollectionBalances,
            self::CollectionTransactions,
            self::CollectionReconciliations,
        ];

        foreach ($collections as $c) {
            Log::get()->debug('Dropping collection', ['collection' => $c]);
            $this->dropCollection($c);
        }
    }

    /**
     * getLedgerSchema returns the schema for the "ledgers" collection.
     *
     * @return array<string, mixed>
     */
    public static function getLedgerSchema(): array
    {
        return [
            'name' => 'ledgers',
            'fields' => [
                ['name' => 'ledger_id', 'type' => 'string', 'facet' => true],
                ['name' => 'name', 'type' => 'string', 'facet' => true],
                ['name' => 'created_at', 'type' => 'int64', 'facet' => true],
                ['name' => 'meta_data', 'type' => 'object', 'facet' => true, 'optional' => true],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,
        ];
    }

    /**
     * getBalanceSchema returns the schema for the "balances" collection.
     *
     * @return array<string, mixed>
     */
    public static function getBalanceSchema(): array
    {
        return [
            'name' => 'balances',
            'fields' => [
                ['name' => 'balance', 'type' => 'string', 'facet' => true],
                ['name' => 'version', 'type' => 'int64', 'facet' => true],
                ['name' => 'inflight_balance', 'type' => 'string', 'facet' => true],
                ['name' => 'credit_balance', 'type' => 'string', 'facet' => true],
                ['name' => 'inflight_credit_balance', 'type' => 'string', 'facet' => true],
                ['name' => 'debit_balance', 'type' => 'string', 'facet' => true],
                ['name' => 'inflight_debit_balance', 'type' => 'string', 'facet' => true],
                ['name' => 'precision', 'type' => 'float', 'facet' => true],
                ['name' => 'ledger_id', 'type' => 'string', 'reference' => 'ledgers.ledger_id', 'facet' => true],
                ['name' => 'identity_id', 'type' => 'string', 'facet' => true, 'reference' => 'identities.identity_id', 'optional' => true],
                ['name' => 'balance_id', 'type' => 'string', 'facet' => true],
                ['name' => 'indicator', 'type' => 'string', 'facet' => true],
                ['name' => 'currency', 'type' => 'string', 'facet' => true],
                ['name' => 'created_at', 'type' => 'int64', 'facet' => true],
                ['name' => 'inflight_expires_at', 'type' => 'int64', 'facet' => true],
                ['name' => 'meta_data', 'type' => 'object', 'facet' => true, 'optional' => true],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,
        ];
    }

    /**
     * getTransactionSchema returns the schema for the "transactions" collection.
     *
     * @return array<string, mixed>
     */
    public static function getTransactionSchema(): array
    {
        return [
            'name' => 'transactions',
            'fields' => [
                ['name' => 'precise_amount', 'type' => 'string', 'facet' => true],
                ['name' => 'amount', 'type' => 'float', 'facet' => true],
                ['name' => 'precision', 'type' => 'float', 'facet' => true],
                ['name' => 'transaction_id', 'type' => 'string', 'facet' => true],
                ['name' => 'parent_transaction', 'type' => 'string', 'facet' => true],
                ['name' => 'source', 'type' => 'string', 'reference' => 'balances.id', 'facet' => true],
                ['name' => 'destination', 'type' => 'string', 'reference' => 'balances.id', 'facet' => true],
                ['name' => 'reference', 'type' => 'string', 'facet' => true],
                ['name' => 'currency', 'type' => 'string', 'facet' => true],
                ['name' => 'description', 'type' => 'string', 'facet' => true],
                ['name' => 'status', 'type' => 'string', 'facet' => true],
                ['name' => 'hash', 'type' => 'string', 'facet' => true],
                ['name' => 'allow_overdraft', 'type' => 'bool', 'facet' => true],
                ['name' => 'inflight', 'type' => 'bool', 'facet' => true],
                ['name' => 'created_at', 'type' => 'int64', 'facet' => true],
                ['name' => 'scheduled_for', 'type' => 'int64', 'facet' => true],
                ['name' => 'inflight_expiry_date', 'type' => 'int64', 'facet' => true],
                ['name' => 'effective_date', 'type' => 'int64', 'facet' => true],
                ['name' => 'meta_data', 'type' => 'object', 'facet' => true, 'optional' => true],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,
        ];
    }

    /**
     * getReconciliationSchema returns the schema for the "reconciliations" collection.
     *
     * @return array<string, mixed>
     */
    public static function getReconciliationSchema(): array
    {
        return [
            'name' => 'reconciliations',
            'fields' => [
                ['name' => 'reconciliation_id', 'type' => 'string', 'facet' => true],
                ['name' => 'upload_id', 'type' => 'string', 'facet' => true],
                ['name' => 'status', 'type' => 'string', 'facet' => true],
                ['name' => 'matched_transactions', 'type' => 'int32', 'facet' => true],
                ['name' => 'unmatched_transactions', 'type' => 'int32', 'facet' => true],
                ['name' => 'started_at', 'type' => 'int64', 'facet' => true],
                ['name' => 'completed_at', 'type' => 'int64', 'facet' => true],
            ],
            'default_sorting_field' => 'started_at',
        ];
    }

    /**
     * getIdentitySchema returns the schema for the "identities" collection.
     *
     * @return array<string, mixed>
     */
    public static function getIdentitySchema(): array
    {
        return [
            'name' => 'identities',
            'fields' => [
                ['name' => 'identity_id', 'type' => 'string', 'facet' => true],
                ['name' => 'identity_type', 'type' => 'string', 'facet' => true],
                ['name' => 'organization_name', 'type' => 'string', 'facet' => true],
                ['name' => 'category', 'type' => 'string', 'facet' => true],
                ['name' => 'first_name', 'type' => 'string', 'facet' => true],
                ['name' => 'last_name', 'type' => 'string', 'facet' => true],
                ['name' => 'other_names', 'type' => 'string', 'facet' => true],
                ['name' => 'gender', 'type' => 'string', 'facet' => true],
                ['name' => 'email_address', 'type' => 'string', 'facet' => true],
                ['name' => 'phone_number', 'type' => 'string', 'facet' => true],
                ['name' => 'nationality', 'type' => 'string', 'facet' => true],
                ['name' => 'street', 'type' => 'string', 'facet' => true],
                ['name' => 'country', 'type' => 'string', 'facet' => true],
                ['name' => 'state', 'type' => 'string', 'facet' => true],
                ['name' => 'post_code', 'type' => 'string', 'facet' => true],
                ['name' => 'city', 'type' => 'string', 'facet' => true],
                ['name' => 'dob', 'type' => 'int64', 'facet' => true],
                ['name' => 'created_at', 'type' => 'int64', 'facet' => true],
                ['name' => 'meta_data', 'type' => 'object', 'facet' => true, 'optional' => true],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,
        ];
    }

    /**
     * Performs a Typesense HTTP request and decodes the JSON response.
     *
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     *
     * @throws SearchException on transport failure or non-2xx response; the
     *                         message embeds "status: <code>" and the response body
     */
    private function request(string $method, string $path, ?array $query = null, ?array $body = null): array
    {
        $options = [
            'headers' => [
                'X-TYPESENSE-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'http_errors' => false,
        ];
        if ($query !== null) {
            $options['query'] = self::normalizeQuery($query);
        }
        if ($body !== null) {
            try {
                $options['body'] = json_encode(
                    $body === [] ? new \stdClass() : $body,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                );
            } catch (\JsonException $e) {
                throw new SearchException($e->getMessage(), 0, $e);
            }
        }

        try {
            $resp = $this->client->request($method, $this->host . $path, $options);
        } catch (BadResponseException $e) {
            $code = $e->getResponse() !== null ? $e->getResponse()->getStatusCode() : 0;
            throw new SearchException(sprintf('status: %d response: %s', $code, $e->getMessage()), $code, $e);
        } catch (\Throwable $e) {
            throw new SearchException($e->getMessage(), 0, $e);
        }

        $statusCode = $resp->getStatusCode();
        $respBody = (string) $resp->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new SearchException(sprintf('status: %d response: %s', $statusCode, $respBody), $statusCode);
        }

        $decoded = json_decode($respBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Converts booleans to the "true"/"false" strings Typesense expects in
     * query parameters (http_build_query would emit 1/0).
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private static function normalizeQuery(array $query): array
    {
        $out = [];
        foreach ($query as $k => $v) {
            if (is_bool($v)) {
                $out[$k] = $v ? 'true' : 'false';
            } elseif ($v !== null) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Parses an RFC3339 / RFC3339Nano timestamp string (Go's two parse
     * attempts in normalizeTimeFields), returning null on failure.
     */
    private static function parseRFC3339(string $v): ?\DateTimeImmutable
    {
        // Truncate a >6-digit fractional part (nanoseconds) to microseconds.
        $normalized = preg_replace_callback(
            '/\.(\d{7,9})(?=$|[Z+\-])/',
            static fn (array $m): string => '.' . substr($m[1], 0, 6),
            $v
        );
        if (!is_string($normalized)) {
            $normalized = $v;
        }

        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $normalized, new \DateTimeZone('UTC'));
            if ($parsed === false) {
                continue;
            }
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                continue;
            }

            return $parsed;
        }

        return null;
    }
}
