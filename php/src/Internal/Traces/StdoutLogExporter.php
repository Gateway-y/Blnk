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
 * StdoutLogExporter is the port of `stdoutlog.New()`: it writes every log
 * record as one JSON document on standard output, with the field layout of
 * the Go exporter's `recordJSON` (Timestamp, ObservedTimestamp, Severity,
 * SeverityText, Body, Attributes, TraceID, SpanID, TraceFlags, Resource,
 * Scope, DroppedAttributes).
 */
final class StdoutLogExporter implements LogExporterInterface
{
    /** @var resource|null */
    private $stream = null;

    private bool $stopped = false;

    /**
     * @param resource|null $stream defaults to php://stdout
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function export(array $records): void
    {
        if ($this->stopped || $records === []) {
            return;
        }
        $stream = $this->stream();
        if ($stream === null) {
            return;
        }
        foreach ($records as $record) {
            $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                continue;
            }
            fwrite($stream, $json . "\n");
        }
        fflush($stream);
    }

    public function forceFlush(): void
    {
        if ($this->stream !== null && is_resource($this->stream)) {
            fflush($this->stream);
        }
    }

    public function shutdown(): void
    {
        $this->forceFlush();
        $this->stopped = true;
    }

    /**
     * @return resource|null
     */
    private function stream()
    {
        if ($this->stream === null) {
            $stream = @fopen('php://stdout', 'w');
            $this->stream = $stream === false ? null : $stream;
        }
        return $this->stream;
    }
}
