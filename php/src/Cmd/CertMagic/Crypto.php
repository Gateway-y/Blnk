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
 * Crypto is the port of certmagic crypto.go: private key generation
 * (`StandardKeyGenerator`), PEM encoding/decoding of keys, PEM bundle
 * parsing, the fast (FNV) hash and the certificate chain hash, and the
 * CSR helpers.
 */
final class Crypto
{
    /** Constants for all key types we support. */
    public const ED25519 = 'ed25519';
    public const P256 = 'p256';
    public const P384 = 'p384';
    public const RSA2048 = 'rsa2048';
    public const RSA4096 = 'rsa4096';
    public const RSA8192 = 'rsa8192';

    /** DefaultKeyGenerator is the default key source (StandardKeyGenerator{KeyType: P256}). */
    public const DefaultKeyType = self::P256;

    private function __construct()
    {
    }

    /**
     * GenerateKey generates a new private key according to keyType
     * (certmagic `StandardKeyGenerator.GenerateKey`).
     *
     * @throws \RuntimeException "unrecognized or unsupported key type: ..."
     */
    public static function generateKey(string $keyType): \OpenSSLAsymmetricKey
    {
        switch ($keyType) {
            case self::ED25519:
                // ext-openssl cannot generate Ed25519 keys through openssl_pkey_new
                throw new \RuntimeException(sprintf('unrecognized or unsupported key type: %s', $keyType));
            case '':
            case self::P256:
                $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
                break;
            case self::P384:
                $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
                break;
            case self::RSA2048:
                $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
                break;
            case self::RSA4096:
                $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 4096]);
                break;
            case self::RSA8192:
                $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 8192]);
                break;
            default:
                throw new \RuntimeException(sprintf('unrecognized or unsupported key type: %s', $keyType));
        }
        if ($key === false) {
            throw new \RuntimeException('generating private key: ' . self::opensslError());
        }
        return $key;
    }

    /**
     * PEMEncodePrivateKey marshals a private key into a PEM-encoded block.
     *
     * PHP port: ext-openssl exports keys in PKCS#8 form ("PRIVATE KEY") where
     * Go writes the type-specific "EC PRIVATE KEY" / "RSA PRIVATE KEY" blocks;
     * both are accepted by {@see pemDecodePrivateKey()} and by CertMagic's
     * `PEMDecodePrivateKey`, so the storage stays interchangeable.
     *
     * @throws \RuntimeException
     */
    public static function pemEncodePrivateKey(\OpenSSLAsymmetricKey $key): string
    {
        $out = '';
        if (!openssl_pkey_export($key, $out) || !\is_string($out) || $out === '') {
            throw new \RuntimeException('encoding private key: ' . self::opensslError());
        }
        return $out;
    }

    /**
     * PEMDecodePrivateKey loads a PEM-encoded ECC/RSA private key from a string.
     * Borrowed from Go standard library, to handle various private key and PEM block types.
     *
     * @throws \RuntimeException
     */
    public static function pemDecodePrivateKey(string $keyPEMBytes): \OpenSSLAsymmetricKey
    {
        if (!preg_match('/-----BEGIN ([^-]+)-----/', $keyPEMBytes, $m)) {
            throw new \RuntimeException('failed to decode PEM block containing private key');
        }
        $type = trim($m[1]);
        if ($type !== 'PRIVATE KEY' && !str_ends_with($type, ' PRIVATE KEY')) {
            throw new \RuntimeException(sprintf('unknown PEM header "%s"', $type));
        }
        $key = @openssl_pkey_get_private($keyPEMBytes);
        if ($key === false) {
            throw new \RuntimeException('unknown private key type');
        }
        $details = openssl_pkey_get_details($key);
        $keyType = \is_array($details) ? (int) ($details['type'] ?? -1) : -1;
        if (!\in_array($keyType, [\OPENSSL_KEYTYPE_RSA, \OPENSSL_KEYTYPE_EC], true)) {
            // ed25519 keys parse as "unknown" type in ext-openssl; Go accepts them
            if ($keyType !== -1 && $keyType !== 0) {
                throw new \RuntimeException(sprintf('found unknown private key type in PKCS#8 wrapping: %d', $keyType));
            }
        }
        return $key;
    }

    /**
     * parseCertsFromPEMBundle parses a certificate bundle from top to bottom and returns
     * a list of x509 certificates. This function will throw if no certificates are found.
     *
     * @return X509Certificate[]
     * @throws \RuntimeException
     */
    public static function parseCertsFromPEMBundle(string $bundle): array
    {
        $certificates = [];
        if (preg_match_all('/-----BEGIN ([^-]+)-----(.*?)-----END \1-----/s', $bundle, $blocks, \PREG_SET_ORDER)) {
            foreach ($blocks as $block) {
                if (trim($block[1]) !== 'CERTIFICATE') {
                    continue;
                }
                $der = base64_decode((string) preg_replace('/\s+/', '', $block[2]), true);
                if ($der === false || $der === '') {
                    throw new \RuntimeException('x509: malformed PEM certificate');
                }
                $certificates[] = X509Certificate::parseDER($der);
            }
        }
        if ($certificates === []) {
            throw new \RuntimeException('no certificates found in bundle');
        }
        return $certificates;
    }

    /**
     * fastHash hashes input using a hashing algorithm that
     * is fast, and returns the hash as a hex-encoded string.
     * Do not use this for cryptographic purposes.
     * (FNV-1a 32 bit, printed with %x.)
     */
    public static function fastHash(string $input): string
    {
        $hash = 0x811C9DC5;
        $len = \strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $hash ^= \ord($input[$i]);
            $hash = ($hash * 0x01000193) & 0xFFFFFFFF;
        }
        return dechex($hash);
    }

    /**
     * hashCertificateChain computes the unique hash of certChain,
     * which is the chain of DER-encoded bytes. It returns the
     * hex encoding of the hash.
     *
     * PHP port: Go hashes with BLAKE3, which ext-openssl does not offer; SHA-256
     * (also 32 bytes / 64 hex characters) serves the same purpose — an
     * in-memory cache key never written to storage.
     *
     * @param string[] $certChain
     */
    public static function hashCertificateChain(array $certChain): string
    {
        $ctx = hash_init('sha256');
        foreach ($certChain as $certInChain) {
            hash_update($ctx, $certInChain);
        }
        return hash_final($ctx);
    }

    /**
     * namesFromCSR lists the subjects of a CSR: CommonName (if any), DNS names,
     * emails, IPs, URIs.
     *
     * @return string[]
     */
    public static function namesFromCSR(CertificateRequest $csr): array
    {
        $nameSet = [];
        // TODO: CommonName should not be used (it has been deprecated for 25+ years,
        // but ZeroSSL CA still requires it to be filled out and not overlap SANs...)
        if ($csr->commonName !== '') {
            $nameSet[] = $csr->commonName;
        }
        foreach ($csr->dnsNames as $n) {
            $nameSet[] = $n;
        }
        foreach ($csr->emailAddresses as $n) {
            $nameSet[] = $n;
        }
        foreach ($csr->ipAddresses as $v) {
            $nameSet[] = $v;
        }
        foreach ($csr->uris as $v) {
            $nameSet[] = $v;
        }
        return $nameSet;
    }

    /**
     * idnaToASCII converts an internationalized name to its ASCII (punycode)
     * form, as Go's `idna.ToASCII` (no case mapping for ASCII input).
     *
     * @throws \RuntimeException
     */
    public static function idnaToASCII(string $name): string
    {
        if (preg_match('/^[\x00-\x7F]*$/', $name)) {
            return $name;
        }
        if (\function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($name, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
            if (\is_string($ascii) && $ascii !== '') {
                return $ascii;
            }
        }
        throw new \RuntimeException(sprintf('idna: invalid label "%s"', $name));
    }

    /** keyType reports "RSA", "EC" or "unknown" for a key. */
    public static function keyType(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        $type = \is_array($details) ? (int) ($details['type'] ?? -1) : -1;
        return match ($type) {
            \OPENSSL_KEYTYPE_RSA => 'RSA',
            \OPENSSL_KEYTYPE_EC => 'EC',
            default => 'unknown',
        };
    }

    /** pemToDER decodes the first PEM block of any type. */
    public static function pemToDER(string $pem): string
    {
        if (!preg_match('/-----BEGIN [^-]+-----(.*?)-----END [^-]+-----/s', $pem, $m)) {
            throw new \RuntimeException('failed to decode PEM block');
        }
        $der = base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);
        if ($der === false) {
            throw new \RuntimeException('malformed PEM block');
        }
        return $der;
    }

    /** derToPEM is `pem.EncodeToMemory(&pem.Block{Type: type, Bytes: der})`. */
    public static function derToPEM(string $der, string $type): string
    {
        return "-----BEGIN {$type}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$type}-----\n";
    }

    /** opensslError drains the OpenSSL error queue into one message. */
    public static function opensslError(): string
    {
        $messages = [];
        while (($msg = openssl_error_string()) !== false) {
            $messages[] = $msg;
        }
        return $messages === [] ? 'unknown OpenSSL error' : implode('; ', $messages);
    }
}
