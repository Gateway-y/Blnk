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
 * EAB (External Account Binding) contains information
 * necessary to bind or map an ACME account to some
 * other account known by the CA.
 *
 * External account bindings are "used to associate an
 * ACME account with an existing account in a non-ACME
 * system, such as a CA customer database."
 *
 * "To enable ACME account binding, the CA operating the
 * ACME server needs to provide the ACME client with a
 * MAC key and a key identifier, using some mechanism
 * outside of ACME." §7.3.4
 */
final class EAB implements \JsonSerializable
{
    /** "The key identifier MUST be an ASCII string." §7.3.4 */
    public string $keyID = '';

    /**
     * "The MAC key SHOULD be provided in base64url-encoded
     * form, to maximize compatibility between non-ACME
     * provisioning systems and ACME clients." §7.3.4
     */
    public string $macKey = '';

    public function __construct(string $keyID = '', string $macKey = '')
    {
        $this->keyID = $keyID;
        $this->macKey = $macKey;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['key_id'] ?? ''), (string) ($data['mac_key'] ?? ''));
    }

    /** @return array{key_id: string, mac_key: string} */
    public function jsonSerialize(): array
    {
        return ['key_id' => $this->keyID, 'mac_key' => $this->macKey];
    }
}
