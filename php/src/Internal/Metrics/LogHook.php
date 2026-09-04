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
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * LogHook is the port of `monitoringexporter.LogHook` (logs.go): a logging
 * hook that asynchronously forwards sanitized log entries to the remote
 * monitoring endpoint (`POST <endpoint>/monitoring/ingest/v1/logs`).
 *
 * The logrus hook becomes a Monolog handler that {@see MonitoringExporter::installLogHook()}
 * pushes onto the process logger; it fires for every level (logrus.AllLevels)
 * and bubbles so the regular stderr handler still writes the entry.
 *
 * DIVERGENCE (documented): Go drains the bounded queue (1024 entries, newer
 * entries dropped when full) from a goroutine. PHP queues the payloads and
 * sends them in-line at most once per second from CLI processes; in
 * request-serving SAPIs they are sent at request shutdown, after the
 * response was delivered. Send errors are ignored exactly like Go.
 */
final class LogHook extends AbstractProcessingHandler
{
    /** logQueueSize */
    public const LogQueueSize = 1024;

    /** Minimum interval between two in-line drains, in seconds. */
    private const DrainIntervalSec = 1.0;

    private MonitoringExporterConfig $cfg;

    private ClientInterface $client;

    /** @var array<int, array<string, mixed>> */
    private array $queue = [];

    private bool $closed = false;

    private float $lastDrainAt;

    public function __construct(MonitoringExporterConfig $cfg, ?ClientInterface $http = null)
    {
        // logrus fires hooks only for entries that pass the logger's level
        // (BLNK_LOG_LEVEL, default info); Monolog filters per handler, so the
        // hook applies the same floor as the stderr handler.
        parent::__construct(Log::levelFromEnv(), true);
        $this->cfg = $cfg;
        $this->client = $http ?? new GuzzleClient([
            'timeout' => $cfg->timeout,
            'connect_timeout' => $cfg->timeout,
        ]);
        $this->lastDrainAt = Clock::now();
    }

    public function config(): MonitoringExporterConfig
    {
        return $this->cfg;
    }

    /**
     * Levels mirrors `LogHook.Levels()` (logrus.AllLevels): every level the
     * logger lets through.
     *
     * @return Level[]
     */
    public function levels(): array
    {
        return array_values(array_filter(Level::cases(), fn (Level $l): bool => $this->isHandling(new LogRecord(new \DateTimeImmutable(), 'blnk', $l, ''))));
    }

    /**
     * write is Monolog's entry point; it maps the record onto {@see fire()}.
     */
    protected function write(LogRecord $record): void
    {
        $this->fire(self::levelName($record->level), $record->message, $record->context, $record->datetime);
    }

    /**
     * Fire mirrors `LogHook.Fire(entry)`: builds the redacted payload (time,
     * level, message, fields, and the trace/span ids of the active span when
     * there is one) and queues it, dropping it when the queue is full.
     *
     * Nothing in here may log through Log::get(): the hook is attached to
     * that very logger.
     *
     * @param array<string, mixed> $fields
     */
    public function fire(string $level, string $message, array $fields = [], ?\DateTimeInterface $time = null): void
    {
        if ($this->closed) {
            return;
        }

        $payload = [
            'time' => Clock::formatRFC3339Nano($time === null ? Clock::nowUnixNano() : self::unixNano($time)),
            'level' => $level,
            'message' => Redact::redactString($message),
        ];

        $span = Tracer::currentSpan();
        if ($span !== null) {
            $spanContext = $span->spanContext();
            if ($spanContext->isValid()) {
                $payload['trace_id'] = $spanContext->traceId;
                $payload['span_id'] = $spanContext->spanId;
            }
        }

        $redacted = Redact::redactFields($fields);
        if ($redacted !== null) {
            $payload['fields'] = $redacted;
        }

        // select { case h.queue <- payload: default: } — drop when full.
        if (count($this->queue) >= self::LogQueueSize) {
            return;
        }
        $this->queue[] = $payload;

        $this->tick(Clock::now());
    }

    /**
     * tick drains the queue in-line when allowed and due.
     */
    public function tick(float $now): void
    {
        if ($this->closed || $this->queue === [] || !Tracer::inlineExportsEnabled()) {
            return;
        }
        if ($now - $this->lastDrainAt >= self::DrainIntervalSec) {
            $this->flush();
        }
    }

    /**
     * flush sends every queued payload (the work of the Go `run` goroutine).
     */
    public function flush(): void
    {
        $this->lastDrainAt = Clock::now();
        while ($this->queue !== []) {
            $payload = array_shift($this->queue);
            $this->send($payload);
        }
    }

    /**
     * Shutdown mirrors `LogHook.Shutdown(ctx)`: closes the hook (idempotent)
     * and delivers what is still queued.
     */
    public function shutdown(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->flush();
    }

    /** Whether Shutdown has been called. */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Number of payloads waiting to be sent. */
    public function queueLength(): int
    {
        return count($this->queue);
    }

    /**
     * send mirrors `LogHook.send`: one POST per payload, errors ignored.
     *
     * @param array<string, mixed> $payload
     */
    private function send(array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($body === false) {
            return;
        }
        $headers = ['Content-Type' => 'application/json'];
        foreach ($this->cfg->headers() as $key => $value) {
            $headers[$key] = $value;
        }
        try {
            $this->client->request('POST', $this->cfg->signalURL('logs'), [
                'headers' => $headers,
                'body' => $body,
                'http_errors' => false,
                'timeout' => $this->cfg->timeout,
                'connect_timeout' => $this->cfg->timeout,
            ]);
        } catch (\Throwable) {
            // Go: if err != nil { return }
        }
    }

    /**
     * levelName maps a Monolog level onto the logrus level string of the Go
     * payload (`entry.Level.String()`).
     */
    public static function levelName(Level $level): string
    {
        return match ($level) {
            Level::Emergency => 'panic',
            Level::Alert, Level::Critical => 'fatal',
            Level::Error => 'error',
            Level::Warning => 'warning',
            Level::Notice, Level::Info => 'info',
            Level::Debug => 'debug',
        };
    }

    private static function unixNano(\DateTimeInterface $time): int
    {
        return ((int) $time->format('U')) * 1_000_000_000 + ((int) $time->format('u')) * 1_000;
    }
}
