<?php

declare(strict_types=1);

namespace Blnk\Api;

use Blnk\Api\Middleware\AuthMiddleware;
use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\Hooks\Hook;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/hooks.go: the webhook management handlers of the Go `Api`
 * struct (plus the package-level helpers of that file), composed into
 * {@see Api}.
 */
trait HookHandlers
{
    /** Go: `errHooksRequireMasterKey = errors.New("hook management requires master key")`. */
    private const errHooksRequireMasterKey = 'hook management requires master key';

    /**
     * hookFailureCode distinguishes a missing hook (the manager returns
     * "hook not found: <id>") from genuine infrastructure failures.
     */
    private static function hookFailureCode(\Throwable $err): string
    {
        $code = Errors::classifyMessage($err->getMessage());
        if ($code !== null && ErrorCode::statusForCode($code) === 404) {
            return ErrorCode::ErrHookNotFound;
        }

        return ErrorCode::ErrHookOperationFailed;
    }

    private static function isHookMasterKeyRequest(ServerRequestInterface $request): bool
    {
        return $request->getAttribute(AuthMiddleware::IsMasterKeyAttribute) === true;
    }

    /**
     * ensureHookManagementAuthorized returns null when the request may manage
     * hooks, otherwise the 403 response (Go: returns false after responding).
     */
    private static function ensureHookManagementAuthorized(ServerRequestInterface $request, ResponseInterface $response): ?ResponseInterface
    {
        if (self::isHookMasterKeyRequest($request)) {
            return null;
        }

        return Errors::respondCode($response, ErrorCode::ErrAuthMasterKeyRequired, self::errHooksRequireMasterKey, null);
    }

    /**
     * RegisterHook handles the registration of a new webhook.
     */
    public function registerHook(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $denied = self::ensureHookManagementAuthorized($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $hook = Hook::fromArray(Binding::shouldBindJSON($request, 'hooks.Hook'));
        } catch (\RuntimeException $err) {
            return Errors::respondBareAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrInvalidInput, 'invalid hook data', $err), ErrorCode::ErrHookInvalid);
        }

        try {
            $this->blnk->hooks->registerHook($hook);
        } catch (\Throwable $err) {
            return Errors::respondBareAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrInvalidInput, 'failed to register hook', $err), ErrorCode::ErrHookOperationFailed);
        }

        return Json::write($response, 201, $hook);
    }

    /**
     * UpdateHook handles updating an existing webhook.
     */
    public function updateHook(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $denied = self::ensureHookManagementAuthorized($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $hookID = Query::param($args, 'id');
        try {
            $hook = Hook::fromArray(Binding::shouldBindJSON($request, 'hooks.Hook'));
        } catch (\RuntimeException $err) {
            return Errors::respondBareAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrInvalidInput, 'invalid hook data', $err), ErrorCode::ErrHookInvalid);
        }

        try {
            $this->blnk->hooks->updateHook($hookID, $hook);
        } catch (\Throwable $err) {
            return Errors::respondBareAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrInvalidInput, 'failed to update hook', $err), self::hookFailureCode($err));
        }

        return Json::write($response, 200, $hook);
    }

    /**
     * GetHook retrieves a specific webhook by ID.
     */
    public function getHook(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $denied = self::ensureHookManagementAuthorized($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $hookID = Query::param($args, 'id');
        try {
            $hook = $this->blnk->hooks->getHook($hookID);
        } catch (\Throwable $err) {
            return Errors::respondBareAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrNotFound, 'hook not found', $err), ErrorCode::ErrHookNotFound);
        }

        return Json::write($response, 200, $hook);
    }

    /**
     * ListHooks retrieves all hooks of a specific type.
     */
    public function listHooks(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $denied = self::ensureHookManagementAuthorized($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $hookType = Query::query($request, 'type');
        try {
            $hooks = $this->blnk->hooks->listHooks($hookType);
        } catch (\Throwable $err) {
            return Errors::respondBareAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrInvalidInput, 'failed to list hooks', $err), ErrorCode::ErrHookOperationFailed);
        }

        return Json::write($response, 200, $hooks);
    }

    /**
     * DeleteHook removes a webhook by ID.
     */
    public function deleteHook(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $denied = self::ensureHookManagementAuthorized($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $hookID = Query::param($args, 'id');
        try {
            $this->blnk->hooks->deleteHook($hookID);
        } catch (\Throwable $err) {
            return Errors::respondBareAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrInvalidInput, 'failed to delete hook', $err), self::hookFailureCode($err));
        }

        return Json::write($response, 200, ['message' => 'hook deleted successfully']);
    }
}
