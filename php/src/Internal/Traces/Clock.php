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

namespace Blnk\Internal\Traces;

/**
 * Clock is the wall-clock helper of the telemetry SDK port (the analogue of
 * `time.Now()` as used by the OTel SDK): Unix nanosecond timestamps for
 * span/metric/log records and the RFC3339Nano formatting Go's `time.Time`
 * uses when marshalled to JSON.
 */
final class Clock
{
    private function __construct()
    {
    }

    /**
     * nowUnixNano returns the current wall-clock time in nanoseconds since the
     * Unix epoch (`time.Now().UnixNano()`), computed with integer arithmetic
     * from microtime so no precision is lost in a float.
     */
    public static function nowUnixNano(): int
    {
        [$usec, $sec] = explode(' ', microtime(false), 2);
        // "0.12345600" → 123456 microseconds
        $micro = (int) round(((float) $usec) * 1_000_000);
        return ((int) $sec) * 1_000_000_000 + $micro * 1_000;
    }

    /**
     * now returns the current wall-clock time as float seconds (microtime(true)).
     */
    public static function now(): float
    {
        return microtime(true);
    }

    /**
     * formatRFC3339Nano formats a Unix nanosecond timestamp the way Go marshals
     * a UTC `time.Time` to JSON (RFC3339Nano with trailing zeros of the
     * fractional part removed, e.g. "2024-01-02T15:04:05.123456Z").
     */
    public static function formatRFC3339Nano(int $unixNano): string
    {
        $sec = intdiv($unixNano, 1_000_000_000);
        $nanos = $unixNano - $sec * 1_000_000_000;
        if ($nanos < 0) {
            $sec--;
            $nanos += 1_000_000_000;
        }
        $base = gmdate('Y-m-d\TH:i:s', $sec);
        if ($nanos === 0) {
            return $base . 'Z';
        }
        $frac = rtrim(sprintf('%09d', $nanos), '0');
        return $base . '.' . $frac . 'Z';
    }
}
