<?php

declare(strict_types=1);

namespace Blnk\Core;

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Model\APIKey;

/**
 * Port of apikey.go: the API-key methods of the Go `Blnk` struct, composed
 * into {@see Blnk}.
 */
trait ApiKeyService
{
    /**
     * CreateAPIKey creates a new API key for the specified owner
     *
     * Parameters:
     * - $name: Name of the API key
     * - $ownerID: ID of the key owner
     * - $scopes: List of permission scopes
     * - $expiresAt: Expiration time for the key
     *
     * Returns the created API key.
     *
     * @param string[] $scopes
     * @throws ApiErrorException if the operation fails
     */
    public function createAPIKey(string $name, string $ownerID, array $scopes, \DateTimeImmutable $expiresAt): APIKey
    {
        return $this->datasource->createAPIKey($name, $ownerID, $scopes, $expiresAt);
    }

    /**
     * ListAPIKeys retrieves all API keys for a specific owner
     *
     * Parameters:
     * - $ownerID: ID of the key owner
     *
     * @return APIKey[] List of API keys
     * @throws ApiErrorException if the operation fails
     */
    public function listAPIKeys(string $ownerID): array
    {
        return $this->datasource->listAPIKeys($ownerID);
    }

    /**
     * RevokeAPIKey revokes an API key if it belongs to the specified owner
     *
     * Parameters:
     * - $id: ID of the API key to revoke
     * - $ownerID: ID of the key owner
     *
     * @throws ApiErrorException if the operation fails
     */
    public function revokeAPIKey(string $id, string $ownerID): void
    {
        $this->datasource->revokeAPIKey($id, $ownerID);
    }

    /**
     * GetAPIKeyByKey retrieves an API key by its key string
     *
     * Parameters:
     * - $key: The API key string
     *
     * Returns the API key if found.
     *
     * @throws ApiErrorException if the operation fails
     */
    public function getAPIKeyByKey(string $key): APIKey
    {
        return $this->datasource->getAPIKey($key);
    }

    /**
     * UpdateLastUsed updates the last used timestamp of an API key
     *
     * Parameters:
     * - $id: ID of the API key to update
     *
     * @throws ApiErrorException if the operation fails
     */
    public function updateLastUsed(string $id): void
    {
        $this->datasource->updateLastUsed($id);
    }
}
