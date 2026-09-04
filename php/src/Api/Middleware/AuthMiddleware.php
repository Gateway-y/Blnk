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

namespace Blnk\Api\Middleware;

use Blnk\Api\Json;
use Blnk\Config\Configuration;
use Blnk\Core\Blnk;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\ApiError\ErrorResponse;
use Blnk\Internal\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Port of api/middleware/auth.go.
 *
 * AuthMiddleware handles authentication and authorization for API routes.
 * It supports both master key and API key authentication using the X-Blnk-Key header.
 *
 * Gin's `c.Set("isMasterKey", true)` / `c.Set("apiKey", apiKey)` become PSR-7
 * request attributes of the same names, read by the handlers through
 * `$request->getAttribute('isMasterKey')` / `getAttribute('apiKey')`.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const KeyHeader = 'X-Blnk-Key';

    /** Request attribute set for master-key requests (Go: `c.Set("isMasterKey", true)`). */
    public const IsMasterKeyAttribute = 'isMasterKey';

    /** Request attribute carrying the authenticated \Blnk\Model\APIKey (Go: `c.Set("apiKey", apiKey)`). */
    public const ApiKeyAttribute = 'apiKey';

    /**
     * pathToResource maps URL paths to their corresponding resource types.
     * This is used by the authentication middleware to determine the required permissions.
     *
     * @var array<string, string>
     */
    public const pathToResource = [
        'ledgers' => Scope::ResourceLedgers,
        'balances' => Scope::ResourceBalances,
        'accounts' => Scope::ResourceAccounts,
        'identities' => Scope::ResourceIdentities,
        'transactions' => Scope::ResourceTransactions,
        'balance-monitors' => Scope::ResourceBalanceMonitors,
        'hooks' => Scope::ResourceHooks,
        'api-keys' => Scope::ResourceAPIKeys,
        'search' => Scope::ResourceSearch,
        'reconciliation' => Scope::ResourceReconciliation,
        'metadata' => Scope::ResourceMetadata,
        'backup' => Scope::ResourceBackup,
    ];

    private Blnk $service;

    /**
     * NewAuthMiddleware creates a new instance of AuthMiddleware.
     *
     * Parameters:
     * - $blnk: The Blnk service used to validate API keys.
     */
    public function __construct(Blnk $blnk)
    {
        $this->service = $blnk;
    }

    /**
     * NewAuthMiddleware — static factory mirroring the Go constructor name.
     */
    public static function newAuthMiddleware(Blnk $blnk): self
    {
        return new self($blnk);
    }

    /**
     * abortWithCode writes the standard dual error payload (legacy flat "error"
     * string + structured "error_detail") and aborts the request. The status is
     * resolved from the error-code catalog.
     */
    public static function abortWithCode(string $code, string $message): ResponseInterface
    {
        $resp = ErrorResponse::newErrorResponse($code, $message, null);
        $detail = ['code' => $resp->code, 'message' => $resp->message];
        if ($resp->details !== null) {
            $detail['details'] = $resp->details;
        }

        return Json::write((new ResponseFactory())->createResponse(), ErrorCode::statusForCode($resp->code), [
            'error' => $message,
            'error_detail' => $detail,
        ]);
    }

    /**
     * getResourceFromPath determines the resource type from the URL path.
     *
     * Parameters:
     * - $path: The URL path to analyze.
     *
     * Returns the determined resource type, or empty string if not found.
     */
    public static function getResourceFromPath(string $path): string
    {
        // Remove leading slash and get first path segment
        $trimmed = str_starts_with($path, '/') ? substr($path, 1) : $path;
        $parts = explode('/', $trimmed);
        if (count($parts) === 0) {
            return '';
        }

        // Special case for mocked-account
        if ($parts[0] === 'mocked-account') {
            return Scope::ResourceAccounts;
        }

        // Special case for multi-search
        if ($parts[0] === 'multi-search') {
            return Scope::ResourceSearch;
        }

        if ($parts[0] === 'refund-transaction') {
            return Scope::ResourceTransactions;
        }

        // Check if the path segment maps to a known resource
        if (isset(self::pathToResource[$parts[0]])) {
            return self::pathToResource[$parts[0]];
        }

        return '';
    }

    /**
     * injectAPIKeyToMetadata modifies the request body to include the API key ID in the meta_data.
     * This function reads the request body, adds or updates the meta_data field, and sets the modified
     * body back to the request.
     *
     * Parameters:
     * - $request: The request (Go: the gin context).
     * - $apiKeyID: The API key ID to inject into the metadata.
     *
     * Returns the request carrying the modified body (PSR-7 requests are
     * immutable; Go mutates c.Request in place). When the body is not valid
     * JSON the original request is left untouched and the error is thrown
     * (Go: the original body is restored and the error returned).
     *
     * @throws \RuntimeException if the body processing fails.
     */
    public static function injectAPIKeyToMetadata(ServerRequestInterface $request, string $apiKeyID): ServerRequestInterface
    {
        // Only proceed if this is a POST request
        if ($request->getMethod() !== 'POST') {
            return $request;
        }

        // Read the request body
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $bodyBytes = $body->getContents();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        // Parse the request body as JSON (objects are kept as objects so an
        // empty `{}` survives the round trip)
        $bodyMap = json_decode($bodyBytes, false, 512, JSON_BIGINT_AS_STRING);
        if ($bodyMap === null && json_last_error() !== JSON_ERROR_NONE) {
            // If not valid JSON, restore the original body and continue
            throw new \RuntimeException($bodyBytes === '' ? 'unexpected end of JSON input' : json_last_error_msg());
        }
        if (!($bodyMap instanceof \stdClass)) {
            // Go: json.Unmarshal into map[string]interface{} fails for non-objects
            throw new \RuntimeException(sprintf('json: cannot unmarshal %s into Go value of type map[string]interface {}', is_array($bodyMap) ? 'array' : (is_string($bodyMap) ? 'string' : (is_bool($bodyMap) ? 'bool' : 'number'))));
        }

        // Check if meta_data field exists
        $metaData = $bodyMap->meta_data ?? null;
        if (!($metaData instanceof \stdClass)) {
            // If meta_data doesn't exist or is not an object, create it
            $metaData = new \stdClass();
        }

        // Add the API key ID to meta_data
        $metaData->BLNK_GENERATED_BY = $apiKeyID;

        // Update the meta_data in the body
        $bodyMap->meta_data = $metaData;

        // Convert back to JSON
        try {
            $modifiedBody = json_encode($bodyMap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            // If marshaling fails, restore the original body and continue
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        // Set the modified body back to the request
        return $request
            ->withBody((new StreamFactory())->createStream($modifiedBody))
            ->withHeader('Content-Length', (string) strlen($modifiedBody));
    }

    /**
     * Authenticate returns the middleware that handles authentication and authorization for all routes.
     * It checks for the X-Blnk-Key header and validates it against either the master key or API keys.
     * For API keys, it verifies the key's validity and checks permissions based on the resource and HTTP method.
     * For POST requests with API keys, it injects the API key ID into the metadata of the request body.
     *
     * Responses:
     * - 200 OK: When authentication succeeds.
     * - 401 Unauthorized: When the API key is missing or invalid.
     * - 403 Forbidden: When the API key lacks sufficient permissions.
     */
    public function authenticate(): MiddlewareInterface
    {
        return $this;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Skip auth for root path
        if ($path === '/') {
            return $handler->handle($request);
        }

        // Skip auth for health endpoint (server health check only)
        if ($path === '/health') {
            return $handler->handle($request);
        }

        // Skip X-Blnk-Key auth for metrics endpoint, it uses its own bearer token auth
        if ($path === '/metrics') {
            return $handler->handle($request);
        }

        // Check if secure mode is enabled
        $conf = null;
        try {
            $conf = Configuration::fetch();
        } catch (\Throwable) {
            $conf = null;
        }
        if ($conf !== null && !$conf->server->secure) {
            // Skip authentication when secure mode is disabled
            return $handler->handle($request);
        }

        $key = self::extractKey($request);
        if ($key === '') {
            return self::abortWithCode(ErrorCode::ErrAuthMissingAPIKey, 'Authentication required. Use X-Blnk-Key header');
        }

        // First check if it's the master key
        if ($conf !== null && $conf->server->secretKey !== '' && hash_equals($conf->server->secretKey, $key)) {
            // Master key has all permissions
            return $handler->handle($request->withAttribute(self::IsMasterKeyAttribute, true));
        }

        // If not master key, try API key authentication
        try {
            $apiKey = $this->service->getAPIKeyByKey($key);
        } catch (\Throwable) {
            return self::abortWithCode(ErrorCode::ErrAuthInvalidAPIKey, 'Invalid API key');
        }

        if (!$apiKey->isValid()) {
            return self::abortWithCode(ErrorCode::ErrAuthExpiredAPIKey, 'API key is expired or revoked');
        }

        // Determine required resource from path
        $resource = self::getResourceFromPath($path);
        if ($resource === '') {
            return self::abortWithCode(ErrorCode::ErrAuthUnknownResource, 'Unknown resource type');
        }

        // Check if API key has permission for this resource and method
        $method = $request->getMethod();
        if (!Scope::hasPermission($apiKey->scopes ?? [], $resource, $method)) {
            // Get the required action for this method
            $action = Scope::methodToAction[$method] ?? '';

            return self::abortWithCode(ErrorCode::ErrAuthInsufficientPermissions, 'Insufficient permissions for ' . $resource . ':' . $action);
        }

        // For POST requests, inject the API key ID into the metadata
        if ($method === 'POST') {
            try {
                $request = self::injectAPIKeyToMetadata($request, $apiKey->apiKeyID);
            } catch (\Throwable $err) {
                Log::get()->error('Failed to inject API key ID into metadata:' . $err->getMessage());
            }
        }

        // Update last used timestamp in background (Go: goroutine; run inline
        // here, the result is discarded either way)
        try {
            $this->service->updateLastUsed($apiKey->apiKeyID);
        } catch (\Throwable) {
            // ignored, as in Go
        }

        return $handler->handle($request->withAttribute(self::ApiKeyAttribute, $apiKey));
    }

    /**
     * extractKey retrieves the authentication key from the X-Blnk-Key header.
     *
     * Parameters:
     * - $request: The request containing the headers.
     *
     * Returns the authentication key, or empty string if not found.
     */
    public static function extractKey(ServerRequestInterface $request): string
    {
        return $request->getHeaderLine(self::KeyHeader);
    }
}
