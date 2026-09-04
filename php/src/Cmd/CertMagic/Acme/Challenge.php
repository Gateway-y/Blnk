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
 * Challenge holds information about an ACME challenge.
 *
 * "An ACME challenge object represents a server's offer to validate a
 * client's possession of an identifier in a specific way.  Unlike the
 * other objects listed above, there is not a single standard structure
 * for a challenge object.  The contents of a challenge object depend on
 * the validation method being used.  The general structure of challenge
 * objects and an initial set of validation methods are described in
 * Section 8." §7.1.5
 */
final class Challenge implements \JsonSerializable
{
    /** The standard or well-known ACME challenge types. */
    public const ChallengeTypeHTTP01 = 'http-01'; // RFC 8555 §8.3
    public const ChallengeTypeDNS01 = 'dns-01'; // RFC 8555 §8.4
    public const ChallengeTypeTLSALPN01 = 'tls-alpn-01'; // RFC 8737 §3
    public const ChallengeTypeDeviceAttest01 = 'device-attest-01'; // draft-acme-device-attest-00 §5
    public const ChallengeTypeEmailReply00 = 'email-reply-00'; // RFC 8823 §5.2
    public const ChallengeTypeAuthorityToken = 'tkauth-01'; // RFC 9447 §3 - ACME Authority Token challenge type

    // "Challenge objects all contain the following basic fields..." §8

    /** type (required, string):  The type of challenge encoded in the object. */
    public string $type = '';

    /** url (required, string):  The URL to which a response can be posted. */
    public string $url = '';

    /**
     * status (required, string):  The status of this challenge.  Possible
     * values are "pending", "processing", "valid", and "invalid" (see
     * Section 7.1.6).
     */
    public string $status = '';

    /**
     * validated (optional, string):  The time at which the server validated
     * this challenge, encoded in the format specified in [RFC3339].
     * This field is REQUIRED if the "status" field is "valid".
     */
    public string $validated = '';

    /**
     * error (optional, object):  Error that occurred while the server was
     * validating the challenge, if any, structured as a problem document
     * [RFC7807].  Multiple errors can be indicated by using subproblems
     * Section 6.7.1.  A challenge object with an error MUST have status
     * equal to "invalid".
     */
    public ?Problem $error = null;

    // "All additional fields are specified by the challenge type." §8
    // (We also add our own for convenience.)

    /**
     * "The token for a challenge is a string comprised entirely of
     * characters in the URL-safe base64 alphabet." §8.1
     *
     * Used by the http-01, tls-alpn-01, and dns-01 challenges.
     */
    public string $token = '';

    /**
     * A key authorization is a string that concatenates the token for the
     * challenge with a key fingerprint, separated by a "." character (§8.1):
     *
     *     keyAuthorization = token || '.' || base64url(Thumbprint(accountKey))
     *
     * This client package automatically assembles and sets this value for you.
     */
    public string $keyAuthorization = '';

    /**
     * We attach the identifier that this challenge is associated with, which
     * may be useful information for solving a challenge. It is not part of the
     * structure as defined by the spec but is added by us to provide enough
     * information to solve the DNS-01 challenge.
     */
    public Identifier $identifier;

    /**
     * From header of email must match with the "from" field of challenge object
     * as described in RFC8823 §3.1 - 2, added on 3-6.3.1
     */
    public string $from = '';

    /**
     * Payload contains a JSON-marshallable value that will be sent to the CA
     * when responding to challenges. If not set, an empty JSON body "{}" will
     * be included in the POST request. This field is applicable when responding
     * to "device-attest-01" challenges.
     */
    public mixed $payload = null;

    /**
     * TkAuthType is the Authority Token Subtype as described in RFC9447 §3
     * This field is only applicable when responding to "tkauth-01" challenges
     * and indicates the type of Authority token that will be used
     * to validate the challenge.
     */
    public string $tkAuthType = '';

    public function __construct()
    {
        $this->identifier = new Identifier();
    }

    /**
     * HTTP01ResourcePath returns the URI path for solving the http-01 challenge.
     *
     * "The path at which the resource is provisioned is comprised of the
     * fixed prefix '/.well-known/acme-challenge/', followed by the 'token'
     * value in the challenge." §8.3
     */
    public function http01ResourcePath(): string
    {
        return '/.well-known/acme-challenge/' . $this->token;
    }

    /**
     * DNS01TXTRecordName returns the name of the TXT record to create for
     * solving the dns-01 challenge.
     *
     * "The client constructs the validation domain name by prepending the
     * label '_acme-challenge' to the domain name being validated, then
     * provisions a TXT record with the digest value under that name." §8.4
     */
    public function dns01TXTRecordName(): string
    {
        return '_acme-challenge.' . $this->identifier->value;
    }

    /**
     * DNS01KeyAuthorization encodes a key authorization value to be used
     * in a TXT record for the _acme-challenge DNS record.
     *
     * "A client fulfills this challenge by constructing a key authorization
     * from the 'token' value provided in the challenge and the client's
     * account key.  The client then computes the SHA-256 digest [FIPS180-4]
     * of the key authorization.
     *
     * The record provisioned to the DNS contains the base64url encoding of
     * this digest." §8.4
     */
    public function dns01KeyAuthorization(): string
    {
        return Jws::base64url(hash('sha256', $this->keyAuthorization, true));
    }

    /**
     * applyArray updates the challenge from a server response.
     *
     * @param array<string, mixed> $data
     */
    public function applyArray(array $data): void
    {
        if (\array_key_exists('type', $data)) {
            $this->type = (string) $data['type'];
        }
        if (\array_key_exists('url', $data)) {
            $this->url = (string) $data['url'];
        }
        if (\array_key_exists('status', $data)) {
            $this->status = (string) $data['status'];
        }
        if (\array_key_exists('validated', $data)) {
            $this->validated = (string) $data['validated'];
        }
        if (\array_key_exists('error', $data)) {
            $this->error = \is_array($data['error']) ? Problem::fromArray($data['error']) : null;
        }
        if (\array_key_exists('token', $data)) {
            $this->token = (string) $data['token'];
        }
        if (\array_key_exists('keyAuthorization', $data)) {
            $this->keyAuthorization = (string) $data['keyAuthorization'];
        }
        if (isset($data['identifier']) && \is_array($data['identifier'])) {
            $this->identifier = Identifier::fromArray($data['identifier']);
        }
        if (\array_key_exists('from', $data)) {
            $this->from = (string) $data['from'];
        }
        if (\array_key_exists('tkauth-type', $data)) {
            $this->tkAuthType = (string) $data['tkauth-type'];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $c = new self();
        $c->applyArray($data);
        return $c;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = [
            'type' => $this->type,
            'url' => $this->url,
            'status' => $this->status,
        ];
        if ($this->validated !== '') {
            $out['validated'] = $this->validated;
        }
        if ($this->error !== null) {
            $out['error'] = $this->error->toArray();
        }
        if ($this->token !== '') {
            $out['token'] = $this->token;
        }
        if ($this->keyAuthorization !== '') {
            $out['keyAuthorization'] = $this->keyAuthorization;
        }
        $out['identifier'] = $this->identifier->jsonSerialize(); // a struct is never omitted by omitempty
        if ($this->from !== '') {
            $out['from'] = $this->from;
        }
        if ($this->tkAuthType !== '') {
            $out['tkauth-type'] = $this->tkAuthType;
        }
        return $out;
    }
}
