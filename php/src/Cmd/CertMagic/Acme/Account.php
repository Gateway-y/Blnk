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
 * Account represents a set of metadata associated with an account
 * as defined by the ACME spec §7.1.2:
 * https://tools.ietf.org/html/rfc8555#section-7.1.2
 *
 * Users of this package should generally set Contact,
 * TermsOfServiceAgreed, ExternalAccountBinding if relevant,
 * and PrivateKey fields when creating a new account. Other
 * fields are populated by the ACME server.
 */
final class Account implements \JsonSerializable
{
    /**
     * Possible status values. From several spec sections:
     * - Account §7.1.2 (valid, deactivated, revoked)
     * - Order §7.1.3 (pending, ready, processing, valid, invalid)
     * - Authorization §7.1.4 (pending, valid, invalid, deactivated, expired, revoked)
     * - Challenge §7.1.5 (pending, processing, valid, invalid)
     * - Status changes §7.1.6
     */
    public const StatusPending = 'pending';
    public const StatusProcessing = 'processing';
    public const StatusValid = 'valid';
    public const StatusInvalid = 'invalid';
    public const StatusDeactivated = 'deactivated';
    public const StatusExpired = 'expired';
    public const StatusRevoked = 'revoked';
    public const StatusReady = 'ready';

    /**
     * status (required, string):  The status of this account.  Possible
     * values are "valid", "deactivated", and "revoked". The client need
     * NOT set this field when creating a new account.
     */
    public string $status = '';

    /**
     * contact (optional, array of string):  An array of URLs that the
     * server can use to contact the client for issues related to this
     * account.
     *
     * @var string[]
     */
    public array $contact = [];

    /**
     * termsOfServiceAgreed (optional, boolean):  Including this field in a
     * newAccount request, with a value of true, indicates the client's
     * agreement with the terms of service.  This field cannot be updated
     * by the client.
     */
    public bool $termsOfServiceAgreed = false;

    /**
     * externalAccountBinding (optional, object):  Including this field in a
     * newAccount request indicates approval by the holder of an existing
     * non-ACME account to bind that account to this ACME account.
     *
     * Use {@see setExternalAccountBinding()} to set this field's value properly.
     * (Raw JSON object, decoded.)
     *
     * @var array<string, mixed>|null
     */
    public ?array $externalAccountBinding = null;

    /**
     * orders (required, string):  A URL from which a list of orders
     * submitted by this account can be fetched via a POST-as-GET
     * request, as described in Section 7.1.2.1. (Omitted when empty for
     * compatibility with non-compliant servers.)
     */
    public string $orders = '';

    /**
     * In response to new-account, "the server returns this account
     * object in a 201 (Created) response, with the account URL
     * in a Location header field." §7.3
     *
     * We transfer the value from the header to this field for
     * storage and recall purposes.
     */
    public string $location = '';

    /**
     * The private key to the account. Because it is secret, it is
     * not serialized as JSON and must be stored separately (usually
     * a PEM-encoded file).
     *
     * This is a required field when creating a new account.
     */
    public ?\OpenSSLAsymmetricKey $privateKey = null;

    /**
     * SetExternalAccountBinding sets the ExternalAccountBinding field of the account.
     * It only sets the field value; it does not register the account with the CA. (The
     * client parameter is necessary because the EAB encoding depends on the directory.)
     *
     * @throws \RuntimeException
     */
    public function setExternalAccountBinding(Client $client, EAB $eab): void
    {
        $client->provision();

        $macKey = base64_decode(strtr($eab->macKey, '-_', '+/') . str_repeat('=', (4 - \strlen($eab->macKey) % 4) % 4), true);
        if ($macKey === false) {
            throw new \RuntimeException('base64-decoding MAC key: illegal base64 data');
        }
        if ($this->privateKey === null) {
            throw new \RuntimeException('signing EAB content: account has no private key');
        }

        $eabJWS = Jws::jwsEncodeEAB($this->privateKey, $macKey, $eab->keyID, $client->getDirectory()->newAccount);

        $this->externalAccountBinding = json_decode($eabJWS, true);
    }

    /**
     * fromArray decodes an account object (server response or storage file).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $a = new self();
        $a->applyArray($data);
        return $a;
    }

    /**
     * applyArray updates this account from a decoded JSON object (Go:
     * json.Unmarshal into the existing value keeps fields the document does
     * not mention).
     *
     * @param array<string, mixed> $data
     */
    public function applyArray(array $data): void
    {
        if (\array_key_exists('status', $data)) {
            $this->status = (string) $data['status'];
        }
        if (\array_key_exists('contact', $data)) {
            $this->contact = array_map('strval', (array) $data['contact']);
        }
        if (\array_key_exists('termsOfServiceAgreed', $data)) {
            $this->termsOfServiceAgreed = (bool) $data['termsOfServiceAgreed'];
        }
        if (\array_key_exists('externalAccountBinding', $data)) {
            $this->externalAccountBinding = \is_array($data['externalAccountBinding']) ? $data['externalAccountBinding'] : null;
        }
        if (\array_key_exists('orders', $data)) {
            $this->orders = (string) $data['orders'];
        }
        if (\array_key_exists('location', $data)) {
            $this->location = (string) $data['location'];
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = ['status' => $this->status];
        if ($this->contact !== []) {
            $out['contact'] = $this->contact;
        }
        if ($this->termsOfServiceAgreed) {
            $out['termsOfServiceAgreed'] = true;
        }
        if ($this->externalAccountBinding !== null) {
            $out['externalAccountBinding'] = $this->externalAccountBinding;
        }
        if ($this->orders !== '') {
            $out['orders'] = $this->orders;
        }
        if ($this->location !== '') {
            $out['location'] = $this->location;
        }
        return $out;
    }
}
