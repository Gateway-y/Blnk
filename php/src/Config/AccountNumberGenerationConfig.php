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
 * Port of Go `config.AccountNumberGenerationConfig`.
 */
final class AccountNumberGenerationConfig
{
    /** JSON: "enable_auto_generation" */
    public bool $enableAutoGeneration = false;

    /** JSON: "http_service" */
    public AccountGenerationHttpService $httpService;

    public function __construct()
    {
        $this->httpService = new AccountGenerationHttpService();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->enableAutoGeneration = (bool) ($data['enable_auto_generation'] ?? false);
        $httpService = $data['http_service'] ?? [];
        if (is_array($httpService)) {
            $c->httpService = AccountGenerationHttpService::fromArray($httpService);
        }
        return $c;
    }
}
