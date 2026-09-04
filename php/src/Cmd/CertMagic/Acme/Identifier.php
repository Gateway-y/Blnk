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

namespace Blnk\Cmd\CertMagic\Acme;

/**
 * Identifier is used in order and authorization (authz) objects.
 */
final class Identifier implements \JsonSerializable
{
    /**
     * type (required, string):  The type of identifier.  This document
     * defines the "dns" identifier type.  See the registry defined in
     * Section 9.7.7 for any others.
     */
    public string $type = '';

    /** value (required, string):  The identifier itself. */
    public string $value = '';

    public function __construct(string $type = '', string $value = '')
    {
        $this->type = $type;
        $this->value = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['type'] ?? ''), (string) ($data['value'] ?? ''));
    }

    /** @return array{type: string, value: string} */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }
}
