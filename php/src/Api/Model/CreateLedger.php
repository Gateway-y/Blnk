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

use Blnk\Model\Ledger;
use Blnk\Model\ModelHelpers as CoreModelHelpers;

/**
 * CreateLedger is the request payload of POST /ledgers
 * (Go: api/model/ledger.go `CreateLedger`).
 */
final class CreateLedger implements \JsonSerializable
{
    public string $name = '';

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    /**
     * Binds a decoded JSON object with encoding/json's semantics (see
     * {@see JsonBinding}); $struct is the Go struct path used in type
     * mismatch messages.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'CreateLedger'): self
    {
        $l = new self();
        $l->name = JsonBinding::string($data, 'name', $struct);
        $l->metaData = JsonBinding::map($data, 'meta_data', $struct);

        return $l;
    }

    /**
     * ValidateCreateLedger runs the ozzo-validation rules of model.go:
     * `name` is required.
     *
     * @throws ValidationErrors
     */
    public function validateCreateLedger(): void
    {
        ModelHelpers::validateStruct([
            'name' => function (): void {
                ModelHelpers::required($this->name);
            },
        ]);
    }

    /**
     * ToLedger converts the request payload into the domain Ledger.
     */
    public function toLedger(): Ledger
    {
        $ledger = new Ledger();
        $ledger->name = $this->name;
        $ledger->metaData = $this->metaData;

        return $ledger;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'meta_data' => CoreModelHelpers::mapToJson($this->metaData),
        ];
    }
}
