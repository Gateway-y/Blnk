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
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;

/**
 * OtlpHttpClient is the transport shared by the OTLP/HTTP trace and metric
 * exporters (the `client` of otlptracehttp / otlpmetrichttp): one POST of an
 * OTLP/JSON request body per export, with the configured headers, timeout,
 * optional gzip compression and TLS material.
 *
 * DIVERGENCE (documented): the Go clients retry retry-able failures (429,
 * 502, 503, 504, transport errors) with exponential backoff for up to a
 * minute from a background goroutine. The PHP port runs exports in-line and
 * therefore makes a single attempt; a failed batch is reported to the caller
 * (which logs and drops it, like `otel.Handle`).
 */
final class OtlpHttpClient
{
    private const UserAgent = 'OTel OTLP Exporter Blnk-PHP/1.0';

    private ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new GuzzleClient();
    }

    /**
     * post sends one OTLP request body and interprets the response like the
     * Go client's `Do`: 2xx succeeds (a partial-success message is reported
     * through the logger), anything else fails.
     *
     * @param string $signalName the noun used in messages ("spans", "metric data points")
     *
     * @throws \RuntimeException on transport failure or a non-2xx response
     */
    public function post(OtlpHttpConfig $cfg, string $body, string $signalName): void
    {
        $url = $cfg->url();
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => self::UserAgent,
        ];
        foreach ($cfg->headers as $name => $value) {
            $headers[$name] = $value;
        }
        if ($cfg->compression === OtlpHttpConfig::CompressionGzip) {
            $compressed = gzencode($body);
            if ($compressed !== false) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        $options = [
            'headers' => $headers,
            'body' => $body,
            'http_errors' => false,
            'timeout' => $cfg->timeoutSec > 0 ? $cfg->timeoutSec : OtlpHttpConfig::DefaultTimeoutSec,
            'connect_timeout' => $cfg->timeoutSec > 0 ? $cfg->timeoutSec : OtlpHttpConfig::DefaultTimeoutSec,
        ];
        if ($cfg->certificateFile !== null) {
            $options['verify'] = $cfg->certificateFile;
        }
        if ($cfg->clientCertificateFile !== null && $cfg->clientKeyFile !== null) {
            $options['cert'] = $cfg->clientCertificateFile;
            $options['ssl_key'] = $cfg->clientKeyFile;
        }

        try {
            $resp = $this->http->request('POST', $url, $options);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('failed to send %s to %s: %s', $signalName, $url, $err->getMessage()), 0, $err);
        }

        $sc = $resp->getStatusCode();
        if ($sc >= 200 && $sc <= 299) {
            // Success, do not retry. Read the partial success message, if any.
            $respData = (string) $resp->getBody();
            if ($respData === '') {
                return;
            }
            $decoded = json_decode($respData, true);
            if (is_array($decoded) && isset($decoded['partialSuccess']) && is_array($decoded['partialSuccess'])) {
                $ps = $decoded['partialSuccess'];
                $msg = (string) ($ps['errorMessage'] ?? '');
                $n = (int) ($ps['rejectedSpans'] ?? $ps['rejectedDataPoints'] ?? $ps['rejectedLogRecords'] ?? 0);
                if ($n !== 0 || $msg !== '') {
                    Log::get()->warning(sprintf('OTLP partial success: %s (%d %s rejected)', $msg, $n, $signalName));
                }
            }
            return;
        }

        // Retry-able failures (429, 502, 503, 504) and every other error: the
        // single attempt of the PHP port fails the batch.
        throw new \RuntimeException(sprintf('failed to send %s to %s: %d %s', $signalName, $url, $sc, $resp->getReasonPhrase()));
    }
}
