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

namespace Blnk\Cmd\CertMagic;

/**
 * IssuedCertificate represents a certificate that was just issued.
 */
final class IssuedCertificate
{
    /** The PEM-encoding of DER-encoded ASN.1 data. */
    public string $certificate = '';

    /**
     * Any extra information to serialize alongside the
     * certificate in storage. It MUST be serializable
     * as JSON in order to be preserved.
     */
    public mixed $metadata = null;

    public function __construct(string $certificate = '', mixed $metadata = null)
    {
        $this->certificate = $certificate;
        $this->metadata = $metadata;
    }
}
