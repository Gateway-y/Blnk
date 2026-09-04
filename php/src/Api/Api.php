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

namespace Blnk\Api;

use Blnk\Api\Middleware\AuthMiddleware;
use Blnk\Api\Middleware\RateLimitMiddleware;
use Blnk\Api\Middleware\RequestSizeLimit;
use Blnk\Api\Middleware\SecurityHeaders;
use Blnk\Config\Configuration;
use Blnk\Core\Blnk;
use Blnk\Internal\ApiError\ErrorCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Api represents the API structure for handling requests.
 *
 * Port of api/api.go. The Go `Api` struct has its handlers spread across the
 * api package's files; each of those files becomes one trait (one trait per
 * Go file) that this class composes:
 *
 *   ledger.go             → LedgerHandlers
 *   balance.go            → BalanceHandlers
 *   accounts.go           → AccountHandlers
 *   identity.go           → IdentityHandlers
 *   apikeys.go            → ApiKeyHandlers
 *   admin.go              → AdminHandlers
 *   metadata.go           → MetadataHandlers
 *   hooks.go              → HookHandlers
 *   reindex.go            → ReindexHandlers
 *   reconciliation_api.go → ReconciliationHandlers
 *   transactions.go       → TransactionHandlers
 *
 * Gin becomes Slim 4: every route of api.go is registered with the same
 * method and path (`:param` → `{param}`), each handler keeps its Go name in
 * camelCase and the PSR-15 signature `(ServerRequestInterface $request,
 * ResponseInterface $response, array $args): ResponseInterface`; the
 * package-level response helpers of errors.go live in {@see Errors}.
 */
final class Api
{
    use LedgerHandlers;
    use BalanceHandlers;
    use AccountHandlers;
    use IdentityHandlers;
    use ApiKeyHandlers;
    use AdminHandlers;
    use MetadataHandlers;
    use HookHandlers;
    use ReindexHandlers;
    use ReconciliationHandlers;
    use TransactionHandlers;

    /** Go: `blnk *blnk.Blnk`. */
    private Blnk $blnk;

    /** Go: `router *gin.Engine`. */
    private App $app;

    /** Go: `auth *middleware.AuthMiddleware`. */
    private AuthMiddleware $auth;

    /** True once {@see Api::router()} has registered the routes. */
    private bool $routed = false;

    private function __construct(Blnk $blnk, App $app, AuthMiddleware $auth)
    {
        $this->blnk = $blnk;
        $this->app = $app;
        $this->auth = $auth;
    }

    /**
     * NewAPI creates a new Api instance with the provided Blnk service and sets up the router.
     *
     * Parameters:
     * - $b: The Blnk service used to interact with business logic.
     *
     * Returns a new instance of the Api with the configured router — the
     * global middleware chain of Go's NewAPI plus every route of Router().
     *
     * Go returns nil when the configuration cannot be fetched; the PHP port
     * lets Configuration::fetch() throw instead.
     *
     * @throws \RuntimeException when no configuration has been loaded.
     */
    public static function newApi(Blnk $b): self
    {
        $conf = Configuration::fetch();
        $r = AppFactory::create();

        // gin's MaxMultipartMemory (8 MiB) has no Slim equivalent: PHP buffers
        // uploads according to php.ini (upload_max_filesize / post_max_size).

        // Gin runs `Use` middlewares in registration order; Slim runs the last
        // added middleware first, so the chain is registered in reverse. The
        // auth middleware — which Go's Router() applies with `router.Use` after
        // these, i.e. right before the handler — is therefore added first.
        $auth = AuthMiddleware::newAuthMiddleware($b);
        $r->add($auth->authenticate());
        // otelgin.Middleware("BLNK", excluding /metrics): tracing is a no-op
        // logger stub in the PHP port (PORTING.md), nothing to register.
        $r->add(SecurityHeaders::securityHeaders());
        $r->add(RateLimitMiddleware::rateLimitMiddleware($conf));
        $r->add(RequestSizeLimit::requestSizeLimit($conf->server->maxRequestBodySizeMB * 1024 * 1024));
        $r->add(LogrusRecovery::logrusRecovery());
        $r->add(LogrusAccessLogger::logrusAccessLogger());

        $r->get('/', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            return Json::write($response, 200, 'server running...');
        });

        $api = new self($b, $r, $auth);
        $api->router();

        return $api;
    }

    /**
     * Router sets up the routes for the API and returns the router instance.
     *
     * Responses:
     * - 200 OK: When the router is successfully set up.
     */
    public function router(): App
    {
        $router = $this->app;
        if ($this->routed) {
            return $router;
        }
        $this->routed = true;

        // Apply auth middleware to all routes: `$this->auth->authenticate()` is
        // already on the app's stack (added first in newApi so that, with
        // Slim's last-added-runs-first order, it runs right before the handler
        // as Gin's `router.Use` placement does).

        // Ledger routes
        $router->post('/ledgers', [$this, 'createLedger']);
        $router->get('/ledgers/{id}', [$this, 'getLedger']);
        $router->get('/ledgers', [$this, 'getAllLedgers']);
        $router->post('/ledgers/filter', [$this, 'filterLedgers']);
        $router->put('/ledgers/{id}', [$this, 'updateLedger']);

        // Balance routes
        $router->post('/balances', [$this, 'createBalance']);
        $router->get('/balances', [$this, 'getBalances']);
        $router->post('/balances/filter', [$this, 'filterBalances']);
        $router->get('/balances/{id}', [$this, 'getBalance']);
        $router->get('/balances/indicator/{indicator}/currency/{currency}', [$this, 'getBalanceByIndicator']);
        $router->get('/balances/{id}/at', [$this, 'getBalanceAtTime']);
        $router->post('/balances-snapshots', [$this, 'takeBalanceSnapshots']);
        $router->put('/balances/{id}/identity', [$this, 'updateBalanceIdentity']);
        $router->get('/balances/{id}/lineage', [$this, 'getBalanceLineage']);

        // Balance Monitor routes
        $router->post('/balance-monitors', [$this, 'createBalanceMonitor']);
        $router->get('/balance-monitors/{id}', [$this, 'getBalanceMonitor']);
        $router->get('/balance-monitors', [$this, 'getAllBalanceMonitors']);
        $router->get('/balance-monitors/balances/{balance_id}', [$this, 'getBalanceMonitorsByBalanceID']);
        $router->put('/balance-monitors/{id}', [$this, 'updateBalanceMonitor']);
        $router->delete('/balance-monitors/{id}', [$this, 'deleteBalanceMonitor']);

        // Transaction routes
        $router->post('/transactions', [$this, 'queueTransaction']);
        $router->post('/transactions/bulk', [$this, 'createBulkTransactions']);
        $router->post('/transactions/filter', [$this, 'filterTransactions']);
        $router->post('/refund-transaction/{id}', [$this, 'refundTransaction']);
        $router->get('/transactions', [$this, 'getAllTransactions']);
        $router->get('/transactions/{id}', [$this, 'getTransaction']);
        $router->get('/transactions/reference/{reference}', [$this, 'getTransactionByRef']);
        $router->put('/transactions/inflight/{txID}', [$this, 'updateInflightStatus']);
        $router->post('/transactions/inflight/bulk/void', [$this, 'bulkVoidInflight']);
        $router->post('/transactions/inflight/bulk/commit', [$this, 'bulkCommitInflight']);
        $router->get('/transactions/{id}/lineage', [$this, 'getTransactionLineage']);

        // Recovery routes
        $router->post('/transactions/recover', [$this, 'recoverQueuedTransactions']);

        // Identity routes
        $router->post('/identities', [$this, 'createIdentity']);
        $router->get('/identities/{id}', [$this, 'getIdentity']);
        $router->put('/identities/{id}', [$this, 'updateIdentity']);
        $router->get('/identities', [$this, 'getAllIdentities']);
        $router->delete('/identities/{id}', [$this, 'deleteIdentity']);
        $router->post('/identities/filter', [$this, 'filterIdentities']);
        $router->get('/identities/{id}/tokenized-fields', [$this, 'getTokenizedFields']);
        $router->post('/identities/{id}/tokenize/{field}', [$this, 'tokenizeIdentityField']);
        $router->get('/identities/{id}/detokenize/{field}', [$this, 'detokenizeIdentityField']);
        $router->post('/identities/{id}/tokenize', [$this, 'tokenizeIdentity']);
        $router->post('/identities/{id}/detokenize', [$this, 'detokenizeIdentity']);

        // Account routes
        $router->post('/accounts', [$this, 'createAccount']);
        $router->get('/accounts/{id}', [$this, 'getAccount']);
        $router->get('/accounts', [$this, 'getAllAccounts']);
        $router->post('/accounts/filter', [$this, 'filterAccounts']);

        // Mocked Account route
        $router->get('/mocked-account', [$this, 'generateMockAccount']);

        // Backup routes
        $router->get('/backup', [$this, 'backupDB']);
        $router->get('/backup-s3', [$this, 'backupDBS3']);

        // Reindex routes — registered before the search routes: FastRoute
        // rejects a static route (POST /search/reindex) declared after a
        // variable route (POST /search/{collection}) that shadows it, whereas
        // Gin's tree resolves the static path first either way.
        $router->post('/search/reindex', [$this, 'startReindex']);
        $router->get('/search/reindex', [$this, 'getReindexProgress']);
        // Search routes
        $router->post('/search/{collection}', [$this, 'search']);
        $router->post('/multi-search', [$this, 'multiSearch']);

        // Reconciliation routes
        $router->post('/reconciliation/upload', [$this, 'uploadExternalData']);
        $router->post('/reconciliation/matching-rules', [$this, 'createMatchingRule']);
        $router->put('/reconciliation/matching-rules/{id}', [$this, 'updateMatchingRule']);
        $router->delete('/reconciliation/matching-rules/{id}', [$this, 'deleteMatchingRule']);
        $router->post('/reconciliation/start', [$this, 'startReconciliation']);
        $router->post('/reconciliation/start-instant', [$this, 'instantReconciliation']);
        $router->get('/reconciliation/{id}', [$this, 'getReconciliation']);

        // Metadata routes
        $router->post('/{entity-id}/metadata', [$this, 'updateMetadata']);

        // Hook management routes
        $router->post('/hooks', [$this, 'registerHook']);
        $router->put('/hooks/{id}', [$this, 'updateHook']);
        $router->get('/hooks/{id}', [$this, 'getHook']);
        $router->get('/hooks', [$this, 'listHooks']);
        $router->delete('/hooks/{id}', [$this, 'deleteHook']);

        // API Key routes
        $router->post('/api-keys', [$this, 'createAPIKey']);
        $router->get('/api-keys', [$this, 'listAPIKeys']);
        $router->delete('/api-keys/{id}', [$this, 'revokeAPIKey']);

        // Gin's NoRoute default (HandleMethodNotAllowed is off, so an unknown
        // path or method is a plain "404 page not found") is produced by
        // LogrusRecovery from Slim's routing exceptions. Slim routes inside the
        // middleware chain, so the global middleware — auth included — still
        // runs for unknown paths exactly as it does in Gin.

        return $this->app;
    }

    /**
     * app returns the Slim application (the Go `*gin.Engine`) so the front
     * controller can run it and register server-level routes (/health, /metrics).
     */
    public function app(): App
    {
        return $this->app;
    }

    /**
     * blnk returns the Blnk service the handlers use.
     */
    public function blnk(): Blnk
    {
        return $this->blnk;
    }

    /**
     * Search performs a search query on a specified collection.
     * It binds the incoming JSON request to a SearchCollectionParams object,
     * executes the search query, and responds with the search results.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding JSON or performing the search.
     * - 201 Created: If the search query is successfully executed and results are returned.
     */
    public function search(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['collection'])) {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'collection is required. pass id in the route /:collection', null);
        }
        $collection = Query::param($args, 'collection');

        try {
            $query = Binding::bindJSON($request, 'api.SearchCollectionParams');
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        try {
            $resp = $this->blnk->search($collection, $query);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withDefault(ErrorCode::ErrSrchQueryInvalid));
        }

        return Json::write($response, 201, $resp);
    }

    /**
     * MultiSearch performs a multi-search query.
     * It binds the incoming JSON request to a MultiSearchParameter object,
     * executes the multi-search query, and responds with the search results.
     *
     * Responses:
     * - 400 Bad Request: If there's an error in binding JSON or performing the search.
     * - 200 OK: If the multi-search query is successfully executed and results are returned.
     */
    public function multiSearch(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $searchRequests = Binding::bindJSON($request, 'api.MultiSearchSearchesParameter');
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        try {
            $resp = $this->blnk->multiSearch($searchRequests);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err, Errors::withDefault(ErrorCode::ErrSrchQueryInvalid));
        }

        return Json::write($response, 200, $resp);
    }
}
