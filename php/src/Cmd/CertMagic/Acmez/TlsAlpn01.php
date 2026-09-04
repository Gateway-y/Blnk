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

use Blnk\Cmd\CertMagic\Acme\Challenge;
use Blnk\Cmd\CertMagic\Crypto;

/**
 * TlsAlpn01 is the port of acmez tlsalpn01.go: the self-signed certificate
 * presented during the tls-alpn-01 challenge handshake (RFC 8737 §3).
 */
final class TlsAlpn01
{
    /**
     * ACMETLS1Protocol is the ALPN value for the TLS-ALPN challenge
     * handshake. See RFC 8737 §6.2.
     */
    public const ACMETLS1Protocol = 'acme-tls/1';

    /**
     * idPEACMEIdentifierV1 is the SMI Security for PKIX Certification Extension OID referencing the ACME extension.
     * See RFC 8737 §6.1. https://www.rfc-editor.org/rfc/rfc8737.html#section-6.1
     */
    public const idPEACMEIdentifierV1 = '1.3.6.1.5.5.7.1.31';

    private function __construct()
    {
    }

    /**
     * TLSALPN01ChallengeCert creates a certificate that can be used for
     * handshakes while solving the tls-alpn-01 challenge. See RFC 8737 §3.
     * Returns the PEM-encoded certificate and private key.
     *
     * @return array{0: string, 1: string}
     * @throws \RuntimeException
     */
    public static function tlsALPN01ChallengeCert(Challenge $challenge): array
    {
        $keyAuthSum = hash('sha256', $challenge->keyAuthorization, true);
        // asn1.Marshal of a 32-byte OCTET STRING: 04 20 <digest>
        $keyAuthSumASN1 = "\x04\x20" . $keyAuthSum;

        $certKey = Crypto::generateKey(Crypto::P256);

        $serialNumber = random_int(1, \PHP_INT_MAX);

        $config = "[req]\ndistinguished_name = dn\n[dn]\n[v3_challenge]\n"
            . 'subjectAltName = DNS:' . $challenge->identifier->value . "\n"
            . "basicConstraints = CA:FALSE\n"
            . "extendedKeyUsage = serverAuth\n"
            // add key authentication digest as the acmeValidation-v1 extension
            // (marked as critical such that it won't be used by non-ACME software).
            // Reference: https://www.rfc-editor.org/rfc/rfc8737.html#section-3
            . self::idPEACMEIdentifierV1 . ' = critical,DER:' . implode(':', str_split(bin2hex($keyAuthSumASN1), 2)) . "\n";

        $configFile = @tempnam(sys_get_temp_dir(), 'blnk-alpn-');
        if ($configFile === false || @file_put_contents($configFile, $config) === false) {
            throw new \RuntimeException('creating TLS-ALPN challenge certificate: cannot write temporary OpenSSL configuration');
        }
        try {
            $csr = @openssl_csr_new(['commonName' => 'ACME challenge'], $certKey, ['config' => $configFile, 'digest_alg' => 'sha256']);
            if ($csr === false) {
                throw new \RuntimeException('creating TLS-ALPN challenge CSR: ' . Crypto::opensslError());
            }
            $cert = @openssl_csr_sign($csr, null, $certKey, 365, [
                'config' => $configFile,
                'x509_extensions' => 'v3_challenge',
                'digest_alg' => 'sha256',
            ], $serialNumber);
            if ($cert === false) {
                throw new \RuntimeException('creating TLS-ALPN challenge certificate: ' . Crypto::opensslError());
            }
        } finally {
            @unlink($configFile);
        }

        $challengeCertPEM = '';
        if (!openssl_x509_export($cert, $challengeCertPEM)) {
            throw new \RuntimeException('encoding TLS-ALPN challenge certificate: ' . Crypto::opensslError());
        }
        $challengeKeyPEM = Crypto::pemEncodePrivateKey($certKey);

        return [$challengeCertPEM, $challengeKeyPEM];
    }
}
