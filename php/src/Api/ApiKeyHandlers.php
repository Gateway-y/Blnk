<?php

declare(strict_types=1);

namespace Blnk\Api;

use Blnk\Api\Middleware\AuthMiddleware;
use Blnk\Api\Middleware\Scope;
use Blnk\Api\Model\CreateAPIKeyRequest;
use Blnk\Database\ApiKeyNotFoundException;
use Blnk\Database\InvalidApiKeyException;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Model\APIKey;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/apikeys.go: the API-key handlers of the Go `Api` struct (plus
 * the package-level authorization helpers of that file), composed into
 * {@see Api}.
 *
 * The Go sentinel errors of the file are the message constants below; the
 * helpers throw plain \RuntimeExceptions carrying them and
 * writeAPIKeyAuthorizationError switches on the message, the counterpart of
 * Go's identity comparison against the sentinels.
 */
trait ApiKeyHandlers
{
    private const errAPIKeyOwnerRequired = 'owner is required';
    private const errAPIKeyCrossOwnerAccess = 'cannot manage API keys for another owner';
    private const errAPIKeyScopeEscalation = 'cannot grant scopes broader than caller';
    private const errMissingAPIKeyPrincipal = 'authenticated API key principal missing';

    private static function isMasterKeyRequest(ServerRequestInterface $request): bool
    {
        $isMasterKey = $request->getAttribute(AuthMiddleware::IsMasterKeyAttribute);

        return $isMasterKey === true;
    }

    /**
     * currentAPIKeyPrincipal returns the authenticated API key set by the auth
     * middleware, or null when the request carries none (Go: `(nil, false)`).
     */
    private static function currentAPIKeyPrincipal(ServerRequestInterface $request): ?APIKey
    {
        $apiKeyValue = $request->getAttribute(AuthMiddleware::ApiKeyAttribute);
        if ($apiKeyValue === null) {
            return null;
        }

        return $apiKeyValue instanceof APIKey ? $apiKeyValue : null;
    }

    /**
     * @throws \RuntimeException one of the sentinel messages
     */
    private static function resolveAPIKeyOwner(bool $isMaster, ?APIKey $principal, string $requestedOwner, bool $ownerRequired): string
    {
        if ($isMaster) {
            if ($ownerRequired && $requestedOwner === '') {
                throw new \RuntimeException(self::errAPIKeyOwnerRequired);
            }

            return $requestedOwner;
        }

        if ($principal === null) {
            throw new \RuntimeException(self::errMissingAPIKeyPrincipal);
        }

        if ($requestedOwner !== '' && $requestedOwner !== $principal->ownerID) {
            throw new \RuntimeException(self::errAPIKeyCrossOwnerAccess);
        }

        return $principal->ownerID;
    }

    /**
     * @param string[] $requestedScopes
     * @throws \RuntimeException one of the sentinel messages
     */
    private static function validateGrantedScopes(bool $isMaster, ?APIKey $principal, array $requestedScopes): void
    {
        if ($isMaster) {
            return;
        }

        if ($principal === null) {
            throw new \RuntimeException(self::errMissingAPIKeyPrincipal);
        }

        if (!Scope::canGrantScopes($principal->scopes ?? [], $requestedScopes)) {
            throw new \RuntimeException(self::errAPIKeyScopeEscalation);
        }
    }

    /**
     * writeAPIKeyAuthorizationError writes the response for an authorization
     * failure; null when there is no error (Go: returns false).
     */
    private static function writeAPIKeyAuthorizationError(ResponseInterface $response, ?\Throwable $err): ?ResponseInterface
    {
        if ($err === null) {
            return null;
        }

        switch ($err->getMessage()) {
            case self::errAPIKeyOwnerRequired:
                return Errors::respondCode($response, ErrorCode::ErrAPIKeyOwnerRequired, $err->getMessage(), null);
            case self::errMissingAPIKeyPrincipal:
                return Errors::respondCode($response, ErrorCode::ErrAuthMissingPrincipal, $err->getMessage(), null);
            case self::errAPIKeyCrossOwnerAccess:
                return Errors::respondCode($response, ErrorCode::ErrAuthCrossOwnerAccess, $err->getMessage(), null);
            case self::errAPIKeyScopeEscalation:
                return Errors::respondCode($response, ErrorCode::ErrAuthScopeEscalation, $err->getMessage(), null);
            default:
                return Errors::respondError($response, $err);
        }
    }

    /**
     * CreateAPIKey creates a new API key for the authenticated user
     *
     * Responses:
     * - 400 Bad Request: If there's an error in the request body
     * - 201 Created: If the API key is successfully created
     */
    public function createAPIKey(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $req = CreateAPIKeyRequest::fromArray(Binding::shouldBindJSON($request, 'model.CreateAPIKeyRequest'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        $isMaster = self::isMasterKeyRequest($request);
        $principal = self::currentAPIKeyPrincipal($request);

        try {
            $ownerID = self::resolveAPIKeyOwner($isMaster, $principal, $req->owner, true);
        } catch (\RuntimeException $err) {
            return self::writeAPIKeyAuthorizationError($response, $err);
        }

        try {
            self::validateGrantedScopes($isMaster, $principal, $req->scopes ?? []);
        } catch (\RuntimeException $err) {
            return self::writeAPIKeyAuthorizationError($response, $err);
        }

        try {
            $apiKey = $this->blnk->createAPIKey($req->name, $ownerID, $req->scopes ?? [], $req->expiresAt);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 201, $apiKey);
    }

    /**
     * ListAPIKeys lists all API keys for the authenticated user
     *
     * Responses:
     * - 200 OK: Returns the list of API keys
     * - 500 Internal Server Error: If there's an error retrieving the keys
     */
    public function listAPIKeys(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $isMaster = self::isMasterKeyRequest($request);
        $principal = self::currentAPIKeyPrincipal($request);

        try {
            $ownerID = self::resolveAPIKeyOwner($isMaster, $principal, Query::query($request, 'owner'), $isMaster);
        } catch (\RuntimeException $err) {
            return self::writeAPIKeyAuthorizationError($response, $err);
        }

        try {
            $keys = $this->blnk->listAPIKeys($ownerID);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 200, $keys);
    }

    /**
     * RevokeAPIKey revokes an API key
     *
     * Responses:
     * - 204 No Content: If the API key is successfully revoked
     * - 404 Not Found: If the API key is not found
     * - 403 Forbidden: If the user doesn't own the API key
     */
    public function revokeAPIKey(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = Query::param($args, 'id');
        $isMaster = self::isMasterKeyRequest($request);
        $principal = self::currentAPIKeyPrincipal($request);

        try {
            $ownerID = self::resolveAPIKeyOwner($isMaster, $principal, Query::query($request, 'owner'), $isMaster);
        } catch (\RuntimeException $err) {
            return self::writeAPIKeyAuthorizationError($response, $err);
        }

        try {
            $this->blnk->revokeAPIKey($id, $ownerID);
        } catch (\Throwable $err) {
            // Go compares the sentinels by identity (`switch err`), so only the
            // thrown exception itself is inspected, not its chain.
            if ($err instanceof ApiKeyNotFoundException) {
                return Errors::respondCode($response, ErrorCode::ErrAPIKeyNotFound, 'API key not found', null);
            }
            if ($err instanceof InvalidApiKeyException) {
                // In the revoke flow this means the key belongs to another owner —
                // an authorization failure, so 403 (not 401) stays correct.
                return Errors::respondCode($response, ErrorCode::ErrAuthCrossOwnerAccess, 'unauthorized', null);
            }

            return Errors::respondError($response, $err);
        }

        return Json::status($response, 204);
    }
}
