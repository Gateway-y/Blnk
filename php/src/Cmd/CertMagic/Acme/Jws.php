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

use Blnk\Cmd\CertMagic\JsonUtil;

/**
 * Jws is the port of acmez acme/jws.go: JWS (RFC 7515) encoding of ACME
 * request bodies with an RSA or ECDSA account key, the JWK encoding of the
 * public key (RFC 7517/7518) and the JWK thumbprint (RFC 7638) used for key
 * authorizations.
 */
final class Jws
{
    public const errUnsupportedKey = 'unknown key type; only RSA and ECDSA are supported';

    /** noKeyID indicates that jwsEncodeJSON should compute and use JWK instead of a KID. */
    public const noKeyID = '';

    private function __construct()
    {
    }

    /**
     * jwsEncodeEAB creates a JWS payload for External Account Binding according to RFC 8555 §7.3.4.
     *
     * @throws \RuntimeException
     */
    public static function jwsEncodeEAB(\OpenSSLAsymmetricKey $accountKey, string $hmacKey, string $kid, string $url): string
    {
        // §7.3.4: "The 'alg' field MUST indicate a MAC-based algorithm"
        $alg = 'HS256';

        // §7.3.4: "The 'nonce' field MUST NOT be present"
        $phead = self::jwsHead($alg, '', $url, $kid, null);

        $encodedKey = self::jwkEncode($accountKey);
        $payload = self::base64url($encodedKey);

        $payloadToSign = $phead . '.' . $payload;

        $sig = hash_hmac('sha256', $payloadToSign, $hmacKey, true);

        return self::jwsFinal($sig, $phead, $payload);
    }

    /**
     * jwsEncodeJSON signs claimset using provided key and a nonce.
     * The result is serialized in JSON format containing either kid or jwk
     * fields based on the provided keyID value.
     *
     * If kid is non-empty, its quoted value is inserted in the protected head
     * as "kid" field value. Otherwise, JWK is computed using jwkEncode and inserted
     * as "jwk" field value. The "jwk" and "kid" fields are mutually exclusive.
     *
     * See https://tools.ietf.org/html/rfc7515#section-7.
     *
     * If nonce is empty, it will not be encoded into the header.
     * A null claimset produces an empty payload (POST-as-GET, §6.3).
     *
     * @throws \RuntimeException
     */
    public static function jwsEncodeJSON(mixed $claimset, \OpenSSLAsymmetricKey $key, string $kid, string $nonce, string $url): string
    {
        [$alg, $sha] = self::jwsHasher($key);
        if ($alg === '') {
            throw new \RuntimeException(self::errUnsupportedKey);
        }

        $phead = self::jwsHead($alg, $nonce, $url, $kid, $key);

        $payload = '';
        if ($claimset !== null) {
            $cs = JsonUtil::marshal($claimset);
            $payload = self::base64url($cs);
        }

        $payloadToSign = $phead . '.' . $payload;

        $sig = self::jwsSign($key, $sha, $payloadToSign);

        return self::jwsFinal($sig, $phead, $payload);
    }

    /**
     * jwkEncode encodes public part of an RSA or ECDSA key into a JWK.
     * The result is also suitable for creating a JWK thumbprint.
     * https://tools.ietf.org/html/rfc7517
     *
     * @throws \RuntimeException
     */
    public static function jwkEncode(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if (!\is_array($details)) {
            throw new \RuntimeException(self::errUnsupportedKey);
        }
        switch ((int) ($details['type'] ?? -1)) {
            case \OPENSSL_KEYTYPE_RSA:
                // https://tools.ietf.org/html/rfc7518#section-6.3.1
                $n = ltrim((string) $details['rsa']['n'], "\x00");
                $e = ltrim((string) $details['rsa']['e'], "\x00");
                // Field order is important.
                // See https://tools.ietf.org/html/rfc7638#section-3.3 for details.
                return sprintf('{"e":"%s","kty":"RSA","n":"%s"}', self::base64url($e), self::base64url($n));
            case \OPENSSL_KEYTYPE_EC:
                // https://tools.ietf.org/html/rfc7518#section-6.2.1
                $curve = (string) ($details['ec']['curve_name'] ?? '');
                $crv = self::curveName($curve);
                if ($crv === '') {
                    throw new \RuntimeException(self::errUnsupportedKey);
                }
                $bits = (int) ($details['bits'] ?? 0);
                $n = intdiv($bits, 8) + ($bits % 8 !== 0 ? 1 : 0);
                $x = str_pad(ltrim((string) $details['ec']['x'], "\x00"), $n, "\x00", \STR_PAD_LEFT);
                $y = str_pad(ltrim((string) $details['ec']['y'], "\x00"), $n, "\x00", \STR_PAD_LEFT);
                // Field order is important.
                // See https://tools.ietf.org/html/rfc7638#section-3.3 for details.
                return sprintf('{"crv":"%s","kty":"EC","x":"%s","y":"%s"}', $crv, self::base64url($x), self::base64url($y));
        }
        throw new \RuntimeException(self::errUnsupportedKey);
    }

    /**
     * jwsHead constructs the protected JWS header for the given fields.
     * Since jwk and kid are mutually-exclusive, the jwk will be encoded
     * only if kid is empty. If nonce is empty, it will not be encoded.
     *
     * @throws \RuntimeException
     */
    public static function jwsHead(string $alg, string $nonce, string $url, string $kid, ?\OpenSSLAsymmetricKey $key): string
    {
        $phead = sprintf('{"alg":%s', self::quote($alg));
        if ($kid === self::noKeyID) {
            if ($key === null) {
                throw new \RuntimeException(self::errUnsupportedKey);
            }
            $jwk = self::jwkEncode($key);
            $phead .= sprintf(',"jwk":%s', $jwk);
        } else {
            $phead .= sprintf(',"kid":%s', self::quote($kid));
        }
        if ($nonce !== '') {
            $phead .= sprintf(',"nonce":%s', self::quote($nonce));
        }
        $phead .= sprintf(',"url":%s}', self::quote($url));
        return self::base64url($phead);
    }

    /** jwsFinal constructs the final JWS object. */
    public static function jwsFinal(string $sig, string $phead, string $payload): string
    {
        return JsonUtil::marshal([
            'protected' => $phead,
            'payload' => $payload,
            'signature' => self::base64url($sig),
        ]);
    }

    /**
     * jwsSign signs the payload using the given key and hash algorithm.
     * ECDSA signatures are converted from OpenSSL's ASN.1 encoding to the
     * fixed-size R||S format required by RFC 7518.
     *
     * @throws \RuntimeException
     */
    public static function jwsSign(\OpenSSLAsymmetricKey $key, string $hashAlgo, string $payloadToSign): string
    {
        $sig = '';
        if (!openssl_sign($payloadToSign, $sig, $key, $hashAlgo)) {
            throw new \RuntimeException('signing JWS: ' . (openssl_error_string() ?: 'unknown OpenSSL error'));
        }
        $details = openssl_pkey_get_details($key);
        if (\is_array($details) && (int) ($details['type'] ?? -1) === \OPENSSL_KEYTYPE_EC) {
            // The key.Sign method of ecdsa returns ASN1-encoded signature.
            // So, we use the package Sign function instead
            // to get R and S values directly and format the result accordingly.
            $bits = (int) ($details['bits'] ?? 0);
            $size = intdiv($bits, 8);
            if ($size % 8 > 0) {
                $size++;
            }
            return self::derToRawECDSA($sig, $size);
        }
        return $sig;
    }

    /**
     * jwsHasher indicates suitable JWS algorithm name and a hash function
     * to use for signing a digest with the provided key.
     * It returns ["", ""] if the key is not supported.
     *
     * @return array{0: string, 1: string}
     */
    public static function jwsHasher(\OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        if (!\is_array($details)) {
            return ['', ''];
        }
        switch ((int) ($details['type'] ?? -1)) {
            case \OPENSSL_KEYTYPE_RSA:
                return ['RS256', 'sha256'];
            case \OPENSSL_KEYTYPE_EC:
                switch (self::curveName((string) ($details['ec']['curve_name'] ?? ''))) {
                    case 'P-256':
                        return ['ES256', 'sha256'];
                    case 'P-384':
                        return ['ES384', 'sha384'];
                    case 'P-521':
                        return ['ES512', 'sha512'];
                }
        }
        return ['', ''];
    }

    /**
     * jwkThumbprint creates a JWK thumbprint out of the key's public part
     * as specified in https://tools.ietf.org/html/rfc7638.
     *
     * @throws \RuntimeException
     */
    public static function jwkThumbprint(\OpenSSLAsymmetricKey $key): string
    {
        $jwk = self::jwkEncode($key);
        return self::base64url(hash('sha256', $jwk, true));
    }

    /** base64url is `base64.RawURLEncoding.EncodeToString`. */
    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * base64urlDecode is `base64.RawURLEncoding.DecodeString`.
     *
     * @throws \RuntimeException
     */
    public static function base64urlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - \strlen($data) % 4) % 4), true);
        if ($decoded === false) {
            throw new \RuntimeException('illegal base64 data');
        }
        return $decoded;
    }

    /** curveName maps OpenSSL curve names to the JOSE "crv" values. */
    private static function curveName(string $opensslName): string
    {
        return match (strtolower($opensslName)) {
            'prime256v1', 'p-256', 'secp256r1' => 'P-256',
            'secp384r1', 'p-384' => 'P-384',
            'secp521r1', 'p-521' => 'P-521',
            default => '',
        };
    }

    /** quote is Go's %q for a JSON string. */
    private static function quote(string $s): string
    {
        return JsonUtil::marshal($s);
    }

    /**
     * derToRawECDSA converts an ASN.1 DER ECDSA signature (SEQUENCE of two
     * INTEGERs) into the raw R||S encoding with each value left-padded to size.
     *
     * @throws \RuntimeException
     */
    private static function derToRawECDSA(string $der, int $size): string
    {
        $pos = 0;
        $len = \strlen($der);
        if ($len < 8 || \ord($der[$pos++]) !== 0x30) {
            throw new \RuntimeException('signing JWS: malformed ECDSA signature');
        }
        self::readDERLength($der, $pos);
        $ints = [];
        for ($i = 0; $i < 2; $i++) {
            if ($pos >= $len || \ord($der[$pos++]) !== 0x02) {
                throw new \RuntimeException('signing JWS: malformed ECDSA signature');
            }
            $n = self::readDERLength($der, $pos);
            $ints[] = ltrim(substr($der, $pos, $n), "\x00");
            $pos += $n;
        }
        return str_pad($ints[0], $size, "\x00", \STR_PAD_LEFT) . str_pad($ints[1], $size, "\x00", \STR_PAD_LEFT);
    }

    private static function readDERLength(string $der, int &$pos): int
    {
        $first = \ord($der[$pos++]);
        if ($first < 0x80) {
            return $first;
        }
        $count = $first & 0x7F;
        $n = 0;
        for ($i = 0; $i < $count; $i++) {
            $n = ($n << 8) | \ord($der[$pos++]);
        }
        return $n;
    }
}
