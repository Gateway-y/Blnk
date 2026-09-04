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
 * Histogram is the in-memory replacement for OTel `metric.Float64Histogram` /
 * `metric.Int64Histogram`. It keeps count/sum/min/max per attribute set within
 * the current PHP process; nothing is exported.
 */
final class Histogram
{
    public readonly string $name;

    public readonly string $description;

    public readonly string $unit;

    /** @var array<string, array{count: int, sum: float, min: float, max: float}> */
    private array $values = [];

    public function __construct(string $name, string $description = '', string $unit = '')
    {
        $this->name = $name;
        $this->description = $description;
        $this->unit = $unit;
    }

    /**
     * Record records a measurement (OTel `histogram.Record(ctx, value, attrs...)`).
     *
     * @param array<string, mixed> $attributes
     */
    public function record(int|float $value, array $attributes = []): void
    {
        $key = MetricAttributes::key($attributes);
        $value = (float) $value;
        if (!isset($this->values[$key])) {
            $this->values[$key] = ['count' => 0, 'sum' => 0.0, 'min' => $value, 'max' => $value];
        }
        $bucket = &$this->values[$key];
        $bucket['count']++;
        $bucket['sum'] += $value;
        $bucket['min'] = min($bucket['min'], $value);
        $bucket['max'] = max($bucket['max'], $value);
    }

    /**
     * The accumulated aggregates, keyed by serialized attribute set.
     *
     * @return array<string, array{count: int, sum: float, min: float, max: float}>
     */
    public function values(): array
    {
        return $this->values;
    }

    /** Resets all accumulated values (test helper). */
    public function reset(): void
    {
        $this->values = [];
    }
}
