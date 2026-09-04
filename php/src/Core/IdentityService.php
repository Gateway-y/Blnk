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

namespace Blnk\Core;

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\Filter\QueryFilterSet;
use Blnk\Internal\Filter\QueryOptions;
use Blnk\Internal\Notification\Notification;
use Blnk\Internal\Tokenization\TokenizationException;
use Blnk\Internal\Tokenization\TokenizationMode;
use Blnk\Internal\Tokenization\TokenizationService;
use Blnk\Model\Identity;
use Blnk\Model\ModelHelpers;

/**
 * Port of identity.go: the identity and PII-tokenization methods of the Go
 * `Blnk` struct (plus the package-level `convertToStructFieldName`), composed
 * into {@see Blnk}.
 *
 * Reflection note: Go reads/writes identity fields with
 * `reflect.Value.FieldByName(<GoStructFieldName>)`. The PHP Identity model
 * exposes the same fields as camelCase public properties, so a Go struct field
 * name ("FirstName") maps to the property `lcfirst()` of it ("firstName") —
 * see {@see IdentityService::identityFieldProperty()}.
 */
trait IdentityService
{
    /**
     * postIdentityActions performs actions after an identity has been created.
     * It sends the newly created identity to the search index queue and sends a webhook notification.
     *
     * Go runs this in a goroutine; the PHP port runs it inline. Failures never
     * reach the caller: they are reported through Notification::notifyError.
     */
    protected function postIdentityActions(Identity $identity): void
    {
        try {
            $this->queue->queueIndexData($identity->identityID, 'identities', $identity);
        } catch (\Throwable $err) {
            Notification::notifyError($err);
        }
        try {
            $this->sendWebhook(new NewWebhook('identity.created', $identity));
        } catch (\Throwable $err) {
            Notification::notifyError($err);
        }
    }

    /**
     * CreateIdentity creates a new identity in the database.
     *
     * Parameters:
     * - $identity: The Identity model to be created.
     *
     * Returns the created Identity model.
     *
     * @throws ApiErrorException if the identity could not be created.
     */
    public function createIdentity(Identity $identity): Identity
    {
        $identity = $this->datasource->createIdentity($identity);
        $this->postIdentityActions($identity);
        return $identity;
    }

    /**
     * GetIdentity retrieves an identity by its ID.
     *
     * Parameters:
     * - $id: The ID of the identity to retrieve.
     *
     * @throws ApiErrorException if the identity could not be retrieved.
     */
    public function getIdentity(string $id): Identity
    {
        return $this->datasource->getIdentityByID($id);
    }

    /**
     * GetAllIdentities retrieves all identities from the database.
     *
     * @return Identity[]
     * @throws ApiErrorException if the identities could not be retrieved.
     */
    public function getAllIdentities(): array
    {
        return $this->datasource->getAllIdentities();
    }

    /**
     * GetAllIdentitiesWithFilter retrieves identities using advanced filters.
     *
     * Parameters:
     * - $filters: Filter conditions to apply.
     * - $limit: Maximum number of identities to return.
     * - $offset: Offset for pagination.
     *
     * @return Identity[] Identity models matching the filter criteria.
     * @throws ApiErrorException if the identities could not be retrieved.
     */
    public function getAllIdentitiesWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array
    {
        return $this->datasource->getAllIdentitiesWithFilter($filters, $limit, $offset);
    }

    /**
     * GetAllIdentitiesWithFilterAndOptions retrieves identities with filters, sorting, and optional count.
     *
     * Parameters:
     * - $filters: Filter conditions to apply.
     * - $opts: Query options including sorting and count settings.
     * - $limit: Maximum number of identities to return.
     * - $offset: Offset for pagination.
     *
     * Go returns `([]model.Identity, *int64, error)`.
     *
     * @return array{0: Identity[], 1: int|null} `[$identities, $totalCount]`; the count is null unless `$opts->includeCount`.
     * @throws ApiErrorException if the identities could not be retrieved.
     */
    public function getAllIdentitiesWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array
    {
        return $this->datasource->getAllIdentitiesWithFilterAndOptions($filters, $opts, $limit, $offset);
    }

    /**
     * UpdateIdentity updates an existing identity in the database.
     *
     * Parameters:
     * - $identity: The Identity model to be updated.
     *
     * @throws ApiErrorException if the identity could not be updated.
     */
    public function updateIdentity(Identity $identity): void
    {
        $this->datasource->updateIdentity($identity);
    }

    /**
     * DeleteIdentity deletes an identity by its ID.
     *
     * Parameters:
     * - $id: The ID of the identity to delete.
     *
     * @throws ApiErrorException if the identity could not be deleted.
     */
    public function deleteIdentity(string $id): void
    {
        $this->datasource->deleteIdentity($id);
    }

    /**
     * TokenizeIdentityField tokenizes a specific field in an identity.
     *
     * Parameters:
     * - $identityID: The ID of the identity.
     * - $fieldName: The name of the field to tokenize.
     *
     * @throws \RuntimeException if the field could not be tokenized
     *         ("field %s is not tokenizable", "field %s is already tokenized",
     *         "field %s not found or cannot be set").
     * @throws TokenizationException|ApiErrorException on tokenization / datasource failure.
     */
    public function tokenizeIdentityField(string $identityID, string $fieldName): void
    {
        // Convert field name to struct field format for reflection
        $structFieldName = self::convertToStructFieldName($fieldName);

        // Check if field is tokenizable
        $validField = false;
        foreach (TokenizationService::TokenizableFields as $field) {
            if ($field === $structFieldName) {
                $validField = true;
                break;
            }
        }

        if (!$validField) {
            throw new \RuntimeException(sprintf('field %s is not tokenizable', $fieldName));
        }

        // Get the identity
        $identity = $this->getIdentity($identityID);

        // Check if field is already tokenized using the original field name
        // as IsFieldTokenized will handle the conversion internally
        if ($identity->isFieldTokenized($fieldName)) {
            throw new \RuntimeException(sprintf('field %s is already tokenized', $fieldName));
        }

        // Get the field value using reflection with struct field name
        $property = self::identityFieldProperty($structFieldName);

        if ($property === null) {
            throw new \RuntimeException(sprintf('field %s not found or cannot be set', $fieldName));
        }

        // Get the string value
        $strVal = self::identityFieldString($identity, $property);

        // Tokenize the value
        $token = $this->tokenizer->tokenizeWithMode($strVal, TokenizationMode::FormatPreservingMode);

        // Set the tokenized value
        $identity->{$property} = $token;

        // Mark the field as tokenized using the original field name
        // as MarkFieldAsTokenized will handle the conversion internally
        $identity->markFieldAsTokenized($fieldName);

        // Update the identity
        $this->updateIdentity($identity);
    }

    /**
     * DetokenizeIdentityField detokenizes a specific field in an identity.
     *
     * Parameters:
     * - $identityID: The ID of the identity.
     * - $fieldName: The name of the field to detokenize.
     *
     * Returns the detokenized field value.
     *
     * @throws \RuntimeException if the field could not be detokenized
     *         ("field %s is not tokenized[. Metadata: %s]", "field %s not found").
     * @throws TokenizationException|ApiErrorException on detokenization / datasource failure.
     */
    public function detokenizeIdentityField(string $identityID, string $fieldName): string
    {
        // Get the identity
        $identity = $this->getIdentity($identityID);

        // Try both original and capitalized field name
        $structFieldName = self::convertToStructFieldName($fieldName);

        // Check if field is tokenized
        if (!$identity->isFieldTokenized($fieldName) && !$identity->isFieldTokenized($structFieldName)) {
            // Debug info
            if ($identity->metaData !== null) {
                $metaStr = json_encode(ModelHelpers::mapToJson($identity->metaData));
                if ($metaStr === false) {
                    $metaStr = ''; // Go: metaStr, _ := json.Marshal(...) — a nil []byte prints as ""
                }
                throw new \RuntimeException(sprintf('field %s is not tokenized. Metadata: %s', $fieldName, $metaStr));
            }
            throw new \RuntimeException(sprintf('field %s is not tokenized', $fieldName));
        }

        // Get the field value using reflection - try both field name versions
        $property = self::identityFieldProperty($structFieldName);

        if ($property === null) {
            $property = self::identityFieldProperty($fieldName);
            if ($property === null) {
                throw new \RuntimeException(sprintf('field %s not found', $fieldName));
            }
        }

        // Get the tokenized value
        $tokenVal = self::identityFieldString($identity, $property);

        // Detokenize the value
        return $this->tokenizer->detokenize($tokenVal);
    }

    /**
     * TokenizeIdentity tokenizes all specified fields in an identity.
     *
     * Parameters:
     * - $identityID: The ID of the identity.
     * - $fields: The names of the fields to tokenize.
     *
     * @param string[] $fields
     * @throws \Throwable if any field could not be tokenized.
     */
    public function tokenizeIdentity(string $identityID, array $fields): void
    {
        foreach ($fields as $field) {
            $this->tokenizeIdentityField($identityID, (string) $field);
        }
    }

    /**
     * DetokenizeIdentity detokenizes and returns all tokenized fields in an identity.
     *
     * Parameters:
     * - $identityID: The ID of the identity.
     *
     * @return array<string, string> A map of field names to their detokenized values.
     * @throws \Throwable if any field could not be detokenized.
     */
    public function detokenizeIdentity(string $identityID): array
    {
        // Get the identity
        $identity = $this->getIdentity($identityID);

        $result = [];

        // Check each tokenized field in metadata. The map is stored as
        // map[string]bool in memory but unmarshals from the database as
        // map[string]interface{}, so both shapes must be handled (in PHP both
        // are arrays; only boolean entries are considered, as Go does for the
        // decoded shape).
        if ($identity->metaData !== null) {
            $tokenized = [];
            $fields = $identity->metaData['tokenized_fields'] ?? null;
            if (\is_array($fields)) {
                foreach ($fields as $fieldName => $val) {
                    if (\is_bool($val)) {
                        $tokenized[(string) $fieldName] = $val;
                    }
                }
            }
            foreach ($tokenized as $fieldName => $isTokenized) {
                if ($isTokenized) {
                    $originalValue = $this->detokenizeIdentityField($identityID, $fieldName);
                    $result[$fieldName] = $originalValue;
                }
            }
        }

        return $result;
    }

    /**
     * TokenizeAllPII tokenizes all eligible PII fields in an identity.
     *
     * Parameters:
     * - $identityID: The ID of the identity.
     *
     * Go always returns nil: errors for fields that might already be
     * tokenized are ignored.
     */
    public function tokenizeAllPII(string $identityID): void
    {
        foreach (TokenizationService::TokenizableFields as $field) {
            // Ignore errors for fields that might already be tokenized
            try {
                $this->tokenizeIdentityField($identityID, $field);
            } catch (\Throwable) {
                // ignored, as in Go (`_ = l.TokenizeIdentityField(...)`)
            }
        }
    }

    /**
     * GetDetokenizedIdentity returns a copy of the identity with all fields detokenized.
     * Note: This does not modify the stored identity.
     *
     * Parameters:
     * - $identityID: The ID of the identity.
     *
     * Returns the detokenized Identity model.
     *
     * Divergence (documented): Go only detokenizes when
     * MetaData["tokenized_fields"] is the in-memory `map[string]bool` shape
     * (the type assertion fails for the `map[string]interface{}` shape produced
     * by JSON decoding). PHP cannot tell the two apart, so any array of boolean
     * flags is honored.
     *
     * @throws \Throwable if the identity could not be detokenized.
     */
    public function getDetokenizedIdentity(string $identityID): Identity
    {
        // Get the identity
        $identity = $this->getIdentity($identityID);

        // Create a copy
        $detokenizedIdentity = clone $identity;

        // Detokenize all tokenized fields
        if ($identity->metaData !== null) {
            $tokenizedFields = $identity->metaData['tokenized_fields'] ?? null;
            if (\is_array($tokenizedFields)) {
                foreach ($tokenizedFields as $field => $isTokenized) {
                    if ($isTokenized === true) {
                        $originalValue = $this->detokenizeIdentityField($identityID, (string) $field);

                        // Set the original value in the copy
                        $property = self::identityFieldProperty((string) $field);
                        if ($property !== null) {
                            $detokenizedIdentity->{$property} = $originalValue;
                        }
                    }
                }
            }
        }

        // Create a clean copy of metadata without tokenized_fields
        if ($detokenizedIdentity->metaData !== null) {
            $newMetaData = [];
            foreach ($detokenizedIdentity->metaData as $k => $v) {
                if ($k !== 'tokenized_fields') {
                    $newMetaData[$k] = $v;
                }
            }
            $detokenizedIdentity->metaData = $newMetaData;
        }

        return $detokenizedIdentity;
    }

    /**
     * convertToStructFieldName ensures consistent field name format by returning
     * the Go struct field name (typically capitalized) for the given input
     */
    private static function convertToStructFieldName(string $fieldName): string
    {
        // For simple cases, just capitalize the first letter
        if (\strlen($fieldName) > 0) {
            return strtoupper(substr($fieldName, 0, 1)) . substr($fieldName, 1);
        }
        return $fieldName;
    }

    /**
     * identityFieldProperty is the port of `reflect.ValueOf(identity).Elem().FieldByName(name)`
     * on model.Identity: it maps a Go struct field name ("FirstName") to the
     * Identity property holding that field ("firstName"). Returns null when no
     * such field exists (Go: `!fieldVal.IsValid()`).
     */
    private static function identityFieldProperty(string $structFieldName): ?string
    {
        if ($structFieldName === '') {
            return null;
        }
        $property = lcfirst($structFieldName);
        if (!property_exists(Identity::class, $property)) {
            return null;
        }
        return $property;
    }

    /**
     * identityFieldString is the port of `reflect.Value.String()` on an identity
     * field: string fields yield their value; any other kind yields the
     * "<T Value>" placeholder Go's reflect produces.
     */
    private static function identityFieldString(Identity $identity, string $property): string
    {
        $value = $identity->{$property};
        if (\is_string($value)) {
            return $value;
        }
        return sprintf('<%s Value>', get_debug_type($value));
    }
}
