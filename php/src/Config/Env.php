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

namespace Blnk\Config;

/**
 * Env is the PHP replacement for `envconfig.Process("blnk", &cnf)`: each
 * config class reads its `BLNK_*` variables (the exact names from the Go
 * `envconfig:"..."` struct tags) through these helpers.
 *
 * Semantics mirror kelseyhightower/envconfig:
 * - An unset variable leaves the field untouched (returns null here).
 * - A set variable overrides the JSON value, even when empty (strings).
 * - Unparseable numeric/bool/duration values raise \InvalidArgumentException,
 *   like envconfig.Process returning an error.
 */
final class Env
{
    private function __construct()
    {
    }

    /** Returns the raw value, or null when the variable is unset. */
    public static function lookup(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            return null;
        }
        return $value;
    }

    public static function getString(string $name): ?string
    {
        return self::lookup($name);
    }

    /**
     * Parses booleans like Go's strconv.ParseBool:
     * 1, t, T, TRUE, true, True, 0, f, F, FALSE, false, False.
     */
    public static function getBool(string $name): ?bool
    {
        $value = self::lookup($name);
        if ($value === null) {
            return null;
        }
        switch ($value) {
            case '1':
            case 't':
            case 'T':
            case 'TRUE':
            case 'true':
            case 'True':
                return true;
            case '0':
            case 'f':
            case 'F':
            case 'FALSE':
            case 'false':
            case 'False':
                return false;
        }
        throw new \InvalidArgumentException(sprintf('envconfig: invalid boolean value "%s" for %s', $value, $name));
    }

    public static function getInt(string $name): ?int
    {
        $value = self::lookup($name);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^[+-]?\d+$/', trim($value)) !== 1) {
            throw new \InvalidArgumentException(sprintf('envconfig: invalid integer value "%s" for %s', $value, $name));
        }
        return (int) trim($value);
    }

    public static function getFloat(string $name): ?float
    {
        $value = self::lookup($name);
        if ($value === null) {
            return null;
        }
        if (!is_numeric(trim($value))) {
            throw new \InvalidArgumentException(sprintf('envconfig: invalid float value "%s" for %s', $value, $name));
        }
        return (float) trim($value);
    }

    /**
     * Parses a Go `time.Duration` string ("300ms", "1.5h", "2h45m", "5m", "30s")
     * into whole seconds. The PHP port represents every `time.Duration` config
     * field as an integer number of seconds; a bare number (no unit) is
     * accepted as seconds for convenience (Go's time.ParseDuration would
     * reject it, but Blnk's JSON config already treats bare numbers as
     * seconds — see Configuration::setTransactionDefaults).
     * Sub-second results are rounded to the nearest second.
     */
    public static function getDurationSeconds(string $name): ?int
    {
        $value = self::lookup($name);
        if ($value === null) {
            return null;
        }
        return (int) round(self::parseDurationSeconds($value, $name));
    }

    /**
     * parseDurationSeconds implements Go's time.ParseDuration grammar,
     * returning seconds as float.
     */
    public static function parseDurationSeconds(string $value, string $name = ''): float
    {
        $original = $value;
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('envconfig: invalid duration "" for %s', $name));
        }

        // Bare numbers are treated as seconds (PHP-port convenience; see PHPDoc).
        if (is_numeric($value)) {
            return (float) $value;
        }

        $sign = 1.0;
        if ($value[0] === '+' || $value[0] === '-') {
            if ($value[0] === '-') {
                $sign = -1.0;
            }
            $value = substr($value, 1);
        }

        // Unit multipliers in seconds, mirroring Go's unitMap.
        $units = [
            'ns' => 1e-9,
            'us' => 1e-6,
            "\u{00B5}s" => 1e-6, // µs (micro sign)
            "\u{03BC}s" => 1e-6, // μs (greek mu)
            'ms' => 1e-3,
            's' => 1.0,
            'm' => 60.0,
            'h' => 3600.0,
        ];

        $seconds = 0.0;
        $matched = preg_match_all('/(\d+(?:\.\d*)?|\.\d+)(ns|us|\x{00B5}s|\x{03BC}s|ms|s|m|h)/u', $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($matched === false || $matched === 0) {
            throw new \InvalidArgumentException(sprintf('envconfig: invalid duration "%s" for %s', $original, $name));
        }
        // Ensure the whole string is consumed by number+unit pairs.
        $consumed = 0;
        foreach ($matches as $m) {
            if ($m[0][1] !== $consumed) {
                throw new \InvalidArgumentException(sprintf('envconfig: invalid duration "%s" for %s', $original, $name));
            }
            $consumed += strlen($m[0][0]);
            $seconds += ((float) $m[1][0]) * $units[$m[2][0]];
        }
        if ($consumed !== strlen($value)) {
            throw new \InvalidArgumentException(sprintf('envconfig: invalid duration "%s" for %s', $original, $name));
        }

        return $sign * $seconds;
    }

    /**
     * Parses a map value like envconfig does for `map[string]string`:
     * comma-separated `key:value` pairs, e.g. "Authorization:Bearer x,X-Env:prod".
     *
     * @return array<string, string>|null
     */
    public static function getHeaderMap(string $name): ?array
    {
        $value = self::lookup($name);
        if ($value === null) {
            return null;
        }
        $out = [];
        if (trim($value) === '') {
            return $out;
        }
        foreach (explode(',', $value) as $pair) {
            if ($pair === '') {
                continue;
            }
            $kv = explode(':', $pair, 2);
            if (count($kv) !== 2) {
                throw new \InvalidArgumentException(sprintf('envconfig: invalid map item "%s" for %s', $pair, $name));
            }
            $out[$kv[0]] = $kv[1];
        }
        return $out;
    }
}
