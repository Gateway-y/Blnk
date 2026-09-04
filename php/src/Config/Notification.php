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
 * Port of Go `config.Notification`.
 */
final class Notification
{
    /** JSON: "slack" */
    public SlackWebhook $slack;

    /** JSON: "webhook" */
    public WebhookConfig $webhook;

    public function __construct()
    {
        $this->slack = new SlackWebhook();
        $this->webhook = new WebhookConfig();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $slack = $data['slack'] ?? [];
        if (is_array($slack)) {
            $c->slack = SlackWebhook::fromArray($slack);
        }
        $webhook = $data['webhook'] ?? [];
        if (is_array($webhook)) {
            $c->webhook = WebhookConfig::fromArray($webhook);
        }
        return $c;
    }

    /** Applies the BLNK_* environment overrides (envconfig equivalents). */
    public function applyEnvOverrides(): void
    {
        $this->slack->applyEnvOverrides();
        $this->webhook->applyEnvOverrides();
    }
}
