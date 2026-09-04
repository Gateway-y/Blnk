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
 * Port of Go `config.ServerConfig`.
 */
final class ServerConfig
{
    /** JSON: "ssl"; env: BLNK_SERVER_SSL */
    public bool $ssl = false;

    /** JSON: "cert_storage_path"; env: BLNK_CERT_STORAGE_PATH */
    public string $certStoragePath = '';

    /** JSON: "secure"; env: BLNK_SERVER_SECURE */
    public bool $secure = false;

    /** JSON: "secret_key"; env: BLNK_SERVER_SECRET_KEY */
    public string $secretKey = '';

    /** JSON: "domain"; env: BLNK_SERVER_SSL_DOMAIN */
    public string $domain = '';

    /** JSON: "ssl_email"; env: BLNK_SERVER_SSL_EMAIL */
    public string $email = '';

    /** JSON: "port"; env: BLNK_SERVER_PORT */
    public string $port = '';

    /** JSON: "metrics_bearer_token"; env: BLNK_METRICS_BEARER_TOKEN */
    public string $metricsBearerToken = '';

    /** JSON: "max_upload_size_mb"; env: BLNK_SERVER_MAX_UPLOAD_SIZE_MB */
    public int $maxUploadSizeMB = 0;

    /** JSON: "max_request_body_size_mb"; env: BLNK_SERVER_MAX_REQUEST_BODY_SIZE_MB */
    public int $maxRequestBodySizeMB = 0;

    /**
     * UploadDomainWhitelist is a comma-separated list of exact hostnames permitted as
     * targets for URL-based reconciliation uploads (BLNK_UPLOAD_DOMAIN_WHITELIST).
     * Empty/unset means deny-by-default: every URL upload is rejected. Listing a
     * bare IP literal or "localhost" is unsafe and defeats the SSRF guard.
     *
     * JSON: "upload_whitelist"; env: BLNK_UPLOAD_DOMAIN_WHITELIST
     */
    public string $uploadDomainWhitelist = '';

    /**
     * UploadURLTimeoutSec caps the HTTP GET issued for a URL-based upload
     * (BLNK_UPLOAD_URL_TIMEOUT_SEC). Defaults to
     * Configuration::DEFAULT_UPLOAD_URL_TIMEOUT_SEC.
     *
     * JSON: "upload_url_timeout_sec"; env: BLNK_UPLOAD_URL_TIMEOUT_SEC
     */
    public int $uploadURLTimeoutSec = 0;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->ssl = (bool) ($data['ssl'] ?? false);
        $c->certStoragePath = (string) ($data['cert_storage_path'] ?? '');
        $c->secure = (bool) ($data['secure'] ?? false);
        $c->secretKey = (string) ($data['secret_key'] ?? '');
        $c->domain = (string) ($data['domain'] ?? '');
        $c->email = (string) ($data['ssl_email'] ?? '');
        $c->port = (string) ($data['port'] ?? '');
        $c->metricsBearerToken = (string) ($data['metrics_bearer_token'] ?? '');
        $c->maxUploadSizeMB = (int) ($data['max_upload_size_mb'] ?? 0);
        $c->maxRequestBodySizeMB = (int) ($data['max_request_body_size_mb'] ?? 0);
        $c->uploadDomainWhitelist = (string) ($data['upload_whitelist'] ?? '');
        $c->uploadURLTimeoutSec = (int) ($data['upload_url_timeout_sec'] ?? 0);
        return $c;
    }

    /** Applies the BLNK_* environment overrides (envconfig equivalents). */
    public function applyEnvOverrides(): void
    {
        $this->ssl = Env::getBool('BLNK_SERVER_SSL') ?? $this->ssl;
        $this->certStoragePath = Env::getString('BLNK_CERT_STORAGE_PATH') ?? $this->certStoragePath;
        $this->secure = Env::getBool('BLNK_SERVER_SECURE') ?? $this->secure;
        $this->secretKey = Env::getString('BLNK_SERVER_SECRET_KEY') ?? $this->secretKey;
        $this->domain = Env::getString('BLNK_SERVER_SSL_DOMAIN') ?? $this->domain;
        $this->email = Env::getString('BLNK_SERVER_SSL_EMAIL') ?? $this->email;
        $this->port = Env::getString('BLNK_SERVER_PORT') ?? $this->port;
        $this->metricsBearerToken = Env::getString('BLNK_METRICS_BEARER_TOKEN') ?? $this->metricsBearerToken;
        $this->maxUploadSizeMB = Env::getInt('BLNK_SERVER_MAX_UPLOAD_SIZE_MB') ?? $this->maxUploadSizeMB;
        $this->maxRequestBodySizeMB = Env::getInt('BLNK_SERVER_MAX_REQUEST_BODY_SIZE_MB') ?? $this->maxRequestBodySizeMB;
        $this->uploadDomainWhitelist = Env::getString('BLNK_UPLOAD_DOMAIN_WHITELIST') ?? $this->uploadDomainWhitelist;
        $this->uploadURLTimeoutSec = Env::getInt('BLNK_UPLOAD_URL_TIMEOUT_SEC') ?? $this->uploadURLTimeoutSec;
    }
}
