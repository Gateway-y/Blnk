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

/**
 * ReindexService handles reindexing operations.
 *
 * Port of internal/search/reindex.go. The datasource is the ported
 * \Blnk\Database\IDataSource implementation exposing:
 *   getAllLedgers(int $limit, int $offset): array
 *   getAllIdentitiesPaginated(int $limit, int $offset): array
 *   getAllBalances(int $limit, int $offset): array
 *   getAllTransactions(int $limit, int $offset): array
 *
 * Concurrency divergences (documented, per PORTING.md "Concurrency"):
 * - Go guards progress with a sync.RWMutex; PHP requests are single threaded,
 *   so the mutex is dropped and progress copies are plain clones.
 * - Go's TryReindexIfNeeded runs the reindex in a goroutine with a 30 minute
 *   context timeout; the PHP port runs it synchronously in-line with no
 *   deadline (callers wanting background behavior should dispatch it through
 *   the Redis queue / workers CLI).
 */
final class ReindexService
{
    private TypesenseClient $client;

    /** @var object The ported \Blnk\Database\IDataSource implementation. */
    private object $datasource;

    private ReindexConfig $config;

    private ReindexProgress $progress;

    /**
     * newReindexService creates a new ReindexService instance.
     */
    public function __construct(TypesenseClient $client, object $datasource, ReindexConfig $config)
    {
        if ($config->batchSize <= 0) {
            $config->batchSize = 1000;
        }
        $this->client = $client;
        $this->datasource = $datasource;
        $this->config = $config;
        $this->progress = new ReindexProgress();
        $this->progress->status = 'pending';
    }

    /**
     * getProgress returns the current progress of the reindex operation (a copy).
     */
    public function getProgress(): ReindexProgress
    {
        return clone $this->progress;
    }

    private function updateProgress(string $phase, int $processed, int $total): void
    {
        $this->progress->phase = $phase;
        $this->progress->processedRecords = $processed;
        $this->progress->totalRecords = $total;
    }

    private function addError(string $err): void
    {
        $this->progress->errors[] = $err;
    }

    /**
     * startReindex performs a complete reindex of all data.
     * It drops all collections, recreates them, and indexes data in order:
     * ledgers -> identities -> balances -> transactions
     *
     * @throws SearchException on failure (after recording it in the progress),
     *                         mirroring Go's (progress, error) return — read the
     *                         progress via getProgress()/getProgressPtr()
     */
    public function startReindex(): ReindexProgress
    {
        $this->progress = new ReindexProgress();
        $this->progress->status = 'in_progress';
        $this->progress->phase = 'starting';
        $this->progress->startedAt = new \DateTimeImmutable('now');

        Log::get()->info('Starting reindex operation');

        try {
            $this->dropCollections();
        } catch (\Throwable $e) {
            throw $this->failWithError($e, 'drop_collections');
        }

        try {
            $this->createCollections();
        } catch (\Throwable $e) {
            throw $this->failWithError($e, 'create_collections');
        }

        try {
            $this->indexLedgers();
        } catch (\Throwable $e) {
            throw $this->failWithError($e, 'indexing_ledgers');
        }

        try {
            $this->indexIdentities();
        } catch (\Throwable $e) {
            throw $this->failWithError($e, 'indexing_identities');
        }

        try {
            $this->indexBalances();
        } catch (\Throwable $e) {
            throw $this->failWithError($e, 'indexing_balances');
        }

        try {
            $this->indexTransactions();
        } catch (\Throwable $e) {
            throw $this->failWithError($e, 'indexing_transactions');
        }

        $now = new \DateTimeImmutable('now');
        $this->progress->status = 'completed';
        $this->progress->phase = 'done';
        $this->progress->completedAt = $now;

        $duration = $this->progress->startedAt !== null
            ? sprintf('%.3fs', $now->format('U.u') - $this->progress->startedAt->format('U.u'))
            : '';
        Log::get()->info('Reindex operation completed', [
            'total_records' => $this->progress->totalRecords,
            'processed_records' => $this->progress->processedRecords,
            'duration' => $duration,
        ]);

        return $this->getProgressPtr();
    }

    /**
     * getProgressPtr returns a copy of the current progress
     * (Go returns a pointer to a copied struct).
     */
    public function getProgressPtr(): ReindexProgress
    {
        return clone $this->progress;
    }

    private function failWithError(\Throwable $err, string $phase): SearchException
    {
        $now = new \DateTimeImmutable('now');
        $this->progress->status = 'failed';
        $this->progress->phase = $phase;
        $this->progress->completedAt = $now;
        $this->progress->errors[] = $err->getMessage();

        Log::get()->error('Reindex operation failed', ['phase' => $phase, 'error' => $err->getMessage()]);

        return $err instanceof SearchException
            ? $err
            : new SearchException($err->getMessage(), 0, $err);
    }

    private function dropCollections(): void
    {
        $this->updateProgress('drop_collections', 0, 0);
        Log::get()->info('Dropping all collections');

        $this->client->dropAllCollections();

        Log::get()->info('All collections dropped successfully');
    }

    private function createCollections(): void
    {
        $this->updateProgress('create_collections', 0, 0);
        Log::get()->info('Creating collections');

        $this->client->ensureCollectionsExist();

        Log::get()->info('All collections created successfully');
    }

    private function indexLedgers(): void
    {
        $this->updateProgress('indexing_ledgers', 0, 0);
        Log::get()->info('Starting to index ledgers');

        $offset = 0;
        $totalIndexed = 0;
        $batchNum = 0;

        while (true) {
            $ledgers = $this->datasource->getAllLedgers($this->config->batchSize, $offset);

            if (count($ledgers) === 0) {
                break;
            }

            $batchCount = count($ledgers);
            foreach ($ledgers as $ledger) {
                $id = self::itemId($ledger, 'ledger_id');
                try {
                    $data = TypesenseClient::toMap($ledger);
                } catch (\Throwable $e) {
                    $this->addError('ledger ' . $id . ': ' . $e->getMessage());
                    continue;
                }

                try {
                    $this->client->handleNotification(TypesenseClient::CollectionLedgers, $data);
                } catch (\Throwable $e) {
                    $this->addError('ledger ' . $id . ': ' . $e->getMessage());
                    continue;
                }
                $totalIndexed++;
            }

            $this->updateProgress('indexing_ledgers', $totalIndexed, $totalIndexed);

            $batchNum++;
            if ($batchNum % 100 === 0) {
                Log::get()->info('Ledger indexing progress', [
                    'batch' => $batchNum,
                    'indexed' => $totalIndexed,
                ]);
            }

            $offset += $batchCount;
        }

        Log::get()->info('Ledger indexing completed', ['total' => $totalIndexed]);
    }

    private function indexIdentities(): void
    {
        $this->updateProgress('indexing_identities', $this->progress->processedRecords, $this->progress->totalRecords);
        Log::get()->info('Starting to index identities');

        $offset = 0;
        $totalIndexed = 0;
        $batchNum = 0;

        while (true) {
            $identities = $this->datasource->getAllIdentitiesPaginated($this->config->batchSize, $offset);

            if (count($identities) === 0) {
                break;
            }

            $batchCount = count($identities);
            foreach ($identities as $identity) {
                $id = self::itemId($identity, 'identity_id');
                try {
                    $data = TypesenseClient::toMap($identity);
                } catch (\Throwable $e) {
                    $this->addError('identity ' . $id . ': ' . $e->getMessage());
                    continue;
                }

                try {
                    $this->client->handleNotification(TypesenseClient::CollectionIdentities, $data);
                } catch (\Throwable $e) {
                    $this->addError('identity ' . $id . ': ' . $e->getMessage());
                    continue;
                }
                $totalIndexed++;
            }

            // Mirrors the Go accounting exactly (cumulative totalIndexed added
            // per batch).
            $this->progress->processedRecords += $totalIndexed;
            $this->progress->totalRecords = $this->progress->processedRecords;

            $batchNum++;
            if ($batchNum % 100 === 0) {
                Log::get()->info('Identity indexing progress', [
                    'batch' => $batchNum,
                    'indexed' => $totalIndexed,
                ]);
            }

            $offset += $batchCount;
        }

        Log::get()->info('Identity indexing completed', ['total' => $totalIndexed]);
    }

    private function indexBalances(): void
    {
        $this->updateProgress('indexing_balances', $this->progress->processedRecords, $this->progress->totalRecords);
        Log::get()->info('Starting to index balances');

        $offset = 0;
        $totalIndexed = 0;
        $batchNum = 0;

        while (true) {
            $balances = $this->datasource->getAllBalances($this->config->batchSize, $offset);

            if (count($balances) === 0) {
                break;
            }

            $batchCount = count($balances);
            foreach ($balances as $balance) {
                $id = self::itemId($balance, 'balance_id');
                try {
                    $data = TypesenseClient::toMap($balance);
                } catch (\Throwable $e) {
                    $this->addError('balance ' . $id . ': ' . $e->getMessage());
                    continue;
                }

                try {
                    $this->client->handleNotification(TypesenseClient::CollectionBalances, $data);
                } catch (\Throwable $e) {
                    $this->addError('balance ' . $id . ': ' . $e->getMessage());
                    continue;
                }
                $totalIndexed++;
            }

            $this->progress->processedRecords += $totalIndexed;
            $this->progress->totalRecords = $this->progress->processedRecords;

            $batchNum++;
            if ($batchNum % 100 === 0) {
                Log::get()->info('Balance indexing progress', [
                    'batch' => $batchNum,
                    'indexed' => $totalIndexed,
                ]);
            }

            $offset += $batchCount;
        }

        Log::get()->info('Balance indexing completed', ['total' => $totalIndexed]);
    }

    private function indexTransactions(): void
    {
        $this->updateProgress('indexing_transactions', $this->progress->processedRecords, $this->progress->totalRecords);
        Log::get()->info('Starting to index transactions');

        $offset = 0;
        $totalIndexed = 0;
        $batchNum = 0;

        while (true) {
            $transactions = $this->datasource->getAllTransactions($this->config->batchSize, $offset);

            if (count($transactions) === 0) {
                break;
            }

            $batchCount = count($transactions);
            foreach ($transactions as $transaction) {
                $id = self::itemId($transaction, 'transaction_id');
                try {
                    $data = TypesenseClient::toMap($transaction);
                } catch (\Throwable $e) {
                    $this->addError('transaction ' . $id . ': ' . $e->getMessage());
                    continue;
                }

                try {
                    $this->client->handleNotification(TypesenseClient::CollectionTransactions, $data);
                } catch (\Throwable $e) {
                    $this->addError('transaction ' . $id . ': ' . $e->getMessage());
                    continue;
                }
                $totalIndexed++;
            }

            $this->progress->processedRecords += $totalIndexed;
            $this->progress->totalRecords = $this->progress->processedRecords;

            $batchNum++;
            if ($batchNum % 100 === 0) {
                Log::get()->info('Transaction indexing progress', [
                    'batch' => $batchNum,
                    'indexed' => $totalIndexed,
                ]);
            }

            $offset += $batchCount;
        }

        Log::get()->info('Transaction indexing completed', ['total' => $totalIndexed]);
    }

    private static function dbHasData(object $ds): bool
    {
        try {
            $ledgers = $ds->getAllLedgers(2, 0);
        } catch (\Throwable $e) {
            Log::get()->debug('could not check ledgers for reindex', ['error' => $e->getMessage()]);

            return false;
        }
        if (count($ledgers) > 1) {
            return true;
        }

        try {
            $txns = $ds->getAllTransactions(1, 0);
        } catch (\Throwable $e) {
            Log::get()->debug('could not check transactions for reindex', ['error' => $e->getMessage()]);

            return false;
        }
        if (count($txns) > 0) {
            return true;
        }

        try {
            $balances = $ds->getAllBalances(1, 0);
        } catch (\Throwable $e) {
            Log::get()->debug('could not check balances for reindex', ['error' => $e->getMessage()]);

            return false;
        }
        if (count($balances) > 0) {
            return true;
        }

        try {
            $identities = $ds->getAllIdentitiesPaginated(1, 0);
        } catch (\Throwable $e) {
            Log::get()->debug('could not check identities for reindex', ['error' => $e->getMessage()]);

            return false;
        }

        return count($identities) > 0;
    }

    private static function typesenseHasData(TypesenseClient $client): bool
    {
        try {
            $resp = $client->retrieveCollection(TypesenseClient::CollectionTransactions);
        } catch (\Throwable $e) {
            Log::get()->debug('could not retrieve transactions collection for reindex', ['error' => $e->getMessage()]);

            return true;
        }
        if (!isset($resp['num_documents'])) {
            return false;
        }

        return (int) $resp['num_documents'] > 0;
    }

    private static function shouldReindex(TypesenseClient $client, object $ds): bool
    {
        if (!self::dbHasData($ds)) {
            return false;
        }
        if (self::typesenseHasData($client)) {
            return false;
        }

        return true;
    }

    /**
     * tryReindexIfNeeded triggers a one-time reindex when the database has data
     * but Typesense is empty.
     *
     * Divergence (documented): Go runs the reindex in a goroutine with a
     * 30 minute context timeout; the PHP port runs it synchronously with no
     * deadline (see class doc).
     */
    public static function tryReindexIfNeeded(?TypesenseClient $client, ?object $ds): void
    {
        if ($client === null || $ds === null) {
            return;
        }
        if (!self::shouldReindex($client, $ds)) {
            return;
        }

        Log::get()->info('Database has data but Typesense is empty, triggering one-time reindex');

        $svc = new self($client, $ds, new ReindexConfig(1000));
        try {
            $svc->startReindex();
        } catch (\Throwable $e) {
            Log::get()->error('reindex failed', ['error' => $e->getMessage()]);

            return;
        }
        Log::get()->info('reindex completed successfully');
    }

    /**
     * Best-effort extraction of a record's identifier for error messages
     * (Go reads the typed model field, e.g. ledger.LedgerID).
     */
    private static function itemId(mixed $item, string $jsonField): string
    {
        if (is_array($item)) {
            $v = $item[$jsonField] ?? '';

            return is_scalar($v) ? (string) $v : '';
        }
        if (is_object($item)) {
            $needle = strtolower(str_replace('_', '', $jsonField));
            foreach (get_object_vars($item) as $k => $v) {
                if (strtolower(str_replace('_', '', (string) $k)) === $needle && is_scalar($v)) {
                    return (string) $v;
                }
            }
        }

        return '';
    }
}
