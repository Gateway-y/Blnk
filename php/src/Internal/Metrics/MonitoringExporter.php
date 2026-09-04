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

/**
 * MonitoringExporter is the logging STUB replacing Go
 * `internal/monitoringexporter` (`exporters.go` + `logs.go`), per PORTING.md:
 * "OTEL traces/metrics → no-op logger stubs".
 *
 * DIVERGENCE (documented): the Go package ships OTLP/HTTP trace and metric
 * exporters plus an asynchronous logrus hook that POSTs redacted log entries
 * to the remote monitoring endpoint. The PHP port performs NO remote export:
 * every entry point logs (at debug level) that the exporter is stubbed, and
 * {@see MonitoringExporter::fire()} only redacts and debug-logs the payload
 * locally. The DSN parsing ({@see MonitoringExporterConfig}) and redaction
 * ({@see Redact}) logic are ported faithfully so a real exporter can be
 * plugged in later.
 */
final class MonitoringExporter
{
    private MonitoringExporterConfig $cfg;

    private bool $closed = false;

    private function __construct(MonitoringExporterConfig $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * NewLogHook mirrors Go `monitoringexporter.NewLogHook`: returns the stub
     * hook without registering it anywhere.
     */
    public static function newLogHook(MonitoringExporterConfig $cfg): self
    {
        return new self($cfg);
    }

    /**
     * InstallLogHook mirrors Go `monitoringexporter.InstallLogHook`. The Go
     * version registers a logrus hook that forwards every entry to the remote
     * sink; the PHP stub only announces itself.
     */
    public static function installLogHook(MonitoringExporterConfig $cfg): self
    {
        Log::get()->debug('monitoring exporter log hook stubbed (no remote log export in the PHP port)', [
            'endpoint' => $cfg->endpoint,
            'project_id' => $cfg->projectID,
        ]);
        return new self($cfg);
    }

    /**
     * NewTraceExporter mirrors Go `monitoringexporter.NewTraceExporter`
     * (an OTLP/HTTP span exporter). Stub: logs and returns null.
     */
    public static function newTraceExporter(MonitoringExporterConfig $cfg): mixed
    {
        Log::get()->debug('monitoring trace exporter stubbed (no OTLP export in the PHP port)', [
            'endpoint' => $cfg->otlpSignalURL('traces'),
        ]);
        return null;
    }

    /**
     * NewMetricReader mirrors Go `monitoringexporter.NewMetricReader`
     * (a periodic OTLP/HTTP metric reader). Stub: logs and returns null.
     */
    public static function newMetricReader(MonitoringExporterConfig $cfg): mixed
    {
        Log::get()->debug('monitoring metric exporter stubbed (no OTLP export in the PHP port)', [
            'endpoint' => $cfg->otlpSignalURL('metrics'),
        ]);
        return null;
    }

    /**
     * Fire mirrors the Go `LogHook.Fire` shape (level + message + fields with
     * redaction applied) but only debug-logs the sanitized payload locally
     * instead of enqueueing an HTTP POST to `SignalURL("logs")`.
     *
     * @param array<string, mixed> $fields
     */
    public function fire(string $level, string $message, array $fields = []): void
    {
        if ($this->closed) {
            return;
        }
        Log::get()->debug('monitoring exporter stub: log entry not exported', [
            'level' => $level,
            'message' => Redact::redactString($message),
            'fields' => Redact::redactFields($fields),
            'target' => $this->cfg->signalURL('logs'),
        ]);
    }

    /**
     * Shutdown mirrors Go `LogHook.Shutdown`: marks the hook closed. There is
     * no queue to drain in the stub.
     */
    public function shutdown(): void
    {
        $this->closed = true;
    }
}
