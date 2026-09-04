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

use Blnk\Cmd\CertMagic\Acme\AcmeCertificate;
use Blnk\Cmd\CertMagic\Acme\RenewalInfo;

/**
 * CertificateResource associates a certificate with its private
 * key and other useful information, for use in maintaining the
 * certificate. Its JSON form is the `<domain>.json` sidecar file
 * in storage: {"sans": [...], "issuer_data": {...}}.
 */
final class CertificateResource implements \JsonSerializable
{
    /**
     * The list of names on the certificate;
     * for convenience only.
     *
     * @var string[]
     */
    public array $sans = [];

    /**
     * The PEM-encoding of DER-encoded ASN.1 data
     * for the cert or chain.
     */
    public string $certificatePEM = '';

    /** The PEM-encoding of the certificate's private key. */
    public string $privateKeyPEM = '';

    /**
     * Any extra information associated with the certificate,
     * usually provided by the issuer implementation.
     * (Go: json.RawMessage; decoded here.)
     *
     * @var array<string, mixed>|null
     */
    public ?array $issuerData = null;

    /**
     * The unique string identifying the issuer of the
     * certificate; internally useful for storage access.
     */
    public string $issuerKey = '';

    /**
     * NamesKey returns the list of SANs as a single string,
     * truncated to some ridiculously long size limit. It
     * can act as a key for the set of names on the resource.
     */
    public function namesKey(): string
    {
        sort($this->sans, \SORT_STRING);
        $result = implode(',', $this->sans);
        if (\strlen($result) > 1024) {
            $trunc = '_trunc';
            $result = substr($result, 0, 1024 - \strlen($trunc)) . $trunc;
        }
        return $result;
    }

    /**
     * getARI unpacks ACME Renewal Information from the issuer data, if available.
     * It is only an error if there is invalid JSON.
     *
     * @throws \RuntimeException
     */
    public function getARI(): ?RenewalInfo
    {
        return $this->getACMEData()->renewalInfo;
    }

    /**
     * getACMEData returns the ACME certificate metadata from the IssuerData, but
     * note that a non-ACME-issued certificate may return an empty value
     * since the JSON may still decode successfully but just not match any or all
     * of the fields. Remember that the IssuerKey is used to store and access the
     * cert files in the first place (it is part of the path) so in theory if you
     * load a CertificateResource from an ACME issuer it should work as expected.
     *
     * @throws \RuntimeException
     */
    public function getACMEData(): AcmeCertificate
    {
        if ($this->issuerData === null || $this->issuerData === []) {
            return new AcmeCertificate();
        }
        return AcmeCertificate::fromArray($this->issuerData);
    }

    /**
     * applyArray decodes the metadata sidecar file into this resource
     * (json.Unmarshal(metaBytes, &certRes) keeps the PEM fields).
     *
     * @param array<string, mixed> $data
     */
    public function applyArray(array $data): void
    {
        if (\array_key_exists('sans', $data)) {
            $this->sans = array_map('strval', (array) $data['sans']);
        }
        if (\array_key_exists('issuer_data', $data)) {
            $this->issuerData = \is_array($data['issuer_data']) ? $data['issuer_data'] : null;
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = [];
        if ($this->sans !== []) {
            $out['sans'] = $this->sans;
        }
        if ($this->issuerData !== null) {
            $out['issuer_data'] = $this->issuerData === [] ? new \stdClass() : $this->issuerData;
        }
        return $out;
    }
}
