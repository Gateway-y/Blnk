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
 * Port of Go `config.ReconciliationConfig`; `time.Duration` fields are integer seconds.
 */
final class ReconciliationConfig
{
    /** JSON: "default_strategy"; env: BLNK_RECONCILIATION_DEFAULT_STRATEGY */
    public string $defaultStrategy = '';

    /** JSON: "progress_interval"; env: BLNK_RECONCILIATION_PROGRESS_INTERVAL */
    public int $progressInterval = 0;

    /** JSON: "max_retries"; env: BLNK_RECONCILIATION_MAX_RETRIES */
    public int $maxRetries = 0;

    /** Seconds. JSON: "retry_delay"; env: BLNK_RECONCILIATION_RETRY_DELAY */
    public int $retryDelay = 0;

    /**
     * Default values (Go `defaultReconciliation`):
     * DefaultStrategy "one_to_one", ProgressInterval 100, MaxRetries 3,
     * RetryDelay 5s.
     */
    public static function defaults(): self
    {
        $c = new self();
        $c->defaultStrategy = 'one_to_one';
        $c->progressInterval = 100;
        $c->maxRetries = 3;
        $c->retryDelay = 5; // 5 * time.Second
        return $c;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->defaultStrategy = (string) ($data['default_strategy'] ?? '');
        $c->progressInterval = (int) ($data['progress_interval'] ?? 0);
        $c->maxRetries = (int) ($data['max_retries'] ?? 0);
        $c->retryDelay = (int) ($data['retry_delay'] ?? 0);
        return $c;
    }

    /** Applies the BLNK_* environment overrides (envconfig equivalents). */
    public function applyEnvOverrides(): void
    {
        $this->defaultStrategy = Env::getString('BLNK_RECONCILIATION_DEFAULT_STRATEGY') ?? $this->defaultStrategy;
        $this->progressInterval = Env::getInt('BLNK_RECONCILIATION_PROGRESS_INTERVAL') ?? $this->progressInterval;
        $this->maxRetries = Env::getInt('BLNK_RECONCILIATION_MAX_RETRIES') ?? $this->maxRetries;
        $this->retryDelay = Env::getDurationSeconds('BLNK_RECONCILIATION_RETRY_DELAY') ?? $this->retryDelay;
    }
}
