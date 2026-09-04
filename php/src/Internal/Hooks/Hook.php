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

namespace Blnk\Internal\Hooks;

/**
 * Hook represents a webhook configuration.
 *
 * Port of the Go `Hook` struct (internal/hooks/types.go); JSON field names and
 * shapes match the Go `json:"..."` tags exactly.
 */
final class Hook implements \JsonSerializable
{
    /** Go's zero time.Time, as it marshals to JSON. */
    private const ZERO_TIME = '0001-01-01T00:00:00Z';

    /** Unique identifier for the hook (JSON: "id"). */
    public string $id = '';

    /** Friendly name for the hook (JSON: "name"). */
    public string $name = '';

    /** Webhook endpoint URL (JSON: "url"). */
    public string $url = '';

    /** Type of hook (pre or post transaction) (JSON: "type"). */
    public string $type = '';

    /** Whether the hook is currently active (JSON: "active"). */
    public bool $active = false;

    /** Timeout in seconds for the webhook call (JSON: "timeout"). */
    public int $timeout = 0;

    /** Number of retries on failure (JSON: "retry_count"). */
    public int $retryCount = 0;

    /** Creation timestamp (JSON: "created_at"). */
    public ?\DateTimeImmutable $createdAt = null;

    /** Last execution timestamp (JSON: "last_run"). */
    public ?\DateTimeImmutable $lastRun = null;

    /** Status of last execution (JSON: "last_success"). */
    public bool $lastSuccess = false;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $hook = new self();
        $hook->id = (string) ($data['id'] ?? '');
        $hook->name = (string) ($data['name'] ?? '');
        $hook->url = (string) ($data['url'] ?? '');
        $hook->type = (string) ($data['type'] ?? '');
        $hook->active = (bool) ($data['active'] ?? false);
        $hook->timeout = (int) ($data['timeout'] ?? 0);
        $hook->retryCount = (int) ($data['retry_count'] ?? 0);
        $hook->createdAt = self::parseTime($data['created_at'] ?? null);
        $hook->lastRun = self::parseTime($data['last_run'] ?? null);
        $hook->lastSuccess = (bool) ($data['last_success'] ?? false);

        return $hook;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'type' => $this->type,
            'active' => $this->active,
            'timeout' => $this->timeout,
            'retry_count' => $this->retryCount,
            'created_at' => self::formatTime($this->createdAt),
            'last_run' => self::formatTime($this->lastRun),
            'last_success' => $this->lastSuccess,
        ];
    }

    private static function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || $value === self::ZERO_TIME) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * time.Time marshals as RFC3339 (with nanoseconds when present); the zero
     * value marshals as 0001-01-01T00:00:00Z. Unset (null) timestamps emit the
     * Go zero-time string to keep the JSON shape identical.
     */
    private static function formatTime(?\DateTimeImmutable $t): string
    {
        if ($t === null) {
            return self::ZERO_TIME;
        }

        return $t->format(\DateTimeInterface::RFC3339);
    }
}
