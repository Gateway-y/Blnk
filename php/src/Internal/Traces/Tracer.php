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

use Blnk\Internal\Log;

/**
 * Tracer is the no-op-friendly wrapper replacing Go `internal/traces`
 * (`otel.go`), per PORTING.md: "OpenTelemetry tracing → port as
 * no-op-friendly wrapper Blnk\Internal\Traces\Tracer with startSpan/end that
 * only logs at debug level. Do not pull an OTEL SDK."
 *
 * DIVERGENCE (documented): the Go package bootstraps a full OTel pipeline —
 * OTLP trace/metric exporters, a Prometheus /metrics handler, a stdout log
 * provider, and the optional remote monitoring exporter
 * (internal/monitoringexporter). The PHP port performs no telemetry export at
 * all: spans are plain objects that log at debug level, and
 * {@see Tracer::setupOTelSDK()} is a logging stub that returns a no-op
 * shutdown callable.
 */
final class Tracer
{
    /** @var array<string, Tracer> */
    private static array $tracers = [];

    private string $name;

    public function __construct(string $name = 'blnk')
    {
        $this->name = $name;
    }

    /**
     * Returns a (cached) named tracer, the analogue of `otel.Tracer(name)`.
     */
    public static function get(string $name = 'blnk'): self
    {
        if (!isset(self::$tracers[$name])) {
            self::$tracers[$name] = new self($name);
        }
        return self::$tracers[$name];
    }

    /** The tracer name. */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * StartSpan starts a new span (the analogue of `tracer.Start(ctx, name)`).
     * Returns a {@see Span} with end()/setAttribute()/recordError(); all
     * operations only log at debug level.
     *
     * @param array<string, mixed> $attributes
     */
    public function startSpan(string $name, array $attributes = []): Span
    {
        Log::get()->debug('span started', ['tracer' => $this->name, 'span' => $name]);
        return new Span($name, $attributes);
    }

    /**
     * SetupOTelSDK mirrors the Go entry point `trace.SetupOTelSDK(ctx,
     * serviceName, monitoringDSN)` as a stub: it logs that tracing is running
     * in no-op mode and returns a shutdown callable that does nothing.
     *
     * @return callable(): void The no-op shutdown function.
     */
    public static function setupOTelSDK(string $serviceName, string $monitoringDSN = ''): callable
    {
        Log::get()->debug('OTel SDK setup skipped (PHP port runs tracing/metrics as no-op log stubs)', [
            'service_name' => $serviceName,
            'monitoring_dsn_configured' => trim($monitoringDSN) !== '',
        ]);
        return static function (): void {
            // no-op shutdown
        };
    }

    /**
     * MetricsHandler mirrors Go `trace.MetricsHandler()`: the Go version
     * returns the Prometheus /metrics HTTP handler; the PHP port has no
     * Prometheus exporter and always returns null.
     */
    public static function metricsHandler(): mixed
    {
        return null;
    }
}
