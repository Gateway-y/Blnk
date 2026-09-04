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
 * AcmeCertificate (acmez `acme.Certificate`) represents a certificate chain,
 * which we usually refer to as "a certificate" because in practice an
 * end-entity certificate is seldom useful/practical without a chain. This
 * structure can be JSON-encoded and stored alongside the certificate chain
 * to preserve potentially-useful metadata.
 */
final class AcmeCertificate implements \JsonSerializable
{
    /**
     * The certificate resource URL as provisioned by
     * the ACME server. Some ACME servers may split
     * the chain into multiple URLs that are Linked
     * together, in which case this URL represents the
     * starting point.
     */
    public string $url = '';

    /**
     * The PEM-encoded certificate chain, end-entity first.
     * It is excluded from JSON marshalling since the
     * chain is usually stored in its own file.
     */
    public string $chainPEM = '';

    /**
     * For convenience, the directory URL of the ACME CA that
     * issued this certificate. This field is not part of the
     * ACME spec, but it can be useful to save this along with
     * the certificate for restoring a lost ACME client config.
     */
    public string $ca = '';

    /**
     * The location of the account that obtained the certificate.
     * This field is not part of the ACME spec, but it can be
     * useful for management; for example, ARI recommends that
     * servers enforce that the same account be used to indicate
     * a replacement as was used to obtain the original cert.
     * This field is set even when ARI is not enabled, for
     * reference/troubleshooting purposes.
     */
    public string $account = '';

    /**
     * When to renew the certificate, and related info, as
     * prescribed by ARI.
     */
    public ?RenewalInfo $renewalInfo = null;

    /**
     * @param array<string, mixed> $data
     * @throws \RuntimeException
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->url = (string) ($data['url'] ?? '');
        $c->ca = (string) ($data['ca'] ?? '');
        $c->account = (string) ($data['account'] ?? '');
        if (isset($data['renewal_info']) && \is_array($data['renewal_info'])) {
            $c->renewalInfo = RenewalInfo::fromArray($data['renewal_info']);
        }
        return $c;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = ['url' => $this->url];
        if ($this->ca !== '') {
            $out['ca'] = $this->ca;
        }
        if ($this->account !== '') {
            $out['account'] = $this->account;
        }
        if ($this->renewalInfo !== null) {
            $out['renewal_info'] = $this->renewalInfo->jsonSerialize();
        }
        return $out;
    }
}
