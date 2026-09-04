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
 * Port of Go `config.AccountGenerationHttpService`.
 *
 * The anonymous Go `Headers struct { Authorization string }` becomes
 * {@see AccountGenerationHttpServiceHeaders}.
 */
final class AccountGenerationHttpService
{
    /** JSON: "url" */
    public string $url = '';

    /** JSON: "timeout" */
    public int $timeout = 0;

    /** JSON: "headers" */
    public AccountGenerationHttpServiceHeaders $headers;

    public function __construct()
    {
        $this->headers = new AccountGenerationHttpServiceHeaders();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->url = (string) ($data['url'] ?? '');
        $c->timeout = (int) ($data['timeout'] ?? 0);
        $headers = $data['headers'] ?? [];
        if (is_array($headers)) {
            $c->headers = AccountGenerationHttpServiceHeaders::fromArray($headers);
        }
        return $c;
    }
}
