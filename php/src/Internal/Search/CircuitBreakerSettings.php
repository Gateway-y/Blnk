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
 * Settings configures CircuitBreaker (port of `gobreaker.Settings`):
 *
 * Name is the name of the CircuitBreaker.
 *
 * MaxRequests is the maximum number of requests allowed to pass through
 * when the CircuitBreaker is half-open.
 * If MaxRequests is 0, the CircuitBreaker allows only 1 request.
 *
 * Interval is the cyclic period of the closed state
 * for the CircuitBreaker to clear the internal Counts, in seconds.
 * If Interval is less than or equal to 0, the CircuitBreaker doesn't clear internal Counts during the closed state.
 *
 * Timeout is the period of the open state, in seconds,
 * after which the state of the CircuitBreaker becomes half-open.
 * If Timeout is less than or equal to 0, the timeout value of the CircuitBreaker is set to 60 seconds.
 *
 * ReadyToTrip is called with a copy of Counts whenever a request fails in the closed state.
 * If ReadyToTrip returns true, the CircuitBreaker will be placed into the open state.
 * If ReadyToTrip is null, default ReadyToTrip is used.
 * Default ReadyToTrip returns true when the number of consecutive failures is more than 5.
 *
 * OnStateChange is called whenever the state of the CircuitBreaker changes.
 *
 * IsSuccessful is called with the exception thrown by a request (null when none).
 * If IsSuccessful returns true, the exception is counted as a success.
 * Otherwise the exception is counted as a failure.
 * If IsSuccessful is null, default IsSuccessful is used, which returns false for all exceptions.
 */
final class CircuitBreakerSettings
{
    public string $name = '';

    public int $maxRequests = 0;

    /** Seconds. */
    public float $interval = 0.0;

    /** Seconds. */
    public float $timeout = 0.0;

    /** @var (callable(CircuitBreakerCounts): bool)|null */
    public $readyToTrip = null;

    /** @var (callable(string, int, int): void)|null receives (name, from, to) */
    public $onStateChange = null;

    /** @var (callable(?\Throwable): bool)|null */
    public $isSuccessful = null;
}
