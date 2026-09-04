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

namespace Blnk\Api;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Query ports the Gin context accessors the handlers rely on onto PSR-7:
 *
 * - `c.Request.URL.Query()` → {@see Query::values()} (Go url.Values semantics:
 *   every occurrence of a repeated key is kept, keys are not bracket-parsed)
 * - `c.Query(key)`          → {@see Query::query()}
 * - `c.DefaultQuery(key, d)`→ {@see Query::defaultQuery()}
 * - `c.QueryArray(key)`     → {@see Query::queryArray()}
 * - `c.Param(key)`          → {@see Query::param()}
 * - `c.ClientIP()`          → {@see Query::clientIP()}
 * - `strconv.Atoi(s)`       → {@see Query::atoi()}
 */
final class Query
{
    private function __construct()
    {
    }

    /**
     * values parses the raw query string exactly like Go's url.ParseQuery
     * (used by `c.Request.URL.Query()`): pairs are split on `&`, keys and
     * values are query-unescaped (`+` becomes a space), a pair without `=`
     * yields an empty value, and malformed escapes are ignored rather than
     * failing the whole parse (Gin discards the ParseQuery error).
     *
     * PHP's parse_str() is deliberately not used: it collapses repeated keys
     * and rewrites `a.b`/`a b` style names, which would break both
     * `c.QueryArray("include")` and the `field_operator` filter parser.
     *
     * @return array<string, string[]>
     */
    public static function values(ServerRequestInterface $request): array
    {
        $raw = $request->getUri()->getQuery();
        $values = [];
        if ($raw === '') {
            return $values;
        }

        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            // Go (>= 1.17) rejects pairs containing a semicolon; Gin then sees
            // an empty value set for that pair, so we skip it as well.
            if (str_contains($pair, ';')) {
                continue;
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                $key = $pair;
                $value = '';
            } else {
                $key = substr($pair, 0, $eq);
                $value = substr($pair, $eq + 1);
            }
            $key = urldecode($key);
            $value = urldecode($value);
            $values[$key][] = $value;
        }

        return $values;
    }

    /**
     * query is the port of `c.Query(key)`: the first value of the parameter,
     * or "" when absent.
     */
    public static function query(ServerRequestInterface $request, string $key): string
    {
        return self::defaultQuery($request, $key, '');
    }

    /**
     * defaultQuery is the port of `c.DefaultQuery(key, defaultValue)`: the
     * first value of the parameter, or the default when the key is absent
     * (an explicitly empty `key=` yields "", not the default — as in Gin).
     */
    public static function defaultQuery(ServerRequestInterface $request, string $key, string $default): string
    {
        $values = self::values($request);
        if (!array_key_exists($key, $values) || $values[$key] === []) {
            return $default;
        }

        return $values[$key][0];
    }

    /**
     * queryArray is the port of `c.QueryArray(key)`: every value given for the
     * parameter (an empty array when absent).
     *
     * @return string[]
     */
    public static function queryArray(ServerRequestInterface $request, string $key): array
    {
        return self::values($request)[$key] ?? [];
    }

    /**
     * param is the port of `c.Param(key)`: the route parameter value or "".
     *
     * @param array<string, mixed> $args the Slim route arguments
     */
    public static function param(array $args, string $key): string
    {
        $value = $args[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * atoi is the port of `strconv.Atoi(s)`: an optionally signed decimal
     * integer that fits in a 64-bit int; null stands for Go's error return.
     */
    public static function atoi(string $s): ?int
    {
        if (preg_match('/^[+-]?[0-9]+$/', $s) !== 1) {
            return null;
        }
        $negative = str_starts_with($s, '-');
        $digits = ltrim(ltrim($s, '+-'), '0');
        if ($digits === '') {
            return 0;
        }
        // Reject values outside the int64 range (Go: strconv.ErrRange).
        $limit = $negative ? '9223372036854775808' : '9223372036854775807';
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return null;
        }

        return (int) $s;
    }

    /**
     * clientIP is the port of `c.ClientIP()` with Gin's default settings
     * (all proxies trusted, RemoteIPHeaders = X-Forwarded-For, X-Real-IP): the
     * first valid address of X-Forwarded-For, then X-Real-IP, then the remote
     * address of the connection.
     */
    public static function clientIP(ServerRequestInterface $request): string
    {
        foreach (['X-Forwarded-For', 'X-Real-IP'] as $header) {
            $value = $request->getHeaderLine($header);
            if ($value === '') {
                continue;
            }
            foreach (explode(',', $value) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        return $remote;
    }
}
