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

namespace Blnk\Api\Model;

/**
 * UpdateLedger is the request payload of PUT /ledgers/:id
 * (Go: api/model/ledger.go `UpdateLedger`).
 */
final class UpdateLedger implements \JsonSerializable
{
    public string $name = '';

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'UpdateLedger'): self
    {
        $l = new self();
        $l->name = JsonBinding::string($data, 'name', $struct);

        return $l;
    }

    /**
     * ValidateUpdateLedger runs the ozzo-validation rules of model.go:
     * `name` is required.
     *
     * @throws ValidationErrors
     */
    public function validateUpdateLedger(): void
    {
        ModelHelpers::validateStruct([
            'name' => function (): void {
                ModelHelpers::required($this->name);
            },
        ]);
    }

    public function jsonSerialize(): array
    {
        return ['name' => $this->name];
    }
}
