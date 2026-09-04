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
 * Span is the port of the SDK's recording span (`sdktrace.ReadWriteSpan`):
 * it carries the span context, parent, kind, timestamps, attributes, events
 * and status, and hands itself to the {@see TracerProvider}'s processors when
 * ended so the OTLP exporters can ship it.
 *
 * When no OTel SDK has been set up ({@see Tracer::setupOTelSDK()} not
 * called, i.e. `enable_observability` is off) the span still records
 * everything in memory but nothing is exported — the analogue of the global
 * no-op tracer provider before `otel.SetTracerProvider`.
 */
final class Span
{
    /** trace.SpanKindInternal */
    public const KindInternal = 1;
    /** trace.SpanKindServer */
    public const KindServer = 2;
    /** trace.SpanKindClient */
    public const KindClient = 3;
    /** trace.SpanKindProducer */
    public const KindProducer = 4;
    /** trace.SpanKindConsumer */
    public const KindConsumer = 5;

    /** codes.Unset */
    public const StatusUnset = 0;
    /** codes.Ok */
    public const StatusOk = 1;
    /** codes.Error */
    public const StatusError = 2;

    private string $scopeName;

    private string $name;

    private SpanContext $context;

    private ?SpanContext $parent;

    private int $kind;

    private int $startTimeUnixNano;

    private ?int $endTimeUnixNano = null;

    /** @var array<string, mixed> */
    private array $attributes;

    /** @var array<int, array{name: string, timeUnixNano: int, attributes: array<string, mixed>}> */
    private array $events = [];

    private int $statusCode = self::StatusUnset;

    private string $statusDescription = '';

    private ?TracerProvider $provider;

    private bool $ended = false;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        string $scopeName,
        string $name,
        SpanContext $context,
        ?SpanContext $parent = null,
        array $attributes = [],
        int $kind = self::KindInternal,
        ?TracerProvider $provider = null,
        ?int $startTimeUnixNano = null
    ) {
        $this->scopeName = $scopeName;
        $this->name = $name;
        $this->context = $context;
        $this->parent = $parent;
        $this->attributes = $attributes;
        $this->kind = $kind;
        $this->provider = $provider;
        $this->startTimeUnixNano = $startTimeUnixNano ?? Clock::nowUnixNano();
    }

    /** The span name. */
    public function name(): string
    {
        return $this->name;
    }

    /** SetName renames the span (`span.SetName`). */
    public function setName(string $name): void
    {
        if ($this->ended) {
            return;
        }
        $this->name = $name;
    }

    /** SpanContext returns the span's context (`span.SpanContext()`). */
    public function spanContext(): SpanContext
    {
        return $this->context;
    }

    /** Parent returns the parent span context, or null for a root span. */
    public function parent(): ?SpanContext
    {
        return $this->parent;
    }

    /** The instrumentation scope (tracer) name that created the span. */
    public function scopeName(): string
    {
        return $this->scopeName;
    }

    /** The span kind (one of the Kind* constants). */
    public function kind(): int
    {
        return $this->kind;
    }

    public function startTimeUnixNano(): int
    {
        return $this->startTimeUnixNano;
    }

    /** The end timestamp, or null while the span is still recording. */
    public function endTimeUnixNano(): ?int
    {
        return $this->endTimeUnixNano;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return array<int, array{name: string, timeUnixNano: int, attributes: array<string, mixed>}>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return array{code: int, description: string}
     */
    public function status(): array
    {
        return ['code' => $this->statusCode, 'description' => $this->statusDescription];
    }

    /** The resource of the provider that created the span (null without an SDK). */
    public function resource(): ?Resource
    {
        return $this->provider?->resource();
    }

    /** IsRecording mirrors `span.IsRecording()`: false once ended. */
    public function isRecording(): bool
    {
        return !$this->ended;
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    /**
     * SetAttribute records an attribute on the span (OTel `span.SetAttributes`).
     */
    public function setAttribute(string $key, mixed $value): void
    {
        if ($this->ended) {
            return;
        }
        $this->attributes[$key] = $value;
    }

    /**
     * SetAttributes records several attributes at once (`span.SetAttributes(kv...)`).
     *
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        if ($this->ended) {
            return;
        }
        foreach ($attributes as $key => $value) {
            $this->attributes[(string) $key] = $value;
        }
    }

    /**
     * AddEvent adds an event with the provided name and attributes
     * (`span.AddEvent(name, trace.WithAttributes(...))`).
     *
     * @param array<string, mixed> $attributes
     */
    public function addEvent(string $name, array $attributes = [], ?int $timeUnixNano = null): void
    {
        if ($this->ended) {
            return;
        }
        $this->events[] = [
            'name' => $name,
            'timeUnixNano' => $timeUnixNano ?? Clock::nowUnixNano(),
            'attributes' => $attributes,
        ];
    }

    /**
     * RecordError records an error as an "exception" span event with the
     * semantic-convention attributes (`span.RecordError(err)`): exception.type
     * and exception.message. It does not change the span status, exactly like
     * the Go SDK.
     *
     * @param array<string, mixed> $attributes extra event attributes
     */
    public function recordError(\Throwable $error, array $attributes = []): void
    {
        if ($this->ended) {
            return;
        }
        $this->addEvent('exception', $attributes + [
            'exception.type' => $error::class,
            'exception.message' => $error->getMessage(),
        ]);
    }

    /**
     * SetStatus sets the span status (`span.SetStatus(code, description)`).
     * As in the SDK, the description is only kept for an Error status and the
     * status can only be overridden by a "higher" one (Unset < Error < Ok).
     */
    public function setStatus(int $code, string $description = ''): void
    {
        if ($this->ended) {
            return;
        }
        // The SDK ignores attempts to lower the status: Ok is final and
        // Error can only replace Unset.
        if ($this->statusCode === self::StatusOk) {
            return;
        }
        if ($code === self::StatusError && $this->statusCode === self::StatusError) {
            $this->statusDescription = $description;
            return;
        }
        $this->statusCode = $code;
        $this->statusDescription = $code === self::StatusError ? $description : '';
    }

    /**
     * End completes the span (OTel `span.End()`); safe to call once. The span
     * is handed to the tracer provider's processors for export and logged at
     * debug level with its duration.
     */
    public function end(?int $endTimeUnixNano = null): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->endTimeUnixNano = $endTimeUnixNano ?? Clock::nowUnixNano();

        Tracer::spanEnded($this);

        Log::get()->debug('span ended', [
            'span' => $this->name,
            'tracer' => $this->scopeName,
            'trace_id' => $this->context->traceId,
            'span_id' => $this->context->spanId,
            'duration_ms' => ($this->endTimeUnixNano - $this->startTimeUnixNano) / 1_000_000,
            'attributes' => $this->attributes,
        ]);

        if ($this->provider !== null) {
            try {
                $this->provider->onEnd($this);
            } catch (\Throwable $err) {
                // Exporters must never break the instrumented code path
                // (Go routes such failures to otel.Handle).
                Log::get()->warning('span processor failed', ['error' => $err->getMessage()]);
            }
        }
    }
}
