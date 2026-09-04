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
 * Authorization "represents a server's authorization for
 * an account to represent an identifier.  In addition to the
 * identifier, an authorization includes several metadata fields, such
 * as the status of the authorization (e.g., 'pending', 'valid', or
 * 'revoked') and which challenges were used to validate possession of
 * the identifier." §7.1.4
 */
final class Authorization
{
    /** identifier (required, object):  The identifier that the account is authorized to represent. */
    public Identifier $identifier;

    /**
     * status (required, string):  The status of this authorization.
     * Possible values are "pending", "valid", "invalid", "deactivated",
     * "expired", and "revoked".  See Section 7.1.6.
     */
    public string $status = '';

    /**
     * expires (optional, string):  The timestamp after which the server
     * will consider this authorization invalid, encoded in the format
     * specified in [RFC3339].  This field is REQUIRED for objects with
     * "valid" in the "status" field.
     */
    public ?\DateTimeImmutable $expires = null;

    /**
     * challenges (required, array of objects):  For pending authorizations,
     * the challenges that the client can fulfill in order to prove
     * possession of the identifier.  For valid authorizations, the
     * challenge that was validated.  For invalid authorizations, the
     * challenge that was attempted and failed.
     *
     * @var Challenge[]
     */
    public array $challenges = [];

    /**
     * wildcard (optional, boolean):  This field MUST be present and true
     * for authorizations created as a result of a newOrder request
     * containing a DNS identifier with a value that was a wildcard
     * domain name.  For other authorizations, it MUST be absent.
     */
    public bool $wildcard = false;

    /**
     * "The server allocates a new URL for this authorization and returns a
     * 201 (Created) response with the authorization URL in the Location
     * header field" §7.4.1
     *
     * We transfer the value from the header to this field for storage and
     * recall purposes.
     */
    public string $location = '';

    public function __construct()
    {
        $this->identifier = new Identifier();
    }

    /**
     * IdentifierValue returns the Identifier.Value field, adjusted
     * according to the Wildcard field.
     */
    public function identifierValue(): string
    {
        if ($this->wildcard) {
            return '*.' . $this->identifier->value;
        }
        return $this->identifier->value;
    }

    /**
     * fillChallengeFields populates extra fields in the challenge structs so that
     * challenges can be solved without needing a bunch of unnecessary extra state.
     *
     * @throws \RuntimeException
     */
    public function fillChallengeFields(Account $account): void
    {
        if ($account->privateKey === null) {
            throw new \RuntimeException('computing account JWK thumbprint: account has no private key');
        }
        try {
            $accountThumbprint = Jws::jwkThumbprint($account->privateKey);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('computing account JWK thumbprint: %s', $err->getMessage()), 0, $err);
        }
        foreach ($this->challenges as $challenge) {
            $challenge->identifier = $this->identifier;
            if ($challenge->keyAuthorization === '') {
                $challenge->keyAuthorization = $challenge->token . '.' . $accountThumbprint;
            }
        }
    }

    /**
     * applyArray updates the authorization from a server response.
     *
     * @param array<string, mixed> $data
     * @throws \RuntimeException
     */
    public function applyArray(array $data): void
    {
        if (isset($data['identifier']) && \is_array($data['identifier'])) {
            $this->identifier = Identifier::fromArray($data['identifier']);
        }
        if (\array_key_exists('status', $data)) {
            $this->status = (string) $data['status'];
        }
        if (\array_key_exists('expires', $data)) {
            $this->expires = Rfc3339::decode(\is_string($data['expires']) ? $data['expires'] : null);
        }
        if (\array_key_exists('challenges', $data)) {
            $this->challenges = [];
            foreach ((array) $data['challenges'] as $chal) {
                if (\is_array($chal)) {
                    $this->challenges[] = Challenge::fromArray($chal);
                }
            }
        }
        if (\array_key_exists('wildcard', $data)) {
            $this->wildcard = (bool) $data['wildcard'];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $a = new self();
        $a->applyArray($data);
        return $a;
    }
}
