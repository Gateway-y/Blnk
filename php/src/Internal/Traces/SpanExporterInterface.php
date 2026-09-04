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
 * SpanExporterInterface is the port of `sdktrace.SpanExporter`: the sink a
 * span processor hands finished spans to.
 */
interface SpanExporterInterface
{
    /**
     * ExportSpans exports a batch of ended spans (`ExportSpans(ctx, spans)`).
     *
     * @param Span[] $spans
     *
     * @throws \Throwable when the batch could not be delivered
     */
    public function exportSpans(array $spans): void;

    /**
     * Shutdown notifies the exporter of a pending halt to operations
     * (`Shutdown(ctx)`); after it, export calls are no-ops.
     */
    public function shutdown(): void;
}
