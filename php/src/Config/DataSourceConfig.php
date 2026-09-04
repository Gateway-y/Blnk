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
 * Port of Go `config.DataSourceConfig`.
 *
 * `time.Duration` fields are represented as integer seconds in the PHP port.
 */
final class DataSourceConfig
{
    /** JSON: "dns"; env: BLNK_DATA_SOURCE_DNS */
    public string $dns = '';

    /** JSON: "max_open_conns"; env: BLNK_DATABASE_MAX_OPEN_CONNS */
    public int $maxOpenConns = 0;

    /** JSON: "max_idle_conns"; env: BLNK_DATABASE_MAX_IDLE_CONNS */
    public int $maxIdleConns = 0;

    /** Seconds. JSON: "conn_max_lifetime"; env: BLNK_DATABASE_CONN_MAX_LIFETIME */
    public int $connMaxLifetime = 0;

    /** Seconds. JSON: "conn_max_idle_time"; env: BLNK_DATABASE_CONN_MAX_IDLE_TIME */
    public int $connMaxIdleTime = 0;

    /**
     * Default values (Go `defaultDatabase`):
     * MaxOpenConns 50, MaxIdleConns 25, ConnMaxLifetime 30m, ConnMaxIdleTime 5m.
     */
    public static function defaults(): self
    {
        $c = new self();
        $c->maxOpenConns = 50;
        $c->maxIdleConns = 25;
        $c->connMaxLifetime = 30 * 60; // 30 * time.Minute
        $c->connMaxIdleTime = 5 * 60; // 5 * time.Minute
        return $c;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->dns = (string) ($data['dns'] ?? '');
        $c->maxOpenConns = (int) ($data['max_open_conns'] ?? 0);
        $c->maxIdleConns = (int) ($data['max_idle_conns'] ?? 0);
        $c->connMaxLifetime = (int) ($data['conn_max_lifetime'] ?? 0);
        $c->connMaxIdleTime = (int) ($data['conn_max_idle_time'] ?? 0);
        return $c;
    }

    /** Applies the BLNK_* environment overrides (envconfig equivalents). */
    public function applyEnvOverrides(): void
    {
        $this->dns = Env::getString('BLNK_DATA_SOURCE_DNS') ?? $this->dns;
        $this->maxOpenConns = Env::getInt('BLNK_DATABASE_MAX_OPEN_CONNS') ?? $this->maxOpenConns;
        $this->maxIdleConns = Env::getInt('BLNK_DATABASE_MAX_IDLE_CONNS') ?? $this->maxIdleConns;
        $this->connMaxLifetime = Env::getDurationSeconds('BLNK_DATABASE_CONN_MAX_LIFETIME') ?? $this->connMaxLifetime;
        $this->connMaxIdleTime = Env::getDurationSeconds('BLNK_DATABASE_CONN_MAX_IDLE_TIME') ?? $this->connMaxIdleTime;
    }
}
