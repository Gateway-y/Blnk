<?php

declare(strict_types=1);

namespace Blnk\Api\Model;

use Blnk\Model\ModelHelpers as CoreModelHelpers;

/**
 * CreateAPIKeyRequest is the request payload of POST /api-keys
 * (Go: api/model/api_key.go). Every field carries `binding:"required"`.
 */
final class CreateAPIKeyRequest implements \JsonSerializable
{
    public string $name = '';

    /** @var string[]|null Go `[]string` (nil until bound) */
    public ?array $scopes = null;

    public string $owner = '';

    /** Go `time.Time` (the zero time is null). */
    public ?\DateTimeImmutable $expiresAt = null;

    /**
     * Binds the JSON body and applies the go-playground `required` tags:
     * name and owner must be non-empty, scopes must be present (an empty
     * list satisfies the tag, as in Go) and expires_at must be a non-zero
     * RFC 3339 time. All failures are reported together, one per line.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch or a required-tag failure
     */
    public static function fromArray(array $data, string $struct = 'CreateAPIKeyRequest'): self
    {
        $r = new self();
        $r->name = JsonBinding::string($data, 'name', $struct);
        $r->scopes = JsonBinding::stringList($data, 'scopes', $struct);
        $r->owner = JsonBinding::string($data, 'owner', $struct);
        $r->expiresAt = JsonBinding::time($data, 'expires_at', $struct);

        $missing = [];
        if ($r->name === '') {
            $missing[] = 'Name';
        }
        if ($r->scopes === null) {
            $missing[] = 'Scopes';
        }
        if ($r->owner === '') {
            $missing[] = 'Owner';
        }
        if ($r->expiresAt === null) {
            $missing[] = 'ExpiresAt';
        }
        if (count($missing) > 0) {
            throw JsonBinding::requiredErrors($struct, $missing);
        }

        return $r;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'scopes' => $this->scopes === null ? null : array_values($this->scopes),
            'owner' => $this->owner,
            'expires_at' => CoreModelHelpers::goTimeString($this->expiresAt),
        ];
    }
}
