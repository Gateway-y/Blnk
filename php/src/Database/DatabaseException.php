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

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\ApiError\ErrorCode;

/**
 * DatabaseException wraps PDO errors, preserving the driver message and the
 * SQLSTATE code, per PORTING.md. Where the Go code checks
 * `pq.Error.Code == "23505"` (unique violation), PHP callers use
 * {@see DatabaseException::isUniqueViolation()}.
 */
class DatabaseException extends ApiErrorException
{
    /** SQLSTATE for unique constraint violation (Postgres `unique_violation`). */
    public const UNIQUE_VIOLATION = '23505';

    /** The five-character SQLSTATE code, when known. */
    public readonly ?string $sqlState;

    public function __construct(string $message, ?string $sqlState = null, mixed $details = null, ?\Throwable $previous = null)
    {
        parent::__construct(ErrorCode::ErrInternalServer, $message, $details, $previous);
        $this->sqlState = $sqlState;
    }

    /**
     * Wraps a PDOException preserving its message and SQLSTATE.
     * (PDOException::$code / errorInfo[0] carries the SQLSTATE for pdo_pgsql.)
     */
    public static function fromPDOException(\PDOException $e): self
    {
        $sqlState = null;
        if (isset($e->errorInfo[0]) && is_string($e->errorInfo[0])) {
            $sqlState = $e->errorInfo[0];
        } elseif (is_string($e->getCode()) && $e->getCode() !== '') {
            $sqlState = (string) $e->getCode();
        }
        return new self($e->getMessage(), $sqlState, null, $e);
    }

    /**
     * Reports whether the wrapped error is a Postgres unique-constraint
     * violation (SQLSTATE 23505) — the equivalent of the Go check
     * `pq.Error.Code == "23505"`.
     */
    public function isUniqueViolation(): bool
    {
        return $this->sqlState === self::UNIQUE_VIOLATION;
    }
}
