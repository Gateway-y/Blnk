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
use Blnk\Internal\Traces\OtlpHttpClient;
use Blnk\Internal\Traces\OtlpHttpConfig;
use Blnk\Internal\Traces\OtlpTraceExporter;

/**
 * MonitoringExporter is the port of Go `internal/monitoringexporter`
 * (`exporters.go` + `logs.go`): the OTLP/HTTP trace exporter and periodic
 * metric reader targeting the remote monitoring sink described by a
 * {@see MonitoringExporterConfig} (DSN `https://<write-key>@<host>/<project-id>`),
 * and the log hook forwarding redacted log entries to it ({@see LogHook}).
 */
final class MonitoringExporter
{
    private function __construct()
    {
    }

    /**
     * NewTraceExporter creates an OTLP/HTTP trace exporter for remote monitoring:
     *
     *   otlptracehttp.New(ctx,
     *       otlptracehttp.WithEndpointURL(cfg.OTLPSignalURL("traces")),
     *       otlptracehttp.WithHeaders(cfg.Headers()),
     *       otlptracehttp.WithTimeout(cfg.Timeout))
     */
    public static function newTraceExporter(MonitoringExporterConfig $cfg, ?OtlpHttpClient $client = null): OtlpTraceExporter
    {
        return OtlpTraceExporter::create(static function (OtlpHttpConfig $c) use ($cfg): void {
            $c->withEndpointURL($cfg->otlpSignalURL('traces'))
                ->withHeaders($cfg->headers())
                ->withTimeout((float) $cfg->timeout);
        }, $client);
    }

    /**
     * NewMetricReader creates an OTLP/HTTP metric reader for remote monitoring:
     * a periodic reader (60s interval, cfg.Timeout) over
     *
     *   otlpmetrichttp.New(ctx,
     *       otlpmetrichttp.WithEndpointURL(cfg.OTLPSignalURL("metrics")),
     *       otlpmetrichttp.WithHeaders(cfg.Headers()),
     *       otlpmetrichttp.WithTimeout(cfg.Timeout))
     */
    public static function newMetricReader(MonitoringExporterConfig $cfg, ?OtlpHttpClient $client = null): PeriodicReader
    {
        $exporter = OtlpMetricExporter::create(static function (OtlpHttpConfig $c) use ($cfg): void {
            $c->withEndpointURL($cfg->otlpSignalURL('metrics'))
                ->withHeaders($cfg->headers())
                ->withTimeout((float) $cfg->timeout);
        }, $client);

        return new PeriodicReader($exporter, 60.0, (float) $cfg->timeout);
    }

    /**
     * NewLogHook mirrors `monitoringexporter.NewLogHook`: the hook, not yet
     * attached to any logger.
     */
    public static function newLogHook(MonitoringExporterConfig $cfg): LogHook
    {
        return new LogHook($cfg);
    }

    /**
     * InstallLogHook mirrors `monitoringexporter.InstallLogHook`: creates the
     * hook and registers it on the process logger (`logrus.AddHook(h)`).
     */
    public static function installLogHook(MonitoringExporterConfig $cfg): LogHook
    {
        $h = self::newLogHook($cfg);
        Log::get()->pushHandler($h);
        return $h;
    }
}
