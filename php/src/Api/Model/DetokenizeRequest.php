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
 * DetokenizeRequest is the body of POST /identities/:id/detokenize
 * (Go: api/model/identity.go `DetokenizeRequest`, `Fields []string json:"fields" binding:"required"`).
 */
final class DetokenizeRequest implements \JsonSerializable
{
    /** @var string[] */
    public array $fields = [];

    /**
     * Binds the JSON body; the Gin `binding:"required"` tag rejects a missing
     * or null `fields` (an empty list satisfies it, exactly as in Go).
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch or a missing `fields`
     */
    public static function fromArray(array $data, string $struct = 'DetokenizeRequest'): self
    {
        $r = new self();
        $fields = JsonBinding::stringList($data, 'fields', $struct);
        if ($fields === null) {
            throw JsonBinding::requiredError($struct, 'Fields');
        }
        $r->fields = $fields;
        return $r;
    }

    public function jsonSerialize(): array
    {
        return ['fields' => array_values($this->fields)];
    }
}
