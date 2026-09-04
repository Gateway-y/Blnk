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
use Blnk\Internal\Metrics\MeterProvider;
use Blnk\Internal\Metrics\MonitoringExporter;
use Blnk\Internal\Metrics\MonitoringExporterConfig;
use Blnk\Internal\Metrics\OtlpMetricExporter;
use Blnk\Internal\Metrics\PeriodicReader;
use Blnk\Internal\Metrics\PrometheusExporter;

/**
 * Tracer is the port of Go `internal/traces` (`otel.go`) plus the
 * `otel.Tracer(name)` entry point the services use:
 *
 *  - {@see setupOTelSDK()} bootstraps the OpenTelemetry pipeline exactly like
 *    `trace.SetupOTelSDK(ctx, serviceName, monitoringDSN)`: resource,
 *    W3C propagator, tracer provider (OTLP/HTTP exporter + optional remote
 *    monitoring exporter, batched), meter provider (Prometheus pull reader
 *    always, OTLP push reader when OTEL_EXPORTER_OTLP_{METRICS_,}ENDPOINT is
 *    set, remote monitoring reader when a DSN is configured), stdout logger
 *    provider and the remote monitoring log hook;
 *  - {@see metricsHandler()} is `trace.MetricsHandler()`: the Prometheus
 *    /metrics handler set during setup (null before it);
 *  - {@see get()} / {@see startSpan()} are `otel.Tracer(name).Start(ctx, name)`.
 *
 * PHP adaptations (documented): PHP has no background goroutines, so batch
 * timeouts and export intervals are honored on the telemetry activity of
 * CLI processes and everything is flushed by a registered shutdown function
 * at process (php-fpm: request) end; OTLP is sent as JSON; without
 * `setupOTelSDK()` spans/metrics stay in memory and nothing is exported.
 */
final class Tracer
{
    /** Upper bound of the active-span stack (spans never ended are evicted oldest first). */
    private const MaxActiveSpans = 1000;

    /** @var array<string, Tracer> */
    private static array $tracers = [];

    private static ?TracerProvider $tracerProvider = null;

    private static ?Propagator $propagator = null;

    /**
     * metricsHandler holds the Prometheus HTTP handler for the /metrics endpoint.
     * Set during SetupOTelSDK and accessed via MetricsHandler().
     *
     * @var callable|null
     */
    private static $metricsHandler = null;

    /** @var Span[] started and not yet ended spans, innermost last (the "current context") */
    private static array $activeSpans = [];

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

    /** The tracer name (instrumentation scope). */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * StartSpan starts a new span (the analogue of `tracer.Start(ctx, name)`).
     * The parent is the innermost active span unless one is given explicitly
     * (a remote parent extracted by the {@see Propagator}, for instance);
     * root spans get a fresh trace id. Sampling follows the SDK default
     * `ParentBased(AlwaysSample)`.
     *
     * @param array<string, mixed> $attributes
     */
    public function startSpan(string $name, array $attributes = [], int $kind = Span::KindInternal, ?SpanContext $parent = null): Span
    {
        $parent ??= self::currentSpan()?->spanContext();
        if ($parent !== null && !$parent->isValid()) {
            $parent = null;
        }

        if ($parent !== null) {
            $fresh = SpanContext::generate($parent->traceId);
            $context = new SpanContext($fresh->traceId, $fresh->spanId, $parent->traceFlags, $parent->traceState);
        } else {
            $context = SpanContext::generate();
        }

        $span = new Span($this->name, $name, $context, $parent, $attributes, $kind, self::$tracerProvider);

        if (count(self::$activeSpans) >= self::MaxActiveSpans) {
            array_shift(self::$activeSpans);
        }
        self::$activeSpans[] = $span;

        Log::get()->debug('span started', ['tracer' => $this->name, 'span' => $name, 'trace_id' => $context->traceId, 'span_id' => $context->spanId]);

        return $span;
    }

    /**
     * currentSpan returns the innermost active span (the span of the current
     * context, `trace.SpanFromContext(ctx)`), or null.
     */
    public static function currentSpan(): ?Span
    {
        $n = count(self::$activeSpans);
        return $n === 0 ? null : self::$activeSpans[$n - 1];
    }

    /**
     * spanEnded removes an ended span from the active stack.
     *
     * @internal called by {@see Span::end()}
     */
    public static function spanEnded(Span $span): void
    {
        for ($i = count(self::$activeSpans) - 1; $i >= 0; $i--) {
            if (self::$activeSpans[$i] === $span) {
                array_splice(self::$activeSpans, $i, 1);
                return;
            }
        }
    }

    /**
     * inlineExportsEnabled reports whether telemetry may be exported in-line
     * on the telemetry activity of this process (the work of Go's background
     * goroutines): true for CLI processes (`blnk workers`, `blnk start`),
     * false for request-serving SAPIs, which export at request shutdown so
     * no HTTP client is ever delayed.
     */
    public static function inlineExportsEnabled(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /** SetTracerProvider mirrors `otel.SetTracerProvider`. */
    public static function setTracerProvider(?TracerProvider $provider): void
    {
        self::$tracerProvider = $provider;
    }

    /** GetTracerProvider mirrors `otel.GetTracerProvider` (null before setup). */
    public static function getTracerProvider(): ?TracerProvider
    {
        return self::$tracerProvider;
    }

    /** SetTextMapPropagator mirrors `otel.SetTextMapPropagator`. */
    public static function setTextMapPropagator(?Propagator $propagator): void
    {
        self::$propagator = $propagator;
    }

    /** GetTextMapPropagator mirrors `otel.GetTextMapPropagator`. */
    public static function getTextMapPropagator(): Propagator
    {
        return self::$propagator ??= new Propagator();
    }

    /**
     * MetricsHandler returns the Prometheus HTTP handler for serving the /metrics endpoint.
     * Returns null if SetupOTelSDK has not been called or metrics are not configured.
     *
     * The handler has the signature
     * `callable(ServerRequestInterface, ResponseInterface): ResponseInterface`.
     */
    public static function metricsHandler(): ?callable
    {
        return self::$metricsHandler;
    }

    /**
     * SetupOTelSDK bootstraps the OpenTelemetry pipeline.
     * If it does not throw, make sure to call the returned shutdown function
     * for proper cleanup (it is also registered to run at process end).
     *
     * @return callable(): void the shutdown function; it joins the errors of
     *                          the registered cleanups into one \RuntimeException
     *
     * @throws \RuntimeException when a provider cannot be created (the error
     *                           joined with any cleanup error, like errors.Join)
     */
    public static function setupOTelSDK(string $serviceName, string $monitoringDSN = ''): callable
    {
        /** @var array<int, callable(): void> $shutdownFuncs */
        $shutdownFuncs = [];

        // shutdown calls cleanup functions registered via shutdownFuncs.
        // The errors from the calls are joined.
        // Each registered cleanup will be invoked once.
        $shutdown = static function () use (&$shutdownFuncs): void {
            $errors = [];
            foreach ($shutdownFuncs as $fn) {
                try {
                    $fn();
                } catch (\Throwable $err) {
                    $errors[] = $err->getMessage();
                }
            }
            $shutdownFuncs = [];
            if ($errors !== []) {
                throw new \RuntimeException(implode("\n", $errors));
            }
        };

        // handleErr calls shutdown for cleanup and makes sure that all errors are returned.
        $handleErr = static function (\Throwable $inErr) use ($shutdown): never {
            $messages = [$inErr->getMessage()];
            try {
                $shutdown();
            } catch (\Throwable $shutdownErr) {
                $messages[] = $shutdownErr->getMessage();
            }
            throw new \RuntimeException(implode("\n", $messages), 0, $inErr);
        };

        // Create shared resource for all providers.
        $res = self::newResource($serviceName);

        [$exporterCfg, $exporterEnabled] = self::monitoringExporterConfigFromDSN($monitoringDSN);

        // Set up propagator.
        $prop = self::newPropagator();
        self::setTextMapPropagator($prop);

        // Set up trace provider.
        try {
            $tracerProvider = self::newTraceProvider($res, $exporterCfg, $exporterEnabled);
        } catch (\Throwable $err) {
            $handleErr($err);
        }
        $shutdownFuncs[] = static function () use ($tracerProvider): void {
            $tracerProvider->shutdown();
        };
        self::setTracerProvider($tracerProvider);

        // Set up meter provider (dual-mode: Prometheus pull + OTLP push).
        try {
            $meterProvider = self::newMeterProvider($res, $exporterCfg, $exporterEnabled);
        } catch (\Throwable $err) {
            $handleErr($err);
        }
        $shutdownFuncs[] = static function () use ($meterProvider): void {
            $meterProvider->shutdown();
        };
        MeterProvider::setGlobal($meterProvider);

        // Set up logger provider.
        try {
            $loggerProvider = self::newLoggerProvider();
        } catch (\Throwable $err) {
            $handleErr($err);
        }
        $shutdownFuncs[] = static function () use ($loggerProvider): void {
            $loggerProvider->shutdown();
        };
        LoggerProvider::setGlobal($loggerProvider);

        if ($exporterEnabled && $exporterCfg !== null) {
            $exporterLogs = MonitoringExporter::installLogHook($exporterCfg);
            $shutdownFuncs[] = static function () use ($exporterLogs): void {
                $exporterLogs->shutdown();
            };
            Log::get()->info('monitoring exporter enabled');
        }

        // PHP: the pipeline has no background workers, so make sure pending
        // telemetry is flushed when the process (php-fpm: the request) ends
        // even if the caller never invokes the returned shutdown function.
        // The cleanups run once, so a later explicit call is a no-op.
        register_shutdown_function(static function () use ($shutdown): void {
            try {
                $shutdown();
            } catch (\Throwable $err) {
                Log::get()->error(sprintf('Error during telemetry shutdown: %s', $err->getMessage()));
            }
        });

        return $shutdown;
    }

    /**
     * newResource creates a shared OTel resource with the service name.
     */
    public static function newResource(string $serviceName): Resource
    {
        return new Resource(['service.name' => $serviceName]);
    }

    /**
     * newPropagator mirrors otel.go: the composite of TraceContext and Baggage.
     */
    public static function newPropagator(): Propagator
    {
        return new Propagator();
    }

    /**
     * newTraceProvider mirrors otel.go: a batcher (5s batch timeout) over the
     * OTLP trace exporter, plus a second one over the monitoring exporter
     * when enabled (its creation failure only logs a warning).
     *
     * @throws \Throwable when the OTLP trace exporter cannot be created
     */
    public static function newTraceProvider(Resource $res, ?MonitoringExporterConfig $exporterCfg, bool $exporterEnabled): TracerProvider
    {
        $exporter = self::newTraceExporter();

        $processors = [
            new BatchSpanProcessor($exporter, 5.0),
        ];
        if ($exporterEnabled && $exporterCfg !== null) {
            try {
                $monitoringExporter = MonitoringExporter::newTraceExporter($exporterCfg);
                $processors[] = new BatchSpanProcessor($monitoringExporter, 5.0);
            } catch (\Throwable $err) {
                Log::get()->warning('monitoring trace exporter unavailable', ['error' => $err->getMessage()]);
            }
        }

        return new TracerProvider($res, $processors);
    }

    /**
     * newMeterProvider creates a MeterProvider with dual-mode export:
     *   - Pull (Prometheus): always active — exposes metrics via /metrics endpoint for scraping.
     *   - Push (OTLP HTTP): opt-in — enabled when OTEL_EXPORTER_OTLP_ENDPOINT is set.
     *     Uses the standard OTel env var, so it works automatically with any OTel Collector.
     *
     * @throws \Throwable when an exporter cannot be created
     */
    public static function newMeterProvider(Resource $res, ?MonitoringExporterConfig $exporterCfg, bool $exporterEnabled): MeterProvider
    {
        // Pull exporter: Prometheus scrape endpoint (always active).
        $promExp = new PrometheusExporter();

        self::$metricsHandler = $promExp->handler();

        $readers = [$promExp];

        // Push exporter: enabled when an OTLP metrics endpoint is configured.
        // Checks OTEL_EXPORTER_OTLP_METRICS_ENDPOINT (signal-specific) or
        // OTEL_EXPORTER_OTLP_ENDPOINT (generic fallback) per the OTel spec.
        if (self::env('OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') !== '' || self::env('OTEL_EXPORTER_OTLP_ENDPOINT') !== '') {
            $otlpExp = OtlpMetricExporter::create(static function (OtlpHttpConfig $c): void {
                $c->withInsecure();
            });
            $readers[] = new PeriodicReader($otlpExp, 60.0);
        }

        if ($exporterEnabled && $exporterCfg !== null) {
            try {
                $readers[] = MonitoringExporter::newMetricReader($exporterCfg);
            } catch (\Throwable $err) {
                Log::get()->warning('monitoring metric exporter unavailable', ['error' => $err->getMessage()]);
            }
        }

        return new MeterProvider($res, $readers);
    }

    /**
     * newLoggerProvider mirrors otel.go: a batch processor over the stdout
     * log exporter.
     */
    public static function newLoggerProvider(): LoggerProvider
    {
        $logExporter = new StdoutLogExporter();

        return new LoggerProvider($logExporter);
    }

    /**
     * newTraceExporter mirrors otel.go: the OTLP/HTTP trace exporter with the
     * endpoint resolved from OTEL_EXPORTER_OTLP_TRACES_ENDPOINT first, then
     * OTEL_EXPORTER_OTLP_ENDPOINT, else the SDK defaults (insecure
     * localhost:4318).
     */
    public static function newTraceExporter(): OtlpTraceExporter
    {
        // Resolve endpoint from signal-specific var first, then generic fallback.
        $endpoint = self::env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT');
        if ($endpoint === '') {
            $endpoint = self::env('OTEL_EXPORTER_OTLP_ENDPOINT');
        }
        if ($endpoint === '') {
            // No endpoint configured — let the SDK use its defaults.
            return OtlpTraceExporter::create(static function (OtlpHttpConfig $c): void {
                $c->withInsecure();
            });
        }

        // Normalize the endpoint to host:port. The SDK's WithEndpoint() takes host:port
        // and always appends /v1/traces, so this works regardless of whether the env var
        // contains "jaeger:4318", "http://jaeger:4318", or "http://jaeger:4318/v1/traces".
        [$host, $insecure] = self::parseOTLPEndpoint($endpoint);
        return OtlpTraceExporter::create(static function (OtlpHttpConfig $c) use ($host, $insecure): void {
            $c->withEndpoint($host);
            if ($insecure) {
                $c->withInsecure();
            }
        });
    }

    /**
     * parseOTLPEndpoint extracts the host:port from an OTLP endpoint string and
     * determines whether to use an insecure (non-TLS) connection.
     * Handles all common formats: "host:port", "http://host:port", "http://host:port/v1/traces".
     *
     * @return array{0: string, 1: bool} [hostPort, insecure]
     */
    public static function parseOTLPEndpoint(string $endpoint): array
    {
        $parsed = OtlpHttpConfig::parseGoURL($endpoint);
        if ($parsed === null || $parsed['host'] === '') {
            // Not a valid URL — treat as bare host:port, assume insecure.
            return [$endpoint, true];
        }
        return [$parsed['host'], $parsed['scheme'] !== 'https'];
    }

    /**
     * monitoringExporterConfigFromDSN mirrors otel.go: an invalid DSN
     * disables the exporter with a warning.
     *
     * @return array{0: MonitoringExporterConfig|null, 1: bool}
     */
    private static function monitoringExporterConfigFromDSN(string $dsn): array
    {
        try {
            $cfg = MonitoringExporterConfig::fromDSN($dsn);
        } catch (\Throwable $err) {
            Log::get()->warning('monitoring exporter disabled', ['error' => $err->getMessage()]);
            return [null, false];
        }
        return [$cfg, $cfg !== null];
    }

    /** env mirrors `os.Getenv`: "" when unset. */
    private static function env(string $name): string
    {
        $v = getenv($name);
        return is_string($v) ? $v : '';
    }
}
