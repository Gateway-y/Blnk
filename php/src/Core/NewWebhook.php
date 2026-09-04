<?php

/*
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

namespace Blnk\Core;

/**
 * NewWebhook represents the structure of a webhook notification.
 * It includes an event type and associated payload data.
 *
 * Port of the `NewWebhook` struct in webhooks.go (JSON: {"event": ..., "data": ...}).
 */
final class NewWebhook implements \JsonSerializable
{
    /** The event type that triggered the webhook. JSON: "event". */
    public string $event = '';

    /** The data associated with the event. JSON: "data". */
    public mixed $payload = null;

    public function __construct(string $event = '', mixed $payload = null)
    {
        $this->event = $event;
        $this->payload = $payload;
    }

    /**
     * Rebuilds a NewWebhook from its JSON form (Go: json.Unmarshal into NewWebhook).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['event'] ?? ''), $data['data'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'event' => $this->event,
            'data' => $this->payload,
        ];
    }
}
