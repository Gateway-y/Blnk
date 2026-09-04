<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * Identity is a person or organization attached to balances/accounts
 * (Go: model.Identity, identity.go).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class Identity implements \JsonSerializable
{
    public string $identityID = '';

    public string $identityType = '';

    public string $organizationName = '';

    public string $category = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $otherNames = '';

    public string $gender = '';

    public string $emailAddress = '';

    public string $phoneNumber = '';

    public string $nationality = '';

    public string $street = '';

    public string $country = '';

    public string $state = '';

    public string $postCode = '';

    public string $city = '';

    public ?\DateTimeImmutable $dob = null;

    public ?\DateTimeImmutable $createdAt = null;

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    /**
     * convertToStructFieldName ensures consistent field name format by returning
     * the Go struct field name (typically capitalized) for the given input.
     */
    private static function convertToStructFieldName(string $fieldName): string
    {
        if (\strlen($fieldName) > 0) {
            return strtoupper(substr($fieldName, 0, 1)) . substr($fieldName, 1);
        }
        return $fieldName;
    }

    /**
     * IsFieldTokenized reports whether the given field was recorded as
     * tokenized in MetaData["tokenized_fields"].
     *
     * Go inspects both map[string]bool and map[string]interface{} shapes; after
     * JSON decoding in PHP both collapse to an array, so the
     * map[string]interface{} semantics apply: the struct-cased key is consulted
     * first (returning its boolean even when false), then the raw field name.
     */
    public function isFieldTokenized(string $fieldName): bool
    {
        if ($this->metaData === null) {
            return false;
        }

        $structFieldName = self::convertToStructFieldName($fieldName);

        if (!\array_key_exists('tokenized_fields', $this->metaData)) {
            return false;
        }
        $tokenizedFieldsRaw = $this->metaData['tokenized_fields'];

        if (\is_array($tokenizedFieldsRaw)) {
            // Check both with and without conversion
            if (\array_key_exists($structFieldName, $tokenizedFieldsRaw)) {
                $val = $tokenizedFieldsRaw[$structFieldName];
                if (\is_bool($val)) {
                    return $val;
                }
            }
            if (\array_key_exists($fieldName, $tokenizedFieldsRaw)) {
                $val = $tokenizedFieldsRaw[$fieldName];
                if (\is_bool($val)) {
                    return $val;
                }
            }
        }

        return false;
    }

    /**
     * MarkFieldAsTokenized records the (struct-cased) field name as tokenized
     * in MetaData["tokenized_fields"], preserving previously recorded fields
     * whose values are booleans.
     */
    public function markFieldAsTokenized(string $fieldName): void
    {
        if ($this->metaData === null) {
            $this->metaData = [];
        }

        $structFieldName = self::convertToStructFieldName($fieldName);

        $existingTokenizedFields = [];

        // First check if tokenized_fields exists and what type it is
        if (\array_key_exists('tokenized_fields', $this->metaData)) {
            $tokenizedFieldsRaw = $this->metaData['tokenized_fields'];
            if (\is_array($tokenizedFieldsRaw)) {
                // Handle both map[string]bool and map[string]interface{} shapes
                // (common when unmarshalled from JSON): keep boolean values only.
                foreach ($tokenizedFieldsRaw as $field => $val) {
                    if (\is_bool($val)) {
                        $existingTokenizedFields[(string) $field] = $val;
                    }
                }
            }
        }

        $existingTokenizedFields[$structFieldName] = true;
        $this->metaData['tokenized_fields'] = $existingTokenizedFields;
    }

    public static function fromArray(array $data): self
    {
        $i = new self();
        $i->identityID = (string) ($data['identity_id'] ?? '');
        $i->identityType = (string) ($data['identity_type'] ?? '');
        $i->organizationName = (string) ($data['organization_name'] ?? '');
        $i->category = (string) ($data['category'] ?? '');
        $i->firstName = (string) ($data['first_name'] ?? '');
        $i->lastName = (string) ($data['last_name'] ?? '');
        $i->otherNames = (string) ($data['other_names'] ?? '');
        $i->gender = (string) ($data['gender'] ?? '');
        $i->emailAddress = (string) ($data['email_address'] ?? '');
        $i->phoneNumber = (string) ($data['phone_number'] ?? '');
        $i->nationality = (string) ($data['nationality'] ?? '');
        $i->street = (string) ($data['street'] ?? '');
        $i->country = (string) ($data['country'] ?? '');
        $i->state = (string) ($data['state'] ?? '');
        $i->postCode = (string) ($data['post_code'] ?? '');
        $i->city = (string) ($data['city'] ?? '');
        $i->dob = ModelHelpers::parseTime($data['dob'] ?? null);
        $i->createdAt = ModelHelpers::parseTime($data['created_at'] ?? null);
        $metaData = $data['meta_data'] ?? null;
        $i->metaData = \is_array($metaData) ? $metaData : null;
        return $i;
    }

    public function jsonSerialize(): array
    {
        return [
            'identity_id' => $this->identityID,
            'identity_type' => $this->identityType,
            'organization_name' => $this->organizationName,
            'category' => $this->category,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'other_names' => $this->otherNames,
            'gender' => $this->gender,
            'email_address' => $this->emailAddress,
            'phone_number' => $this->phoneNumber,
            'nationality' => $this->nationality,
            'street' => $this->street,
            'country' => $this->country,
            'state' => $this->state,
            'post_code' => $this->postCode,
            'city' => $this->city,
            'dob' => ModelHelpers::goTimeString($this->dob),
            'created_at' => ModelHelpers::goTimeString($this->createdAt),
            'meta_data' => ModelHelpers::mapToJson($this->metaData),
        ];
    }
}
