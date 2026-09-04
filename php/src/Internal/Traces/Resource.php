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
 * Resource is the port of the OTel SDK `resource.Resource`: the immutable set
 * of attributes describing the entity producing telemetry (here only
 * `service.name`, see {@see Tracer::newResource()}), merged with the
 * environment resource (`OTEL_RESOURCE_ATTRIBUTES`, `OTEL_SERVICE_NAME`) the
 * way `sdktrace.WithResource` / `sdkmetric.WithResource` do.
 */
final class Resource
{
    /** @var array<string, string|int|float|bool> attributes sorted by key (attribute.Set order) */
    private array $attributes;

    private string $schemaURL;

    /**
     * @param array<string, string|int|float|bool> $attributes
     */
    public function __construct(array $attributes = [], string $schemaURL = '')
    {
        ksort($attributes, SORT_STRING);
        $this->attributes = $attributes;
        $this->schemaURL = $schemaURL;
    }

    /**
     * environment mirrors `resource.Environment()`: a resource built from the
     * OTEL_RESOURCE_ATTRIBUTES ("key=value,key2=value2", percent-decoded
     * values) and OTEL_SERVICE_NAME environment variables.
     */
    public static function environment(): self
    {
        $attributes = [];
        $raw = getenv('OTEL_RESOURCE_ATTRIBUTES');
        if (is_string($raw) && trim($raw) !== '') {
            foreach (explode(',', $raw) as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }
                $eq = strpos($pair, '=');
                if ($eq === false) {
                    continue; // Go: invalid pairs are reported through otel.Handle and skipped
                }
                $key = trim(substr($pair, 0, $eq));
                $value = trim(substr($pair, $eq + 1));
                if ($key === '') {
                    continue;
                }
                $attributes[$key] = rawurldecode($value);
            }
        }
        $svc = getenv('OTEL_SERVICE_NAME');
        if (is_string($svc) && $svc !== '') {
            $attributes['service.name'] = $svc;
        }
        return new self($attributes);
    }

    /**
     * merge mirrors `resource.Merge(a, b)`: the union of both attribute sets
     * where b's values win on conflicting keys.
     */
    public static function merge(?self $a, ?self $b): self
    {
        $attributes = $a?->attributes ?? [];
        foreach ($b?->attributes ?? [] as $key => $value) {
            $attributes[$key] = $value;
        }
        $schema = ($b?->schemaURL ?? '') !== '' ? $b->schemaURL : ($a?->schemaURL ?? '');
        return new self($attributes, $schema);
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function schemaURL(): string
    {
        return $this->schemaURL;
    }

    /** The value of an attribute, or null when unset. */
    public function get(string $key): string|int|float|bool|null
    {
        return $this->attributes[$key] ?? null;
    }
}
