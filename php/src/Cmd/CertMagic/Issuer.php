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
 * Issuer is a type that can issue certificates.
 * (certmagic certmagic.go `Issuer`.)
 */
interface Issuer
{
    /**
     * Issue obtains a certificate for the given CSR. It
     * must honor context cancellation if it is long-running.
     * It can also use the context to find out if the current
     * call is part of a retry, via AttemptsCtxKey — the port
     * hands those context values over as {@see IssueContext}.
     *
     * @throws \RuntimeException
     */
    public function issue(CertificateRequest $request, IssueContext $ctx): IssuedCertificate;

    /**
     * IssuerKey must return a string that uniquely identifies
     * this particular configuration of the Issuer such that
     * any certificates obtained by this Issuer will be treated
     * as identical if they have the same SANs.
     *
     * Certificates obtained from Issuers with the same IssuerKey
     * will overwrite others with the same SANs. For example, an
     * Issuer might be able to obtain certificates from different
     * CAs, say A and B. It is likely that the CAs have different
     * use cases and purposes (e.g. testing and production), so
     * their respective certificates should not overwrite eaach
     * other.
     */
    public function issuerKey(): string;
}
