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
 * JsonUtil reproduces the encoding/json calls the certificate manager makes
 * (`json.Marshal`, `json.MarshalIndent(v, "", "\t")`, `json.Unmarshal`) so
 * the files it writes into storage are laid out like CertMagic's.
 */
final class JsonUtil
{
    private function __construct()
    {
    }

    /**
     * marshal is `json.Marshal`: compact JSON, slashes unescaped, HTML-safe
     * escaping of <, >, & like Go's encoder.
     *
     * @throws \RuntimeException
     */
    public static function marshal(mixed $value): string
    {
        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_PRESERVE_ZERO_FRACTION);
        if ($encoded === false) {
            throw new \RuntimeException('json: ' . json_last_error_msg());
        }
        return $encoded;
    }

    /**
     * marshalIndent is `json.MarshalIndent(v, "", indent)` (default indent: a tab).
     *
     * @throws \RuntimeException
     */
    public static function marshalIndent(mixed $value, string $indent = "\t"): string
    {
        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_PRESERVE_ZERO_FRACTION | \JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new \RuntimeException('json: ' . json_last_error_msg());
        }
        if ($indent === '    ') {
            return $encoded;
        }
        return (string) preg_replace_callback('/^(?: {4})+/m', static function (array $m) use ($indent): string {
            return str_repeat($indent, intdiv(\strlen($m[0]), 4));
        }, $encoded);
    }

    /**
     * unmarshal is `json.Unmarshal` into a generic container (associative arrays).
     *
     * @return array<string, mixed>
     * @throws \RuntimeException on malformed JSON
     */
    public static function unmarshal(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            if (json_last_error() !== \JSON_ERROR_NONE) {
                throw new \RuntimeException('json: ' . json_last_error_msg());
            }
            if (trim($json) === 'null') {
                return [];
            }
            throw new \RuntimeException('json: cannot unmarshal non-object value');
        }
        return $decoded;
    }
}
