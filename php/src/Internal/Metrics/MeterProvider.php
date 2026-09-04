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
use Blnk\Internal\Traces\Resource;

/**
 * MeterProvider is the port of `sdkmetric.MeterProvider` as built by
 * `newMeterProvider` in otel.go: a resource plus the registered readers —
 * the Prometheus pull exporter (always), the OTLP periodic push reader
 * (when OTEL_EXPORTER_OTLP_METRICS_ENDPOINT / OTEL_EXPORTER_OTLP_ENDPOINT
 * is set) and the remote monitoring reader (when a monitoring DSN is
 * configured). Installed globally with {@see setGlobal()}
 * (`otel.SetMeterProvider`).
 *
 * The instruments of the "blnk" meter live in {@see Metrics} (the Go
 * package-level `otel.Meter("blnk")` instruments); {@see collect()} turns
 * their in-memory state into cumulative OTLP metric data.
 */
final class MeterProvider
{
    private static ?self $global = null;

    private Resource $resource;

    /** @var MetricReaderInterface[] */
    private array $readers;

    private bool $stopped = false;

    /**
     * @param MetricReaderInterface[] $readers
     */
    public function __construct(Resource $resource, array $readers)
    {
        // sdkmetric.WithResource merges the environment resource under the
        // explicit one.
        $this->resource = Resource::merge(Resource::environment(), $resource);
        $this->readers = array_values($readers);
        foreach ($this->readers as $reader) {
            $reader->register($this);
        }
    }

    /** SetMeterProvider mirrors `otel.SetMeterProvider`. */
    public static function setGlobal(?self $provider): void
    {
        self::$global = $provider;
    }

    /** GetMeterProvider mirrors `otel.GetMeterProvider` (null when none was set). */
    public static function global(): ?self
    {
        return self::$global;
    }

    /**
     * onMeasurement is called by the instruments after every recording so the
     * push readers can honor their interval without a background thread.
     */
    public static function onMeasurement(): void
    {
        if (self::$global !== null) {
            self::$global->tick(Clock::now());
        }
    }

    public function resource(): Resource
    {
        return $this->resource;
    }

    /**
     * @return MetricReaderInterface[]
     */
    public function readers(): array
    {
        return $this->readers;
    }

    /**
     * collect mirrors `Reader.Collect(ctx, &ResourceMetrics)`: the resource
     * and, per instrumentation scope, every instrument that has data points.
     *
     * @return array{resource: Resource, scopeMetrics: array<int, array{scope: array{name: string, version: string, schemaUrl: string}, metrics: array<int, array<string, mixed>>}>}
     */
    public function collect(): array
    {
        $time = Clock::nowUnixNano();
        $metrics = [];
        if (!$this->stopped) {
            foreach (Metrics::instruments() as $instrument) {
                $data = $instrument->collect($time);
                if ($data['dataPoints'] === []) {
                    continue;
                }
                $metrics[] = $data;
            }
        }

        $scopeMetrics = [];
        if ($metrics !== []) {
            $scopeMetrics[] = [
                'scope' => ['name' => Metrics::ScopeName, 'version' => '', 'schemaUrl' => ''],
                'metrics' => $metrics,
            ];
        }

        return ['resource' => $this->resource, 'scopeMetrics' => $scopeMetrics];
    }

    /** tick forwards the clock to the readers. */
    public function tick(float $now): void
    {
        if ($this->stopped) {
            return;
        }
        foreach ($this->readers as $reader) {
            $reader->tick($now);
        }
    }

    /**
     * ForceFlush flushes all registered readers.
     */
    public function forceFlush(): void
    {
        if ($this->stopped) {
            return;
        }
        foreach ($this->readers as $reader) {
            $reader->forceFlush();
        }
    }

    /**
     * Shutdown shuts down the readers in registration order; idempotent, the
     * individual errors are joined.
     *
     * @throws \RuntimeException the joined reader errors, if any
     */
    public function shutdown(): void
    {
        if ($this->stopped) {
            return;
        }
        $errors = [];
        foreach ($this->readers as $reader) {
            try {
                $reader->shutdown();
            } catch (\Throwable $err) {
                $errors[] = $err->getMessage();
            }
        }
        $this->stopped = true;
        if ($errors !== []) {
            throw new \RuntimeException(implode("\n", $errors));
        }
    }
}
