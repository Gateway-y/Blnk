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

namespace Blnk\Cmd\CertMagic\Acmez;

use Blnk\Cmd\CertMagic\Acme\Account;
use Blnk\Cmd\CertMagic\Acme\Identifier;
use Blnk\Cmd\CertMagic\CertificateRequest;
use Blnk\Cmd\CertMagic\X509Certificate;

/**
 * OrderParameters contains high-level input parameters for ACME transactions,
 * the state of which are represented by Order objects. This type is used as a
 * convenient high-level way to convey all the configuration needed to obtain a
 * certificate (except the private key, which is provided separately to prevent
 * inadvertent exposure of secret material) through ACME in one consolidated value.
 *
 * Account, Identifiers, and CSR fields are REQUIRED.
 * (acmez csr.go)
 */
final class OrderParameters
{
    /**
     * The ACME account with which to perform certificate operations.
     * It should already be registered with the server and have a
     * "valid" status.
     */
    public Account $account;

    /**
     * The name of the ACME profile to use for the order.
     * The list of profiles offered by the ACME server is
     * available at its directory endpoint.
     * EXPERIMENTAL: Subject to change.
     * (https://datatracker.ietf.org/doc/draft-aaron-acme-profiles/)
     */
    public string $profile = '';

    /**
     * The list of identifiers for which to issue the certificate.
     * Identifiers may become Subject Alternate Names (SANs) in the
     * certificate. This slice must be consistent with the SANs
     * listed in the CSR. The orderParametersFromCSR() function can be
     * called to ensure consistency in most cases.
     *
     * Supported identifier types are currently: dns, ip, email.
     *
     * @var Identifier[]
     */
    public array $identifiers = [];

    /**
     * CSR provides the Certificate Signing Request, which is needed when
     * finalizing the ACME order. (Go: a CSRSource; the port uses the static
     * source, `StaticCSR`.)
     */
    public ?CertificateRequest $csr = null;

    /**
     * Optionally customize the lifetime of the certificate by
     * specifying the NotBefore and/or NotAfter dates for the
     * certificate. Not all CAs support this. Check your CA's
     * ACME service documentation.
     */
    public ?\DateTimeImmutable $notBefore = null;

    public ?\DateTimeImmutable $notAfter = null;

    /**
     * Set this to the old certificate if a certificate is being renewed.
     *
     * DRAFT: EXPERIMENTAL ARI DRAFT SPEC. Subject to change/removal.
     */
    public ?X509Certificate $replaces = null;

    public function __construct(Account $account)
    {
        $this->account = $account;
    }

    /**
     * OrderParametersFromCSR makes a valid OrderParameters from the given CSR.
     * If necessary, the returned parameters may be further customized before using.
     *
     * EXPERIMENTAL: This API is subject to change or removal without a major version bump.
     *
     * @throws \RuntimeException
     */
    public static function orderParametersFromCSR(Account $account, CertificateRequest $csr): self
    {
        $ids = self::createIdentifiersUsingCSR($csr);
        if ($ids === []) {
            throw new \RuntimeException('no subjects found in CSR');
        }
        $params = new self($account);
        $params->identifiers = $ids;
        $params->csr = $csr;
        return $params;
    }

    /**
     * createIdentifiersUsingCSR extracts the list of ACME identifiers from the
     * given Certificate Signing Request.
     *
     * @return Identifier[]
     */
    public static function createIdentifiersUsingCSR(CertificateRequest $csr): array
    {
        $ids = [];
        foreach ($csr->dnsNames as $name) {
            $ids[] = new Identifier('dns', $name); // RFC 8555 §9.7.7
        }
        foreach ($csr->ipAddresses as $ip) {
            $ids[] = new Identifier('ip', $ip); // RFC 8738
        }
        foreach ($csr->emailAddresses as $email) {
            $ids[] = new Identifier('email', $email); // RFC 8823
        }
        // (TNAuthList, permanent-identifier and hardware-module identifiers live
        // in CSR extensions the port does not create.)
        return $ids;
    }
}
