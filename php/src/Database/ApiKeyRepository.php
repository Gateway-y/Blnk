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

namespace Blnk\Database;

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\Log;
use Blnk\Model\APIKey;

/**
 * Port of database/api_key.go: the API-key methods of `Datasource`, plus the
 * file's package-level helpers (`hashAPIKey`, `verifyAPIKey`, `getKeyPrefix`,
 * `getAPIKeyCacheKey`) as private methods.
 *
 * The Go sentinel errors `ErrAPIKeyNotFound` / `ErrInvalidAPIKey` are the
 * exception classes {@see ApiKeyNotFoundException} / {@see InvalidApiKeyException}.
 *
 * Composed into {@see Datasource}; uses `$this->conn` (\PDO) and
 * `$this->cache` (?CacheInterface). The Go methods return raw errors: PDO
 * failures surface as {@see DatabaseException} carrying the driver message.
 */
trait ApiKeyRepository
{
    /**
     * hashAPIKey creates a bcrypt hash of the API key for secure storage.
     * bcrypt includes salt and is resistant to brute-force attacks.
     *
     * Go: `bcrypt.GenerateFromPassword([]byte(key), bcrypt.DefaultCost)` —
     * DefaultCost is 10 (PHP 8.4's own default rose to 12, so it is pinned).
     *
     * @throws \ValueError|\RuntimeException when hashing fails
     */
    private function hashAPIKey(string $key): string
    {
        $hash = password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]);
        if (!\is_string($hash) || $hash === '') {
            throw new \RuntimeException('bcrypt: failed to hash API key');
        }
        return $hash;
    }

    /**
     * verifyAPIKey compares a plaintext key with a bcrypt hash.
     */
    private function verifyAPIKey(string $hashedKey, string $plainKey): bool
    {
        return password_verify($plainKey, $hashedKey);
    }

    /**
     * getKeyPrefix extracts the first 16 characters of the key for efficient lookup.
     */
    private function getKeyPrefix(string $key): string
    {
        if (\strlen($key) >= 16) {
            return substr($key, 0, 16);
        }
        return $key;
    }

    /**
     * getAPIKeyCacheKey generates a cache key for an API key lookup
     * Uses the hashed key to ensure consistent cache keys
     */
    private function getAPIKeyCacheKey(string $hashedKey): string
    {
        return sprintf('api_key:hash:%s', $hashedKey);
    }

    /**
     * CreateAPIKey creates a new API key with the specified parameters and stores it in the database.
     * The key is hashed using bcrypt before storage for security. The plain text key is returned ONLY during creation
     * and should be displayed to the user immediately as it cannot be retrieved later.
     *
     * Parameters:
     * - name: A human-readable name for the API key.
     * - ownerID: The ID of the user or entity that owns this API key.
     * - scopes: A slice of permission scopes that this API key grants access to.
     * - expiresAt: The timestamp when this API key will expire.
     *
     * Returns:
     * - *model.APIKey: The created API key object with the PLAIN TEXT key (store this immediately, it won't be retrievable later).
     * - error: An error if the API key creation fails during generation or database insertion.
     *
     * @param string[] $scopes
     *
     * @throws ApiErrorException
     * @throws \Random\RandomException when secure random bytes are unavailable (Go: the rand.Read error)
     */
    public function createAPIKey(string $name, string $ownerID, array $scopes, \DateTimeImmutable $expiresAt): APIKey
    {
        // Generate the API key with plain text
        $apiKey = APIKey::newAPIKey($name, $ownerID, $scopes, $expiresAt);

        // Store the plain text key to return to the user
        $plainTextKey = $apiKey->key;

        // Extract prefix for efficient lookup
        $keyPrefix = $this->getKeyPrefix($plainTextKey);

        // Hash the key using bcrypt before storing in database
        try {
            $hashedKey = $this->hashAPIKey($plainTextKey);
        } catch (\Throwable $e) {
            throw new DatabaseException(sprintf('failed to hash API key: %s', $e->getMessage()), null, null, $e);
        }
        $apiKey->key = $hashedKey;

        $query = '
		INSERT INTO blnk.api_keys (api_key_id, key, key_prefix, name, owner_id, scopes, expires_at, created_at, last_used_at, is_revoked)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
	';

        // (scopes: pq.StringArray — nil is NULL, empty is '{}'; last_used_at: a
        // Go time.Time value, the zero time on creation, never NULL.)
        try {
            PgStatement::execute($this->conn, $query, [
                $apiKey->apiKeyID,
                $apiKey->key, // This is now the bcrypt hashed key
                $keyPrefix,
                $apiKey->name,
                $apiKey->ownerID,
                PqEncoder::textArray($apiKey->scopes),
                PqEncoder::time($apiKey->expiresAt),
                PqEncoder::time($apiKey->createdAt),
                PqEncoder::time($apiKey->lastUsedAt),
                $apiKey->isRevoked,
            ]);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        // Return the API key with the PLAIN TEXT key for the user
        // This is the ONLY time they'll see this key
        $apiKey->key = $plainTextKey;
        return $apiKey;
    }

    /**
     * GetAPIKey retrieves an API key from the database using its key string.
     * The provided key's prefix is used for efficient lookup, then bcrypt verifies the full key.
     * Results are cached for 5 minutes to improve performance.
     * This function is typically used for authentication and authorization purposes.
     *
     * Parameters:
     * - key: The PLAIN TEXT API key string to authenticate.
     *
     * Returns:
     * - *model.APIKey: The API key object if found (with hashed key, not plain text).
     * - error: Returns ErrAPIKeyNotFound if the key doesn't exist, or other database errors if the query fails.
     *
     * @throws ApiKeyNotFoundException when no stored key verifies against the given one
     * @throws ApiErrorException
     */
    public function getAPIKey(string $key): APIKey
    {
        // Extract prefix for efficient lookup
        $keyPrefix = $this->getKeyPrefix($key);
        $cacheKey = $this->getAPIKeyCacheKey($keyPrefix);

        // Try to get from cache first
        if ($this->cache !== null) {
            $apiKey = null;
            try {
                $this->cache->get($cacheKey, $apiKey);
            } catch (\Throwable) {
                $apiKey = null; // Go: a cache error simply skips the cached path
            }
            if ($apiKey instanceof APIKey && $apiKey->key !== '') {
                // Verify the cached key matches using bcrypt
                if ($this->verifyAPIKey($apiKey->key, $key)) {
                    return $apiKey;
                }
            }
        }

        // Cache miss - query the database using key_prefix for efficient lookup
        $query = '
		SELECT api_key_id, key, name, owner_id, scopes, expires_at, created_at, last_used_at, is_revoked, revoked_at
		FROM blnk.api_keys
		WHERE key_prefix = ?
	';

        try {
            $rows = PgStatement::execute($this->conn, $query, [$keyPrefix]);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        // Check each matching key with bcrypt
        try {
            while (($row = $rows->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $candidate = $this->rowToAPIKey($row);

                // Verify the key using bcrypt
                if ($this->verifyAPIKey($candidate->key, $key)) {
                    // Cache the verified key
                    if ($this->cache !== null) {
                        try {
                            $this->cache->set($cacheKey, $candidate, 5 * 60);
                        } catch (\Throwable $err) {
                            Log::get()->warning('failed to cache API key', ['error' => $err->getMessage()]);
                        }
                    }
                    $rows->closeCursor();
                    return $candidate;
                }
            }
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        throw new ApiKeyNotFoundException();
    }

    /**
     * Maps an `api_key_id, key, name, owner_id, scopes, expires_at, created_at,
     * last_used_at, is_revoked, revoked_at` row into an APIKey — the
     * `rows.Scan(&apiKey.APIKeyID, ..., &scopes, ...)` + `apiKey.Scopes = []string(scopes)`
     * of the Go code (`revoked_at` is a `*time.Time`, so NULL stays null).
     *
     * @param array<string, mixed> $row
     *
     * @throws DatabaseException on a malformed timestamp / array value (Go: the raw scan error)
     */
    private function rowToAPIKey(array $row): APIKey
    {
        $apiKey = new APIKey();
        try {
            $apiKey->apiKeyID = RowScanner::toString($row['api_key_id'] ?? null);
            $apiKey->key = RowScanner::toString($row['key'] ?? null);
            $apiKey->name = RowScanner::toString($row['name'] ?? null);
            $apiKey->ownerID = RowScanner::toString($row['owner_id'] ?? null);
            $apiKey->scopes = RowScanner::toStringList($row['scopes'] ?? null);
            $apiKey->expiresAt = RowScanner::toTime($row['expires_at'] ?? null);
            $apiKey->createdAt = RowScanner::toTime($row['created_at'] ?? null);
            $apiKey->lastUsedAt = RowScanner::toTime($row['last_used_at'] ?? null);
            $apiKey->isRevoked = RowScanner::toBool($row['is_revoked'] ?? null);
            $apiKey->revokedAt = RowScanner::toTime($row['revoked_at'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new DatabaseException($e->getMessage(), null, null, $e);
        }
        return $apiKey;
    }

    /**
     * RevokeAPIKey marks an API key as revoked in the database, preventing its future use.
     * The function updates the is_revoked flag to true and sets the revoked_at timestamp.
     * It also invalidates the cache entry for the revoked key to ensure immediate effect.
     * Only the owner of the API key can revoke it.
     *
     * Parameters:
     * - id: The unique identifier of the API key to revoke.
     * - ownerID: The ID of the owner, used to ensure only the owner can revoke their keys.
     *
     * Returns:
     *   - error: Returns ErrAPIKeyNotFound if the key doesn't exist or doesn't belong to the owner,
     *     or other database errors if the update operation fails.
     *
     * @throws ApiKeyNotFoundException
     * @throws ApiErrorException
     */
    public function revokeAPIKey(string $id, string $ownerID): void
    {
        // First, get the API key to retrieve its hashed key for cache invalidation
        $getQuery = '
		SELECT key
		FROM blnk.api_keys
		WHERE api_key_id = ? AND owner_id = ?
	';

        try {
            $hashedKey = PgStatement::execute($this->conn, $getQuery, [$id, $ownerID])->fetchColumn();
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }
        if ($hashedKey === false) {
            throw new ApiKeyNotFoundException();
        }

        // Update the API key to mark it as revoked
        $updateQuery = '
		UPDATE blnk.api_keys
		SET is_revoked = true, revoked_at = ?
		WHERE api_key_id = ? AND owner_id = ?
	';

        try {
            $result = PgStatement::execute($this->conn, $updateQuery, [PqEncoder::now(), $id, $ownerID]);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        $rows = $result->rowCount();

        if ($rows === 0) {
            throw new ApiKeyNotFoundException();
        }

        // Invalidate the cache entry for this API key
        // Note: We need to get the key_prefix to invalidate the correct cache entry
        $getPrefixQuery = 'SELECT key_prefix FROM blnk.api_keys WHERE api_key_id = ?';
        $keyPrefix = null; // sql.NullString
        try {
            $keyPrefix = PgStatement::execute($this->conn, $getPrefixQuery, [$id])->fetchColumn();
        } catch (\PDOException) {
            $keyPrefix = null; // Go: `_ = ...Scan(&keyPrefix)` ignores the error
        }

        if ($keyPrefix !== null && $keyPrefix !== false && $this->cache !== null) {
            $cacheKey = $this->getAPIKeyCacheKey(RowScanner::toString($keyPrefix));
            try {
                $this->cache->delete($cacheKey);
            } catch (\Throwable $err) {
                // Log the error, but don't fail the revocation
                Log::get()->warning('failed to invalidate API key cache', ['error' => $err->getMessage()]);
            }
        }
    }

    /**
     * UpdateLastUsed updates the last_used_at timestamp for an API key to the current time.
     * This function is typically called during authentication to track API key usage patterns.
     * Note: This does NOT invalidate the cache to avoid performance overhead on every request.
     * The cached version will still work for authentication, and the timestamp will be updated
     * in the database for auditing purposes.
     *
     * Parameters:
     * - id: The unique identifier of the API key to update.
     *
     * Returns:
     * - error: An error if the database update operation fails.
     *
     * @throws ApiErrorException
     */
    public function updateLastUsed(string $id): void
    {
        $query = '
		UPDATE blnk.api_keys
		SET last_used_at = ?
		WHERE api_key_id = ?
	';

        try {
            PgStatement::execute($this->conn, $query, [PqEncoder::now(), $id]);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }
    }

    /**
     * ListAPIKeys retrieves all API keys belonging to a specific owner from the database.
     * The results are ordered by creation date in descending order (newest first).
     * Note: The Key field will contain hashed values. For user display, show only a preview
     * (e.g., last 4 characters of the key ID or a masked version).
     *
     * Parameters:
     * - ownerID: The ID of the owner whose API keys should be retrieved.
     *
     * Returns:
     * - []*model.APIKey: A slice of API key objects belonging to the specified owner.
     * - error: An error if the database query fails or if there are issues scanning the results.
     *
     * @return APIKey[]
     *
     * @throws ApiErrorException
     */
    public function listAPIKeys(string $ownerID): array
    {
        $query = '
		SELECT api_key_id, key, name, owner_id, scopes, expires_at, created_at, last_used_at, is_revoked, revoked_at
		FROM blnk.api_keys
		WHERE owner_id = ?
		ORDER BY created_at DESC
	';

        try {
            $rows = PgStatement::execute($this->conn, $query, [$ownerID]);
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        $apiKeys = [];
        try {
            while (($row = $rows->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $apiKeys[] = $this->rowToAPIKey($row);
            }
        } catch (\PDOException $e) {
            throw DatabaseException::fromPDOException($e);
        }

        return $apiKeys;
    }
}
