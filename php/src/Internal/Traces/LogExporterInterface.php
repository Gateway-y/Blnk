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
 * LogExporterInterface is the port of `sdklog.Exporter`: the sink of the
 * {@see LoggerProvider}'s batch processor.
 */
interface LogExporterInterface
{
    /**
     * Export transmits log records (`Export(ctx, records)`).
     *
     * @param array<int, array<string, mixed>> $records
     *
     * @throws \Throwable when the batch could not be written
     */
    public function export(array $records): void;

    /** ForceFlush flushes any buffered data (`ForceFlush(ctx)`). */
    public function forceFlush(): void;

    /** Shutdown releases the exporter's resources (`Shutdown(ctx)`). */
    public function shutdown(): void;
}
