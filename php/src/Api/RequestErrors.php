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

namespace Blnk\Api;

/**
 * RequestErrors stands in for Gin's per-request `c.Errors` list, which the
 * access logger reports as `error_count`. In the Go code only `c.BindJSON`
 * (which calls `c.AbortWithError`) ever appends to it. PHP serves one request
 * per process at a time, so a process-wide counter reset by the access logger
 * at the start of every request is equivalent.
 */
final class RequestErrors
{
    private static int $count = 0;

    private function __construct()
    {
    }

    /** reset clears the list at the start of a request. */
    public static function reset(): void
    {
        self::$count = 0;
    }

    /** add records one error (Go: `c.Error(err)`). */
    public static function add(): void
    {
        self::$count++;
    }

    /** count is `len(c.Errors)`. */
    public static function count(): int
    {
        return self::$count;
    }
}
