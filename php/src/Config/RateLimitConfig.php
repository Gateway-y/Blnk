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

namespace Blnk\Config;

/**
 * Port of Go `config.RateLimitConfig`. Pointer fields (`*float64`, `*int`)
 * become nullable properties; null means "not configured" and triggers the
 * defaulting in Configuration::setupRateLimiting().
 */
final class RateLimitConfig
{
    /** JSON: "requests_per_second"; env: BLNK_RATE_LIMIT_RPS */
    public ?float $requestsPerSecond = null;

    /** JSON: "burst"; env: BLNK_RATE_LIMIT_BURST */
    public ?int $burst = null;

    /** JSON: "cleanup_interval_sec"; env: BLNK_RATE_LIMIT_CLEANUP_INTERVAL_SEC */
    public ?int $cleanupIntervalSec = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        if (array_key_exists('requests_per_second', $data) && $data['requests_per_second'] !== null) {
            $c->requestsPerSecond = (float) $data['requests_per_second'];
        }
        if (array_key_exists('burst', $data) && $data['burst'] !== null) {
            $c->burst = (int) $data['burst'];
        }
        if (array_key_exists('cleanup_interval_sec', $data) && $data['cleanup_interval_sec'] !== null) {
            $c->cleanupIntervalSec = (int) $data['cleanup_interval_sec'];
        }
        return $c;
    }

    /** Applies the BLNK_* environment overrides (envconfig equivalents). */
    public function applyEnvOverrides(): void
    {
        $this->requestsPerSecond = Env::getFloat('BLNK_RATE_LIMIT_RPS') ?? $this->requestsPerSecond;
        $this->burst = Env::getInt('BLNK_RATE_LIMIT_BURST') ?? $this->burst;
        $this->cleanupIntervalSec = Env::getInt('BLNK_RATE_LIMIT_CLEANUP_INTERVAL_SEC') ?? $this->cleanupIntervalSec;
    }
}
