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
 * Clock is the port's stand-in for the parts of Go's `time` package that
 * Tollbooth (github.com/didip/tollbooth/v7) and its vendored copy of
 * golang.org/x/time/rate rely on.
 *
 * Instants (`time.Time`) are integer nanoseconds since the Unix epoch, with
 * `null` standing for Go's zero time; durations (`time.Duration`) are integer
 * nanoseconds, saturating at the int64 bounds exactly like Go's `Sub`.
 *
 * Documented divergence: Go's `time.Now()` carries a monotonic clock reading
 * that `Sub`/`After` prefer. The token buckets of this port are shared between
 * the worker processes of one PHP server through files, so the wall clock
 * (`microtime`, microsecond resolution) is used instead; `rate.Limiter::advance`
 * already guards against the clock moving backwards.
 */
final class Clock
{
    public const Nanosecond = 1;
    public const Microsecond = 1000 * self::Nanosecond;
    public const Millisecond = 1000 * self::Microsecond;
    public const Second = 1000 * self::Millisecond;
    public const Minute = 60 * self::Second;
    public const Hour = 60 * self::Minute;

    /** Go: `maxDuration` / `minDuration` — the saturation bounds of time.Duration (math.MaxInt64 / math.MinInt64). */
    public const MaxDuration = PHP_INT_MAX;
    public const MinDuration = PHP_INT_MIN;

    /** Go: math.MaxInt32, the start value of LimitByRequest's tokensLeft. */
    public const MaxInt32 = 2147483647;

    private function __construct()
    {
    }

    /**
     * now mirrors `time.Now()`: the current wall-clock instant in nanoseconds.
     *
     * `microtime(false)` is parsed rather than `microtime(true)` so the
     * microsecond digits are kept exactly instead of going through a float.
     */
    public static function now(): int
    {
        [$frac, $sec] = explode(' ', microtime(false), 2);
        // "0.uuuuuu00" → nine fractional digits → nanoseconds.
        $nanos = (int) str_pad(substr($frac, 2, 9), 9, '0');

        return (int) $sec * self::Second + $nanos;
    }

    /**
     * sub mirrors `t.Sub(u)`: the duration t-u, saturated to the
     * [MinDuration, MaxDuration] range when it overflows. `u` null is Go's
     * zero time (year 1), which lies far enough before any instant of interest
     * that the difference always saturates to MaxDuration.
     */
    public static function sub(int $t, ?int $u): int
    {
        if ($u === null) {
            return self::MaxDuration;
        }
        // int overflow: PHP would promote to float where Go saturates.
        if ($u > 0 && $t < PHP_INT_MIN + $u) {
            return self::MinDuration;
        }
        if ($u < 0 && $t > PHP_INT_MAX + $u) {
            return self::MaxDuration;
        }

        return $t - $u;
    }

    /**
     * add mirrors `t.Add(d)`.
     */
    public static function add(int $t, int $d): int
    {
        if ($d > 0 && $t > PHP_INT_MAX - $d) {
            return PHP_INT_MAX;
        }
        if ($d < 0 && $t < PHP_INT_MIN - $d) {
            return PHP_INT_MIN;
        }

        return $t + $d;
    }

    /**
     * before mirrors `t.Before(u)`; nothing is before Go's zero time (`u` null).
     */
    public static function before(int $t, ?int $u): bool
    {
        return $u !== null && $t < $u;
    }

    /**
     * after mirrors `t.After(u)`.
     */
    public static function after(int $t, int $u): bool
    {
        return $t > $u;
    }

    /**
     * seconds mirrors `Duration.Seconds()`: the duration as a floating point
     * number of seconds, computed as float64(sec) + float64(nsec)/1e9.
     */
    public static function seconds(int $d): float
    {
        $sec = intdiv($d, self::Second);
        $nsec = $d % self::Second;

        return (float) $sec + (float) $nsec / 1e9;
    }

    /**
     * durationFromFloat is Go's `time.Duration(f)` conversion of a float64.
     *
     * Go leaves an out-of-range float→int64 conversion implementation-defined
     * (amd64 yields math.MinInt64, arm64 saturates). This port saturates: NaN
     * and +Inf become MaxDuration, -Inf MinDuration — the reading under which
     * an unbounded wait is never "within" a zero maxFutureReserve.
     */
    public static function durationFromFloat(float $f): int
    {
        if (is_nan($f) || $f >= 9.2233720368547758e18) {
            return self::MaxDuration;
        }
        if ($f <= -9.2233720368547758e18) {
            return self::MinDuration;
        }

        return (int) $f; // truncation toward zero, as Go's conversion
    }

    /**
     * intFromFloat is Go's `int(f)` conversion of a float64: truncation toward
     * zero, saturating where Go's result would be implementation-defined.
     */
    public static function intFromFloat(float $f): int
    {
        return self::durationFromFloat($f);
    }
}
