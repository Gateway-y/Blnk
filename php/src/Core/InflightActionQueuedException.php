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

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\ApiError\ErrorCode;

/**
 * ErrInflightActionQueued is returned by EnqueueInflightAction when a commit or
 * void for the same transaction is already queued (asynq TaskID conflict). The
 * API maps it to HTTP 409.
 *
 * Port of the `ErrInflightActionQueued` sentinel error in queue.go
 * (`errors.New("a commit or void is already queued for this transaction")`):
 * the Go `errors.Is(err, blnk.ErrInflightActionQueued)` check becomes
 * `catch (InflightActionQueuedException)` / `instanceof`. It carries the
 * catalog code the Go API layer responds with (apierror.ErrGenConflict → 409).
 */
final class InflightActionQueuedException extends ApiErrorException
{
    /** The Go sentinel's message. */
    public const MESSAGE = 'a commit or void is already queued for this transaction';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(ErrorCode::ErrGenConflict, self::MESSAGE, null, $previous);
    }
}
