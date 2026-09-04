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
 * Port of Go `config.WebhookConfig`.
 */
final class WebhookConfig
{
    /** JSON: "url"; env: BLNK_WEBHOOK_URL */
    public string $url = '';

    /**
     * JSON: "headers"; env: BLNK_WEBHOOK_HEADERS (comma-separated key:value pairs).
     *
     * @var array<string, string>
     */
    public array $headers = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->url = (string) ($data['url'] ?? '');
        $headers = $data['headers'] ?? [];
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                $c->headers[(string) $key] = (string) $value;
            }
        }
        return $c;
    }

    /** Applies the BLNK_* environment overrides (envconfig equivalents). */
    public function applyEnvOverrides(): void
    {
        $this->url = Env::getString('BLNK_WEBHOOK_URL') ?? $this->url;
        $this->headers = Env::getHeaderMap('BLNK_WEBHOOK_HEADERS') ?? $this->headers;
    }
}
