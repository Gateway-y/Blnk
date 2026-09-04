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

/**
 * BulkInflightAction discriminates between the two operations supported by
 * BulkInflightUpdate.
 *
 * Go: `type BulkInflightAction int` with the iota constants `BulkInflightVoid`
 * (0) and `BulkInflightCommit` (1) (transaction_inflight.go). The Go type is a
 * plain int, so the PHP port keeps `int` values as class constants (rather than
 * a closed enum) so the "unknown bulk inflight action" branch of
 * {@see Blnk::bulkInflightUpdate()} stays reachable, as in Go.
 */
final class BulkInflightAction
{
    public const BulkInflightVoid = 0;
    public const BulkInflightCommit = 1;

    private function __construct()
    {
    }
}
