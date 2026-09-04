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

namespace Blnk\Database;

use Blnk\Config\Configuration;
use Blnk\Config\DataSourceConfig;
use Blnk\Internal\Cache\CacheInterface;
use Blnk\Internal\Cache\RedisCache;
use Blnk\Internal\Log;
use Blnk\Internal\PgConn\PgConn;

/**
 * Port of Go `database.Datasource` (database/db.go).
 *
 * In Go the `Datasource` methods are spread over one file per concern
 * (ledger.go, balance.go, transaction_*.go, ...). Each of those files is ported
 * as a trait of the same name (PORTING.md contract: `ledger.go` →
 * `LedgerRepository`, etc.) and composed here, so `Datasource` is the single
 * concrete {@see DataSourceInterface} exactly as `*Datasource` is the single
 * concrete `IDataSource`.
 *
 * Traits access the connection as `$this->conn` (\PDO) and the cache as
 * `$this->cache` (?CacheInterface), mirroring the Go fields `Conn` / `Cache`.
 * Go's `db.Begin()` / `tx.Commit()` / `tx.Rollback()` map onto
 * `$this->conn->beginTransaction()` / `commit()` / `rollBack()`.
 */
final class Datasource implements DataSourceInterface
{
    use LedgerRepository;
    use BalanceRepository;
    use AccountRepository;
    use IdentityRepository;
    use ApiKeyRepository;
    use MetadataRepository;
    use ChainRepository;
    use ReconciliationRepository;
    use LineageRepository;
    use TransactionRepository;
    use TransactionCoalescingRepository;
    use TransactionCriteriaRepository;
    use TransactionFiltersRepository;
    use TransactionGroupingRepository;
    use TransactionInflightRepository;
    use TransactionLineageRepository;
    use TransactionQueriesRepository;
    use TransactionQueueRepository;
    use TransactionRecoveryRepository;
    use TransactionRefundsRepository;

    // Declare a package-level variable to hold the singleton instance.
    // (Go: `var instance *Datasource; once sync.Once`)

    private static ?Datasource $instance = null;

    /** Go `once sync.Once`: true once the singleton has been initialised. */
    private static bool $once = false;

    /**
     * Go: `Conn *sql.DB`.
     *
     * PDO has no explicit close; {@see close()} unsets the property, after
     * which any access throws an Error ("must not be accessed before
     * initialization") — the counterpart of Go's "sql: database is closed".
     */
    public \PDO $conn;

    /**
     * Go: `Cache cache.Cache`. Null when the cache could not be created
     * (GetDBConnection "Continue[s] without cache instead of failing completely").
     */
    public ?CacheInterface $cache;

    public function __construct(\PDO $conn, ?CacheInterface $cache = null)
    {
        $this->conn = $conn;
        $this->cache = $cache;
    }

    /**
     * Close closes the underlying database connection pool.
     *
     * Go returns `d.Conn.Close()`'s error; PDO closes by dropping the last
     * reference to the handle and cannot fail, so nothing is returned.
     */
    public function close(): void
    {
        // Dropping the reference releases the PDO connection (Go: d.Conn.Close()).
        unset($this->conn);
    }

    /**
     * NewDataSource initializes a new database connection.
     *
     * @throws DatabaseException when the connection cannot be established or the
     *   default schema cannot be set.
     */
    public static function newDataSource(Configuration $configuration): DataSourceInterface
    {
        $con = self::getDBConnection($configuration);

        // Set the default schema for this connection.
        try {
            $con->conn->exec('SET search_path TO blnk');
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }
        return $con;
    }

    /**
     * GetDBConnection ensures a single database connection instance.
     *
     * Divergence from Go (documented): `sync.Once` marks the initialisation done
     * even when `ConnectDB` fails, so later Go calls return `(nil, nil)`. Here the
     * failure is thrown and the "once" flag is only set after a successful
     * initialisation, so the next call retries instead of returning nothing.
     *
     * @throws DatabaseException when the database connection fails.
     */
    public static function getDBConnection(Configuration $configuration): Datasource
    {
        if (!self::$once) {
            $con = self::connectDB($configuration->dataSource);

            $cacheInstance = null;
            try {
                $cacheInstance = RedisCache::newCache();
            } catch (\Throwable $errCache) {
                Log::get()->error(sprintf('Error creating cache: %s', $errCache->getMessage()));
                // Continue without cache instead of failing completely.
            }

            self::$instance = new self($con, $cacheInstance);
            self::$once = true;
        }
        return self::$instance;
    }

    /**
     * ConnectDB establishes a database connection with pooling.
     *
     * @throws DatabaseException when the connection fails after all retries.
     */
    public static function connectDB(DataSourceConfig $dsConfig): \PDO
    {
        return PgConn::connectDB($dsConfig);
    }
}
