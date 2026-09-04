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

use Blnk\Cmd\CertMagic\Acmez\TlsAlpn01;

/**
 * TlsConfig is the port's `*tls.Config` as returned by {@see Config::tlsConfig()}:
 * the certificate getter, the ALPN protocols, and the modern defaults
 * (TLS >= 1.2, X25519/P-256, the preferred cipher suites, server cipher
 * order). {@see sslContextOptions()} translates it into the `ssl` stream
 * context options of PHP's TLS server streams.
 */
final class TlsConfig
{
    /**
     * GetCertificate returns the certificate to use for the ClientHello's
     * server name (Go: `tls.Config.GetCertificate`).
     *
     * @var callable(string): ?Certificate
     */
    public $getCertificate;

    /**
     * PHP-only: lists every certificate the TLS stack should be able to
     * serve. PHP selects server certificates from a static SNI table on the
     * listening socket rather than through a per-handshake callback, so the
     * HTTPS server installs these (and refreshes them after renewals).
     *
     * @var callable(): Certificate[]
     */
    public $certificates;

    /**
     * NextProtos is pre-populated with a special value to enable
     * solving the TLS-ALPN ACME challenge; servers prepend their
     * application protocols (e.g. "http/1.1").
     *
     * @var string[]
     */
    public array $nextProtos = [TlsAlpn01::ACMETLS1Protocol];

    /** MinVersion TLS 1.2: the crypto methods offered by the server. */
    public int $cryptoMethod = \STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | \STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;

    /**
     * CurvePreferences: X25519 then P-256 (informational — OpenSSL's default
     * group list already prefers X25519, and PHP's `ecdh_curve` option takes
     * a single named EC curve, so the default list is left in place).
     *
     * @var string[]
     */
    public array $curvePreferences = ['X25519', 'prime256v1'];

    /**
     * CipherSuites (OpenSSL names) — `preferredDefaultCipherSuites()`.
     *
     * @var string[]
     */
    public array $cipherSuites = [];

    public bool $preferServerCipherSuites = true;

    /**
     * @param callable(string): ?Certificate $getCertificate
     * @param callable(): Certificate[] $certificates
     */
    public function __construct(callable $getCertificate, callable $certificates)
    {
        $this->getCertificate = $getCertificate;
        $this->certificates = $certificates;
        $this->cipherSuites = self::preferredDefaultCipherSuites();
    }

    /**
     * sslContextOptions returns the `ssl` stream context options for a
     * server socket (certificates excluded — the server installs them).
     *
     * @return array<string, mixed>
     */
    public function sslContextOptions(): array
    {
        return [
            'crypto_method' => $this->cryptoMethod,
            'ciphers' => implode(':', $this->cipherSuites),
            'honor_cipher_order' => $this->preferServerCipherSuites,
            'alpn_protocols' => implode(',', $this->nextProtos),
            'verify_peer' => false,
            'verify_peer_name' => false,
        ];
    }

    /**
     * preferredDefaultCipherSuites returns an appropriate
     * cipher suite to use depending on hardware support
     * for AES-NI.
     *
     * See https://github.com/mholt/caddy/issues/1674
     *
     * @return string[]
     */
    public static function preferredDefaultCipherSuites(): array
    {
        if (self::cpuSupportsAESNI()) {
            return self::defaultCiphersPreferAES();
        }
        return self::defaultCiphersPreferChaCha();
    }

    /** @return string[] */
    public static function defaultCiphersPreferAES(): array
    {
        return [
            'ECDHE-ECDSA-AES256-GCM-SHA384', // TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384
            'ECDHE-RSA-AES256-GCM-SHA384', // TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384
            'ECDHE-ECDSA-AES128-GCM-SHA256', // TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256
            'ECDHE-RSA-AES128-GCM-SHA256', // TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256
            'ECDHE-ECDSA-CHACHA20-POLY1305', // TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305
            'ECDHE-RSA-CHACHA20-POLY1305', // TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305
        ];
    }

    /** @return string[] */
    public static function defaultCiphersPreferChaCha(): array
    {
        return [
            'ECDHE-ECDSA-CHACHA20-POLY1305',
            'ECDHE-RSA-CHACHA20-POLY1305',
            'ECDHE-ECDSA-AES256-GCM-SHA384',
            'ECDHE-RSA-AES256-GCM-SHA384',
            'ECDHE-ECDSA-AES128-GCM-SHA256',
            'ECDHE-RSA-AES128-GCM-SHA256',
        ];
    }

    /** cpuSupportsAESNI reports whether the CPU advertises AES instructions (cpuid.CPU.Supports(cpuid.AESNI)). */
    private static function cpuSupportsAESNI(): bool
    {
        $info = @file_get_contents('/proc/cpuinfo');
        if (!\is_string($info)) {
            return true; // assume modern hardware
        }
        return (bool) preg_match('/^(?:flags|Features)\s*:.*\b(?:aes|aesni)\b/mi', $info);
    }
}
