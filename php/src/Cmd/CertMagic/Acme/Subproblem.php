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
 * Subproblem describes a more specific error in a problem according to
 * RFC 8555 §6.7.1: "An ACME problem document MAY contain the
 * 'subproblems' field, containing a JSON array of problem documents,
 * each of which MAY contain an 'identifier' field."
 */
final class Subproblem extends Problem
{
    /** "If present, the 'identifier' field MUST contain an ACME identifier (Section 9.7.7)." §6.7.1 */
    public Identifier $identifier;

    /**
     * @param Subproblem[] $subproblems
     */
    public function __construct(string $type = '', string $title = '', int $status = 0, string $detail = '', string $instance = '', array $subproblems = [], ?Identifier $identifier = null)
    {
        $this->identifier = $identifier ?? new Identifier();
        parent::__construct($type, $title, $status, $detail, $instance, $subproblems);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        $sub = parent::fromArray($data);
        if (isset($data['identifier']) && \is_array($data['identifier'])) {
            $sub->identifier = Identifier::fromArray($data['identifier']);
        }
        return $sub;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = parent::toArray();
        if ($this->identifier->type !== '' || $this->identifier->value !== '') {
            $out['identifier'] = $this->identifier->jsonSerialize();
        }
        return $out;
    }
}
