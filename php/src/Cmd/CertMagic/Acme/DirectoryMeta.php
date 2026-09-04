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
 * DirectoryMeta is optional extra data that may be
 * included in an ACME server directory. §7.1.1
 */
final class DirectoryMeta
{
    public string $termsOfService = '';
    public string $website = '';

    /** @var string[] */
    public array $caaIdentities = [];

    public bool $externalAccountRequired = false;

    /**
     * ACME profiles are an EXPERIMENTAL DRAFT feature and are subject to change. See:
     * - https://letsencrypt.org/2025/01/09/acme-profiles/
     * - https://datatracker.ietf.org/doc/draft-aaron-acme-profiles/
     * The key is the profile name, and the value is a description.
     *
     * @var array<string, string>
     */
    public array $profiles = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $m = new self();
        $m->termsOfService = (string) ($data['termsOfService'] ?? '');
        $m->website = (string) ($data['website'] ?? '');
        $m->caaIdentities = array_map('strval', (array) ($data['caaIdentities'] ?? []));
        $m->externalAccountRequired = (bool) ($data['externalAccountRequired'] ?? false);
        foreach ((array) ($data['profiles'] ?? []) as $name => $description) {
            $m->profiles[(string) $name] = (string) $description;
        }
        return $m;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [];
        if ($this->termsOfService !== '') {
            $out['termsOfService'] = $this->termsOfService;
        }
        if ($this->website !== '') {
            $out['website'] = $this->website;
        }
        if ($this->caaIdentities !== []) {
            $out['caaIdentities'] = $this->caaIdentities;
        }
        if ($this->externalAccountRequired) {
            $out['externalAccountRequired'] = true;
        }
        if ($this->profiles !== []) {
            $out['profiles'] = $this->profiles;
        }
        return $out;
    }
}
