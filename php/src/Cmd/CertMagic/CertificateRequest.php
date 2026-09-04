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
 * CertificateRequest is the port's stand-in for Go's `*x509.CertificateRequest`:
 * the subject alternative names the request was created with, and the signed
 * DER (`Raw`) that is sent to the ACME server on finalization.
 *
 * Go builds the CSR with `x509.CreateCertificateRequest` from a template and
 * parses it back; PHP builds it with `openssl_csr_new` driven by a generated
 * OpenSSL configuration carrying the `subjectAltName` (and optional TLS
 * feature / must-staple) request extensions. The SANs are remembered from
 * the template because PHP offers no CSR extension parser.
 */
final class CertificateRequest
{
    /** DER encoding of the signed request (Go: `Raw`). */
    public string $raw = '';

    public string $pem = '';

    /** Subject CommonName (only set for the ZeroSSL CSR variant). */
    public string $commonName = '';

    /** @var string[] */
    public array $dnsNames = [];

    /** @var string[] */
    public array $ipAddresses = [];

    /** @var string[] */
    public array $emailAddresses = [];

    /** @var string[] */
    public array $uris = [];

    /** Whether the OCSP must-staple TLS feature extension was requested. */
    public bool $mustStaple = false;

    /**
     * create is `x509.CreateCertificateRequest` + `x509.ParseCertificateRequest`
     * for a template holding the given subjects.
     *
     * @param string[] $dnsNames
     * @param string[] $ipAddresses
     * @param string[] $emailAddresses
     * @param string[] $uris
     * @throws \RuntimeException
     */
    public static function create(
        \OpenSSLAsymmetricKey $privateKey,
        string $commonName,
        array $dnsNames,
        array $ipAddresses,
        array $emailAddresses,
        array $uris,
        bool $mustStaple = false
    ): self {
        $csr = new self();
        $csr->commonName = $commonName;
        $csr->dnsNames = array_values($dnsNames);
        $csr->ipAddresses = array_values($ipAddresses);
        $csr->emailAddresses = array_values($emailAddresses);
        $csr->uris = array_values($uris);
        $csr->mustStaple = $mustStaple;

        $sans = [];
        foreach ($csr->dnsNames as $n) {
            $sans[] = 'DNS:' . $n;
        }
        foreach ($csr->ipAddresses as $ip) {
            $sans[] = 'IP:' . $ip;
        }
        foreach ($csr->emailAddresses as $e) {
            $sans[] = 'email:' . $e;
        }
        foreach ($csr->uris as $u) {
            $sans[] = 'URI:' . $u;
        }

        $config = "[req]\ndistinguished_name = dn\nreq_extensions = v3_req\n[dn]\n[v3_req]\n";
        if ($sans !== []) {
            $config .= 'subjectAltName = ' . implode(', ', $sans) . "\n";
        }
        if ($mustStaple) {
            // PKIX TLS feature extension 1.3.6.1.5.5.7.1.24 with status_request (5)
            $config .= "tlsfeature = status_request\n";
        }

        $configFile = @tempnam(sys_get_temp_dir(), 'blnk-csr-');
        if ($configFile === false || @file_put_contents($configFile, $config) === false) {
            throw new \RuntimeException('creating CSR: cannot write temporary OpenSSL configuration');
        }
        try {
            $dn = $commonName !== '' ? ['commonName' => $commonName] : [];
            $resource = @openssl_csr_new($dn, $privateKey, [
                'config' => $configFile,
                'req_extensions' => 'v3_req',
                'digest_alg' => 'sha256',
            ]);
            if ($resource === false) {
                throw new \RuntimeException('creating CSR: ' . self::opensslError());
            }
            $pem = '';
            if (!openssl_csr_export($resource, $pem) || !\is_string($pem) || $pem === '') {
                throw new \RuntimeException('encoding CSR: ' . self::opensslError());
            }
        } finally {
            @unlink($configFile);
        }
        $csr->pem = $pem;
        $csr->raw = Crypto::pemToDER($pem);

        return $csr;
    }

    private static function opensslError(): string
    {
        $messages = [];
        while (($msg = openssl_error_string()) !== false) {
            $messages[] = $msg;
        }
        return $messages === [] ? 'unknown OpenSSL error' : implode('; ', $messages);
    }
}
