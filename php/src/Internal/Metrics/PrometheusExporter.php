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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PrometheusExporter is the port of `promexporter.New()` (the OTel Go
 * Prometheus exporter, a pull `metric.Reader`) together with the
 * `promhttp.Handler()` that serves the default registry on /metrics.
 *
 * It renders the {@see MeterProvider}'s metrics in the Prometheus text
 * exposition format 0.0.4 following the exporter's conventions:
 *  - metric names translated like `otlptranslator.MetricNamer` with unit and
 *    counter suffixes (`blnk.transaction.duration` + "s" →
 *    `blnk_transaction_duration_seconds`, counters end in `_total`);
 *  - every series carries the `otel_scope_name`, `otel_scope_version` and
 *    `otel_scope_schema_url` labels;
 *  - a `target_info` gauge exposes the resource attributes;
 *  - histograms use the SDK's explicit buckets with cumulative `le` counts.
 * Plus what `promhttp.Handler()` adds on the Go side: the
 * `promhttp_metric_handler_*` self metrics and the `process_*` collector
 * ({@see ProcessCollector}). The Go runtime (`go_*`) metrics are not
 * emulated.
 */
final class PrometheusExporter implements MetricReaderInterface
{
    /** The content type of the text exposition format (expfmt.FmtText). */
    public const ContentType = 'text/plain; version=0.0.4; charset=utf-8';

    private const TargetInfoMetricName = 'target_info';
    private const TargetInfoDescription = 'Target metadata';

    private const ScopeNameLabel = 'otel_scope_name';
    private const ScopeVersionLabel = 'otel_scope_version';
    private const ScopeSchemaLabel = 'otel_scope_schema_url';

    /** The map to translate OTLP units to Prometheus units. */
    private const UnitMap = [
        // Time
        'd' => 'days',
        'h' => 'hours',
        'min' => 'minutes',
        's' => 'seconds',
        'ms' => 'milliseconds',
        'us' => 'microseconds',
        'ns' => 'nanoseconds',
        // Bytes
        'By' => 'bytes',
        'KiBy' => 'kibibytes',
        'MiBy' => 'mebibytes',
        'GiBy' => 'gibibytes',
        'TiBy' => 'tibibytes',
        'KBy' => 'kilobytes',
        'MBy' => 'megabytes',
        'GBy' => 'gigabytes',
        'TBy' => 'terabytes',
        // SI
        'm' => 'meters',
        'V' => 'volts',
        'A' => 'amperes',
        'J' => 'joules',
        'W' => 'watts',
        'g' => 'grams',
        // Misc
        'Cel' => 'celsius',
        'Hz' => 'hertz',
        '1' => '',
        '%' => 'percent',
    ];

    /** The map that translates the "per" unit. */
    private const PerUnitMap = [
        's' => 'second',
        'm' => 'minute',
        'h' => 'hour',
        'd' => 'day',
        'w' => 'week',
        'mo' => 'month',
        'y' => 'year',
    ];

    private ?MeterProvider $producer = null;

    private bool $stopped = false;

    private ProcessCollector $processCollector;

    /** promhttp_metric_handler_requests_in_flight */
    private int $inFlight = 0;

    /** promhttp_metric_handler_requests_total, pre-initialized like InstrumentMetricHandler. @var array<string, int> */
    private array $handlerRequests = ['200' => 0, '500' => 0, '503' => 0];

    public function __construct(?ProcessCollector $processCollector = null)
    {
        $this->processCollector = $processCollector ?? new ProcessCollector();
    }

    public function register(MeterProvider $producer): void
    {
        $this->producer = $producer;
    }

    public function tick(float $now): void
    {
        // Pull reader: nothing to do between scrapes.
    }

    public function forceFlush(): void
    {
        // Pull reader: nothing buffered.
    }

    public function shutdown(): void
    {
        // metric.ErrReaderShutdown: later scrapes only carry the registry's own metrics.
        $this->stopped = true;
    }

    /**
     * handler returns the /metrics HTTP handler (`promhttp.Handler()`) in the
     * shape the server and worker monitoring router call it with:
     * `callable(ServerRequestInterface, ResponseInterface): ResponseInterface`.
     *
     * @return callable(ServerRequestInterface, ResponseInterface): ResponseInterface
     */
    public function handler(): callable
    {
        return function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            return $this->serveHTTP($request, $response);
        };
    }

    /**
     * serveHTTP mirrors `promhttp.HandlerFor(reg, HandlerOpts{})` wrapped in
     * `InstrumentMetricHandler`: gathers and encodes, gzip-compresses when the
     * client accepts it, answers 500 with a text message on failure, and
     * accounts the scrape in the handler's own metrics.
     */
    public function serveHTTP(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->inFlight++;
        $code = 200;
        try {
            $body = $this->render();
        } catch (\Throwable $err) {
            $code = 500;
            $body = "An error has occurred while serving metrics:\n\n" . $err->getMessage();
        } finally {
            $this->inFlight--;
        }
        $this->handlerRequests[(string) $code] = ($this->handlerRequests[(string) $code] ?? 0) + 1;

        $response = $response->withStatus($code);
        if ($code !== 200) {
            $response = $response
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('X-Content-Type-Options', 'nosniff');
            $response->getBody()->write($body);
            return $response;
        }

        $response = $response->withHeader('Content-Type', self::ContentType);
        if (self::gzipAccepted($request->getHeaderLine('Accept-Encoding'))) {
            $compressed = gzencode($body);
            if ($compressed !== false) {
                $body = $compressed;
                $response = $response->withHeader('Content-Encoding', 'gzip');
            }
        }
        $response->getBody()->write($body);
        return $response;
    }

    /**
     * render gathers every family and encodes them in the text format.
     */
    public function render(): string
    {
        $families = $this->gather();
        usort($families, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $out = '';
        foreach ($families as $family) {
            $out .= self::encodeFamily($family);
        }
        return $out;
    }

    /**
     * gather mirrors `Registry.Gather()`: the OTel collector's output
     * (target_info + instruments), the process collector and the handler's
     * self metrics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gather(): array
    {
        $families = [];

        // The OTel collector (collector.Collect).
        if ($this->producer !== null && !$this->stopped) {
            $rm = $this->producer->collect();

            $resourceLabels = [];
            foreach ($rm['resource']->attributes() as $key => $value) {
                $resourceLabels[self::labelName((string) $key)] = self::emitValue($value);
            }
            $families[] = [
                'name' => self::TargetInfoMetricName,
                'help' => self::TargetInfoDescription,
                'type' => 'gauge',
                'metrics' => [['labels' => $resourceLabels, 'value' => 1.0]],
            ];

            $seen = [];
            foreach ($rm['scopeMetrics'] as $scopeMetrics) {
                $scopeLabels = [
                    self::ScopeNameLabel => (string) $scopeMetrics['scope']['name'],
                    self::ScopeVersionLabel => (string) ($scopeMetrics['scope']['version'] ?? ''),
                    self::ScopeSchemaLabel => (string) ($scopeMetrics['scope']['schemaUrl'] ?? ''),
                ];
                foreach ($scopeMetrics['metrics'] as $metric) {
                    $type = self::metricType($metric);
                    $name = self::metricName((string) $metric['name'], (string) $metric['unit'], (string) $metric['kind'], (bool) $metric['monotonic']);
                    if ($name === '') {
                        continue;
                    }
                    // validateMetrics: a name already registered with another type is dropped.
                    if (isset($seen[$name]) && $seen[$name] !== $type) {
                        continue;
                    }
                    $seen[$name] = $type;

                    $families[] = [
                        'name' => $name,
                        'help' => (string) $metric['description'],
                        'type' => $type,
                        'metrics' => self::seriesOf($metric, $scopeLabels),
                    ];
                }
            }
        }

        // prometheus.NewProcessCollector (registered in the default registry).
        foreach ($this->processCollector->collect() as $family) {
            $families[] = $family;
        }

        // promhttp.InstrumentMetricHandler self metrics.
        $families[] = [
            'name' => 'promhttp_metric_handler_requests_in_flight',
            'help' => 'Current number of scrapes being served.',
            'type' => 'gauge',
            'metrics' => [['labels' => [], 'value' => (float) $this->inFlight]],
        ];
        $requests = [];
        foreach ($this->handlerRequests as $code => $count) {
            $requests[] = ['labels' => ['code' => (string) $code], 'value' => (float) $count];
        }
        $families[] = [
            'name' => 'promhttp_metric_handler_requests_total',
            'help' => 'Total number of scrapes by HTTP status code.',
            'type' => 'counter',
            'metrics' => $requests,
        ];

        return $families;
    }

    /**
     * seriesOf converts a metric's data points into series with sorted
     * Prometheus labels (attributes + scope labels).
     *
     * @param array<string, mixed> $metric
     * @param array<string, string> $scopeLabels
     *
     * @return array<int, array<string, mixed>>
     */
    private static function seriesOf(array $metric, array $scopeLabels): array
    {
        $series = [];
        foreach ($metric['dataPoints'] as $dp) {
            $labels = [];
            foreach ($dp['attributes'] as $key => $value) {
                $name = self::labelName((string) $key);
                // Duplicate sanitized keys: sorted values joined with ";" (getAttrs).
                if (isset($labels[$name])) {
                    $vals = explode(';', $labels[$name]);
                    $vals[] = self::emitValue($value);
                    sort($vals, SORT_STRING);
                    $labels[$name] = implode(';', $vals);
                } else {
                    $labels[$name] = self::emitValue($value);
                }
            }
            foreach ($scopeLabels as $name => $value) {
                $labels[$name] = $value;
            }
            ksort($labels, SORT_STRING);

            if ($metric['kind'] === 'histogram') {
                $buckets = [];
                $cumulative = 0;
                foreach ($dp['bounds'] as $i => $bound) {
                    $cumulative += (int) ($dp['bucketCounts'][$i] ?? 0);
                    $buckets[] = [self::formatFloat((float) $bound), $cumulative];
                }
                $series[] = [
                    'labels' => $labels,
                    'buckets' => $buckets,
                    'sum' => (float) $dp['sum'],
                    'count' => (int) $dp['count'],
                ];
            } else {
                $series[] = ['labels' => $labels, 'value' => (float) $dp['value']];
            }
        }
        usort($series, static fn (array $a, array $b): int => strcmp(self::labelsString($a['labels']), self::labelsString($b['labels'])));
        return $series;
    }

    /**
     * encodeFamily writes one family in the text exposition format
     * (expfmt.MetricFamilyToText).
     *
     * @param array<string, mixed> $family
     */
    private static function encodeFamily(array $family): string
    {
        $name = $family['name'];
        $out = '# HELP ' . $name . ' ' . self::escapeHelp((string) $family['help']) . "\n";
        $out .= '# TYPE ' . $name . ' ' . $family['type'] . "\n";
        foreach ($family['metrics'] as $series) {
            if ($family['type'] === 'histogram') {
                foreach ($series['buckets'] as [$le, $count]) {
                    $out .= $name . '_bucket' . self::labelsString($series['labels'] + ['le' => $le], true) . ' ' . self::formatFloat((float) $count) . "\n";
                }
                $out .= $name . '_bucket' . self::labelsString($series['labels'] + ['le' => '+Inf'], true) . ' ' . self::formatFloat((float) $series['count']) . "\n";
                $out .= $name . '_sum' . self::labelsString($series['labels']) . ' ' . self::formatFloat((float) $series['sum']) . "\n";
                $out .= $name . '_count' . self::labelsString($series['labels']) . ' ' . self::formatFloat((float) $series['count']) . "\n";
            } else {
                $out .= $name . self::labelsString($series['labels']) . ' ' . self::formatFloat((float) $series['value']) . "\n";
            }
        }
        return $out;
    }

    /**
     * labelsString renders `{k="v",...}` (empty string without labels). The
     * `le` label of histogram buckets is written last, as client_golang does.
     *
     * @param array<string, string> $labels
     */
    private static function labelsString(array $labels, bool $leLast = false): string
    {
        if ($labels === []) {
            return '';
        }
        $le = null;
        if ($leLast && array_key_exists('le', $labels)) {
            $le = $labels['le'];
            unset($labels['le']);
        }
        $pairs = [];
        foreach ($labels as $name => $value) {
            $pairs[] = $name . '="' . self::escapeLabelValue((string) $value) . '"';
        }
        if ($le !== null) {
            $pairs[] = 'le="' . self::escapeLabelValue((string) $le) . '"';
        }
        return '{' . implode(',', $pairs) . '}';
    }

    private static function metricType(array $metric): string
    {
        return match ($metric['kind']) {
            'histogram' => 'histogram',
            'sum' => $metric['monotonic'] ? 'counter' : 'gauge',
            default => 'gauge',
        };
    }

    /**
     * metricName mirrors `otlptranslator.normalizeName` (the
     * UnderscoreEscapingWithSuffixes translation strategy, no namespace):
     * the name split into tokens of valid characters, unit tokens appended
     * when not already present, `_total` for monotonic counters and `_ratio`
     * for unit-"1" gauges.
     */
    public static function metricName(string $name, string $unit, string $kind, bool $monotonic): string
    {
        $nameTokens = preg_split('/[^a-zA-Z0-9:]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        [$mainUnitSuffix, $perUnitSuffix] = self::buildUnitSuffixes($unit);
        $nameTokens = self::addUnitTokens($nameTokens, self::cleanUpUnit($mainUnitSuffix), self::cleanUpUnit($perUnitSuffix));

        // Append _total for Counters
        if ($kind === 'sum' && $monotonic) {
            $nameTokens = self::removeItem($nameTokens, 'total');
            $nameTokens[] = 'total';
        }

        // Append _ratio for metrics with unit "1" (gauges only)
        if ($unit === '1' && $kind === 'gauge') {
            $nameTokens = self::removeItem($nameTokens, 'ratio');
            $nameTokens[] = 'ratio';
        }

        $normalizedName = implode('_', $nameTokens);

        // Metric name cannot start with a digit, so prefix it with "_" in this case
        if ($normalizedName !== '' && ctype_digit($normalizedName[0])) {
            $normalizedName = '_' . $normalizedName;
        }

        // A name made of underscores only is invalid (buildCompliantMetricName).
        if ($normalizedName !== $name && trim($normalizedName, '_') === '') {
            return '';
        }

        return $normalizedName;
    }

    /**
     * labelName mirrors `otlptranslator.LabelNamer.Build` (escaping mode):
     * invalid characters become `_`, a leading digit gets a `key_` prefix
     * and a single leading underscore a `key` prefix.
     */
    public static function labelName(string $label): string
    {
        if ($label === '') {
            return $label;
        }
        $normalized = (string) preg_replace('/[^a-zA-Z0-9_]/', '_', $label);
        if (ctype_digit($normalized[0])) {
            $normalized = 'key_' . $normalized;
        } elseif (str_starts_with($normalized, '_') && !str_starts_with($normalized, '__')) {
            $normalized = 'key' . $normalized;
        }
        return $normalized;
    }

    /**
     * emitValue mirrors `attribute.Value.Emit()`.
     */
    public static function emitValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::formatFloat($value);
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        if ($value === null) {
            return '';
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        return (string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * formatFloat mirrors `strconv.FormatFloat(v, 'g', -1, 64)` — the number
     * format of the Go text encoder: the shortest decimal that round-trips,
     * in exponent form when the exponent is < -4 or >= 6 (Go's precision for
     * shortest formatting), with a two-digit signed exponent.
     */
    public static function formatFloat(float $v): string
    {
        if (is_nan($v)) {
            return 'NaN';
        }
        if (is_infinite($v)) {
            return $v > 0 ? '+Inf' : '-Inf';
        }
        if ($v == 0.0) {
            // fdiv follows IEEE 754: -INF for negative zero.
            return fdiv(1.0, $v) < 0 ? '-0' : '0';
        }

        $neg = $v < 0;
        [$digits, $dp] = self::shortestDigits(abs($v));
        $exp = $dp - 1;

        if ($exp < -4 || $exp >= 6) {
            $mantissa = $digits[0];
            if (strlen($digits) > 1) {
                $mantissa .= '.' . substr($digits, 1);
            }
            $out = $mantissa . 'e' . ($exp < 0 ? '-' : '+') . str_pad((string) abs($exp), 2, '0', STR_PAD_LEFT);
        } elseif ($dp <= 0) {
            $out = '0.' . str_repeat('0', -$dp) . $digits;
        } elseif ($dp >= strlen($digits)) {
            $out = $digits . str_repeat('0', $dp - strlen($digits));
        } else {
            $out = substr($digits, 0, $dp) . '.' . substr($digits, $dp);
        }

        return ($neg ? '-' : '') . $out;
    }

    /**
     * shortestDigits returns the shortest round-trip decimal digits of a
     * positive float and the position of the decimal point (Go's
     * `decimalSlice.nd/dp`), derived from PHP's shortest repr
     * (serialize_precision = -1).
     *
     * @return array{0: string, 1: int}
     */
    private static function shortestDigits(float $abs): array
    {
        $s = (string) $abs;
        $exp10 = 0;
        $pos = stripos($s, 'e');
        if ($pos !== false) {
            $exp10 = (int) substr($s, $pos + 1);
            $s = substr($s, 0, $pos);
        }
        $dot = strpos($s, '.');
        if ($dot === false) {
            $intPart = $s;
            $fracPart = '';
        } else {
            $intPart = substr($s, 0, $dot);
            $fracPart = substr($s, $dot + 1);
        }
        $digits = $intPart . $fracPart;
        $dp = strlen($intPart) + $exp10;

        $stripped = ltrim($digits, '0');
        $dp -= strlen($digits) - strlen($stripped);
        $digits = rtrim($stripped, '0');
        if ($digits === '') {
            return ['0', 1];
        }
        return [$digits, $dp];
    }

    /**
     * buildUnitSuffixes mirrors otlptranslator's function of the same name.
     *
     * @return array{0: string, 1: string}
     */
    private static function buildUnitSuffixes(string $unit): array
    {
        $mainUnitSuffix = '';
        $perUnitSuffix = '';

        // Split unit at the '/' if any
        $unitTokens = explode('/', $unit, 2);

        // Main unit: update if not blank and doesn't contain '{}'
        $mainUnitOTel = trim($unitTokens[0]);
        if ($mainUnitOTel !== '' && !str_contains($mainUnitOTel, '{') && !str_contains($mainUnitOTel, '}')) {
            $mainUnitSuffix = self::UnitMap[$mainUnitOTel] ?? $mainUnitOTel;
        }

        // Per unit: update if not blank and doesn't contain '{}'
        if (count($unitTokens) > 1 && $unitTokens[1] !== '') {
            $perUnitOTel = trim($unitTokens[1]);
            if ($perUnitOTel !== '' && !str_contains($perUnitOTel, '{') && !str_contains($perUnitOTel, '}')) {
                $perUnitSuffix = self::PerUnitMap[$perUnitOTel] ?? $perUnitOTel;
            }
            if ($perUnitSuffix !== '') {
                $perUnitSuffix = 'per_' . $perUnitSuffix;
            }
        }

        return [$mainUnitSuffix, $perUnitSuffix];
    }

    /**
     * addUnitTokens mirrors otlptranslator's function of the same name.
     *
     * @param string[] $nameTokens
     *
     * @return string[]
     */
    private static function addUnitTokens(array $nameTokens, string $mainUnitSuffix, string $perUnitSuffix): array
    {
        if (in_array($mainUnitSuffix, $nameTokens, true)) {
            $mainUnitSuffix = '';
        }

        if ($perUnitSuffix === 'per_') {
            $perUnitSuffix = '';
        } else {
            $perUnitSuffix = rtrim($perUnitSuffix, '_');
            if (in_array($perUnitSuffix, $nameTokens, true)) {
                $perUnitSuffix = '';
            }
        }

        if ($perUnitSuffix !== '') {
            $mainUnitSuffix = rtrim($mainUnitSuffix, '_');
        }

        if ($mainUnitSuffix !== '') {
            $nameTokens[] = $mainUnitSuffix;
        }
        if ($perUnitSuffix !== '') {
            $nameTokens[] = $perUnitSuffix;
        }
        return $nameTokens;
    }

    /**
     * cleanUpUnit mirrors otlptranslator's function of the same name.
     */
    private static function cleanUpUnit(string $unit): string
    {
        $cleaned = (string) preg_replace('/[^a-zA-Z0-9:]/', '_', $unit);
        $cleaned = (string) preg_replace('/_+/', '_', $cleaned);
        return ltrim($cleaned, '_');
    }

    /**
     * @param string[] $slice
     *
     * @return string[]
     */
    private static function removeItem(array $slice, string $value): array
    {
        return array_values(array_filter($slice, static fn (string $entry): bool => $entry !== $value));
    }

    private static function escapeHelp(string $help): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $help);
    }

    private static function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    /**
     * gzipAccepted mirrors promhttp's check of the Accept-Encoding header.
     */
    private static function gzipAccepted(string $acceptEncoding): bool
    {
        foreach (explode(',', $acceptEncoding) as $part) {
            $part = trim($part);
            if ($part === 'gzip' || str_starts_with($part, 'gzip;')) {
                return true;
            }
        }
        return false;
    }
}
