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
 * X509Certificate is the port's stand-in for Go's `*x509.Certificate`: the
 * fields the certificate manager reads (subjects, validity, serial, authority
 * key identifier) extracted once from `openssl_x509_parse`, plus the raw DER
 * and PEM encodings.
 */
final class X509Certificate
{
    /** DER encoding (Go: `Raw`). */
    public string $raw = '';

    /** PEM encoding of this single certificate. */
    public string $pem = '';

    /** Subject CommonName (deprecated in X.509 but still read for the names list). */
    public string $subjectCommonName = '';

    public string $issuerCommonName = '';

    /** @var string[] */
    public array $dnsNames = [];

    /** @var string[] */
    public array $ipAddresses = [];

    /** @var string[] */
    public array $emailAddresses = [];

    /** @var string[] */
    public array $uris = [];

    public \DateTimeImmutable $notBefore;

    public \DateTimeImmutable $notAfter;

    /** Upper-case hexadecimal serial number (no leading zero bytes). */
    public string $serialNumberHex = '';

    /** Raw bytes of the keyIdentifier of the Authority Key Identifier extension (Go: `AuthorityKeyId`). */
    public string $authorityKeyId = '';

    /** Raw bytes of the Subject Key Identifier extension (Go: `SubjectKeyId`). */
    public string $subjectKeyId = '';

    /** @var array<string, string> extension short name → printed value */
    public array $extensions = [];

    private function __construct()
    {
        $this->notBefore = new \DateTimeImmutable('@0');
        $this->notAfter = new \DateTimeImmutable('@0');
    }

    /**
     * parsePEM parses the first CERTIFICATE block of a PEM string.
     *
     * @throws \RuntimeException
     */
    public static function parsePEM(string $pem): self
    {
        if (!preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $m)) {
            throw new \RuntimeException('x509: no PEM-encoded certificate found');
        }
        $der = base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);
        if ($der === false || $der === '') {
            throw new \RuntimeException('x509: malformed PEM certificate');
        }
        return self::parseDER($der);
    }

    /**
     * parseDER parses a DER-encoded certificate (Go: x509.ParseCertificate).
     *
     * @throws \RuntimeException
     */
    public static function parseDER(string $der): self
    {
        $pem = Crypto::derToPEM($der, 'CERTIFICATE');
        $parsed = @openssl_x509_parse($pem, true);
        if (!\is_array($parsed)) {
            throw new \RuntimeException('x509: malformed certificate' . self::opensslError());
        }

        $cert = new self();
        $cert->raw = $der;
        $cert->pem = $pem;
        $cert->subjectCommonName = self::firstString($parsed['subject']['CN'] ?? '');
        $cert->issuerCommonName = self::firstString($parsed['issuer']['CN'] ?? '');
        $cert->notBefore = (new \DateTimeImmutable('@' . (int) ($parsed['validFrom_time_t'] ?? 0)))->setTimezone(new \DateTimeZone('UTC'));
        $cert->notAfter = (new \DateTimeImmutable('@' . (int) ($parsed['validTo_time_t'] ?? 0)))->setTimezone(new \DateTimeZone('UTC'));
        $cert->serialNumberHex = strtoupper((string) ($parsed['serialNumberHex'] ?? ''));
        $cert->extensions = [];
        foreach ((array) ($parsed['extensions'] ?? []) as $name => $value) {
            $cert->extensions[(string) $name] = \is_string($value) ? $value : (string) json_encode($value);
        }

        // Subject Alternative Names: "DNS:a, IP Address:1.2.3.4, email:x@y, URI:..."
        $san = $cert->extensions['subjectAltName'] ?? '';
        foreach (preg_split('/,\s*/', $san) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $pos = strpos($entry, ':');
            if ($pos === false) {
                continue;
            }
            $kind = strtolower(substr($entry, 0, $pos));
            $value = substr($entry, $pos + 1);
            switch ($kind) {
                case 'dns':
                    $cert->dnsNames[] = $value;
                    break;
                case 'ip address':
                case 'ip':
                    $cert->ipAddresses[] = $value;
                    break;
                case 'email':
                    $cert->emailAddresses[] = $value;
                    break;
                case 'uri':
                    $cert->uris[] = $value;
                    break;
                default:
                    // othername etc. are not subjects we serve
            }
        }

        $cert->authorityKeyId = self::keyIdentifierBytes($cert->extensions['authorityKeyIdentifier'] ?? '');
        $cert->subjectKeyId = self::keyIdentifierBytes($cert->extensions['subjectKeyIdentifier'] ?? '');

        return $cert;
    }

    /**
     * expiresAt returns the time that a certificate expires. Account for the 1s
     * resolution of ASN.1 UTCTime/GeneralizedTime by including the extra fraction
     * of a second of certificate validity beyond the NotAfter value.
     *
     * (certmagic certificates.go `expiresAt`.)
     */
    public function expiresAt(): \DateTimeImmutable
    {
        return $this->notAfter->setTime(
            (int) $this->notAfter->format('G'),
            (int) $this->notAfter->format('i'),
            (int) $this->notAfter->format('s')
        )->modify('+1 second');
    }

    /**
     * serialNumberDERContent returns the content octets of the DER INTEGER
     * encoding of the serial number (what `asn1.Marshal(SerialNumber)[2:]`
     * yields in Go): minimal big-endian bytes with a leading 0x00 when the
     * high bit is set, so the number is interpreted as positive.
     */
    public function serialNumberDERContent(): string
    {
        $hex = $this->serialNumberHex;
        if ($hex === '') {
            return '';
        }
        if (\strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        $bytes = (string) hex2bin($hex);
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            return "\x00";
        }
        if (\ord($bytes[0]) >= 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return $bytes;
    }

    /**
     * keyIdentifierBytes extracts the raw key identifier from OpenSSL's
     * printed form ("keyid:AA:BB:..." or "AA:BB:..."; the AKI may carry
     * further DirName/serial lines).
     */
    private static function keyIdentifierBytes(string $printed): string
    {
        foreach (preg_split('/\R/', $printed) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with(strtolower($line), 'keyid:')) {
                $line = substr($line, 6);
            }
            if (preg_match('/^[0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2})*$/', $line)) {
                return (string) hex2bin(str_replace(':', '', $line));
            }
        }
        return '';
    }

    private static function firstString(mixed $value): string
    {
        if (\is_array($value)) {
            $value = reset($value);
        }
        return \is_string($value) ? $value : (string) $value;
    }

    private static function opensslError(): string
    {
        $messages = [];
        while (($msg = openssl_error_string()) !== false) {
            $messages[] = $msg;
        }
        return $messages === [] ? '' : ' (' . implode('; ', $messages) . ')';
    }
}
