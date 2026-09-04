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
 * TracerProvider is the port of `sdktrace.TracerProvider` as built by
 * `newTraceProvider` in otel.go: a resource plus one batch span processor per
 * exporter (the OTLP collector exporter and, when enabled, the remote
 * monitoring exporter). Installed globally with
 * {@see Tracer::setTracerProvider()} (`otel.SetTracerProvider`).
 */
final class TracerProvider
{
    private Resource $resource;

    /** @var BatchSpanProcessor[] */
    private array $processors;

    private bool $stopped = false;

    /**
     * @param BatchSpanProcessor[] $processors
     */
    public function __construct(Resource $resource, array $processors)
    {
        // sdktrace.WithResource merges the environment resource under the
        // explicit one.
        $this->resource = Resource::merge(Resource::environment(), $resource);
        $this->processors = array_values($processors);
    }

    public function resource(): Resource
    {
        return $this->resource;
    }

    /**
     * @return BatchSpanProcessor[]
     */
    public function processors(): array
    {
        return $this->processors;
    }

    /**
     * onEnd hands an ended span to every registered processor.
     */
    public function onEnd(Span $span): void
    {
        if ($this->stopped) {
            return;
        }
        foreach ($this->processors as $processor) {
            $processor->onEnd($span);
        }
    }

    /**
     * tick lets the processors honor their batch timeout (see
     * {@see BatchSpanProcessor::tick()}).
     */
    public function tick(float $now): void
    {
        if ($this->stopped) {
            return;
        }
        foreach ($this->processors as $processor) {
            $processor->tick($now);
        }
    }

    /**
     * ForceFlush immediately exports all spans that have not yet been exported
     * for all the registered span processors.
     */
    public function forceFlush(): void
    {
        if ($this->stopped) {
            return;
        }
        foreach ($this->processors as $processor) {
            $processor->forceFlush();
        }
    }

    /**
     * Shutdown shuts down the span processors in the order they were
     * registered; it is safe to call multiple times and joins the errors of
     * the individual shutdowns.
     *
     * @throws \RuntimeException the joined processor errors, if any
     */
    public function shutdown(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $errors = [];
        foreach ($this->processors as $processor) {
            try {
                $processor->shutdown();
            } catch (\Throwable $err) {
                $errors[] = $err->getMessage();
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(implode("\n", $errors));
        }
    }
}
