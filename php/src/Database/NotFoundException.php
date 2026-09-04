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
 * NotFoundException is the PHP equivalent of Go's `sql.ErrNoRows` at the
 * repository boundary: repositories throw it when a query yields no rows,
 * and callers catch it (where the Go code checks `err == sql.ErrNoRows`).
 *
 * Subclass of ApiErrorException with code NOT_FOUND, per PORTING.md.
 */
class NotFoundException extends ApiErrorException
{
    public function __construct(string $message = 'sql: no rows in result set', mixed $details = null, ?\Throwable $previous = null)
    {
        parent::__construct(ErrorCode::ErrNotFound, $message, $details, $previous);
    }
}
