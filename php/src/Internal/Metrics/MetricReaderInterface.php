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

/**
 * MetricReaderInterface is the port of `sdkmetric.Reader`: the bridge between
 * the {@see MeterProvider} (the producer) and an exporter — a pull reader
 * ({@see PrometheusExporter}) or a push reader ({@see PeriodicReader}).
 */
interface MetricReaderInterface
{
    /**
     * register binds the reader to the provider that owns it
     * (`Reader.register(producer)`, done by `sdkmetric.WithReader`).
     */
    public function register(MeterProvider $producer): void;

    /**
     * tick lets a push reader honor its export interval (see the divergence
     * note on {@see PeriodicReader}).
     */
    public function tick(float $now): void;

    /** ForceFlush collects and exports immediately (`ForceFlush(ctx)`). */
    public function forceFlush(): void;

    /** Shutdown performs a final export and releases the reader (`Shutdown(ctx)`). */
    public function shutdown(): void;
}
