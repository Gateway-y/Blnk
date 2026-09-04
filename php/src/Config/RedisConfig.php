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
 * Port of Go `config.RedisConfig`.
 */
final class RedisConfig
{
    /** JSON: "dns"; env: BLNK_REDIS_DNS */
    public string $dns = '';

    /** JSON: "skip_tls_verify"; env: BLNK_REDIS_SKIP_TLS_VERIFY */
    public bool $skipTLSVerify = false;

    /** JSON: "pool_size"; env: BLNK_REDIS_POOL_SIZE */
    public int $poolSize = 0;

    /** JSON: "min_idle_conns"; env: BLNK_REDIS_MIN_IDLE_CONNS */
    public int $minIdleConns = 0;

    /** Default values (Go `defaultRedis`): PoolSize 100, MinIdleConns 20. */
    public static function defaults(): self
    {
        $c = new self();
        $c->poolSize = 100;
        $c->minIdleConns = 20;
        return $c;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->dns = (string) ($data['dns'] ?? '');
        $c->skipTLSVerify = (bool) ($data['skip_tls_verify'] ?? false);
        $c->poolSize = (int) ($data['pool_size'] ?? 0);
        $c->minIdleConns = (int) ($data['min_idle_conns'] ?? 0);
        return $c;
    }

    /** Applies the BLNK_* environment overrides (envconfig equivalents). */
    public function applyEnvOverrides(): void
    {
        $this->dns = Env::getString('BLNK_REDIS_DNS') ?? $this->dns;
        $this->skipTLSVerify = Env::getBool('BLNK_REDIS_SKIP_TLS_VERIFY') ?? $this->skipTLSVerify;
        $this->poolSize = Env::getInt('BLNK_REDIS_POOL_SIZE') ?? $this->poolSize;
        $this->minIdleConns = Env::getInt('BLNK_REDIS_MIN_IDLE_CONNS') ?? $this->minIdleConns;
    }
}
