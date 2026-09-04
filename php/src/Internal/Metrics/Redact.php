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

namespace Blnk\Internal\Metrics;

/**
 * Port of Go `internal/monitoringexporter` `redact.go`: sanitizes log
 * fields/strings before they leave the process (secrets, URLs, bearer tokens).
 *
 * Go's map/slice reflection cases collapse into PHP arrays: associative arrays
 * are treated as string-keyed maps, list arrays as slices.
 */
final class Redact
{
    public const REDACTED = '[REDACTED]';

    /** @var string[] */
    private const SENSITIVE_KEY_PARTS = [
        'authorization',
        'token',
        'secret',
        'password',
        'api_key',
        'apikey',
        'credential',
        'dsn',
        'url',
        'uri',
        'endpoint',
        'callback',
        'metadata',
        'webhook',
    ];

    private const ABSOLUTE_URL_PATTERN = '~https?://[^\s"\'<>]+~';
    private const QUERY_VALUE_PATTERN = '/([?&][^=\s&]+)=([^&\s]+)/';
    private const BEARER_PATTERN = '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i';

    private function __construct()
    {
    }

    /**
     * RedactFields sanitizes a log-fields map (logrus.Fields equivalent).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null null when the map is empty (Go returns nil).
     */
    public static function redactFields(array $fields): ?array
    {
        if (count($fields) === 0) {
            return null;
        }

        $out = [];
        foreach ($fields as $key => $value) {
            $key = (string) $key;
            if (self::isSensitiveKey($key)) {
                $out[$key] = self::REDACTED;
                continue;
            }
            $out[$key] = self::redactValue($value);
        }
        return $out;
    }

    public static function redactValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \Throwable) {
            // Go: case error → RedactString(v.Error())
            return self::redactString($value->getMessage());
        }
        if (is_string($value)) {
            return self::redactString($value);
        }
        if ($value instanceof \Stringable) {
            // Go: case fmt.Stringer → RedactString(v.String())
            return self::redactString((string) $value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                // Go: []interface{} / []string / reflect slice
                $out = [];
                foreach ($value as $nested) {
                    $out[] = self::redactValue($nested);
                }
                return $out;
            }
            // Go: map[string]interface{} / map[string]string / reflect map
            $out = [];
            foreach ($value as $key => $nested) {
                $key = (string) $key;
                if (self::isSensitiveKey($key)) {
                    $out[$key] = self::REDACTED;
                    continue;
                }
                $out[$key] = self::redactValue($nested);
            }
            return $out;
        }

        return $value;
    }

    public static function redactString(string $value): string
    {
        $value = (string) preg_replace(self::BEARER_PATTERN, 'Bearer ' . self::REDACTED, $value);
        $value = (string) preg_replace_callback(self::ABSOLUTE_URL_PATTERN, static fn (array $m): string => self::redactURL($m[0]), $value);
        $value = (string) preg_replace(self::QUERY_VALUE_PATTERN, '$1' . self::REDACTED, $value);
        if (strlen($value) <= 512) {
            return $value;
        }
        return substr($value, 0, 512) . '...[truncated]';
    }

    private static function redactURL(string $raw): string
    {
        $parsed = parse_url($raw);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return self::REDACTED;
        }

        $out = $parsed['scheme'] . '://';
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            // Go replaces the whole userinfo with url.User("[REDACTED]").
            $out .= rawurlencode(self::REDACTED) . '@';
        }
        $out .= $parsed['host'];
        if (isset($parsed['port'])) {
            $out .= ':' . $parsed['port'];
        }
        $out .= $parsed['path'] ?? '';
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            parse_str($parsed['query'], $query);
            $redactedQuery = [];
            foreach (array_keys($query) as $key) {
                $redactedQuery[(string) $key] = self::REDACTED;
            }
            // Sorted, percent-encoded like Go's url.Values.Encode().
            ksort($redactedQuery);
            $out .= '?' . http_build_query($redactedQuery, '', '&', PHP_QUERY_RFC3986);
        }
        if (isset($parsed['fragment'])) {
            $out .= '#' . $parsed['fragment'];
        }
        return $out;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace('-', '_', trim($key)));
        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }
        return false;
    }
}
