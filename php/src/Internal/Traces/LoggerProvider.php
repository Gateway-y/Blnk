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
 * LoggerProvider is the port of the `sdklog.LoggerProvider` built by
 * `newLoggerProvider` in otel.go:
 *
 *   log.NewLoggerProvider(log.WithProcessor(log.NewBatchProcessor(stdoutlog exporter)))
 *
 * It is installed globally (`global.SetLoggerProvider`) so OTel log records
 * emitted through {@see emit()} are batched (queue 2048, batches of 512,
 * export interval 1s) and written by the exporter. Nothing in Blnk bridges
 * logrus/Monolog into it — exactly as in Go — so it is inert unless code
 * emits records explicitly.
 */
final class LoggerProvider
{
    /** OTel log severity numbers. */
    public const SeverityTrace = 1;
    public const SeverityDebug = 5;
    public const SeverityInfo = 9;
    public const SeverityWarn = 13;
    public const SeverityError = 17;
    public const SeverityFatal = 21;

    /** BatchProcessor defaults. */
    public const DefaultMaxQueueSize = 2048;
    public const DefaultExportMaxBatchSize = 512;
    public const DefaultExportIntervalSec = 1.0;

    private static ?self $global = null;

    private LogExporterInterface $exporter;

    private Resource $resource;

    /** @var array<int, array<string, mixed>> */
    private array $queue = [];

    private float $lastExportAt;

    private bool $stopped = false;

    public function __construct(LogExporterInterface $exporter, ?Resource $resource = null)
    {
        $this->exporter = $exporter;
        $this->resource = Resource::merge(Resource::environment(), $resource ?? new Resource());
        $this->lastExportAt = Clock::now();
    }

    /** SetLoggerProvider mirrors `global.SetLoggerProvider`. */
    public static function setGlobal(?self $provider): void
    {
        self::$global = $provider;
    }

    /** GetLoggerProvider mirrors `global.GetLoggerProvider` (null when none was set). */
    public static function global(): ?self
    {
        return self::$global;
    }

    public function resource(): Resource
    {
        return $this->resource;
    }

    /**
     * Emit records one OTel log record for the named logger scope
     * (`provider.Logger(scope).Emit(ctx, record)`).
     *
     * @param array<string, mixed> $attributes
     */
    public function emit(string $scopeName, int $severity, string $severityText, mixed $body, array $attributes = [], ?int $timestampUnixNano = null): void
    {
        if ($this->stopped) {
            return;
        }
        $now = Clock::nowUnixNano();
        $span = Tracer::currentSpan();
        $sc = $span?->spanContext();

        $record = [
            'Timestamp' => Clock::formatRFC3339Nano($timestampUnixNano ?? $now),
            'ObservedTimestamp' => Clock::formatRFC3339Nano($now),
            'Severity' => $severity,
            'SeverityText' => $severityText,
            'Body' => self::value($body),
            'Attributes' => self::keyValues($attributes),
            'TraceID' => $sc?->traceId ?? '00000000000000000000000000000000',
            'SpanID' => $sc?->spanId ?? '0000000000000000',
            'TraceFlags' => sprintf('%02x', $sc?->traceFlags ?? 0),
            'Resource' => self::keyValues($this->resource->attributes()),
            'Scope' => ['Name' => $scopeName, 'Version' => '', 'SchemaURL' => '', 'Attributes' => null],
            'DroppedAttributes' => 0,
        ];

        if (count($this->queue) >= self::DefaultMaxQueueSize) {
            // BatchProcessor drops the oldest records when the queue is full.
            array_shift($this->queue);
        }
        $this->queue[] = $record;

        if (count($this->queue) >= self::DefaultExportMaxBatchSize) {
            $this->exportBatch();
        } else {
            $this->tick(Clock::now());
        }
    }

    /** tick exports when the export interval elapsed (CLI processes only, see BatchSpanProcessor). */
    public function tick(float $now): void
    {
        if ($this->stopped || $this->queue === [] || !Tracer::inlineExportsEnabled()) {
            return;
        }
        if ($now - $this->lastExportAt >= self::DefaultExportIntervalSec) {
            $this->exportBatch();
        }
    }

    /** ForceFlush exports every queued record. */
    public function forceFlush(): void
    {
        if ($this->stopped) {
            return;
        }
        while ($this->queue !== []) {
            $this->exportBatch();
        }
        $this->exporter->forceFlush();
    }

    /** Shutdown flushes and shuts the exporter down; idempotent. */
    public function shutdown(): void
    {
        if ($this->stopped) {
            return;
        }
        try {
            $this->forceFlush();
        } finally {
            $this->stopped = true;
            $this->exporter->shutdown();
        }
    }

    private function exportBatch(): void
    {
        $batch = array_splice($this->queue, 0, self::DefaultExportMaxBatchSize);
        $this->lastExportAt = Clock::now();
        if ($batch === []) {
            return;
        }
        try {
            $this->exporter->export($batch);
        } catch (\Throwable $err) {
            Log::get()->warning('failed to export log records', ['error' => $err->getMessage(), 'count' => count($batch)]);
        }
    }

    /**
     * value mirrors the stdoutlog JSON encoding of `log.Value` ({Type, Value}).
     *
     * @return array<string, mixed>
     */
    private static function value(mixed $v): array
    {
        if ($v === null) {
            return ['Type' => 'Empty', 'Value' => null];
        }
        if (is_bool($v)) {
            return ['Type' => 'Bool', 'Value' => $v];
        }
        if (is_int($v)) {
            return ['Type' => 'Int64', 'Value' => $v];
        }
        if (is_float($v)) {
            return ['Type' => 'Float64', 'Value' => $v];
        }
        if (is_string($v)) {
            return ['Type' => 'String', 'Value' => $v];
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return ['Type' => 'Slice', 'Value' => array_map([self::class, 'value'], $v)];
            }
            return ['Type' => 'Map', 'Value' => self::keyValues($v)];
        }
        if ($v instanceof \Stringable) {
            return ['Type' => 'String', 'Value' => (string) $v];
        }
        return ['Type' => 'String', 'Value' => (string) json_encode($v)];
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<int, array{Key: string, Value: array<string, mixed>}>
     */
    private static function keyValues(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            $out[] = ['Key' => (string) $key, 'Value' => self::value($value)];
        }
        return $out;
    }
}
