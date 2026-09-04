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

namespace Blnk\Internal\Lock;

/**
 * Port of the Go sentinel `redlock.ErrLockHeld` ("lock already held").
 * Where Go code checks `errors.Is(err, ErrLockHeld)`, PHP code checks
 * `$e instanceof LockHeldException`.
 */
class LockHeldException extends \RuntimeException
{
    public function __construct(string $message = 'lock already held', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
