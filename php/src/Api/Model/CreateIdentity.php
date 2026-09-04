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

namespace Blnk\Api\Model;

use Blnk\Model\Identity;
use Blnk\Model\ModelHelpers as CoreModelHelpers;

/**
 * CreateIdentity is the identity creation payload shape
 * (Go: api/model/identity.go `CreateIdentity`). The Go handlers bind
 * POST /identities straight into the domain `model.Identity`; this struct is
 * kept for API parity.
 */
final class CreateIdentity implements \JsonSerializable
{
    public string $identityType = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $otherNames = '';

    public string $gender = '';

    /** Go `time.Time` (the zero time is null). */
    public ?\DateTimeImmutable $dob = null;

    public string $emailAddress = '';

    public string $phoneNumber = '';

    public string $nationality = '';

    public string $organizationName = '';

    public string $category = '';

    public string $street = '';

    public string $country = '';

    public string $state = '';

    public string $postCode = '';

    public string $city = '';

    /** Go `time.Time` (the zero time is null). */
    public ?\DateTimeImmutable $createdAt = null;

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'CreateIdentity'): self
    {
        $i = new self();
        $i->identityType = JsonBinding::string($data, 'identity_type', $struct);
        $i->firstName = JsonBinding::string($data, 'first_name', $struct);
        $i->lastName = JsonBinding::string($data, 'last_name', $struct);
        $i->otherNames = JsonBinding::string($data, 'other_names', $struct);
        $i->gender = JsonBinding::string($data, 'gender', $struct);
        $i->dob = JsonBinding::time($data, 'dob', $struct);
        $i->emailAddress = JsonBinding::string($data, 'email_address', $struct);
        $i->phoneNumber = JsonBinding::string($data, 'phone_number', $struct);
        $i->nationality = JsonBinding::string($data, 'nationality', $struct);
        $i->organizationName = JsonBinding::string($data, 'organization_name', $struct);
        $i->category = JsonBinding::string($data, 'category', $struct);
        $i->street = JsonBinding::string($data, 'street', $struct);
        $i->country = JsonBinding::string($data, 'country', $struct);
        $i->state = JsonBinding::string($data, 'state', $struct);
        $i->postCode = JsonBinding::string($data, 'post_code', $struct);
        $i->city = JsonBinding::string($data, 'city', $struct);
        $i->createdAt = JsonBinding::time($data, 'created_at', $struct);
        $i->metaData = JsonBinding::map($data, 'meta_data', $struct);

        return $i;
    }

    /**
     * toIdentity copies the payload into the domain Identity (the Go struct
     * has no converter; provided for convenience, field for field).
     */
    public function toIdentity(): Identity
    {
        $identity = new Identity();
        $identity->identityType = $this->identityType;
        $identity->firstName = $this->firstName;
        $identity->lastName = $this->lastName;
        $identity->otherNames = $this->otherNames;
        $identity->gender = $this->gender;
        $identity->dob = $this->dob;
        $identity->emailAddress = $this->emailAddress;
        $identity->phoneNumber = $this->phoneNumber;
        $identity->nationality = $this->nationality;
        $identity->organizationName = $this->organizationName;
        $identity->category = $this->category;
        $identity->street = $this->street;
        $identity->country = $this->country;
        $identity->state = $this->state;
        $identity->postCode = $this->postCode;
        $identity->city = $this->city;
        $identity->createdAt = $this->createdAt;
        $identity->metaData = $this->metaData;

        return $identity;
    }

    public function jsonSerialize(): array
    {
        return [
            'identity_type' => $this->identityType,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'other_names' => $this->otherNames,
            'gender' => $this->gender,
            'dob' => CoreModelHelpers::goTimeString($this->dob),
            'email_address' => $this->emailAddress,
            'phone_number' => $this->phoneNumber,
            'nationality' => $this->nationality,
            'organization_name' => $this->organizationName,
            'category' => $this->category,
            'street' => $this->street,
            'country' => $this->country,
            'state' => $this->state,
            'post_code' => $this->postCode,
            'city' => $this->city,
            'created_at' => CoreModelHelpers::goTimeString($this->createdAt),
            'meta_data' => CoreModelHelpers::mapToJson($this->metaData),
        ];
    }
}
