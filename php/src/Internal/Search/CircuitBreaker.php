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
 * CircuitBreaker is a state machine to prevent sending requests that are likely to fail.
 *
 * Port of `gobreaker.CircuitBreaker` (sony/gobreaker v0.5.0) together with
 * the `circuit.GoBreaker` wrapper of typesense-go v1.1.0 (its defaults and
 * `DefaultReadyToTrip`), which the Typesense client wraps every HTTP request
 * in — see {@see TypesenseClient}. Time is tracked in float seconds; the Go
 * mutex is unnecessary in PHP's single-threaded request model.
 */
final class CircuitBreaker
{
    /** These constants are states of CircuitBreaker. */
    public const StateClosed = 0;
    public const StateHalfOpen = 1;
    public const StateOpen = 2;

    /** gobreaker defaultInterval (0: never clear the closed-state counts). */
    public const DefaultIntervalSec = 0.0;

    /** gobreaker defaultTimeout (60 seconds). */
    public const DefaultTimeoutSec = 60.0;

    /** typesense-go circuit.DefaultGoBreakerName */
    public const DefaultGoBreakerName = 'GoBreaker';

    /** typesense-go circuit.DefaultGoBreakerMaxRequests */
    public const DefaultGoBreakerMaxRequests = 50;

    /** typesense-go circuit.DefaultGoBreakerInterval (2 * time.Minute), seconds. */
    public const DefaultGoBreakerIntervalSec = 120.0;

    /** typesense-go circuit.DefaultGoBreakerTimeout (1 * time.Minute), seconds. */
    public const DefaultGoBreakerTimeoutSec = 60.0;

    private string $name;

    private int $maxRequests;

    private float $interval;

    private float $timeout;

    /** @var callable(CircuitBreakerCounts): bool */
    private $readyToTrip;

    /** @var callable(?\Throwable): bool */
    private $isSuccessful;

    /** @var (callable(string, int, int): void)|null */
    private $onStateChange;

    private int $state = self::StateClosed;

    private int $generation = 0;

    private CircuitBreakerCounts $counts;

    /** Expiry of the current generation (null is Go's zero time). */
    private ?float $expiry = null;

    /**
     * NewCircuitBreaker returns a new CircuitBreaker configured with the given Settings.
     */
    public function __construct(CircuitBreakerSettings $st)
    {
        $this->name = $st->name;
        $this->onStateChange = $st->onStateChange;

        if ($st->maxRequests === 0) {
            $this->maxRequests = 1;
        } else {
            $this->maxRequests = $st->maxRequests;
        }

        if ($st->interval <= 0) {
            $this->interval = self::DefaultIntervalSec;
        } else {
            $this->interval = $st->interval;
        }

        if ($st->timeout <= 0) {
            $this->timeout = self::DefaultTimeoutSec;
        } else {
            $this->timeout = $st->timeout;
        }

        if ($st->readyToTrip === null) {
            $this->readyToTrip = [self::class, 'defaultReadyToTrip'];
        } else {
            $this->readyToTrip = $st->readyToTrip;
        }

        if ($st->isSuccessful === null) {
            $this->isSuccessful = [self::class, 'defaultIsSuccessful'];
        } else {
            $this->isSuccessful = $st->isSuccessful;
        }

        $this->counts = new CircuitBreakerCounts();
        $this->toNewGeneration(microtime(true));
    }

    /**
     * NewGoBreaker mirrors typesense-go `circuit.NewGoBreaker(opts...)`: the
     * typesense defaults (name "GoBreaker", 50 max requests, 2 minute
     * interval, 1 minute timeout, {@see goBreakerDefaultReadyToTrip}) with
     * the given overrides.
     *
     * @param (callable(CircuitBreakerCounts): bool)|null $readyToTrip
     * @param (callable(string, int, int): void)|null $onStateChange
     */
    public static function newGoBreaker(
        ?string $name = null,
        ?int $maxRequests = null,
        ?float $intervalSec = null,
        ?float $timeoutSec = null,
        ?callable $readyToTrip = null,
        ?callable $onStateChange = null
    ): self {
        $settings = new CircuitBreakerSettings();
        $settings->name = $name ?? self::DefaultGoBreakerName;
        $settings->maxRequests = $maxRequests ?? self::DefaultGoBreakerMaxRequests;
        $settings->interval = $intervalSec ?? self::DefaultGoBreakerIntervalSec;
        $settings->timeout = $timeoutSec ?? self::DefaultGoBreakerTimeoutSec;
        $settings->readyToTrip = $readyToTrip ?? [self::class, 'goBreakerDefaultReadyToTrip'];
        $settings->onStateChange = $onStateChange;
        return new self($settings);
    }

    /**
     * defaultReadyToTrip (gobreaker): more than 5 consecutive failures.
     */
    public static function defaultReadyToTrip(CircuitBreakerCounts $counts): bool
    {
        return $counts->consecutiveFailures > 5;
    }

    /**
     * DefaultReadyToTrip (typesense-go circuit): more than 100 requests with a
     * failure ratio above 50 percent.
     */
    public static function goBreakerDefaultReadyToTrip(CircuitBreakerCounts $counts): bool
    {
        return $counts->requests > 100 &&
            ($counts->totalFailures / $counts->requests) > 0.5;
    }

    /**
     * defaultIsSuccessful: no exception means success.
     */
    public static function defaultIsSuccessful(?\Throwable $err): bool
    {
        return $err === null;
    }

    /**
     * String implements stringer interface for a state.
     */
    public static function stateString(int $state): string
    {
        return match ($state) {
            self::StateClosed => 'closed',
            self::StateHalfOpen => 'half-open',
            self::StateOpen => 'open',
            default => sprintf('unknown state: %d', $state),
        };
    }

    /**
     * Name returns the name of the CircuitBreaker.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * State returns the current state of the CircuitBreaker.
     */
    public function state(): int
    {
        $now = microtime(true);
        [$state] = $this->currentState($now);
        return $state;
    }

    /**
     * Counts returns internal counters (a copy).
     */
    public function counts(): CircuitBreakerCounts
    {
        return clone $this->counts;
    }

    /**
     * Execute runs the given request if the CircuitBreaker accepts it.
     * Execute throws instantly if the CircuitBreaker rejects the request.
     * Otherwise, Execute returns the result of the request.
     * If the request throws, the CircuitBreaker judges it with IsSuccessful
     * (a failure by default) and rethrows it — Go's error return and panic
     * cases collapse into one in PHP.
     *
     * @template T
     *
     * @param callable(): T $req
     *
     * @return T
     *
     * @throws CircuitBreakerException when the breaker is open or half-open and saturated
     * @throws \Throwable whatever the request throws
     */
    public function execute(callable $req): mixed
    {
        $generation = $this->beforeRequest();

        try {
            $result = $req();
        } catch (\Throwable $err) {
            $this->afterRequest($generation, ($this->isSuccessful)($err));
            throw $err;
        }

        $this->afterRequest($generation, ($this->isSuccessful)(null));
        return $result;
    }

    /**
     * @throws CircuitBreakerException
     */
    private function beforeRequest(): int
    {
        $now = microtime(true);
        [$state, $generation] = $this->currentState($now);

        if ($state === self::StateOpen) {
            throw CircuitBreakerException::openState();
        } elseif ($state === self::StateHalfOpen && $this->counts->requests >= $this->maxRequests) {
            throw CircuitBreakerException::tooManyRequests();
        }

        $this->counts->onRequest();
        return $generation;
    }

    private function afterRequest(int $before, bool $success): void
    {
        $now = microtime(true);
        [$state, $generation] = $this->currentState($now);
        if ($generation !== $before) {
            return;
        }

        if ($success) {
            $this->onSuccess($state, $now);
        } else {
            $this->onFailure($state, $now);
        }
    }

    private function onSuccess(int $state, float $now): void
    {
        switch ($state) {
            case self::StateClosed:
                $this->counts->onSuccess();
                break;
            case self::StateHalfOpen:
                $this->counts->onSuccess();
                if ($this->counts->consecutiveSuccesses >= $this->maxRequests) {
                    $this->setState(self::StateClosed, $now);
                }
                break;
        }
    }

    private function onFailure(int $state, float $now): void
    {
        switch ($state) {
            case self::StateClosed:
                $this->counts->onFailure();
                if (($this->readyToTrip)(clone $this->counts)) {
                    $this->setState(self::StateOpen, $now);
                }
                break;
            case self::StateHalfOpen:
                $this->setState(self::StateOpen, $now);
                break;
        }
    }

    /**
     * @return array{0: int, 1: int} [state, generation]
     */
    private function currentState(float $now): array
    {
        switch ($this->state) {
            case self::StateClosed:
                if ($this->expiry !== null && $this->expiry < $now) {
                    $this->toNewGeneration($now);
                }
                break;
            case self::StateOpen:
                if ($this->expiry !== null && $this->expiry < $now) {
                    $this->setState(self::StateHalfOpen, $now);
                }
                break;
        }
        return [$this->state, $this->generation];
    }

    private function setState(int $state, float $now): void
    {
        if ($this->state === $state) {
            return;
        }

        $prev = $this->state;
        $this->state = $state;

        $this->toNewGeneration($now);

        if ($this->onStateChange !== null) {
            ($this->onStateChange)($this->name, $prev, $state);
        }
    }

    private function toNewGeneration(float $now): void
    {
        $this->generation++;
        $this->counts->clear();

        switch ($this->state) {
            case self::StateClosed:
                if ($this->interval == 0.0) {
                    $this->expiry = null;
                } else {
                    $this->expiry = $now + $this->interval;
                }
                break;
            case self::StateOpen:
                $this->expiry = $now + $this->timeout;
                break;
            default: // StateHalfOpen
                $this->expiry = null;
        }
    }
}
