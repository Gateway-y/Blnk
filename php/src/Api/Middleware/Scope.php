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

/**
 * Port of api/middleware/scope.go.
 *
 * Resource represents a protected API resource that can be accessed via API keys.
 * Each resource corresponds to a specific API endpoint category.
 * Action represents the allowed actions on a resource.
 * Actions include read, write, delete, and wildcard (*).
 *
 * The Go `Resource` and `Action` string types are plain PHP strings; the
 * constants keep their Go names.
 */
final class Scope
{
    // Actions
    public const ActionRead = 'read';
    public const ActionWrite = 'write';
    public const ActionDelete = 'delete';
    public const ActionAll = '*';

    // Resources
    public const ResourceLedgers = 'ledgers';
    public const ResourceBalances = 'balances';
    public const ResourceAccounts = 'accounts';
    public const ResourceIdentities = 'identities';
    public const ResourceTransactions = 'transactions';
    public const ResourceBalanceMonitors = 'balance-monitors';
    public const ResourceHooks = 'hooks';
    public const ResourceAPIKeys = 'api-keys';
    public const ResourceSearch = 'search';
    public const ResourceReconciliation = 'reconciliation';
    public const ResourceMetadata = 'metadata';
    public const ResourceBackup = 'backup';
    public const ResourceAll = '*';

    /**
     * methodToAction maps HTTP methods to actions
     *
     * @var array<string, string>
     */
    public const methodToAction = [
        'GET' => self::ActionRead,
        'HEAD' => self::ActionRead,
        'POST' => self::ActionWrite,
        'PUT' => self::ActionWrite,
        'PATCH' => self::ActionWrite,
        'DELETE' => self::ActionDelete,
    ];

    private function __construct()
    {
    }

    /**
     * BuildScope creates a scope string from resource and action
     */
    public static function buildScope(string $resource, string $action): string
    {
        return $resource . ':' . $action;
    }

    /**
     * ParseScope parses a scope string into resource and action
     *
     * @return array{0: string, 1: string} `[$resource, $action]` — both "" when malformed
     */
    public static function parseScope(string $scope): array
    {
        $parts = explode(':', $scope);
        if (count($parts) !== 2) {
            return ['', ''];
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * HasPermission checks if a set of scopes has permission for a given resource and HTTP method
     *
     * @param string[] $scopes
     */
    public static function hasPermission(array $scopes, string $resource, string $method): bool
    {
        $action = self::methodToAction[$method] ?? '';
        if ($action === '') {
            return false;
        }

        foreach ($scopes as $scope) {
            [$scopeResource, $scopeAction] = self::parseScope((string) $scope);

            // Check for wildcard resource
            if ($scopeResource === self::ResourceAll) {
                if ($scopeAction === self::ActionAll || $scopeAction === $action) {
                    return true;
                }
                continue;
            }

            // Check for exact resource match
            if ($scopeResource === $resource) {
                if ($scopeAction === self::ActionAll || $scopeAction === $action) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ScopeCovers reports whether a granted scope is broad enough to authorize a requested scope.
     */
    public static function scopeCovers(string $grantedScope, string $requestedScope): bool
    {
        [$grantedResource, $grantedAction] = self::parseScope($grantedScope);
        [$requestedResource, $requestedAction] = self::parseScope($requestedScope);

        if ($grantedResource === '' || $grantedAction === '' || $requestedResource === '' || $requestedAction === '') {
            return false;
        }

        if ($grantedResource === self::ResourceAll) {
            return $grantedAction === self::ActionAll || $grantedAction === $requestedAction;
        }

        if ($grantedResource !== $requestedResource) {
            return false;
        }

        return $grantedAction === self::ActionAll || $grantedAction === $requestedAction;
    }

    /**
     * CanGrantScopes reports whether all requested scopes are covered by the caller's scopes.
     *
     * @param string[] $callerScopes
     * @param string[] $requestedScopes
     */
    public static function canGrantScopes(array $callerScopes, array $requestedScopes): bool
    {
        foreach ($requestedScopes as $requestedScope) {
            $covered = false;

            foreach ($callerScopes as $callerScope) {
                if (self::scopeCovers((string) $callerScope, (string) $requestedScope)) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered) {
                return false;
            }
        }

        return true;
    }
}
