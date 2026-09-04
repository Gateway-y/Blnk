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

namespace Blnk\Cmd\CertMagic\Solvers;

use Blnk\Cmd\CertMagic\Acme\Challenge;

/**
 * ActiveChallenge is an ACME challenge, but optionally paired with
 * data that can make it easier or more efficient to solve
 * (certmagic solvers.go `Challenge`: `acme.Challenge` + `data`).
 */
final class ActiveChallenge
{
    public Challenge $challenge;

    /** For the TLS-ALPN challenge: the pre-generated challenge certificate [certPEM, keyPEM]. */
    public mixed $data = null;

    public function __construct(Challenge $challenge, mixed $data = null)
    {
        $this->challenge = $challenge;
        $this->data = $data;
    }
}
