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
 * Config describes the optional remote monitoring telemetry sink.
 *
 * Port of Go `internal/monitoringexporter` `config.go` (the exporter itself
 * is a logging stub in the PHP port — see {@see MonitoringExporter}).
 *
 * Go's `FromDSN(dsn) (Config, bool, error)` maps to
 * {@see MonitoringExporterConfig::fromDSN()}: it returns null when the DSN is
 * empty (remote export disabled) and throws \InvalidArgumentException when
 * the DSN is invalid.
 */
final class MonitoringExporterConfig
{
    public const ENV_DSN = 'BLNK_MONITORING_DSN';
    public const ENV_LEGACY_DSN = 'BLNK_CLOUD_DSN';

    /** defaultTimeout (Go: 5 * time.Second), in seconds. */
    public const DEFAULT_TIMEOUT_SEC = 5;

    public string $dsn = '';

    public string $publicKey = '';

    public string $projectID = '';

    public string $endpoint = '';

    /** Seconds. */
    public int $timeout = 0;

    /**
     * FromEnv returns the monitoring exporter config from environment variables.
     * A missing DSN is not an error; it means remote export is disabled (null).
     *
     * @throws \InvalidArgumentException when a present DSN is invalid.
     */
    public static function fromEnv(): ?self
    {
        $dsn = getenv(self::ENV_DSN);
        $dsn = $dsn === false ? '' : $dsn;
        if (trim($dsn) === '') {
            $legacy = getenv(self::ENV_LEGACY_DSN);
            $dsn = $legacy === false ? '' : $legacy;
        }
        return self::fromDSN($dsn);
    }

    /**
     * FromDSN parses a DSN into a config. Returns null for an empty DSN
     * (remote export disabled); throws for an invalid one.
     *
     * @throws \InvalidArgumentException when the DSN is invalid.
     */
    public static function fromDSN(string $dsn): ?self
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            return null;
        }

        $cfg = self::parseDSN($dsn);
        $cfg->timeout = self::DEFAULT_TIMEOUT_SEC;
        return $cfg;
    }

    /**
     * ParseDSN parses a remote monitoring DSN:
     * https://<write-key>@<host>/<project-id>
     *
     * @throws \InvalidArgumentException on invalid input (Go returns an error).
     */
    public static function parseDSN(string $raw): self
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('monitoring exporter DSN is empty');
        }

        $parsed = parse_url($raw);
        if ($parsed === false) {
            throw new \InvalidArgumentException('monitoring exporter DSN is invalid');
        }
        if (($parsed['scheme'] ?? '') !== 'https') {
            throw new \InvalidArgumentException('monitoring exporter DSN must use https');
        }
        if (($parsed['host'] ?? '') === '') {
            throw new \InvalidArgumentException('monitoring exporter DSN host is required');
        }
        if (($parsed['user'] ?? '') === '') {
            throw new \InvalidArgumentException('monitoring exporter DSN write key is required');
        }

        $projectID = trim(trim($parsed['path'] ?? ''), '/');
        if ($projectID === '') {
            throw new \InvalidArgumentException('monitoring exporter DSN project id is required');
        }
        if (str_contains($projectID, '/')) {
            throw new \InvalidArgumentException('monitoring exporter DSN project id must be a single path segment');
        }

        $host = $parsed['host'];
        if (isset($parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }
        $endpoint = 'https://' . $host;

        $cfg = new self();
        $cfg->dsn = $raw;
        $cfg->publicKey = rawurldecode($parsed['user']);
        $cfg->projectID = $projectID;
        $cfg->endpoint = $endpoint;
        $cfg->timeout = self::DEFAULT_TIMEOUT_SEC;
        return $cfg;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];
        if ($this->publicKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->publicKey;
        }
        return $headers;
    }

    public function signalURL(string $signal): string
    {
        $endpoint = rtrim($this->endpoint, '/');
        return $endpoint . '/monitoring/ingest/v1/' . ltrim($signal, '/');
    }

    public function otlpSignalURL(string $signal): string
    {
        $endpoint = rtrim($this->endpoint, '/');
        return $endpoint . '/monitoring/ingest/v1/otlp/' . ltrim($signal, '/');
    }
}
