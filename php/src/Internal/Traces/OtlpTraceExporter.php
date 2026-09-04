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

/**
 * OtlpTraceExporter is the port of `otlptracehttp.Exporter`: it ships ended
 * spans to an OTLP/HTTP collector endpoint (`POST <endpoint>/v1/traces`),
 * configured from the OTEL_EXPORTER_OTLP_* environment plus the options the
 * caller applies (see {@see OtlpHttpConfig}).
 *
 * Encoding: OTLP/JSON (see the divergence note on {@see OtlpJson}).
 */
final class OtlpTraceExporter implements SpanExporterInterface
{
    /** SPAN_FLAGS_CONTEXT_HAS_IS_REMOTE_MASK */
    private const FlagsContextHasIsRemote = 0x100;

    /** SPAN_FLAGS_CONTEXT_IS_REMOTE_MASK */
    private const FlagsContextIsRemote = 0x200;

    private OtlpHttpConfig $cfg;

    private OtlpHttpClient $client;

    private bool $stopped = false;

    public function __construct(OtlpHttpConfig $cfg, ?OtlpHttpClient $client = null)
    {
        $this->cfg = $cfg;
        $this->client = $client ?? new OtlpHttpClient();
    }

    /**
     * create mirrors `otlptracehttp.New(ctx, opts...)`: defaults and
     * environment first, then the caller's options applied to the config.
     *
     * @param callable(OtlpHttpConfig): void|null $options
     */
    public static function create(?callable $options = null, ?OtlpHttpClient $client = null): self
    {
        $cfg = OtlpHttpConfig::newHTTPConfig('TRACES', OtlpHttpConfig::DefaultTracesPath);
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
     * @param Span[] $spans
     *
     * @throws \RuntimeException when the batch could not be delivered
     */
    public function exportSpans(array $spans): void
    {
        if ($this->stopped || $spans === []) {
            return;
        }

        $body = OtlpJson::encode(['resourceSpans' => self::resourceSpans($spans)]);
        $this->client->post($this->cfg, $body, 'spans');
    }

    public function shutdown(): void
    {
        $this->stopped = true;
    }

    /**
     * resourceSpans mirrors `tracetransform.Spans`: spans grouped by resource
     * and instrumentation scope.
     *
     * @param Span[] $spans
     *
     * @return array<int, array<string, mixed>>
     */
    public static function resourceSpans(array $spans): array
    {
        /** @var array<string, array{resource: Resource|null, scopes: array<string, Span[]>}> $groups */
        $groups = [];
        foreach ($spans as $span) {
            $resource = $span->resource();
            $rk = $resource === null ? '' : (string) spl_object_id($resource);
            if (!isset($groups[$rk])) {
                $groups[$rk] = ['resource' => $resource, 'scopes' => []];
            }
            $groups[$rk]['scopes'][$span->scopeName()][] = $span;
        }

        $out = [];
        foreach ($groups as $group) {
            $scopeSpans = [];
            foreach ($group['scopes'] as $scopeName => $scoped) {
                $encoded = [];
                foreach ($scoped as $span) {
                    $encoded[] = self::span($span);
                }
                $scopeSpans[] = [
                    'scope' => OtlpJson::scope((string) $scopeName),
                    'spans' => $encoded,
                ];
            }
            $out[] = [
                'resource' => OtlpJson::resource($group['resource']),
                'scopeSpans' => $scopeSpans,
                'schemaUrl' => $group['resource']?->schemaURL() ?? '',
            ];
        }
        return $out;
    }

    /**
     * span mirrors `tracetransform.span`.
     *
     * @return array<string, mixed>
     */
    public static function span(Span $span): array
    {
        $sc = $span->spanContext();
        $parent = $span->parent();
        $status = $span->status();

        $flags = self::FlagsContextHasIsRemote;
        if ($parent !== null && $parent->isRemote()) {
            $flags |= self::FlagsContextIsRemote;
        }

        $events = [];
        foreach ($span->events() as $event) {
            $events[] = [
                'timeUnixNano' => OtlpJson::uint64($event['timeUnixNano']),
                'name' => $event['name'],
                'attributes' => OtlpJson::attributes($event['attributes']),
            ];
        }

        $out = [
            'traceId' => $sc->traceId,
            'spanId' => $sc->spanId,
        ];
        if ($sc->traceState !== '') {
            $out['traceState'] = $sc->traceState;
        }
        if ($parent !== null && $parent->isValid()) {
            $out['parentSpanId'] = $parent->spanId;
        }
        $out['flags'] = $flags;
        $out['name'] = $span->name();
        $out['kind'] = $span->kind();
        $out['startTimeUnixNano'] = OtlpJson::uint64($span->startTimeUnixNano());
        $out['endTimeUnixNano'] = OtlpJson::uint64($span->endTimeUnixNano() ?? $span->startTimeUnixNano());
        $out['attributes'] = OtlpJson::attributes($span->attributes());
        $out['events'] = $events;
        $out['status'] = [
            'code' => $status['code'],
            'message' => $status['description'],
        ];

        return $out;
    }
}
