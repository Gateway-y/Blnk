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
 * Helper turning a metric attribute map (the OTel `attribute.KeyValue` set)
 * into a stable string key for in-memory aggregation.
 */
final class MetricAttributes
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function key(array $attributes): string
    {
        if ($attributes === []) {
            return '';
        }
        ksort($attributes);
        return (string) json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
