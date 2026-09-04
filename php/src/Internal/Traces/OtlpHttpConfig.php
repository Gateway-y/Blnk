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

use Blnk\Internal\Log;

/**
 * OtlpHttpConfig is the port of the OTLP/HTTP exporter configuration
 * (`otlpconfig.Config` / `SignalConfig` shared by otlptracehttp and
 * otlpmetrichttp): defaults, the `OTEL_EXPORTER_OTLP_*` environment
 * variables applied by `ApplyHTTPEnvConfigs`, and the programmatic options
 * (`WithEndpoint`, `WithEndpointURL`, `WithInsecure`, `WithHeaders`,
 * `WithTimeout`, `WithCompression`) applied on top of them, in that order,
 * exactly like `NewHTTPConfig(opts...)`.
 *
 * Environment variables honored (generic, then signal-specific — the
 * signal-specific one wins because it is applied last):
 *
 *   OTEL_EXPORTER_OTLP_ENDPOINT / OTEL_EXPORTER_OTLP_{TRACES,METRICS}_ENDPOINT
 *   OTEL_EXPORTER_OTLP_INSECURE / ..._{TRACES,METRICS}_INSECURE
 *   OTEL_EXPORTER_OTLP_HEADERS  / ..._{TRACES,METRICS}_HEADERS
 *   OTEL_EXPORTER_OTLP_COMPRESSION / ..._{TRACES,METRICS}_COMPRESSION
 *   OTEL_EXPORTER_OTLP_TIMEOUT (ms) / ..._{TRACES,METRICS}_TIMEOUT
 *   OTEL_EXPORTER_OTLP_CERTIFICATE / ..._{TRACES,METRICS}_CERTIFICATE
 *   OTEL_EXPORTER_OTLP_CLIENT_CERTIFICATE + CLIENT_KEY (and signal-specific)
 */
final class OtlpHttpConfig
{
    /** DefaultCollectorHost is the host address the Exporter will attempt connect to if no endpoint is configured. */
    public const DefaultCollectorHost = 'localhost';

    /** DefaultCollectorHTTPPort is the default HTTP port of the collector. */
    public const DefaultCollectorHTTPPort = 4318;

    /** DefaultTracesPath is a default URL path for endpoint that receives spans. */
    public const DefaultTracesPath = '/v1/traces';

    /** DefaultMetricsPath is a default URL path for endpoint that receives metrics. */
    public const DefaultMetricsPath = '/v1/metrics';

    /** DefaultTimeout is a default max waiting time for the backend to process each batch (10 * time.Second). */
    public const DefaultTimeoutSec = 10.0;

    public const CompressionNone = 'none';
    public const CompressionGzip = 'gzip';

    /** Environment namespace of the OTLP exporter variables (`envconfig.EnvOptionsReader.Namespace`). */
    private const EnvNamespace = 'OTEL_EXPORTER_OTLP';

    /** host:port of the collector (`SignalConfig.Endpoint`). */
    public string $endpoint;

    /** URL path of the signal (`SignalConfig.URLPath`). */
    public string $urlPath;

    /** Use plain HTTP instead of HTTPS (`SignalConfig.Insecure`). */
    public bool $insecure = false;

    /** @var array<string, string> extra request headers (`SignalConfig.Headers`) */
    public array $headers = [];

    public string $compression = self::CompressionNone;

    /** Export timeout in seconds (`SignalConfig.Timeout`). */
    public float $timeoutSec = self::DefaultTimeoutSec;

    /** PEM CA bundle to verify the collector's certificate (`OTEL_EXPORTER_OTLP_CERTIFICATE`). */
    public ?string $certificateFile = null;

    /** Client certificate / key for mTLS. */
    public ?string $clientCertificateFile = null;

    public ?string $clientKeyFile = null;

    /** The signal's default path, used by {@see finalize()} (`cleanPath`). */
    private string $defaultPath;

    private function __construct(string $defaultPath)
    {
        $this->endpoint = sprintf('%s:%d', self::DefaultCollectorHost, self::DefaultCollectorHTTPPort);
        $this->urlPath = $defaultPath;
        $this->defaultPath = $defaultPath;
    }

    /**
     * newHTTPConfig mirrors `otlpconfig.NewHTTPConfig` before the caller's
     * options: defaults, then the environment. `$signal` is "TRACES" or
     * "METRICS" and selects the signal-specific variable names.
     */
    public static function newHTTPConfig(string $signal, string $defaultPath): self
    {
        $cfg = new self($defaultPath);
        $cfg->applyEnv(strtoupper($signal));
        return $cfg;
    }

    /**
     * applyEnv mirrors `getOptionsFromEnv` (envconfig.go) for the HTTP driver.
     */
    public function applyEnv(string $signal): void
    {
        // envconfig.WithURL("ENDPOINT", ...)
        $u = self::envURL('ENDPOINT');
        if ($u !== null) {
            $this->withEndpointScheme($u['scheme']);
            $this->endpoint = $u['host'];
            // For OTLP/HTTP endpoint URLs without a per-signal
            // configuration, the passed endpoint is used as a base URL
            // and the signals are sent to these paths relative to that.
            $this->urlPath = self::pathJoin($u['path'], $this->defaultPath);
        }

        // envconfig.WithURL("<SIGNAL>_ENDPOINT", ...)
        $u = self::envURL($signal . '_ENDPOINT');
        if ($u !== null) {
            $this->withEndpointScheme($u['scheme']);
            $this->endpoint = $u['host'];
            // For endpoint URLs for OTLP/HTTP per-signal variables, the
            // URL MUST be used as-is without any modification. The only
            // exception is that if an URL contains no path part, the root
            // path / MUST be used.
            $path = $u['path'];
            if ($path === '') {
                $path = '/';
            }
            $this->urlPath = $path;
        }

        // envconfig.WithCertPool / WithClientCert (TLS material for HTTPS endpoints).
        foreach (['CERTIFICATE', $signal . '_CERTIFICATE'] as $key) {
            $file = self::envValue($key);
            if ($file !== null) {
                $this->certificateFile = $file;
            }
        }
        foreach ([['CLIENT_CERTIFICATE', 'CLIENT_KEY'], [$signal . '_CLIENT_CERTIFICATE', $signal . '_CLIENT_KEY']] as [$certKey, $keyKey]) {
            $cert = self::envValue($certKey);
            $key = self::envValue($keyKey);
            if ($cert !== null && $key !== null) {
                $this->clientCertificateFile = $cert;
                $this->clientKeyFile = $key;
            }
        }

        // envconfig.WithBool("INSECURE" / "<SIGNAL>_INSECURE", ...)
        foreach (['INSECURE', $signal . '_INSECURE'] as $key) {
            $b = self::envBool($key);
            if ($b !== null) {
                $this->insecure = $b;
            }
        }

        // envconfig.WithHeaders("HEADERS" / "<SIGNAL>_HEADERS", ...)
        foreach (['HEADERS', $signal . '_HEADERS'] as $key) {
            $raw = self::envValue($key);
            if ($raw !== null) {
                $this->headers = self::parseHeaders($raw);
            }
        }

        // WithEnvCompression("COMPRESSION" / "<SIGNAL>_COMPRESSION", ...)
        foreach (['COMPRESSION', $signal . '_COMPRESSION'] as $key) {
            $raw = self::envValue($key);
            if ($raw !== null) {
                $this->compression = $raw === 'gzip' ? self::CompressionGzip : self::CompressionNone;
            }
        }

        // envconfig.WithDuration("TIMEOUT" / "<SIGNAL>_TIMEOUT", ...) — integer milliseconds.
        foreach (['TIMEOUT', $signal . '_TIMEOUT'] as $key) {
            $raw = self::envValue($key);
            if ($raw !== null) {
                if (preg_match('/^-?\d+$/', $raw) !== 1) {
                    Log::get()->debug('otlp exporter: invalid environment value, ignoring', ['key' => self::EnvNamespace . '_' . $key, 'value' => $raw]);
                    continue;
                }
                $this->timeoutSec = ((int) $raw) / 1000;
            }
        }
    }

    /**
     * WithEndpoint configures the host and port only; endpoint should resemble
     * "example.com" or "localhost:4318". To configure the scheme and path, use
     * {@see withEndpointURL()}.
     */
    public function withEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    /**
     * WithEndpointURL configures the scheme, host, port, and path; the provided
     * value should resemble "https://example.com:4318/v1/traces".
     */
    public function withEndpointURL(string $url): self
    {
        $u = self::parseGoURL($url);
        if ($u === null) {
            Log::get()->error('otlp exporter: parse endpoint url', ['url' => $url]);
            return $this;
        }
        $this->endpoint = $u['host'];
        $this->urlPath = $u['path'];
        $this->insecure = $u['scheme'] !== 'https';
        return $this;
    }

    public function withInsecure(): self
    {
        $this->insecure = true;
        return $this;
    }

    public function withSecure(): self
    {
        $this->insecure = false;
        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    public function withTimeout(float $seconds): self
    {
        $this->timeoutSec = $seconds;
        return $this;
    }

    public function withCompression(string $compression): self
    {
        $this->compression = $compression === self::CompressionGzip ? self::CompressionGzip : self::CompressionNone;
        return $this;
    }

    /**
     * finalize applies `cleanPath` (trailing step of `NewHTTPConfig`): an
     * empty path falls back to the signal default and a relative one is
     * made absolute.
     */
    public function finalize(): self
    {
        $tmp = trim($this->urlPath);
        if ($tmp === '' || $tmp === '.') {
            $tmp = $this->defaultPath;
        }
        if (!str_starts_with($tmp, '/')) {
            $tmp = '/' . $tmp;
        }
        $this->urlPath = $tmp;
        return $this;
    }

    /** The full request URL (`scheme://endpoint + urlPath`). */
    public function url(): string
    {
        return ($this->insecure ? 'http' : 'https') . '://' . $this->endpoint . $this->urlPath;
    }

    /**
     * withEndpointScheme mirrors envconfig.go `withEndpointScheme`: "http" and
     * "unix" URLs are insecure, anything else secure.
     */
    private function withEndpointScheme(string $scheme): void
    {
        switch (strtolower($scheme)) {
            case 'http':
            case 'unix':
                $this->insecure = true;
                break;
            default:
                $this->insecure = false;
        }
    }

    /**
     * envValue mirrors `EnvOptionsReader.GetEnvValue`: the trimmed value of
     * OTEL_EXPORTER_OTLP_<key>, or null when unset/blank.
     */
    private static function envValue(string $key): ?string
    {
        $v = getenv(self::EnvNamespace . '_' . $key);
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    /**
     * envURL mirrors `envconfig.WithURL`: parses the variable as a URL and
     * returns its parts, or null when unset or unparsable.
     *
     * @return array{scheme: string, host: string, path: string}|null
     */
    private static function envURL(string $key): ?array
    {
        $v = self::envValue($key);
        if ($v === null) {
            return null;
        }
        $u = self::parseGoURL($v);
        if ($u === null) {
            Log::get()->error('otlp exporter: environment variable holds an invalid URL', ['key' => self::EnvNamespace . '_' . $key, 'value' => $v]);
        }
        return $u;
    }

    /**
     * envBool mirrors `envconfig.WithBool` (strconv.ParseBool semantics).
     */
    private static function envBool(string $key): ?bool
    {
        $v = self::envValue($key);
        if ($v === null) {
            return null;
        }
        return match ($v) {
            '1', 't', 'T', 'TRUE', 'true', 'True' => true,
            '0', 'f', 'F', 'FALSE', 'false', 'False' => false,
            default => null,
        };
    }

    /**
     * parseGoURL emulates Go's `url.Parse` closely enough for endpoint
     * handling: returns the lower-cased scheme, the host (with port) and the
     * path. A bare "host:port" (no "://") is what Go parses as an opaque URL
     * with an empty Host; a value Go rejects yields null.
     *
     * @return array{scheme: string, host: string, path: string}|null
     */
    public static function parseGoURL(string $v): ?array
    {
        if (str_contains($v, '://')) {
            $parsed = parse_url($v);
            if ($parsed === false) {
                return null;
            }
            $host = (string) ($parsed['host'] ?? '');
            if ($host !== '' && isset($parsed['port'])) {
                $host .= ':' . $parsed['port'];
            }
            return [
                'scheme' => strtolower((string) ($parsed['scheme'] ?? '')),
                'host' => $host,
                'path' => (string) ($parsed['path'] ?? ''),
            ];
        }

        // "scheme:opaque" — Go accepts it with an empty Host.
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $v, $m) === 1) {
            return ['scheme' => strtolower($m[1]), 'host' => '', 'path' => ''];
        }

        // No scheme: Go rejects a first path segment containing a colon.
        $segment = explode('/', $v, 2)[0];
        if (str_contains($segment, ':')) {
            return null;
        }
        return ['scheme' => '', 'host' => '', 'path' => $v];
    }

    /**
     * parseHeaders mirrors `envconfig.stringToHeader`: "key=value,key2=value2"
     * with percent-decoded names and values; malformed pairs are skipped.
     *
     * @return array<string, string>
     */
    public static function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode(',', $raw) as $header) {
            $eq = strpos($header, '=');
            if ($eq === false) {
                Log::get()->debug('otlp exporter: missing "=" in header, ignoring', ['header' => $header]);
                continue;
            }
            $name = trim(rawurldecode(substr($header, 0, $eq)));
            $value = trim(rawurldecode(substr($header, $eq + 1)));
            if ($name === '' || preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $name) !== 1) {
                Log::get()->debug('otlp exporter: invalid header key, ignoring', ['header' => $header]);
                continue;
            }
            $headers[$name] = $value;
        }
        return $headers;
    }

    /**
     * pathJoin mirrors Go's `path.Join(a, b)` for the two-segment case.
     */
    private static function pathJoin(string $a, string $b): string
    {
        $joined = ($a === '' ? '' : rtrim($a, '/')) . '/' . ltrim($b, '/');
        $joined = (string) preg_replace('#/+#', '/', $joined);
        if ($a !== '' && !str_starts_with($a, '/')) {
            $joined = ltrim($joined, '/');
        }
        return $joined;
    }
}
