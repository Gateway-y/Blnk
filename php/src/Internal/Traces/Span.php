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
 * Span is the no-op-friendly stand-in for an OpenTelemetry span
 * (`trace.Span`). It records nothing remotely; every operation logs at debug
 * level only, per PORTING.md ("OTEL traces/metrics → no-op logger stubs").
 */
final class Span
{
    private string $name;

    private float $startedAt;

    /** @var array<string, mixed> */
    private array $attributes;

    private bool $ended = false;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(string $name, array $attributes = [])
    {
        $this->name = $name;
        $this->startedAt = microtime(true);
        $this->attributes = $attributes;
    }

    /** The span name. */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * SetAttribute records an attribute on the span (OTel `span.SetAttributes`).
     * Logged at debug level only.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
        Log::get()->debug('span attribute', ['span' => $this->name, 'key' => $key, 'value' => $value]);
    }

    /**
     * RecordError records an error on the span (OTel `span.RecordError`).
     * Logged at debug level only.
     */
    public function recordError(\Throwable $error): void
    {
        Log::get()->debug('span error', ['span' => $this->name, 'error' => $error->getMessage()]);
    }

    /**
     * End completes the span (OTel `span.End()`); safe to call once.
     * Logged at debug level with the span duration.
     */
    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        Log::get()->debug('span ended', [
            'span' => $this->name,
            'duration_ms' => (microtime(true) - $this->startedAt) * 1000,
            'attributes' => $this->attributes,
        ]);
    }
}
