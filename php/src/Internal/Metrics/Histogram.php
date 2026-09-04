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

use Blnk\Internal\Traces\Clock;

/**
 * Histogram is the in-memory `metric.Float64Histogram` /
 * `metric.Int64Histogram` of the PHP port. It keeps count/sum/min/max and
 * the explicit-bucket counts of the SDK's default aggregation (boundaries
 * 0, 5, 10, 25, 50, 75, 100, 250, 500, 750, 1000, 2500, 5000, 7500, 10000)
 * per attribute set within the current PHP process; the {@see MeterProvider}
 * collects it as an OTLP cumulative `Histogram`.
 */
final class Histogram
{
    /** The SDK's default explicit bucket boundaries. */
    public const DefaultBounds = [0.0, 5.0, 10.0, 25.0, 50.0, 75.0, 100.0, 250.0, 500.0, 750.0, 1000.0, 2500.0, 5000.0, 7500.0, 10000.0];

    public readonly string $name;

    public readonly string $description;

    public readonly string $unit;

    /** True for an Int64Histogram, false for a Float64Histogram (export value type). */
    public readonly bool $integer;

    /** @var float[] */
    private array $bounds;

    private int $createdAtUnixNano;

    /** @var array<string, array{count: int, sum: float, min: float, max: float, buckets: int[]}> */
    private array $values = [];

    /**
     * @param float[]|null $bounds explicit bucket boundaries (defaults to {@see DefaultBounds})
     */
    public function __construct(string $name, string $description = '', string $unit = '', bool $integer = false, ?array $bounds = null)
    {
        $this->name = $name;
        $this->description = $description;
        $this->unit = $unit;
        $this->integer = $integer;
        $this->bounds = $bounds ?? self::DefaultBounds;
        $this->createdAtUnixNano = Clock::nowUnixNano();
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
            $this->values[$key] = [
                'count' => 0,
                'sum' => 0.0,
                'min' => $value,
                'max' => $value,
                'buckets' => array_fill(0, count($this->bounds) + 1, 0),
            ];
        }
        $bucket = &$this->values[$key];
        $bucket['count']++;
        $bucket['sum'] += $value;
        $bucket['min'] = min($bucket['min'], $value);
        $bucket['max'] = max($bucket['max'], $value);
        $bucket['buckets'][$this->bucketIndex($value)]++;
        unset($bucket);

        MeterProvider::onMeasurement();
    }

    /**
     * The accumulated aggregates, keyed by serialized attribute set.
     *
     * @return array<string, array{count: int, sum: float, min: float, max: float, buckets: int[]}>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * The explicit bucket boundaries.
     *
     * @return float[]
     */
    public function bounds(): array
    {
        return $this->bounds;
    }

    /** Resets all accumulated values (test helper). */
    public function reset(): void
    {
        $this->values = [];
        $this->createdAtUnixNano = Clock::nowUnixNano();
    }

    /**
     * collect returns the instrument as OTLP-shaped metric data (a cumulative
     * Histogram with one data point per attribute set).
     *
     * @return array<string, mixed>
     */
    public function collect(int $timeUnixNano): array
    {
        $points = [];
        foreach ($this->values as $key => $agg) {
            $points[] = [
                'attributes' => MetricAttributes::decode((string) $key),
                'startTimeUnixNano' => $this->createdAtUnixNano,
                'timeUnixNano' => $timeUnixNano,
                'count' => $agg['count'],
                'sum' => $agg['sum'],
                'min' => $agg['min'],
                'max' => $agg['max'],
                'bucketCounts' => $agg['buckets'],
                'bounds' => $this->bounds,
            ];
        }
        return [
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'kind' => 'histogram',
            'monotonic' => false,
            'integer' => $this->integer,
            'dataPoints' => $points,
        ];
    }

    /**
     * bucketIndex finds the bucket of a value: the first boundary the value is
     * less than or equal to, else the overflow bucket (SDK semantics: upper
     * boundaries are inclusive).
     */
    private function bucketIndex(float $value): int
    {
        foreach ($this->bounds as $i => $bound) {
            if ($value <= $bound) {
                return $i;
            }
        }
        return count($this->bounds);
    }
}
