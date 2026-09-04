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

namespace Blnk\Api\Middleware\Tollbooth\Rate;

use Blnk\Api\Middleware\Tollbooth\Clock;

/**
 * Port of the token bucket Tollbooth vendors from golang.org/x/time/rate
 * (github.com/didip/tollbooth/v7/internal/time/rate/rate.go), limited to the
 * parts the limiter uses: NewLimiter, Limit, Burst, Allow/AllowN, TokensAt,
 * ReserveN and the reservation arithmetic behind them (Wait/WaitN,
 * SetLimit/SetBurst and Reservation.Cancel are not ported).
 *
 * A Limiter controls how frequently events are allowed to happen.
 * It implements a "token bucket" of size b, initially full and refilled
 * at rate r tokens per second.
 * Informally, in any large enough time interval, the Limiter limits the
 * rate to r tokens per second, with a maximum burst size of b events.
 * As a special case, if r == Inf (the infinite rate), b is ignored.
 * See https://en.wikipedia.org/wiki/Token_bucket for more about token buckets.
 *
 * The zero value is a valid Limiter, but it will reject all events.
 * Use NewLimiter to create non-zero Limiters.
 *
 * Each of the methods consumes a single token (AllowN/ReserveN consume n).
 * If no token is available, Allow returns false.
 * If no token is available, Reserve returns a reservation for a future token
 * and the amount of time the caller must wait before using it.
 *
 * Instants are nanoseconds as produced by {@see Clock::now()}; Go's zero time
 * is `null`. The bucket state is exported/imported through {@see state()} and
 * {@see fromState()} because a PHP process keeps nothing between requests
 * (see {@see \Blnk\Api\Middleware\Tollbooth\TokenBucketCache}).
 */
final class Limiter
{
    /**
     * Go: `type Limit float64` — the maximum frequency of some events,
     * represented as number of events per second. A zero Limit allows no events.
     *
     * Inf is the infinite rate limit; it allows all events (even if burst is zero).
     * Go: `Limit(math.MaxFloat64)`.
     */
    public const Inf = PHP_FLOAT_MAX;

    /** InfDuration is the duration returned by Delay when a Reservation is not OK. Go: `time.Duration(1<<63 - 1)`. */
    public const InfDuration = PHP_INT_MAX;

    private float $limit;
    private int $burst;
    private float $tokens = 0.0;
    /** last is the last time the limiter's tokens field was updated (null: Go's zero time). */
    private ?int $last = null;
    /** lastEvent is the latest time of a rate-limited event (past or future). */
    private ?int $lastEvent = null;

    private function __construct(float $limit, int $burst)
    {
        $this->limit = $limit;
        $this->burst = $burst;
    }

    /**
     * Every converts a minimum time interval between events to a Limit.
     */
    public static function every(int $interval): float
    {
        if ($interval <= 0) {
            return self::Inf;
        }

        return 1 / Clock::seconds($interval);
    }

    /**
     * NewLimiter returns a new Limiter that allows events up to rate r and permits
     * bursts of at most b tokens.
     *
     * Like Go's `&Limiter{limit: r, burst: b}` the bucket starts with zero
     * tokens and the zero `last` time; the first advance() then credits the
     * saturated "time since the zero time", i.e. the bucket starts full for
     * any rate worth configuring.
     */
    public static function newLimiter(float $r, int $b): self
    {
        return new self($r, $b);
    }

    /**
     * Limit returns the maximum overall event rate.
     */
    public function limit(): float
    {
        return $this->limit;
    }

    /**
     * Burst returns the maximum burst size. Burst is the maximum number of tokens
     * that can be consumed in a single call to Allow, Reserve, or Wait, so higher
     * Burst values allow more events to happen at once.
     * A zero Burst allows no events, unless limit == Inf.
     */
    public function burst(): int
    {
        return $this->burst;
    }

    /**
     * Allow is shorthand for AllowN(time.Now(), 1).
     */
    public function allow(): bool
    {
        return $this->allowN(Clock::now(), 1);
    }

    /**
     * TokensAt returns the number of tokens available for the given time.
     */
    public function tokensAt(int $t): float
    {
        [, , $tokens] = $this->advance($t); // does not mutate lim

        return $tokens;
    }

    /**
     * AllowN reports whether n events may happen at time now.
     * Use this method if you intend to drop / skip events that exceed the rate limit.
     * Otherwise use Reserve or Wait.
     */
    public function allowN(int $now, int $n): bool
    {
        return $this->reserveNInternal($now, $n, 0)->ok;
    }

    /**
     * Reserve is shorthand for ReserveN(time.Now(), 1).
     */
    public function reserve(): Reservation
    {
        return $this->reserveN(Clock::now(), 1);
    }

    /**
     * ReserveN returns a Reservation that indicates how long the caller must wait before n events happen.
     * The Limiter takes this Reservation into account when allowing future events.
     * The returned Reservation's OK() method returns false if n exceeds the Limiter's burst size.
     * Use this method if you wish to wait and slow down in accordance with the rate limit without dropping events.
     * To drop or skip events exceeding rate limit, use Allow instead.
     */
    public function reserveN(int $now, int $n): Reservation
    {
        return $this->reserveNInternal($now, $n, self::InfDuration);
    }

    /**
     * reserveN is a helper method for AllowN, ReserveN, and WaitN.
     * maxFutureReserve specifies the maximum reservation wait duration allowed.
     *
     * (Go's unexported `reserveN` — renamed because PHP method names are
     * case-insensitive and would collide with the exported ReserveN.)
     */
    private function reserveNInternal(int $now, int $n, int $maxFutureReserve): Reservation
    {
        if ($this->limit === self::Inf) {
            return new Reservation(ok: true, lim: $this, tokens: $n, timeToAct: $now);
        }

        [$now, $last, $tokens] = $this->advance($now);

        // Calculate the remaining number of tokens resulting from the request.
        $tokens -= (float) $n;

        // Calculate the wait duration
        $waitDuration = 0;
        if ($tokens < 0) {
            $waitDuration = self::durationFromTokens($this->limit, -$tokens);
        }

        // Decide result
        $ok = $n <= $this->burst && $waitDuration <= $maxFutureReserve;

        // Prepare reservation
        $r = new Reservation(ok: $ok, lim: $this, limit: $this->limit);
        if ($ok) {
            $r->tokens = $n;
            $r->timeToAct = Clock::add($now, $waitDuration);
        }

        // Update state
        if ($ok) {
            $this->last = $now;
            $this->tokens = $tokens;
            $this->lastEvent = $r->timeToAct;
        } else {
            $this->last = $last;
        }

        return $r;
    }

    /**
     * advance calculates and returns an updated state for lim resulting from the passage of time.
     * lim is not changed.
     *
     * @return array{0: int, 1: ?int, 2: float} newNow, newLast, newTokens
     */
    private function advance(int $now): array
    {
        $last = $this->last;
        if (Clock::before($now, $last)) {
            $last = $now;
        }

        // Calculate the new number of tokens, due to time that passed.
        $elapsed = Clock::sub($now, $last);
        $delta = self::tokensFromDuration($this->limit, $elapsed);
        $tokens = $this->tokens + $delta;
        $burst = (float) $this->burst;
        if ($tokens > $burst) {
            $tokens = $burst;
        }

        return [$now, $last, $tokens];
    }

    /**
     * durationFromTokens is a unit conversion function from the number of tokens to the duration
     * of time it takes to accumulate them at a rate of limit tokens per second.
     */
    private static function durationFromTokens(float $limit, float $tokens): int
    {
        // Go: seconds := tokens / float64(limit) — IEEE division (fdiv: a zero
        // limit yields ±Inf where PHP's `/` would throw).
        $seconds = fdiv($tokens, $limit);

        return Clock::durationFromFloat((float) Clock::Second * $seconds);
    }

    /**
     * tokensFromDuration is a unit conversion function from a time duration to the number of tokens
     * which could be accumulated during that duration at a rate of limit tokens per second.
     */
    private static function tokensFromDuration(float $limit, int $d): float
    {
        return Clock::seconds($d) * $limit;
    }

    /**
     * state exports the bucket for persistence between requests.
     *
     * @return array{limit: float, burst: int, tokens: float, last: ?int, last_event: ?int}
     */
    public function state(): array
    {
        return [
            'limit' => $this->limit,
            'burst' => $this->burst,
            'tokens' => $this->tokens,
            'last' => $this->last,
            'last_event' => $this->lastEvent,
        ];
    }

    /**
     * fromState rebuilds a bucket exported by {@see state()}.
     *
     * @param array<string, mixed> $s
     */
    public static function fromState(array $s): self
    {
        $lim = new self((float) ($s['limit'] ?? 0.0), (int) ($s['burst'] ?? 0));
        $lim->tokens = (float) ($s['tokens'] ?? 0.0);
        $lim->last = isset($s['last']) ? (int) $s['last'] : null;
        $lim->lastEvent = isset($s['last_event']) ? (int) $s['last_event'] : null;

        return $lim;
    }
}
