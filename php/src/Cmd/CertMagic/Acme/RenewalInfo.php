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
 * RenewalInfo "is a new resource type introduced to ACME protocol.
 * This new resource allows clients to query the server for suggestions
 * on when they should renew certificates."
 *
 * ACME Renewal Information (ARI):
 * https://www.ietf.org/archive/id/draft-ietf-acme-ari-03.html §4.2
 *
 * This is a DRAFT specification and the API is subject to change.
 */
final class RenewalInfo implements \JsonSerializable
{
    /**
     * suggestedWindow (object, required): A JSON object with two keys,
     * "start" and "end", whose values are timestamps, encoded in the
     * format specified in [RFC3339], which bound the window of time
     * in which the CA recommends renewing the certificate.
     */
    public ?\DateTimeImmutable $suggestedWindowStart = null;

    public ?\DateTimeImmutable $suggestedWindowEnd = null;

    /**
     * explanationURL (string, optional): A URL pointing to a page which may
     * explain why the suggested renewal window is what it is.
     */
    public string $explanationURL = '';

    // The following fields are not part of the RenewalInfo object in
    // the ARI spec, but are important for proper conformance to the
    // spec, and are practically useful for implementers:

    /**
     * "The unique identifier is constructed by concatenating the
     * base64url-encoding Section 5 of [RFC4648] of the bytes of the
     * keyIdentifier field of certificate's Authority Key Identifier
     * (AKI) Section 4.2.1.1 of [RFC5280] extension, a literal period,
     * and the base64url-encoding of the bytes of the DER encoding of
     * the certificate's Serial Number (without the tag and length bytes).
     * All trailing "=" characters MUST be stripped from both parts of
     * the unique identifier."
     *
     * We generate this once and store it so the certificate does not
     * need to be stored in its decoded form or decoded multiple times.
     */
    public string $uniqueIdentifier = '';

    /**
     * The next poll time based on the Retry-After response header for
     * the benefit of the caller for scheduling renewals. If specified,
     * GetRenewalInfo should not be called again before this time.
     */
    public ?\DateTimeImmutable $retryAfter = null;

    /**
     * The client should "select a uniform random time within the suggested
     * window." We select this time when getting the renewal info from the
     * server, though this behavior is ambiguous:
     * https://github.com/aarongable/draft-acme-ari/issues/70
     */
    public ?\DateTimeImmutable $selectedTime = null;

    /**
     * NeedsRefresh returns true if the renewal info needs updating.
     * It returns false otherwise, or if the renewal info is empty
     * (window is missing), assuming that there is no ARI available.
     */
    public function needsRefresh(): bool
    {
        if (!$this->hasWindow()) {
            return false;
        }
        if ($this->retryAfter === null) {
            // TODO: this seems like an unlikely condition, but we could be smart in its absence, like based on the window... play it safe for now though and just always be updating I guess
            return true;
        }
        return microtime(true) > Rfc3339::seconds($this->retryAfter);
    }

    /**
     * HasWindow returns true if this ARI has a window. If not,
     * it's likely because ARI is not supported or available.
     */
    public function hasWindow(): bool
    {
        return $this->suggestedWindowStart !== null && $this->suggestedWindowEnd !== null;
    }

    /**
     * SameWindow returns true if this ARI has the same window as the ARI passed in.
     * Note that suggested windows can move in either direction, expand, or contract,
     * so this method compares both start and end values for exact equality.
     */
    public function sameWindow(RenewalInfo $other): bool
    {
        return self::sameTime($this->suggestedWindowStart, $other->suggestedWindowStart)
            && self::sameTime($this->suggestedWindowEnd, $other->suggestedWindowEnd);
    }

    /**
     * @param array<string, mixed> $data
     * @throws \RuntimeException
     */
    public static function fromArray(array $data): self
    {
        $ari = new self();
        $window = (array) ($data['suggestedWindow'] ?? []);
        $ari->suggestedWindowStart = Rfc3339::decode(isset($window['start']) && \is_string($window['start']) ? $window['start'] : null);
        $ari->suggestedWindowEnd = Rfc3339::decode(isset($window['end']) && \is_string($window['end']) ? $window['end'] : null);
        $ari->explanationURL = (string) ($data['explanationURL'] ?? '');
        $ari->uniqueIdentifier = (string) ($data['_uniqueIdentifier'] ?? '');
        $ari->retryAfter = Rfc3339::decode(isset($data['_retryAfter']) && \is_string($data['_retryAfter']) ? $data['_retryAfter'] : null);
        $ari->selectedTime = Rfc3339::decode(isset($data['_selectedTime']) && \is_string($data['_selectedTime']) ? $data['_selectedTime'] : null);
        return $ari;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = [
            'suggestedWindow' => [
                'start' => Rfc3339::encode($this->suggestedWindowStart),
                'end' => Rfc3339::encode($this->suggestedWindowEnd),
            ],
        ];
        if ($this->explanationURL !== '') {
            $out['explanationURL'] = $this->explanationURL;
        }
        if ($this->uniqueIdentifier !== '') {
            $out['_uniqueIdentifier'] = $this->uniqueIdentifier;
        }
        if ($this->retryAfter !== null) {
            $out['_retryAfter'] = Rfc3339::encode($this->retryAfter);
        }
        $out['_selectedTime'] = Rfc3339::encode($this->selectedTime);
        return $out;
    }

    private static function sameTime(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return $a->format('U.u') === $b->format('U.u');
    }
}
