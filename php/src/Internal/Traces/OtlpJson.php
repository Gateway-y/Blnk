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
 * OtlpJson holds the OTLP/JSON encoding helpers shared by the trace and
 * metric exporters: the proto3-JSON mapping of `common.v1.AnyValue`,
 * `KeyValue`, `Resource` and `InstrumentationScope` (lowerCamelCase field
 * names, 64-bit integers as decimal strings, ids as hex strings).
 *
 * DIVERGENCE (documented): the Go exporters send OTLP over HTTP with the
 * protobuf binary encoding; the PHP port sends the OTLP JSON encoding
 * (`application/json`), which every OTLP/HTTP receiver accepts, because no
 * protobuf runtime is available.
 */
final class OtlpJson
{
    private function __construct()
    {
    }

    /**
     * anyValue encodes a PHP value as an OTLP `AnyValue`.
     *
     * @return array<string, mixed>|\stdClass
     */
    public static function anyValue(mixed $value): array|\stdClass
    {
        if ($value === null) {
            return new \stdClass(); // an empty AnyValue is the OTLP null
        }
        if (is_bool($value)) {
            return ['boolValue' => $value];
        }
        if (is_int($value)) {
            return ['intValue' => (string) $value];
        }
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return ['stringValue' => (string) $value];
            }
            return ['doubleValue' => $value];
        }
        if (is_string($value)) {
            return ['stringValue' => $value];
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                $values = [];
                foreach ($value as $item) {
                    $values[] = self::anyValue($item);
                }
                return ['arrayValue' => ['values' => $values]];
            }
            return ['kvlistValue' => ['values' => self::attributes($value)]];
        }
        if ($value instanceof \DateTimeInterface) {
            return ['stringValue' => $value->format(\DateTimeInterface::RFC3339_EXTENDED)];
        }
        if ($value instanceof \Stringable) {
            return ['stringValue' => (string) $value];
        }
        if ($value instanceof \JsonSerializable) {
            return self::anyValue($value->jsonSerialize());
        }
        if (is_object($value)) {
            return self::anyValue(get_object_vars($value));
        }

        return ['stringValue' => (string) json_encode($value)];
    }

    /**
     * attributes encodes an attribute map as a list of OTLP `KeyValue`s.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<int, array{key: string, value: array<string, mixed>|\stdClass}>
     */
    public static function attributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            $out[] = ['key' => (string) $key, 'value' => self::anyValue($value)];
        }
        return $out;
    }

    /**
     * resource encodes a {@see Resource} as an OTLP `resource.v1.Resource`.
     *
     * @return array<string, mixed>
     */
    public static function resource(?Resource $resource): array
    {
        return ['attributes' => self::attributes($resource?->attributes() ?? [])];
    }

    /**
     * scope encodes an OTLP `InstrumentationScope`.
     *
     * @return array<string, mixed>
     */
    public static function scope(string $name, string $version = ''): array
    {
        return ['name' => $name, 'version' => $version];
    }

    /**
     * uint64 encodes a 64-bit integer the proto3-JSON way (decimal string).
     */
    public static function uint64(int $value): string
    {
        return (string) $value;
    }

    /**
     * encode serializes an OTLP request body.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \JsonException
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
