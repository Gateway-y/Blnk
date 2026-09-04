<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * APIKey is an authentication key with scopes and expiry
 * (Go: model.APIKey, api_key.go).
 *
 * The Go file's standalone funcs GenerateKey and NewAPIKey live here as static
 * methods (PORTING.md: standalone funcs land on an existing class when one
 * fits).
 */
final class APIKey implements \JsonSerializable
{
    public string $apiKeyID = '';

    public string $key = '';

    public string $name = '';

    public string $ownerID = '';

    /**
     * Go: `[]string` — a nil slice marshals as null, so the property is
     * nullable.
     *
     * @var string[]|null
     */
    public ?array $scopes = null;

    public ?\DateTimeImmutable $expiresAt = null;

    public ?\DateTimeImmutable $createdAt = null;

    public ?\DateTimeImmutable $lastUsedAt = null;

    public bool $isRevoked = false;

    /** Go: *time.Time `json:"revoked_at,omitempty"`. */
    public ?\DateTimeImmutable $revokedAt = null;

    /**
     * GenerateKey creates a new secure API key.
     *
     * Go: 32 bytes from crypto/rand, base64.URLEncoding (URL-safe alphabet,
     * padded) — mirrored with random_bytes + strtr, keeping the '=' padding.
     *
     * @throws \Random\RandomException when secure random bytes are unavailable
     *                                 (Go returns the rand.Read error)
     */
    public static function generateKey(): string
    {
        $b = random_bytes(32);
        return strtr(base64_encode($b), '+/', '-_');
    }

    /**
     * NewAPIKey creates a new API key instance.
     *
     * @param string[] $scopes
     */
    public static function newAPIKey(string $name, string $ownerID, ?array $scopes, \DateTimeImmutable $expiresAt): self
    {
        $key = self::generateKey();

        $apiKey = new self();
        $apiKey->apiKeyID = ModelHelpers::generateUUIDWithSuffix('api_key');
        $apiKey->key = $key;
        $apiKey->name = $name;
        $apiKey->ownerID = $ownerID;
        $apiKey->scopes = $scopes;
        $apiKey->expiresAt = $expiresAt;
        $apiKey->createdAt = new \DateTimeImmutable('now');
        return $apiKey;
    }

    /**
     * IsValid checks if the API key is valid.
     */
    public function isValid(): bool
    {
        $now = new \DateTimeImmutable('now');
        // Go: !k.IsRevoked && now.Before(k.ExpiresAt) — a zero (null) expiry is
        // never after "now", so the key is invalid.
        return !$this->isRevoked && $this->expiresAt !== null && $now < $this->expiresAt;
    }

    /**
     * HasScope checks if the API key has the required scope.
     */
    public function hasScope(string $scope): bool
    {
        foreach ($this->scopes ?? [] as $s) {
            if ($s === $scope) {
                return true;
            }
        }
        return false;
    }

    public static function fromArray(array $data): self
    {
        $k = new self();
        $k->apiKeyID = (string) ($data['api_key_id'] ?? '');
        $k->key = (string) ($data['key'] ?? '');
        $k->name = (string) ($data['name'] ?? '');
        $k->ownerID = (string) ($data['owner_id'] ?? '');
        $scopes = $data['scopes'] ?? null;
        $k->scopes = \is_array($scopes) ? array_map(strval(...), array_values($scopes)) : null;
        $k->expiresAt = ModelHelpers::parseTime($data['expires_at'] ?? null);
        $k->createdAt = ModelHelpers::parseTime($data['created_at'] ?? null);
        $k->lastUsedAt = ModelHelpers::parseTime($data['last_used_at'] ?? null);
        $k->isRevoked = (bool) ($data['is_revoked'] ?? false);
        $k->revokedAt = ModelHelpers::parseTime($data['revoked_at'] ?? null);
        return $k;
    }

    public function jsonSerialize(): array
    {
        $out = [];
        $out['api_key_id'] = $this->apiKeyID;
        $out['key'] = $this->key;
        $out['name'] = $this->name;
        $out['owner_id'] = $this->ownerID;
        $out['scopes'] = $this->scopes === null ? null : array_values($this->scopes);
        $out['expires_at'] = ModelHelpers::goTimeString($this->expiresAt);
        $out['created_at'] = ModelHelpers::goTimeString($this->createdAt);
        $out['last_used_at'] = ModelHelpers::goTimeString($this->lastUsedAt);
        $out['is_revoked'] = $this->isRevoked;
        if ($this->revokedAt !== null) { // omitempty (*time.Time)
            $out['revoked_at'] = ModelHelpers::goTimeString($this->revokedAt);
        }
        return $out;
    }
}
