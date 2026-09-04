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

namespace Blnk\Api\Middleware\Tollbooth;

/**
 * Port of `limiter.ExpirableOptions` (github.com/didip/tollbooth/v7/limiter/limiter_options.go).
 *
 * ExpirableOptions are options used for new limiter creation.
 * Durations are nanoseconds ({@see Clock}).
 */
final class ExpirableOptions
{
    public int $defaultExpirationTTL;

    /**
     * How frequently expire job triggers
     * Deprecated: not used anymore
     */
    public int $expireJobInterval;

    public function __construct(int $defaultExpirationTTL = 0, int $expireJobInterval = 0)
    {
        $this->defaultExpirationTTL = $defaultExpirationTTL;
        $this->expireJobInterval = $expireJobInterval;
    }
}
