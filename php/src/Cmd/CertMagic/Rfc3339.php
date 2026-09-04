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

namespace Blnk\Cmd\CertMagic;

/**
 * Rfc3339 reproduces Go's `time.Time` JSON encoding (RFC 3339 with
 * nanoseconds, "Z" for UTC) and its zero value ("0001-01-01T00:00:00Z"), as
 * found in the lock files, account files and certificate metadata CertMagic
 * writes. A Go zero time is represented by null in PHP.
 */
final class Rfc3339
{
    /** The JSON form of Go's zero `time.Time`. */
    public const Zero = '0001-01-01T00:00:00Z';

    private function __construct()
    {
    }

    /**
     * encode formats a time like Go's `time.Time.MarshalJSON` (RFC3339Nano);
     * null (the zero time) encodes as {@see Zero}.
     */
    public static function encode(?\DateTimeImmutable $t): string
    {
        if ($t === null) {
            return self::Zero;
        }
        $base = $t->format('Y-m-d\TH:i:s');
        $fraction = rtrim($t->format('u'), '0');
        if ($fraction !== '') {
            $base .= '.' . $fraction;
        }
        $offset = $t->format('P');
        return $base . ($offset === '+00:00' ? 'Z' : $offset);
    }

    /**
     * decode parses an RFC 3339 timestamp (any number of fractional digits);
     * returns null for an empty string or Go's zero time.
     *
     * @throws \RuntimeException on an unparseable value
     */
    public static function decode(?string $s): ?\DateTimeImmutable
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);
        if ($s === '' || $s === self::Zero) {
            return null;
        }
        // PHP parses at most 6 fractional digits; Go emits up to 9.
        $normalized = (string) preg_replace_callback('/\.(\d{7,})(?=[Zz+\-])/', static function (array $m): string {
            return '.' . substr($m[1], 0, 6);
        }, $s);
        try {
            $t = new \DateTimeImmutable($normalized);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('parsing time "%s" as RFC3339: %s', $s, $e->getMessage()), 0, $e);
        }
        if ((int) $t->format('Y') <= 1) {
            return null; // Go zero time
        }
        return $t;
    }

    /** isZero reports whether the time is Go's zero value (null in the port). */
    public static function isZero(?\DateTimeImmutable $t): bool
    {
        return $t === null;
    }

    /** now returns the current time with microsecond precision in the local timezone (Go: time.Now()). */
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }

    /** nowUTC returns the current UTC time (Go: time.Now().UTC()). */
    public static function nowUTC(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** seconds converts a time to a float unix timestamp (Go comparisons via Before/After/Sub). */
    public static function seconds(\DateTimeImmutable $t): float
    {
        return (float) $t->format('U.u');
    }

    /** fromSeconds builds a UTC time from a unix timestamp (Go: time.Unix(sec, 0).UTC()). */
    public static function fromSeconds(int $seconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * durationString renders seconds the way Go prints a time.Duration with
     * %v (e.g. "2s", "1m30s", "500ms", "720h0m0s").
     */
    public static function durationString(float $seconds): string
    {
        $neg = $seconds < 0;
        $seconds = abs($seconds);
        if ($seconds < 1) {
            $out = rtrim(rtrim(sprintf('%.6F', $seconds * 1000), '0'), '.') . 'ms';
            return ($neg ? '-' : '') . $out;
        }
        $whole = (int) floor($seconds);
        $frac = $seconds - $whole;
        $h = intdiv($whole, 3600);
        $m = intdiv($whole % 3600, 60);
        $s = $whole % 60;
        $secStr = rtrim(rtrim(sprintf('%.9F', $s + $frac), '0'), '.');
        $out = '';
        if ($h > 0) {
            $out .= $h . 'h';
        }
        if ($h > 0 || $m > 0) {
            $out .= $m . 'm';
        }
        $out .= $secStr . 's';
        return ($neg ? '-' : '') . $out;
    }
}
