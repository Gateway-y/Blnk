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
 * Gauge is the in-memory `metric.Int64Gauge` / `metric.Float64Gauge` of the
 * PHP port. It keeps the last recorded value per attribute set within the
 * current PHP process; the {@see MeterProvider} collects it as an OTLP
 * `Gauge` for the Prometheus and OTLP exporters.
 */
final class Gauge
{
    public readonly string $name;

    public readonly string $description;

    public readonly string $unit;

    /** True for an Int64Gauge, false for a Float64Gauge (export value type). */
    public readonly bool $integer;

    private int $createdAtUnixNano;

    /** @var array<string, float> last value per serialized attribute set */
    private array $values = [];

    public function __construct(string $name, string $description = '', string $unit = '', bool $integer = false)
    {
        $this->name = $name;
        $this->description = $description;
        $this->unit = $unit;
        $this->integer = $integer;
        $this->createdAtUnixNano = Clock::nowUnixNano();
    }

    /**
     * Record sets the gauge value (OTel `gauge.Record(ctx, value, attrs...)`).
     *
     * @param array<string, mixed> $attributes
     */
    public function record(int|float $value, array $attributes = []): void
    {
        $this->values[MetricAttributes::key($attributes)] = (float) $value;
        MeterProvider::onMeasurement();
    }

    /**
     * The last recorded values, keyed by serialized attribute set.
     *
     * @return array<string, float>
     */
    public function values(): array
    {
        return $this->values;
    }

    /** Resets all recorded values (test helper). */
    public function reset(): void
    {
        $this->values = [];
        $this->createdAtUnixNano = Clock::nowUnixNano();
    }

    /**
     * collect returns the instrument as OTLP-shaped metric data (a Gauge with
     * one data point per attribute set).
     *
     * @return array<string, mixed>
     */
    public function collect(int $timeUnixNano): array
    {
        $points = [];
        foreach ($this->values as $key => $value) {
            $points[] = [
                'attributes' => MetricAttributes::decode((string) $key),
                'startTimeUnixNano' => $this->createdAtUnixNano,
                'timeUnixNano' => $timeUnixNano,
                'value' => $this->integer ? (int) $value : $value,
            ];
        }
        return [
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'kind' => 'gauge',
            'monotonic' => false,
            'integer' => $this->integer,
            'dataPoints' => $points,
        ];
    }
}
