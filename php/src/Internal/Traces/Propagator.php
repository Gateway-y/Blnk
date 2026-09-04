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
 * Propagator is the port of the composite text-map propagator built by
 * `newPropagator` in otel.go:
 *
 *   propagation.NewCompositeTextMapPropagator(propagation.TraceContext{}, propagation.Baggage{})
 *
 * It injects/extracts the W3C `traceparent` + `tracestate` headers and the
 * W3C `baggage` header. Carriers are header maps (name → value); lookups are
 * case-insensitive like `http.Header`.
 */
final class Propagator
{
    public const TraceparentHeader = 'traceparent';
    public const TracestateHeader = 'tracestate';
    public const BaggageHeader = 'baggage';

    /** supportedVersion of the W3C trace context */
    private const SupportedVersion = 0;

    /** maxVersion */
    private const MaxVersion = 254;

    /**
     * Fields returns the keys whose values are set with Inject
     * (`CompositeTextMapPropagator.Fields`).
     *
     * @return string[]
     */
    public function fields(): array
    {
        return [self::TraceparentHeader, self::TracestateHeader, self::BaggageHeader];
    }

    /**
     * Inject sets the cross-cutting concerns from the span context and baggage
     * into the carrier (`Inject(ctx, carrier)`).
     *
     * @param array<string, string> $baggage
     * @param array<string, string> $carrier
     */
    public function inject(?SpanContext $sc, array $baggage, array &$carrier): void
    {
        // propagation.TraceContext.Inject
        if ($sc !== null && $sc->isValid()) {
            $carrier[self::TraceparentHeader] = sprintf(
                '%02x-%s-%s-%02x',
                self::SupportedVersion,
                $sc->traceId,
                $sc->spanId,
                $sc->traceFlags & SpanContext::FlagsSampled
            );
            if ($sc->traceState !== '') {
                $carrier[self::TracestateHeader] = $sc->traceState;
            }
        }

        // propagation.Baggage.Inject
        if ($baggage !== []) {
            $members = [];
            foreach ($baggage as $key => $value) {
                $members[] = (string) $key . '=' . rawurlencode((string) $value);
            }
            $carrier[self::BaggageHeader] = implode(',', $members);
        }
    }

    /**
     * Extract reads the span context and baggage from the carrier
     * (`Extract(ctx, carrier)`). The span context is null when no valid
     * `traceparent` is present; it is marked remote otherwise.
     *
     * @param array<string, string|string[]> $carrier
     *
     * @return array{0: SpanContext|null, 1: array<string, string>}
     */
    public function extract(array $carrier): array
    {
        $lookup = [];
        foreach ($carrier as $name => $value) {
            $lookup[strtolower((string) $name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        $sc = $this->extractTraceContext($lookup);
        $baggage = $this->extractBaggage($lookup[self::BaggageHeader] ?? '');

        return [$sc, $baggage];
    }

    /**
     * extractTraceContext mirrors `TraceContext.extract`.
     *
     * @param array<string, string> $lookup lower-cased header name → value
     */
    private function extractTraceContext(array $lookup): ?SpanContext
    {
        $h = $lookup[self::TraceparentHeader] ?? '';
        if ($h === '') {
            return null;
        }

        $matches = [];
        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(?:-(.*))?$/i', $h, $matches) !== 1) {
            return null;
        }

        $version = hexdec($matches[1]);
        if ($version > self::MaxVersion) {
            return null;
        }
        if ($version === self::SupportedVersion && isset($matches[5])) {
            // Version 00 must not carry additional trailing fields.
            return null;
        }

        $traceId = strtolower($matches[2]);
        $spanId = strtolower($matches[3]);
        $flags = (int) hexdec($matches[4]);

        $sc = new SpanContext($traceId, $spanId, $flags & SpanContext::FlagsSampled, $lookup[self::TracestateHeader] ?? '', true);
        if (!$sc->isValid()) {
            return null;
        }
        return $sc;
    }

    /**
     * extractBaggage mirrors `Baggage.Extract`: "key=value;prop,key2=value2",
     * percent-decoded; malformed members are dropped.
     *
     * @return array<string, string>
     */
    private function extractBaggage(string $header): array
    {
        $baggage = [];
        if (trim($header) === '') {
            return $baggage;
        }
        foreach (explode(',', $header) as $member) {
            $member = trim($member);
            if ($member === '') {
                continue;
            }
            $kv = explode(';', $member, 2)[0];
            $eq = strpos($kv, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($kv, 0, $eq));
            $value = trim(substr($kv, $eq + 1));
            if ($key === '') {
                continue;
            }
            $baggage[$key] = rawurldecode($value);
        }
        return $baggage;
    }
}
