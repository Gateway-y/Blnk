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
 * Directory acts as an index for the ACME server as
 * specified in the spec: "In order to help clients
 * configure themselves with the right URLs for each
 * ACME operation, ACME servers provide a directory
 * object." §7.1.1
 */
final class Directory
{
    public string $newNonce = '';
    public string $newAccount = '';
    public string $newOrder = '';
    public string $newAuthz = '';
    public string $revokeCert = '';
    public string $keyChange = '';

    /** draft-ietf-acme-ari */
    public string $renewalInfo = '';

    public ?DirectoryMeta $meta = null;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $d = new self();
        $d->newNonce = (string) ($data['newNonce'] ?? '');
        $d->newAccount = (string) ($data['newAccount'] ?? '');
        $d->newOrder = (string) ($data['newOrder'] ?? '');
        $d->newAuthz = (string) ($data['newAuthz'] ?? '');
        $d->revokeCert = (string) ($data['revokeCert'] ?? '');
        $d->keyChange = (string) ($data['keyChange'] ?? '');
        $d->renewalInfo = (string) ($data['renewalInfo'] ?? '');
        if (isset($data['meta']) && \is_array($data['meta'])) {
            $d->meta = DirectoryMeta::fromArray($data['meta']);
        }
        return $d;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'newNonce' => $this->newNonce,
            'newAccount' => $this->newAccount,
            'newOrder' => $this->newOrder,
        ];
        if ($this->newAuthz !== '') {
            $out['newAuthz'] = $this->newAuthz;
        }
        $out['revokeCert'] = $this->revokeCert;
        $out['keyChange'] = $this->keyChange;
        if ($this->renewalInfo !== '') {
            $out['renewalInfo'] = $this->renewalInfo;
        }
        if ($this->meta !== null) {
            $out['meta'] = $this->meta->toArray();
        }
        return $out;
    }
}
