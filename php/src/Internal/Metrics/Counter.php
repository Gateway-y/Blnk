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
 * Counter is the in-memory replacement for an OTel `metric.Int64Counter`
 * (per PORTING.md: "internal/metrics → simple in-memory counters class with
 * the same method names"). Values accumulate per attribute set within the
 * current PHP process only; nothing is exported.
 */
final class Counter
{
    public readonly string $name;

    public readonly string $description;

    public readonly string $unit;

    /** @var array<string, int> total per serialized attribute set */
    private array $values = [];

    public function __construct(string $name, string $description = '', string $unit = '')
    {
        $this->name = $name;
        $this->description = $description;
        $this->unit = $unit;
    }

    /**
     * Add increments the counter (OTel `counter.Add(ctx, incr, attrs...)`).
     *
     * @param array<string, mixed> $attributes
     */
    public function add(int $incr, array $attributes = []): void
    {
        $key = MetricAttributes::key($attributes);
        $this->values[$key] = ($this->values[$key] ?? 0) + $incr;
    }

    /**
     * The accumulated totals, keyed by serialized attribute set.
     *
     * @return array<string, int>
     */
    public function values(): array
    {
        return $this->values;
    }

    /** The grand total across all attribute sets. */
    public function total(): int
    {
        return array_sum($this->values);
    }

    /** Resets all accumulated values (test helper). */
    public function reset(): void
    {
        $this->values = [];
    }
}
