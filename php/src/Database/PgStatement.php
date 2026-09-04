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

/**
 * PgStatement is the thin execution layer the repository traits share: it
 * prepares and executes a statement with lib/pq-encoded arguments
 * ({@see PqEncoder::args()}) and exposes the pieces of `*pq.Error` the Go
 * repositories inspect (`pqErr.Code.Name()`), so each call site can keep the
 * Go error mapping and messages.
 *
 * Errors are left as \PDOException here; the traits translate them into
 * {@see DatabaseException} / {@see \Blnk\Internal\ApiError\ApiErrorException}
 * with the exact message the Go code uses at that point.
 */
final class PgStatement
{
    /** PostgreSQL condition `unique_violation`. */
    public const UNIQUE_VIOLATION = '23505';

    /** PostgreSQL condition `foreign_key_violation`. */
    public const FOREIGN_KEY_VIOLATION = '23503';

    /** PDO's generic, client-side error code (no server SQLSTATE). */
    public const GENERAL_ERROR = 'HY000';

    /** Not instantiable: static helpers only. */
    private function __construct()
    {
    }

    /**
     * Prepares and executes `$query` (PDO positional `?` placeholders) with the
     * given arguments, encoded like lib/pq would encode the Go values.
     *
     * Go: `d.Conn.ExecContext / QueryContext / QueryRowContext(ctx, query, args...)`,
     * or the same calls on a `*sql.Tx` when `$conn` has a transaction open.
     *
     * @param array<int, mixed> $args
     *
     * @throws \PDOException
     */
    public static function execute(\PDO $conn, string $query, array $args = []): \PDOStatement
    {
        $stmt = $conn->prepare($query);
        $stmt->execute(PqEncoder::args($args));
        return $stmt;
    }

    /**
     * The SQLSTATE of a PDO error, when the driver reported one
     * (`pq.Error.Code`).
     */
    public static function sqlState(\PDOException $e): ?string
    {
        if (isset($e->errorInfo[0]) && \is_string($e->errorInfo[0]) && $e->errorInfo[0] !== '') {
            return $e->errorInfo[0];
        }
        $code = $e->getCode();
        if (\is_string($code) && $code !== '') {
            return $code;
        }
        return null;
    }

    /**
     * `err.(*pq.Error)` succeeded: the error carries a SQLSTATE assigned by the
     * server (anything but PDO's client-side HY000).
     */
    public static function isServerError(\PDOException $e): bool
    {
        $state = self::sqlState($e);
        return $state !== null && $state !== self::GENERAL_ERROR;
    }

    /**
     * `pq.Error.Code.Name()` for the conditions the Go repositories switch on;
     * '' for any other error.
     */
    public static function conditionName(\PDOException $e): string
    {
        return match (self::sqlState($e)) {
            self::UNIQUE_VIOLATION => 'unique_violation',
            self::FOREIGN_KEY_VIOLATION => 'foreign_key_violation',
            default => '',
        };
    }

    /**
     * Wraps a PDO error into a {@see DatabaseException} carrying a Go-style
     * message (`apierror.NewAPIError(apierror.ErrInternalServer, message, err)`),
     * the SQLSTATE, the driver message as details and the cause.
     */
    public static function wrap(\PDOException $e, string $message): DatabaseException
    {
        return new DatabaseException($message, self::sqlState($e), $e->getMessage(), $e);
    }

    /**
     * Go `defer func() { _ = tx.Rollback() }()`: rolls back the open
     * transaction, if any, ignoring failures (a no-op after a commit).
     */
    public static function rollbackQuietly(\PDO $conn): void
    {
        try {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
        } catch (\PDOException) {
            // ignored, as in Go
        }
    }
}
