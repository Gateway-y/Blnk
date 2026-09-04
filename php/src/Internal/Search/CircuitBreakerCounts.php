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

namespace Blnk\Internal\Search;

/**
 * Counts holds the numbers of requests and their successes/failures.
 * CircuitBreaker clears the internal Counts either
 * on the change of the state or at the closed-state intervals.
 * Counts ignores the results of the requests sent before clearing.
 *
 * Port of `gobreaker.Counts` (sony/gobreaker v0.5.0), the circuit breaker
 * behind the typesense-go client.
 */
final class CircuitBreakerCounts
{
    public int $requests = 0;

    public int $totalSuccesses = 0;

    public int $totalFailures = 0;

    public int $consecutiveSuccesses = 0;

    public int $consecutiveFailures = 0;

    public function onRequest(): void
    {
        $this->requests++;
    }

    public function onSuccess(): void
    {
        $this->totalSuccesses++;
        $this->consecutiveSuccesses++;
        $this->consecutiveFailures = 0;
    }

    public function onFailure(): void
    {
        $this->totalFailures++;
        $this->consecutiveFailures++;
        $this->consecutiveSuccesses = 0;
    }

    public function clear(): void
    {
        $this->requests = 0;
        $this->totalSuccesses = 0;
        $this->totalFailures = 0;
        $this->consecutiveSuccesses = 0;
        $this->consecutiveFailures = 0;
    }
}
