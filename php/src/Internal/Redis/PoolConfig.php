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

namespace Blnk\Internal\Redis;

/**
 * PoolConfig holds Redis connection pool settings.
 *
 * Port of Go `redis_db.PoolConfig`. phpredis has no client-side connection
 * pool (each PHP worker holds a single connection; persistent connections are
 * pooled by the SAPI process), so these values are retained for config parity
 * and future use rather than applied to the client.
 */
final class PoolConfig
{
    public int $poolSize = 0;

    public int $minIdleConns = 0;

    public function __construct(int $poolSize = 0, int $minIdleConns = 0)
    {
        $this->poolSize = $poolSize;
        $this->minIdleConns = $minIdleConns;
    }
}
