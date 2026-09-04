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

namespace Blnk\Internal\PgConn;

use Blnk\Config\DataSourceConfig;
use Blnk\Database\DatabaseException;
use Blnk\Internal\Log;

/**
 * Port of Go `internal/pg-conn` (`pg_conn.go`): Postgres connection handling.
 *
 * POOLING NOTE (documented divergence per PORTING.md): Go applies
 * `MaxOpenConns`, `MaxIdleConns`, `ConnMaxLifetime` and `ConnMaxIdleTime` to
 * the database/sql pool. PHP's PDO holds exactly one connection per
 * process/request and has no client-side pool, so those DataSourceConfig
 * values are accepted but not applied — connection pooling should be provided
 * externally (e.g. PgBouncer) when needed.
 */
final class PgConn
{
    public const MAX_CONN_RETRIES = 5;

    /** initialRetryDelay (Go: 1 * time.Second), in seconds. */
    public const INITIAL_RETRY_DELAY_SEC = 1;

    private function __construct()
    {
    }

    /**
     * ConnectDB establishes a database connection.
     * It retries the initial connection up to MAX_CONN_RETRIES times with
     * exponential backoff to handle transient network issues during startup.
     *
     * (Go opens lazily and pings; PDO connects eagerly, so the open+ping pair
     * collapses into the constructor attempt inside the retry loop.)
     *
     * @throws DatabaseException when the connection fails after all retries.
     */
    public static function connectDB(DataSourceConfig $dsConfig): \PDO
    {
        $dsn = self::toPdoDsn($dsConfig->dns);

        // Verify connection with retry
        $delay = self::INITIAL_RETRY_DELAY_SEC;
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_CONN_RETRIES; $attempt++) {
            try {
                $db = new \PDO($dsn, null, null, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]);
                Log::get()->info('database connection established');
                return $db;
            } catch (\PDOException $e) {
                $lastError = $e;
                Log::get()->warning(
                    sprintf('Database ping failed, retrying in %ds...', $delay),
                    ['error' => $e->getMessage(), 'attempt' => $attempt]
                );

                if ($attempt < self::MAX_CONN_RETRIES) {
                    sleep($delay);
                    $delay *= 2; // exponential backoff
                }
            }
        }

        // All retries exhausted
        Log::get()->error('Database connection failed after retries', ['error' => $lastError?->getMessage()]);
        if ($lastError instanceof \PDOException) {
            throw DatabaseException::fromPDOException($lastError);
        }
        throw new DatabaseException('database connection failed after retries');
    }

    /**
     * Converts a lib/pq style DSN — a `postgres://`/`postgresql://` URL or a
     * `key=value` conninfo string — into a PDO pgsql DSN
     * (`pgsql:host=...;port=...;dbname=...;...`). Recognized libpq keywords
     * are passed through unchanged.
     */
    public static function toPdoDsn(string $dns): string
    {
        $dns = trim($dns);
        if ($dns === '') {
            return 'pgsql:';
        }
        if (str_starts_with($dns, 'pgsql:')) {
            return $dns; // already a PDO DSN
        }

        $params = [];
        if (str_starts_with($dns, 'postgres://') || str_starts_with($dns, 'postgresql://')) {
            $parsed = parse_url($dns);
            if ($parsed === false) {
                throw new DatabaseException(sprintf('invalid data source DNS: %s', $dns));
            }
            if (isset($parsed['host']) && $parsed['host'] !== '') {
                $params['host'] = $parsed['host'];
            }
            if (isset($parsed['port'])) {
                $params['port'] = (string) $parsed['port'];
            }
            $dbname = trim($parsed['path'] ?? '', '/');
            if ($dbname !== '') {
                $params['dbname'] = rawurldecode($dbname);
            }
            if (isset($parsed['user']) && $parsed['user'] !== '') {
                $params['user'] = rawurldecode($parsed['user']);
            }
            if (isset($parsed['pass']) && $parsed['pass'] !== '') {
                $params['password'] = rawurldecode($parsed['pass']);
            }
            if (isset($parsed['query']) && $parsed['query'] !== '') {
                parse_str($parsed['query'], $query);
                foreach ($query as $key => $value) {
                    if (is_string($value)) {
                        $params[(string) $key] = $value;
                    }
                }
            }
        } else {
            // key=value conninfo format (values may be single-quoted).
            $matched = preg_match_all("/(\\w+)\\s*=\\s*(?:'((?:[^'\\\\]|\\\\.)*)'|(\\S+))/", $dns, $matches, PREG_SET_ORDER);
            if ($matched === false || $matched === 0) {
                throw new DatabaseException(sprintf('invalid data source DNS: %s', $dns));
            }
            foreach ($matches as $m) {
                $value = $m[3] ?? '';
                if (isset($m[2]) && $m[2] !== '') {
                    $value = stripcslashes($m[2]);
                } elseif (isset($m[3])) {
                    $value = $m[3];
                }
                $params[$m[1]] = $value;
            }
        }

        $parts = [];
        foreach ($params as $key => $value) {
            if (preg_match('/[\\s;\']/', $value) === 1) {
                $value = "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
            }
            $parts[] = $key . '=' . $value;
        }
        return 'pgsql:' . implode(';', $parts);
    }
}
