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
 * UpdateBalanceIdentity represents the payload required to update a balance's identity.
 * Only the identity_id field is accepted.
 * (Go: api/model/balance.go `UpdateBalanceIdentity`, `IdentityId string json:"identity_id" binding:"required"`.)
 */
final class UpdateBalanceIdentity implements \JsonSerializable
{
    public string $identityId = '';

    /**
     * Binds the JSON body; the Gin `binding:"required"` tag rejects a missing,
     * null or empty identity_id.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch or a missing identity_id
     */
    public static function fromArray(array $data, string $struct = 'UpdateBalanceIdentity'): self
    {
        $r = new self();
        $r->identityId = JsonBinding::string($data, 'identity_id', $struct);
        if ($r->identityId === '') {
            throw JsonBinding::requiredError($struct, 'IdentityId');
        }

        return $r;
    }

    public function jsonSerialize(): array
    {
        return ['identity_id' => $this->identityId];
    }
}
