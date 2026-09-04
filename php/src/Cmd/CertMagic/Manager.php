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
 * Manager is a type that manages certificates (keeps them renewed) such
 * that we can get certificates during TLS handshakes to immediately serve
 * to clients.
 *
 * TODO: This is an EXPERIMENTAL API. It is subject to change/removal.
 */
interface Manager
{
    /**
     * GetCertificate returns the certificate to use to complete the handshake.
     * Since this is called during every TLS handshake, it must be very fast and not block.
     * Returning any non-null value indicates that this Manager manages a certificate
     * for the described handshake. Returning null is valid and is simply treated as
     * a no-op: return null when the Manager has no certificate for this handshake.
     * Throw or return a certificate only if the Manager is supposed to get a certificate
     * for this handshake. Returning null lets other Managers or Issuers try to get
     * a certificate for the handshake.
     *
     * @throws \RuntimeException
     */
    public function getCertificate(ClientHelloInfo $hello): ?Certificate;
}
