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

use Blnk\Cmd\CertMagic\Acme\RenewalInfo;

/**
 * Certificate is a tls.Certificate with associated metadata tacked on.
 * Even if the metadata can be obtained by parsing the certificate,
 * we are more efficient by extracting the metadata onto this struct,
 * but at the cost of slightly higher memory use.
 *
 * (certmagic certificates.go. OCSP staples are not ported: PHP's TLS
 * streams cannot staple OCSP responses.)
 */
final class Certificate
{
    /**
     * The DER-encoded certificate chain, leaf first
     * (Go: `tls.Certificate.Certificate`).
     *
     * @var string[]
     */
    public array $certificate = [];

    /** The PEM bundle the chain was loaded from. */
    public string $certificatePEM = '';

    /** The PEM-encoded private key. */
    public string $privateKeyPEM = '';

    /** The parsed leaf certificate (Go: `tls.Certificate.Leaf`). */
    public ?X509Certificate $leaf = null;

    /**
     * Names is the list of subject names this
     * certificate is signed for.
     *
     * @var string[]
     */
    public array $names = [];

    /**
     * Optional; user-provided, and arbitrary.
     *
     * @var string[]
     */
    public array $tags = [];

    /** The hex-encoded hash of this cert's chain's DER bytes. */
    public string $hash = '';

    /** Whether this certificate is under our management. */
    public bool $managed = false;

    /** The unique string identifying the issuer of this certificate. */
    public string $issuerKey = '';

    /** ACME Renewal Information, if available */
    public RenewalInfo $ari;

    public function __construct()
    {
        $this->ari = new RenewalInfo();
    }

    /**
     * Empty returns true if the certificate struct is not filled out; at
     * least the tls.Certificate.Certificate field is expected to be set.
     */
    public function empty(): bool
    {
        return $this->certificate === [];
    }

    /** Hash returns a checksum of the certificate chain's DER-encoded bytes. */
    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * NeedsRenewal returns true if the certificate is expiring
     * soon (according to ARI and/or cfg) or has expired.
     */
    public function needsRenewal(Config $cfg): bool
    {
        return $cfg->certNeedsRenewal($this->leaf, $this->ari, true);
    }

    /** Expired returns true if the certificate has expired. */
    public function expired(): bool
    {
        if ($this->leaf === null) {
            // ideally cert.Leaf would never be nil, but this can happen for
            // "synthetic" certs like those made to solve the TLS-ALPN challenge
            // which adds a special cert directly  to the cache, since
            // tls.X509KeyPair() discards the leaf; oh well
            return false;
        }
        return microtime(true) > Rfc3339::seconds($this->leaf->expiresAt());
    }

    /** Lifetime returns the duration of the certificate's validity, in seconds. */
    public function lifetime(): float
    {
        if ($this->leaf === null || $this->leaf->notAfter->getTimestamp() === 0) {
            return 0.0;
        }
        return Rfc3339::seconds($this->leaf->expiresAt()) - Rfc3339::seconds($this->leaf->notBefore);
    }

    /** HasTag returns true if cert.Tags has tag. */
    public function hasTag(string $tag): bool
    {
        return \in_array($tag, $this->tags, true);
    }

    /**
     * makeCertificate turns a certificate PEM bundle and a key PEM block into
     * a Certificate with necessary metadata from parsing its bytes filled into
     * its struct fields for convenience (except for the OnDemand and Managed
     * flags; it is up to the caller to set those properties!).
     *
     * @throws \RuntimeException
     */
    public static function makeCertificate(string $certPEMBlock, string $keyPEMBlock): self
    {
        $cert = new self();

        // Convert to a tls.Certificate (tls.X509KeyPair)
        $chain = Crypto::parseCertsFromPEMBundle($certPEMBlock);
        $key = Crypto::pemDecodePrivateKey($keyPEMBlock);
        if (!@openssl_x509_check_private_key($chain[0]->pem, $key)) {
            throw new \RuntimeException('tls: private key does not match public key');
        }
        $cert->certificatePEM = $certPEMBlock;
        $cert->privateKeyPEM = $keyPEMBlock;

        // Extract necessary metadata
        self::fillCertFromLeaf($cert, $chain);

        return $cert;
    }

    /**
     * fillCertFromLeaf populates cert from the parsed chain. If it succeeds, it
     * guarantees that cert.Leaf is non-null.
     *
     * @param X509Certificate[] $chain
     * @throws \RuntimeException
     */
    public static function fillCertFromLeaf(Certificate $cert, array $chain): void
    {
        if ($chain === []) {
            throw new \RuntimeException('certificate is empty');
        }
        $cert->certificate = [];
        foreach ($chain as $c) {
            $cert->certificate[] = $c->raw;
        }

        // the leaf cert should be the one for the site; we must set
        // the tls.Certificate.Leaf field so that TLS handshakes are
        // more efficient
        $leaf = $chain[0];
        $cert->leaf = $leaf;

        // for convenience, we do want to assemble all the
        // subjects on the certificate into one list
        $cert->names = [];
        if ($leaf->subjectCommonName !== '') { // TODO: CommonName is deprecated
            $cert->names = [strtolower($leaf->subjectCommonName)];
        }
        foreach ($leaf->dnsNames as $name) {
            if ($name !== $leaf->subjectCommonName) { // TODO: CommonName is deprecated
                $cert->names[] = strtolower($name);
            }
        }
        foreach ($leaf->ipAddresses as $ip) {
            if ($ip !== $leaf->subjectCommonName) { // TODO: CommonName is deprecated
                $cert->names[] = strtolower($ip);
            }
        }
        foreach ($leaf->emailAddresses as $email) {
            if ($email !== $leaf->subjectCommonName) { // TODO: CommonName is deprecated
                $cert->names[] = strtolower($email);
            }
        }
        foreach ($leaf->uris as $u) {
            if ($u !== $leaf->subjectCommonName) { // TODO: CommonName is deprecated
                $cert->names[] = $u;
            }
        }
        if ($cert->names === []) {
            throw new \RuntimeException('certificate has no names');
        }

        $cert->hash = Crypto::hashCertificateChain($cert->certificate);
    }
}
