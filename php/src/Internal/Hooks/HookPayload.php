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
 * HookPayload represents the data sent to webhook endpoints.
 *
 * Port of the Go `HookPayload` struct (internal/hooks/types.go). Go's
 * `Data json.RawMessage` (pre-marshalled JSON) is represented here as the
 * decoded value; it re-encodes to the same JSON.
 */
final class HookPayload implements \JsonSerializable
{
    /** JSON: "transaction_id". */
    public string $transactionId = '';

    /** JSON: "hook_type". */
    public string $hookType = '';

    /** JSON: "timestamp". */
    public ?\DateTimeImmutable $timestamp = null;

    /** JSON: "data", omitempty (omitted when null to handle nil data). */
    public mixed $data = null;

    public function __construct(string $transactionId = '', string $hookType = '', ?\DateTimeImmutable $timestamp = null, mixed $data = null)
    {
        $this->transactionId = $transactionId;
        $this->hookType = $hookType;
        $this->timestamp = $timestamp;
        $this->data = $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $timestamp = null;
        if (is_string($data['timestamp'] ?? null) && $data['timestamp'] !== '') {
            try {
                $timestamp = new \DateTimeImmutable($data['timestamp']);
            } catch (\Exception) {
                $timestamp = null;
            }
        }

        return new self(
            (string) ($data['transaction_id'] ?? ''),
            (string) ($data['hook_type'] ?? ''),
            $timestamp,
            $data['data'] ?? null
        );
    }

    public function jsonSerialize(): array
    {
        $out = [
            'transaction_id' => $this->transactionId,
            'hook_type' => $this->hookType,
            // time.Time marshals as RFC3339Nano ("Z" for UTC); the zero value as 0001-01-01T00:00:00Z.
            'timestamp' => \Blnk\Model\ModelHelpers::goTimeString($this->timestamp),
        ];
        if ($this->data !== null) {
            $out['data'] = $this->data;
        }

        return $out;
    }
}
