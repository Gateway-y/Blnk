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
 * BatchSpanProcessor is the port of `sdktrace.NewBatchSpanProcessor` as used
 * by `sdktrace.WithBatcher(exporter, sdktrace.WithBatchTimeout(5*time.Second))`:
 * ended spans are queued (max 2048) and exported in batches of at most 512,
 * when a batch is full or the batch timeout has elapsed, and fully drained on
 * ForceFlush/Shutdown.
 *
 * DIVERGENCE (documented): Go runs the timer and the exports on a background
 * goroutine. PHP has no such thread, so:
 *  - a full batch is exported in-line at the moment it fills up;
 *  - the batch timeout is honored on the next {@see tick()} (driven by span
 *    ends) — only in CLI processes (`blnk workers`, `blnk start`), where the
 *    in-line export cannot delay an HTTP client;
 *  - in request-serving SAPIs (php-fpm, the built-in server) everything left
 *    is exported when the request shuts down, after the response was sent;
 *  - like Go, spans arriving when the queue is full are dropped.
 */
final class BatchSpanProcessor
{
    /** DefaultMaxQueueSize */
    public const DefaultMaxQueueSize = 2048;

    /** DefaultScheduleDelay (batch timeout) in seconds */
    public const DefaultBatchTimeoutSec = 5.0;

    /** DefaultExportTimeout in seconds */
    public const DefaultExportTimeoutSec = 30.0;

    /** DefaultMaxExportBatchSize */
    public const DefaultMaxExportBatchSize = 512;

    private SpanExporterInterface $exporter;

    private float $batchTimeoutSec;

    private int $maxQueueSize;

    private int $maxExportBatchSize;

    /** @var Span[] */
    private array $queue = [];

    private float $lastExportAt;

    private bool $stopped = false;

    private int $dropped = 0;

    public function __construct(
        SpanExporterInterface $exporter,
        float $batchTimeoutSec = self::DefaultBatchTimeoutSec,
        int $maxQueueSize = self::DefaultMaxQueueSize,
        int $maxExportBatchSize = self::DefaultMaxExportBatchSize
    ) {
        $this->exporter = $exporter;
        $this->batchTimeoutSec = $batchTimeoutSec;
        $this->maxQueueSize = $maxQueueSize;
        $this->maxExportBatchSize = $maxExportBatchSize;
        $this->lastExportAt = Clock::now();
    }

    public function exporter(): SpanExporterInterface
    {
        return $this->exporter;
    }

    /**
     * OnEnd queues the ended span (only sampled spans are exported, as in
     * the SDK's `OnEnd`).
     */
    public function onEnd(Span $span): void
    {
        if ($this->stopped || !$span->spanContext()->isSampled()) {
            return;
        }
        if (count($this->queue) >= $this->maxQueueSize) {
            // Go: the span is dropped when the queue is full.
            $this->dropped++;
            Log::get()->debug('batch span processor: queue full, span dropped', ['dropped' => $this->dropped]);
            return;
        }
        $this->queue[] = $span;
        if (count($this->queue) >= $this->maxExportBatchSize) {
            $this->exportBatch();
        }
    }

    /**
     * tick exports the pending batch when the batch timeout has elapsed
     * (the timer of the Go processor), where in-line exports are allowed.
     */
    public function tick(float $now): void
    {
        if ($this->stopped || $this->queue === [] || !Tracer::inlineExportsEnabled()) {
            return;
        }
        if ($now - $this->lastExportAt >= $this->batchTimeoutSec) {
            $this->exportBatch();
        }
    }

    /**
     * ForceFlush exports every queued span (`ForceFlush(ctx)`).
     */
    public function forceFlush(): void
    {
        if ($this->stopped) {
            return;
        }
        while ($this->queue !== []) {
            $this->exportBatch();
        }
    }

    /**
     * Shutdown flushes the queue and shuts the exporter down (`Shutdown(ctx)`);
     * idempotent.
     */
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

    /** Number of spans dropped because the queue was full. */
    public function droppedCount(): int
    {
        return $this->dropped;
    }

    /** Number of spans waiting for export. */
    public function queueLength(): int
    {
        return count($this->queue);
    }

    private function exportBatch(): void
    {
        $batch = array_splice($this->queue, 0, $this->maxExportBatchSize);
        $this->lastExportAt = Clock::now();
        if ($batch === []) {
            return;
        }
        try {
            $this->exporter->exportSpans($batch);
        } catch (\Throwable $err) {
            // Go: otel.Handle(err) — the batch is dropped and the error reported.
            Log::get()->warning('failed to export spans', ['error' => $err->getMessage(), 'count' => count($batch)]);
        }
    }
}
