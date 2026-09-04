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

use Blnk\Internal\Log;
use Blnk\Internal\Traces\Clock;
use Blnk\Internal\Traces\Tracer;

/**
 * PeriodicReader is the port of `sdkmetric.NewPeriodicReader(exporter,
 * sdkmetric.WithInterval(60*time.Second))`: it collects the provider's
 * metrics and pushes them to the exporter every interval and once more at
 * shutdown.
 *
 * DIVERGENCE (documented): Go runs the ticker on a goroutine. In PHP the
 * interval is checked whenever an instrument records a measurement
 * ({@see MeterProvider::onMeasurement()}) and the export runs in-line —
 * only in CLI processes; request-serving SAPIs export at request shutdown
 * (see {@see \Blnk\Internal\Traces\BatchSpanProcessor}). Exports with no
 * data points are skipped.
 */
final class PeriodicReader implements MetricReaderInterface
{
    /** The default export interval (60 * time.Second). */
    public const DefaultIntervalSec = 60.0;

    /** The default export timeout (30 * time.Second). */
    public const DefaultTimeoutSec = 30.0;

    private MetricExporterInterface $exporter;

    private float $intervalSec;

    private float $timeoutSec;

    private ?MeterProvider $producer = null;

    private float $lastExportAt;

    private bool $stopped = false;

    public function __construct(MetricExporterInterface $exporter, float $intervalSec = self::DefaultIntervalSec, float $timeoutSec = self::DefaultTimeoutSec)
    {
        $this->exporter = $exporter;
        $this->intervalSec = $intervalSec > 0 ? $intervalSec : self::DefaultIntervalSec;
        $this->timeoutSec = $timeoutSec > 0 ? $timeoutSec : self::DefaultTimeoutSec;
        $this->lastExportAt = Clock::now();
    }

    public function exporter(): MetricExporterInterface
    {
        return $this->exporter;
    }

    public function intervalSec(): float
    {
        return $this->intervalSec;
    }

    public function timeoutSec(): float
    {
        return $this->timeoutSec;
    }

    public function register(MeterProvider $producer): void
    {
        $this->producer = $producer;
        $this->lastExportAt = Clock::now();
    }

    public function tick(float $now): void
    {
        if ($this->stopped || $this->producer === null || !Tracer::inlineExportsEnabled()) {
            return;
        }
        if ($now - $this->lastExportAt >= $this->intervalSec) {
            $this->collectAndExport();
        }
    }

    public function forceFlush(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->collectAndExport();
        $this->exporter->forceFlush();
    }

    public function shutdown(): void
    {
        if ($this->stopped) {
            return;
        }
        try {
            $this->collectAndExport();
        } finally {
            $this->stopped = true;
            $this->exporter->shutdown();
        }
    }

    /**
     * collectAndExport mirrors the reader's `collectAndExport`: errors are
     * reported (otel.Handle) and never propagated to the instrumented code.
     */
    private function collectAndExport(): void
    {
        $this->lastExportAt = Clock::now();
        if ($this->producer === null) {
            return;
        }
        $rm = $this->producer->collect();
        if ($rm['scopeMetrics'] === []) {
            return;
        }
        try {
            $this->exporter->export($rm);
        } catch (\Throwable $err) {
            Log::get()->warning('failed to export metrics', ['error' => $err->getMessage()]);
        }
    }
}
