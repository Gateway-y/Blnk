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
 * HookResponse represents the expected response from webhook endpoints.
 *
 * Port of the Go `HookResponse` struct (internal/hooks/types.go).
 */
final class HookResponse implements \JsonSerializable
{
    /** JSON: "success". */
    public bool $success = false;

    /** JSON: "message". */
    public string $message = '';

    /** JSON: "data", omitempty. */
    public mixed $data = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $resp = new self();
        $resp->success = (bool) ($data['success'] ?? false);
        $resp->message = (string) ($data['message'] ?? '');
        $resp->data = $data['data'] ?? null;

        return $resp;
    }

    public function jsonSerialize(): array
    {
        $out = [
            'success' => $this->success,
            'message' => $this->message,
        ];
        if ($this->data !== null) {
            $out['data'] = $this->data;
        }

        return $out;
    }
}
