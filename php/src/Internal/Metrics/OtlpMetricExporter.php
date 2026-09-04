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

use Blnk\Internal\Traces\OtlpHttpClient;
use Blnk\Internal\Traces\OtlpHttpConfig;
use Blnk\Internal\Traces\OtlpJson;

/**
 * OtlpMetricExporter is the port of `otlpmetrichttp.Exporter`: it pushes
 * collected metrics to an OTLP/HTTP endpoint (`POST <endpoint>/v1/metrics`)
 * with cumulative temporality, configured from the OTEL_EXPORTER_OTLP_*
 * environment plus the caller's options (see {@see OtlpHttpConfig}).
 *
 * Encoding: OTLP/JSON (see the divergence note on {@see OtlpJson}).
 */
final class OtlpMetricExporter implements MetricExporterInterface
{
    /** AGGREGATION_TEMPORALITY_CUMULATIVE */
    private const AggregationTemporalityCumulative = 2;

    private OtlpHttpConfig $cfg;

    private OtlpHttpClient $client;

    private bool $stopped = false;

    public function __construct(OtlpHttpConfig $cfg, ?OtlpHttpClient $client = null)
    {
        $this->cfg = $cfg;
        $this->client = $client ?? new OtlpHttpClient();
    }

    /**
     * create mirrors `otlpmetrichttp.New(ctx, opts...)`: defaults and
     * environment first, then the caller's options applied to the config.
     *
     * @param callable(OtlpHttpConfig): void|null $options
     */
    public static function create(?callable $options = null, ?OtlpHttpClient $client = null): self
    {
        $cfg = OtlpHttpConfig::newHTTPConfig('METRICS', OtlpHttpConfig::DefaultMetricsPath);
        if ($options !== null) {
            $options($cfg);
        }
        return new self($cfg->finalize(), $client);
    }

    public function config(): OtlpHttpConfig
    {
        return $this->cfg;
    }

    /**
     * @param array<string, mixed> $resourceMetrics
     *
     * @throws \RuntimeException when the data could not be delivered
     */
    public function export(array $resourceMetrics): void
    {
        if ($this->stopped) {
            return;
        }
        $body = OtlpJson::encode(['resourceMetrics' => [self::resourceMetrics($resourceMetrics)]]);
        $this->client->post($this->cfg, $body, 'metric data points');
    }

    public function forceFlush(): void
    {
    }

    public function shutdown(): void
    {
        $this->stopped = true;
    }

    /**
     * resourceMetrics mirrors `transform.ResourceMetrics`.
     *
     * @param array<string, mixed> $rm as produced by {@see MeterProvider::collect()}
     *
     * @return array<string, mixed>
     */
    public static function resourceMetrics(array $rm): array
    {
        $resource = $rm['resource'] ?? null;
        $scopeMetrics = [];
        foreach ($rm['scopeMetrics'] ?? [] as $sm) {
            $metrics = [];
            foreach ($sm['metrics'] as $metric) {
                $metrics[] = self::metric($metric);
            }
            $scopeMetrics[] = [
                'scope' => OtlpJson::scope((string) $sm['scope']['name'], (string) ($sm['scope']['version'] ?? '')),
                'metrics' => $metrics,
                'schemaUrl' => (string) ($sm['scope']['schemaUrl'] ?? ''),
            ];
        }
        return [
            'resource' => OtlpJson::resource($resource),
            'scopeMetrics' => $scopeMetrics,
            'schemaUrl' => $resource?->schemaURL() ?? '',
        ];
    }

    /**
     * metric mirrors `transform.metric`: Sum / Gauge / Histogram data.
     *
     * @param array<string, mixed> $m
     *
     * @return array<string, mixed>
     */
    public static function metric(array $m): array
    {
        $out = [
            'name' => $m['name'],
            'description' => $m['description'],
            'unit' => $m['unit'],
        ];
        $integer = (bool) $m['integer'];

        switch ($m['kind']) {
            case 'sum':
                $out['sum'] = [
                    'dataPoints' => self::numberDataPoints($m['dataPoints'], $integer),
                    'aggregationTemporality' => self::AggregationTemporalityCumulative,
                    'isMonotonic' => (bool) $m['monotonic'],
                ];
                break;
            case 'histogram':
                $out['histogram'] = [
                    'dataPoints' => self::histogramDataPoints($m['dataPoints']),
                    'aggregationTemporality' => self::AggregationTemporalityCumulative,
                ];
                break;
            default:
                $out['gauge'] = [
                    'dataPoints' => self::numberDataPoints($m['dataPoints'], $integer),
                ];
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $points
     *
     * @return array<int, array<string, mixed>>
     */
    private static function numberDataPoints(array $points, bool $integer): array
    {
        $out = [];
        foreach ($points as $dp) {
            $encoded = [
                'attributes' => OtlpJson::attributes($dp['attributes']),
                'startTimeUnixNano' => OtlpJson::uint64((int) $dp['startTimeUnixNano']),
                'timeUnixNano' => OtlpJson::uint64((int) $dp['timeUnixNano']),
            ];
            if ($integer) {
                $encoded['asInt'] = (string) (int) $dp['value'];
            } else {
                $encoded['asDouble'] = (float) $dp['value'];
            }
            $out[] = $encoded;
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $points
     *
     * @return array<int, array<string, mixed>>
     */
    private static function histogramDataPoints(array $points): array
    {
        $out = [];
        foreach ($points as $dp) {
            $out[] = [
                'attributes' => OtlpJson::attributes($dp['attributes']),
                'startTimeUnixNano' => OtlpJson::uint64((int) $dp['startTimeUnixNano']),
                'timeUnixNano' => OtlpJson::uint64((int) $dp['timeUnixNano']),
                'count' => OtlpJson::uint64((int) $dp['count']),
                'sum' => (float) $dp['sum'],
                'bucketCounts' => array_map(static fn (int $c): string => OtlpJson::uint64($c), $dp['bucketCounts']),
                'explicitBounds' => array_map('floatval', $dp['bounds']),
                'min' => (float) $dp['min'],
                'max' => (float) $dp['max'],
            ];
        }
        return $out;
    }
}
