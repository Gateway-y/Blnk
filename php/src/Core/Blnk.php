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

use Blnk\Config\Configuration;
use Blnk\Database\DataSourceInterface;
use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\Cache\CacheInterface;
use Blnk\Internal\Cache\RedisCache;
use Blnk\Internal\Hooks\HookManagerInterface;
use Blnk\Internal\Hooks\RedisHookManager;
use Blnk\Internal\HotPairs\Config as HotPairsConfig;
use Blnk\Internal\HotPairs\Manager as HotPairsManager;
use Blnk\Internal\Log;
use Blnk\Internal\Notification\Notification;
use Blnk\Internal\Redis\PoolConfig;
use Blnk\Internal\Redis\RedisDb;
use Blnk\Internal\Search\TypesenseClient;
use Blnk\Internal\Tokenization\TokenizationService;
use Blnk\Internal\Traces\Span;
use Blnk\Model\BalanceTracker;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;

/**
 * Blnk represents the main struct for the Blnk application.
 *
 * Port of blnk.go. The Go `Blnk` struct has its methods spread across the
 * root-package files; each of those files becomes one trait (one trait per Go
 * file, see PORTING.md and the src/Core contract) that this class composes:
 *
 *   ledger.go                     → LedgerService
 *   balance.go                    → BalanceService
 *   account.go                    → AccountService
 *   identity.go                   → IdentityService
 *   metadata.go                   → MetadataService
 *   apikey.go                     → ApiKeyService
 *   search.go                     → SearchService
 *   webhooks.go                   → WebhookService
 *   transaction.go                → TransactionService
 *   transaction_execution.go      → TransactionExecution
 *   transaction_inflight.go       → TransactionInflight
 *   transaction_queue.go          → TransactionQueueService
 *   transaction_queries.go        → TransactionQueries
 *   transaction_refunds.go        → TransactionRefunds
 *   transaction_rejection.go      → TransactionRejection
 *   transaction_bulk.go           → TransactionBulk
 *   transaction_batch_processing.go → TransactionBatchProcessing
 *   transaction_coalescing.go     → TransactionCoalescing
 *   queue_recovery.go             → QueueRecovery
 *   reconciliation.go             → ReconciliationService
 *   lineage.go                    → LineageService
 *   lineage_allocation.go         → LineageAllocation
 *   lineage_allocation_metadata.go → LineageAllocationMetadata
 *   lineage_credit.go             → LineageCredit
 *   lineage_debit.go              → LineageDebit
 *   lineage_outbox.go             → LineageOutbox
 *   lineage_processing.go         → LineageProcessing
 *   lineage_queries.go            → LineageQueries
 *   lineage_shadow.go             → LineageShadow
 *   lineage_worker.go             → LineageWorker
 *   chain_worker.go               → ChainWorker
 *
 * Trait methods access the collaborators through the properties declared
 * below, using the Go field names: `$this->datasource`, `$this->queue`,
 * `$this->redis`, `$this->asynqClient`, `$this->bt`, `$this->tokenizer`,
 * `$this->httpClient`, `$this->hooks` (Go: exported `Hooks`), `$this->config`,
 * `$this->cache`, `$this->hotPairs`, `$this->search`.
 *
 * Package-level constants of the Go root package become class constants of
 * this class (declared either here or, for constants a specific Go file owns,
 * in that file's trait — PHP >= 8.2 trait constants), so they are always
 * reachable as `Blnk::<Name>` / `self::<Name>`.
 *
 * asynq divergence (documented per PORTING.md "Queue"): Go holds a dedicated
 * `*asynq.Client`; the PHP port replaces asynq with JSON task envelopes on
 * Redis lists (`blnk:queue:<queue_name>`, see {@see Queue} and
 * {@see WebhookService::sendWebhook()}), so `$asynqClient` is the phpredis
 * connection those envelopes are pushed on.
 */
class Blnk
{
    use LedgerService;
    use BalanceService;
    use AccountService;
    use IdentityService;
    use MetadataService;
    use ApiKeyService;
    use SearchService;
    use WebhookService;
    use TransactionService;
    use TransactionExecution;
    use TransactionInflight;
    use TransactionQueueService;
    use TransactionQueries;
    use TransactionRefunds;
    use TransactionRejection;
    use TransactionBulk;
    use TransactionBatchProcessing;
    use TransactionCoalescing;
    use QueueRecovery;
    use ReconciliationService;
    use LineageService;
    use LineageAllocation;
    use LineageAllocationMetadata;
    use LineageCredit;
    use LineageDebit;
    use LineageOutbox;
    use LineageProcessing;
    use LineageQueries;
    use LineageShadow;
    use LineageWorker;
    use ChainWorker;

    public const GeneralLedgerID = 'general_ledger_id';

    /**
     * SQLFiles is the port of Go's `//go:embed sql/*.sql` `var SQLFiles embed.FS`:
     * the directory holding the migration SQL files (php/sql, the exact Go
     * sql/ files — see PORTING.md "SQL").
     */
    public const SQLFiles = __DIR__ . '/../../sql';

    /**
     * Timeout of the HTTP client used for webhook requests
     * (Go: `http.Client{Timeout: 30 * time.Second}`), in seconds.
     */
    public const HttpClientTimeoutSec = 30;

    /** Go: `queue *Queue`. Nullable because Go nil-checks it (metadata.go). */
    protected ?Queue $queue = null;

    /** Go: `search *search.TypesenseClient`. */
    protected TypesenseClient $search;

    /** Go: `redis redis.UniversalClient`. */
    protected \Redis $redis;

    /**
     * Go: `asynqClient *asynq.Client`. In the PHP port this is the Redis
     * connection the asynq-replacement task envelopes are LPUSHed on (the same
     * server — and connection — as {@see Blnk::$redis}); see the class doc.
     * Nullable because Go nil-checks it in Close().
     */
    protected ?\Redis $asynqClient = null;

    /** Go: `datasource database.IDataSource`. */
    protected DataSourceInterface $datasource;

    /** Go: `bt *model.BalanceTracker`. */
    protected BalanceTracker $bt;

    /** Go: `tokenizer *tokenization.TokenizationService`. */
    protected TokenizationService $tokenizer;

    /** Go: `httpClient *http.Client` — the HTTP client used for webhook requests. */
    protected ClientInterface $httpClient;

    /** Go: exported field `Hooks hooks.HookManager` (used by the API layer as `$blnk->hooks`). */
    public HookManagerInterface $hooks;

    /**
     * Go: `config *config.Configuration` — the cached configuration; nullable
     * because {@see Blnk::config()} falls back to Configuration::fetch() when
     * it was never initialized (backward compatibility with tests).
     */
    protected ?Configuration $config = null;

    /** Go: `cache cache.Cache`. */
    protected CacheInterface $cache;

    /** Go: `hotPairs *hotpairs.Manager`. */
    protected HotPairsManager $hotPairs;

    /**
     * initializeRedisClients sets up both the Redis client and Asynq client.
     *
     * Go returns `(redis.UniversalClient, *asynq.Client, error)`. The PHP port
     * returns `[$redisClient, $asynqClient]`; because asynq is replaced by
     * Redis-list task envelopes on the same server, the Redis URL is parsed a
     * second time exactly as Go does (validating it for the queue client) and
     * the same phpredis connection serves both roles instead of opening a
     * second pool.
     *
     * @return array{0: \Redis, 1: \Redis}
     * @throws \RuntimeException on connection or URL parsing failure.
     */
    protected static function initializeRedisClients(Configuration $config): array
    {
        $redisClient = RedisDb::newRedisClient([$config->redis->dns], $config->redis->skipTLSVerify, new PoolConfig(
            $config->redis->poolSize,
            $config->redis->minIdleConns
        ));

        // Go: redis_db.ParseRedisURL(...) feeds the asynq.RedisClientOpt
        // (Addr, Password, DB, TLSConfig, PoolSize). Parsed for parity/validation.
        RedisDb::parseRedisURL($config->redis->dns, $config->redis->skipTLSVerify);

        $client = $redisClient->client();

        return [$client, $client];
    }

    /**
     * initializeTokenizationService creates and configures the tokenization service.
     *
     * Go passes a nil key when no secret is configured; the PHP service takes
     * the raw key bytes and is disabled unless the key is exactly 32 bytes, so
     * an empty string is the equivalent of nil.
     */
    protected static function initializeTokenizationService(Configuration $config): TokenizationService
    {
        if ($config->tokenizationSecret === '') {
            return new TokenizationService('');
        }

        $key = $config->tokenizationSecret;
        return new TokenizationService($key);
    }

    /**
     * initializeHTTPClient creates and configures the HTTP client for webhook requests.
     *
     * Go tunes the transport (MaxIdleConns 100, MaxIdleConnsPerHost 10,
     * IdleConnTimeout 90s); Guzzle/cURL manage connection reuse internally and
     * expose no equivalent knobs, so only the 30s timeout is carried over.
     */
    protected static function initializeHTTPClient(): ClientInterface
    {
        return new GuzzleClient([
            'timeout' => self::HttpClientTimeoutSec,
        ]);
    }

    /**
     * NewBlnk initializes a new instance of Blnk with the provided database datasource.
     * It fetches the configuration, initializes Redis client, balance tracker, queue, and search client.
     *
     * Parameters:
     * - $db: The datasource for database operations.
     *
     * Go returns `(*Blnk, error)`; the constructor throws when any of the
     * initialization steps fail.
     *
     * @throws \RuntimeException if the configuration is not loaded or Redis cannot be reached.
     */
    public function __construct(DataSourceInterface $db)
    {
        $configuration = Configuration::fetch();

        [$redisClient, $asynqClient] = self::initializeRedisClients($configuration);

        $bt = self::newBalanceTracker();
        $hotPairManager = new HotPairsManager($redisClient, new HotPairsConfig(
            $configuration->queue->enableHotLane,
            $configuration->queue->hotQueueName,
            $configuration->queue->hotPairTTL,
            $configuration->queue->hotPairLockContentionThreshold
        ));
        $newQueue = Queue::newQueue($configuration, $asynqClient);
        $newSearch = new TypesenseClient($configuration->typeSenseKey, [$configuration->typeSense->dns]);
        $hookManager = new RedisHookManager($redisClient);
        $tokenizer = self::initializeTokenizationService($configuration);
        $httpClient = self::initializeHTTPClient();

        $newCache = RedisCache::newCacheWithClient($redisClient);

        $this->datasource = $db;
        $this->bt = $bt;
        $this->queue = $newQueue;
        $this->redis = $redisClient;
        $this->asynqClient = $asynqClient;
        $this->search = $newSearch;
        $this->tokenizer = $tokenizer;
        $this->httpClient = $httpClient;
        $this->hooks = $hookManager;
        $this->config = $configuration;
        $this->cache = $newCache;
        $this->hotPairs = $hotPairManager;

        Notification::registerWebhookSender(function (string $event, mixed $payload): void {
            $this->sendWebhook(new NewWebhook($event, $payload));
        });
    }

    /**
     * NewBlnk — static factory mirroring the Go constructor function name;
     * equivalent to `new Blnk($db)`.
     *
     * @throws \RuntimeException if any of the initialization steps fail.
     */
    public static function newBlnk(DataSourceInterface $db): static
    {
        return new static($db);
    }

    /**
     * Close properly closes all connections and resources used by the Blnk instance.
     *
     * Go closes the asynq client only; here that is the Redis connection the
     * task envelopes are pushed on.
     */
    public function close(): void
    {
        if ($this->asynqClient !== null) {
            $this->asynqClient->close();
        }
    }

    /**
     * Config returns the cached configuration for the Blnk instance.
     * Falls back to config.Fetch() if not initialized (for backward compatibility with tests).
     */
    public function config(): Configuration
    {
        if ($this->config !== null) {
            return $this->config;
        }
        try {
            return Configuration::fetch();
        } catch (\Throwable) {
            return new Configuration();
        }
    }

    public function getSearchClient(): TypesenseClient
    {
        return $this->search;
    }

    public function getDataSource(): DataSourceInterface
    {
        return $this->datasource;
    }

    // ------------------------------------------------------------------
    // Shared helpers for the traits (declared on the class so they can never
    // collide with trait method names).
    // ------------------------------------------------------------------

    /**
     * goErrorString renders an exception the way Go's `err.Error()` would:
     * an ApiErrorException prints as "CODE: message" (Go apierror.APIError.Error()),
     * anything else as its message.
     */
    protected static function goErrorString(\Throwable $err): string
    {
        if ($err instanceof ApiErrorException) {
            return $err->error();
        }
        return $err->getMessage();
    }

    /**
     * wrapError is the PHP equivalent of `fmt.Errorf("<prefix>: %w", err)`.
     *
     * The wrapped exception keeps `$err` as its previous exception (the `%w`
     * chain). When an ApiErrorException is found in the chain, the wrapper is
     * an ApiErrorException carrying the same code — the analogue of Go's
     * `errors.As(err, &apiErr)` resolving through the wrap; otherwise a plain
     * \RuntimeException is returned so message-pattern classification (Go
     * api/errors.go messagePatterns) still applies.
     */
    protected static function wrapError(string $prefix, \Throwable $err): \RuntimeException
    {
        $message = sprintf('%s: %s', $prefix, self::goErrorString($err));
        for ($e = $err; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof ApiErrorException) {
                return new ApiErrorException($e->errorCode, $message, $e->details, $err);
            }
        }
        return new \RuntimeException($message, 0, $err);
    }

    /**
     * spanEvent is the analogue of OTel `span.AddEvent(name, trace.WithAttributes(...))`
     * for the no-op tracer of the PHP port: the event is logged at debug level only.
     *
     * @param array<string, mixed> $attributes
     */
    protected static function spanEvent(Span $span, string $name, array $attributes = []): void
    {
        Log::get()->debug('span event', ['span' => $span->name(), 'event' => $name] + $attributes);
    }
}
