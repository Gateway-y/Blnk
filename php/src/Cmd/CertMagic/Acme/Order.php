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

use Blnk\Cmd\CertMagic\Rfc3339;

/**
 * Order is an object that "represents a client's request for a certificate
 * and is used to track the progress of that order through to issuance.
 * Thus, the object contains information about the requested
 * certificate, the authorizations that the server requires the client
 * to complete, and any certificates that have resulted from this order."
 * §7.1.3
 */
final class Order implements \JsonSerializable
{
    /**
     * status (required, string):  The status of this order.  Possible
     * values are "pending", "ready", "processing", "valid", and
     * "invalid".  See Section 7.1.6.
     */
    public string $status = '';

    /**
     * expires (optional, string):  The timestamp after which the server
     * will consider this order invalid, encoded in the format specified
     * in [RFC3339].  This field is REQUIRED for objects with "pending"
     * or "valid" in the status field.
     */
    public ?\DateTimeImmutable $expires = null;

    /**
     * profile (string, optional): A string uniquely identifying the profile
     * which will be used to affect issuance of the certificate requested by
     * this Order.
     *
     * EXPERIMENTAL: Draft ACME extension: draft-aaron-acme-profiles-00
     */
    public string $profile = '';

    /**
     * identifiers (required, array of object):  An array of identifier
     * objects that the order pertains to.
     *
     * @var Identifier[]
     */
    public array $identifiers = [];

    /**
     * replaces (string, optional): A string uniquely identifying a
     * previously-issued certificate which this order is intended to replace.
     *
     * EXPERIMENTAL:  Draft ACME extension ARI: draft-ietf-acme-ari-03
     */
    public string $replaces = '';

    /** notBefore (optional, string):  The requested value of the notBefore field in the certificate. */
    public ?\DateTimeImmutable $notBefore = null;

    /** notAfter (optional, string):  The requested value of the notAfter field in the certificate. */
    public ?\DateTimeImmutable $notAfter = null;

    /**
     * error (optional, object):  The error that occurred while processing
     * the order, if any.  This field is structured as a problem document
     * [RFC7807].
     */
    public ?Problem $error = null;

    /**
     * authorizations (required, array of string):  For pending orders, the
     * authorizations that the client needs to complete before the
     * requested certificate can be issued (see Section 7.5) [...] Each entry
     * is a URL from which an authorization can be fetched with a POST-as-GET
     * request.
     *
     * @var string[]|null
     */
    public ?array $authorizations = null;

    /**
     * finalize (required, string):  A URL that a CSR must be POSTed to once
     * all of the order's authorizations are satisfied to finalize the
     * order.  The result of a successful finalization will be the
     * population of the certificate URL for the order.
     */
    public string $finalize = '';

    /** certificate (optional, string):  A URL for the certificate that has been issued in response to this order. */
    public string $certificate = '';

    /**
     * Similar to new-account, the server returns a 201 response with
     * the URL to the order object in the Location header.
     *
     * We transfer the value from the header to this field for
     * storage and recall purposes.
     */
    public string $location = '';

    /** @return string[] */
    public function identifierValues(): array
    {
        $list = [];
        foreach ($this->identifiers as $id) {
            $list[] = $id->value;
        }
        return $list;
    }

    /**
     * applyArray updates the order from a server response (json.Unmarshal
     * into the existing value).
     *
     * @param array<string, mixed> $data
     * @throws \RuntimeException
     */
    public function applyArray(array $data): void
    {
        if (\array_key_exists('status', $data)) {
            $this->status = (string) $data['status'];
        }
        if (\array_key_exists('expires', $data)) {
            $this->expires = Rfc3339::decode(\is_string($data['expires']) ? $data['expires'] : null);
        }
        if (\array_key_exists('profile', $data)) {
            $this->profile = (string) $data['profile'];
        }
        if (\array_key_exists('identifiers', $data)) {
            $this->identifiers = [];
            foreach ((array) $data['identifiers'] as $id) {
                if (\is_array($id)) {
                    $this->identifiers[] = Identifier::fromArray($id);
                }
            }
        }
        if (\array_key_exists('replaces', $data)) {
            $this->replaces = (string) $data['replaces'];
        }
        if (\array_key_exists('notBefore', $data)) {
            $this->notBefore = Rfc3339::decode(\is_string($data['notBefore']) ? $data['notBefore'] : null);
        }
        if (\array_key_exists('notAfter', $data)) {
            $this->notAfter = Rfc3339::decode(\is_string($data['notAfter']) ? $data['notAfter'] : null);
        }
        if (\array_key_exists('error', $data)) {
            $this->error = \is_array($data['error']) ? Problem::fromArray($data['error']) : null;
        }
        if (\array_key_exists('authorizations', $data)) {
            $this->authorizations = $data['authorizations'] === null ? null : array_map('strval', (array) $data['authorizations']);
        }
        if (\array_key_exists('finalize', $data)) {
            $this->finalize = (string) $data['finalize'];
        }
        if (\array_key_exists('certificate', $data)) {
            $this->certificate = (string) $data['certificate'];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $o = new self();
        $o->applyArray($data);
        return $o;
    }

    /**
     * jsonSerialize encodes the order exactly like Go's `json.Marshal` of
     * `acme.Order` (a zero `expires` is emitted as Go's zero time since
     * omitempty does not apply to structs).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $out = [];
        if ($this->status !== '') {
            $out['status'] = $this->status;
        }
        $out['expires'] = Rfc3339::encode($this->expires);
        if ($this->profile !== '') {
            $out['profile'] = $this->profile;
        }
        $out['identifiers'] = array_map(static fn (Identifier $id): array => $id->jsonSerialize(), $this->identifiers);
        if ($this->replaces !== '') {
            $out['replaces'] = $this->replaces;
        }
        if ($this->notBefore !== null) {
            $out['notBefore'] = Rfc3339::encode($this->notBefore);
        }
        if ($this->notAfter !== null) {
            $out['notAfter'] = Rfc3339::encode($this->notAfter);
        }
        if ($this->error !== null) {
            $out['error'] = $this->error->toArray();
        }
        $out['authorizations'] = $this->authorizations;
        $out['finalize'] = $this->finalize;
        $out['certificate'] = $this->certificate;
        return $out;
    }
}
