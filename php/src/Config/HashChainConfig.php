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
 * HashChainConfig controls the background hash-chainer that seals transaction
 * history into a tamper-evident chain. It lives under transaction config and is
 * disabled by default.
 *
 * Port of Go `config.HashChainConfig`; `time.Duration` fields are integer seconds.
 */
final class HashChainConfig
{
    /** JSON: "enabled"; env: BLNK_TRANSACTION_HASHCHAIN_ENABLED */
    public bool $enabled = false;

    /** Seconds. JSON: "poll_interval"; env: BLNK_TRANSACTION_HASHCHAIN_POLL_INTERVAL */
    public int $pollInterval = 0;

    /** JSON: "batch_size"; env: BLNK_TRANSACTION_HASHCHAIN_BATCH_SIZE */
    public int $batchSize = 0;

    /** Seconds. JSON: "trailing_delay"; env: BLNK_TRANSACTION_HASHCHAIN_TRAILING_DELAY */
    public int $trailingDelay = 0;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->enabled = (bool) ($data['enabled'] ?? false);
        $c->pollInterval = (int) ($data['poll_interval'] ?? 0);
        $c->batchSize = (int) ($data['batch_size'] ?? 0);
        $c->trailingDelay = (int) ($data['trailing_delay'] ?? 0);
        return $c;
    }

    /** Applies the BLNK_* environment overrides (envconfig equivalents). */
    public function applyEnvOverrides(): void
    {
        $this->enabled = Env::getBool('BLNK_TRANSACTION_HASHCHAIN_ENABLED') ?? $this->enabled;
        $this->pollInterval = Env::getDurationSeconds('BLNK_TRANSACTION_HASHCHAIN_POLL_INTERVAL') ?? $this->pollInterval;
        $this->batchSize = Env::getInt('BLNK_TRANSACTION_HASHCHAIN_BATCH_SIZE') ?? $this->batchSize;
        $this->trailingDelay = Env::getDurationSeconds('BLNK_TRANSACTION_HASHCHAIN_TRAILING_DELAY') ?? $this->trailingDelay;
    }
}
