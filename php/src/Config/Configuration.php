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

namespace Blnk\Config;

use Blnk\Internal\Log;

/**
 * Port of Go `config.Configuration` plus the package-level functions of
 * `config/config.go`:
 *
 * - `config.Fetch()`            → {@see Configuration::fetch()}
 * - `config.ConfigStore.Store`  → {@see Configuration::setConfig()}
 * - `config.InitConfig()`       → {@see Configuration::initConfig()}
 * - `config.MockConfig()`       → {@see Configuration::mockConfig()}
 *
 * All `time.Duration` fields in the tree are represented as integer seconds.
 * Note on durations: Go decodes JSON numbers into `time.Duration` as
 * nanoseconds and then rescales sub-second values by `time.Second`
 * (`lock_duration`, `lock_wait_timeout`) or always multiplies by
 * `time.Second` (`hot_pair_ttl`) — the net effect being that JSON numbers
 * mean seconds. The PHP port reads JSON duration numbers directly as
 * seconds, which yields the same effective values without the rescaling
 * dance.
 */
final class Configuration
{
    // Default constants (Go `config` package consts).
    public const DEFAULT_PORT = '5001';
    /** 3 hours in seconds. */
    public const DEFAULT_CLEANUP_SEC = 10800;
    public const DEFAULT_TYPESENSE_KEY = 'blnk-api-key';
    public const DEFAULT_MONITORING_PORT = '5004';
    /** Caps reconciliation file uploads. */
    public const DEFAULT_MAX_UPLOAD_SIZE_MB = 256;
    /**
     * DEFAULT_MAX_REQUEST_BODY_SIZE_MB caps non-upload request bodies so a large
     * POST can't exhaust memory before a handler (or the auth middleware) reads it.
     */
    public const DEFAULT_MAX_REQUEST_BODY_SIZE_MB = 5;
    /**
     * DEFAULT_UPLOAD_URL_TIMEOUT_SEC caps how long a URL-based reconciliation
     * upload may spend fetching the remote body, preventing a slow or stalled
     * upstream from hanging the handler.
     */
    public const DEFAULT_UPLOAD_URL_TIMEOUT_SEC = 30;

    /** The stored singleton (Go `var ConfigStore atomic.Value`). */
    private static ?Configuration $configStore = null;

    /** JSON: "project_name"; env: BLNK_PROJECT_NAME */
    public string $projectName = '';

    /** JSON: "backup_dir"; env: BLNK_BACKUP_DIR */
    public string $backupDir = '';

    /** JSON: "aws_access_key_id"; env: BLNK_AWS_ACCESS_KEY_ID */
    public string $awsAccessKeyId = '';

    /** JSON: "s3_endpoint"; env: BLNK_S3_ENDPOINT */
    public string $s3Endpoint = '';

    /** JSON: "aws_secret_access_key"; env: BLNK_AWS_SECRET_ACCESS_KEY */
    public string $awsSecretAccessKey = '';

    /** JSON: "s3_bucket_name"; env: BLNK_S3_BUCKET_NAME */
    public string $s3BucketName = '';

    /** JSON: "s3_region"; env: BLNK_S3_REGION */
    public string $s3Region = '';

    /** JSON: "server" */
    public ServerConfig $server;

    /** JSON: "data_source" */
    public DataSourceConfig $dataSource;

    /** JSON: "redis" */
    public RedisConfig $redis;

    /** JSON: "typesense" */
    public TypeSenseConfig $typeSense;

    /** JSON: "type_sense_key"; env: BLNK_TYPESENSE_KEY */
    public string $typeSenseKey = '';

    /** JSON: "tokenization_secret"; env: BLNK_TOKENIZATION_SECRET */
    public string $tokenizationSecret = '';

    /** JSON: "account_number_generation" */
    public AccountNumberGenerationConfig $accountNumberGeneration;

    /** JSON: "notification" */
    public Notification $notification;

    /** JSON: "rate_limit" */
    public RateLimitConfig $rateLimit;

    /** JSON: "enable_telemetry"; env: BLNK_ENABLE_TELEMETRY */
    public bool $enableTelemetry = false;

    /** JSON: "enable_observability"; env: BLNK_ENABLE_OBSERVABILITY */
    public bool $enableObservability = false;

    /** JSON: "monitoring_dsn"; env: BLNK_MONITORING_DSN */
    public string $monitoringDSN = '';

    /** JSON: "transaction" */
    public TransactionConfig $transaction;

    /** JSON: "reconciliation" */
    public ReconciliationConfig $reconciliation;

    /** JSON: "queue" */
    public QueueConfig $queue;

    public function __construct()
    {
        $this->server = new ServerConfig();
        $this->dataSource = new DataSourceConfig();
        $this->redis = new RedisConfig();
        $this->typeSense = new TypeSenseConfig();
        $this->accountNumberGeneration = new AccountNumberGenerationConfig();
        $this->notification = new Notification();
        $this->rateLimit = new RateLimitConfig();
        $this->transaction = new TransactionConfig();
        $this->reconciliation = new ReconciliationConfig();
        $this->queue = new QueueConfig();
    }

    /**
     * RemoteMonitoringDSN returns the configured monitoring DSN, or "" when unset.
     */
    public function remoteMonitoringDSN(): string
    {
        if (trim($this->monitoringDSN) !== '') {
            return $this->monitoringDSN;
        }
        return '';
    }

    /**
     * Hydrates a Configuration from decoded blnk.json data, honoring the Go
     * `json:"..."` tag names.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $cnf = new self();
        $cnf->projectName = (string) ($data['project_name'] ?? '');
        $cnf->backupDir = (string) ($data['backup_dir'] ?? '');
        $cnf->awsAccessKeyId = (string) ($data['aws_access_key_id'] ?? '');
        $cnf->s3Endpoint = (string) ($data['s3_endpoint'] ?? '');
        $cnf->awsSecretAccessKey = (string) ($data['aws_secret_access_key'] ?? '');
        $cnf->s3BucketName = (string) ($data['s3_bucket_name'] ?? '');
        $cnf->s3Region = (string) ($data['s3_region'] ?? '');
        if (is_array($data['server'] ?? null)) {
            $cnf->server = ServerConfig::fromArray($data['server']);
        }
        if (is_array($data['data_source'] ?? null)) {
            $cnf->dataSource = DataSourceConfig::fromArray($data['data_source']);
        }
        if (is_array($data['redis'] ?? null)) {
            $cnf->redis = RedisConfig::fromArray($data['redis']);
        }
        if (is_array($data['typesense'] ?? null)) {
            $cnf->typeSense = TypeSenseConfig::fromArray($data['typesense']);
        }
        $cnf->typeSenseKey = (string) ($data['type_sense_key'] ?? '');
        $cnf->tokenizationSecret = (string) ($data['tokenization_secret'] ?? '');
        if (is_array($data['account_number_generation'] ?? null)) {
            $cnf->accountNumberGeneration = AccountNumberGenerationConfig::fromArray($data['account_number_generation']);
        }
        if (is_array($data['notification'] ?? null)) {
            $cnf->notification = Notification::fromArray($data['notification']);
        }
        if (is_array($data['rate_limit'] ?? null)) {
            $cnf->rateLimit = RateLimitConfig::fromArray($data['rate_limit']);
        }
        $cnf->enableTelemetry = (bool) ($data['enable_telemetry'] ?? false);
        $cnf->enableObservability = (bool) ($data['enable_observability'] ?? false);
        $cnf->monitoringDSN = (string) ($data['monitoring_dsn'] ?? '');
        if (is_array($data['transaction'] ?? null)) {
            $cnf->transaction = TransactionConfig::fromArray($data['transaction']);
        }
        if (is_array($data['reconciliation'] ?? null)) {
            $cnf->reconciliation = ReconciliationConfig::fromArray($data['reconciliation']);
        }
        if (is_array($data['queue'] ?? null)) {
            $cnf->queue = QueueConfig::fromArray($data['queue']);
        }
        return $cnf;
    }

    /**
     * loadConfigFromFile mirrors Go `config.loadConfigFromFile`: reads the JSON
     * config file when it exists, applies the BLNK_* env-var overrides
     * (`envconfig.Process("blnk", ...)`), validates, sets defaults, and stores
     * the result in the singleton.
     *
     * @throws \RuntimeException on unreadable/undecodable file or failed validation.
     */
    public static function loadConfigFromFile(string $file): void
    {
        $cnf = new self();
        if (file_exists($file)) {
            $contents = @file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException(sprintf('open %s: unable to read config file', $file));
            }
            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException(sprintf('error decoding config file %s: %s', $file, $e->getMessage()), 0, $e);
            }
            if (is_array($decoded)) {
                $cnf = self::fromArray($decoded);
            } elseif ($decoded !== null) {
                throw new \RuntimeException(sprintf('error decoding config file %s: expected a JSON object', $file));
            }
        } else {
            Log::get()->info('config json not passed, will use env variables');
        }

        // override config from environment variables
        $cnf->applyEnvOverrides();

        $cnf->validateAndAddDefaults();

        self::$configStore = $cnf;
    }

    /**
     * InitConfig mirrors Go `config.InitConfig`. (The Go version also
     * configures the logrus formatter; Monolog setup lives in
     * `Blnk\Internal\Log` in the PHP port.)
     */
    public static function initConfig(string $configFile): void
    {
        self::loadConfigFromFile($configFile);
    }

    /**
     * Fetch mirrors Go `config.Fetch`: returns the stored Configuration or
     * throws when none has been loaded.
     *
     * @throws \RuntimeException when no configuration has been loaded.
     */
    public static function fetch(): self
    {
        if (self::$configStore === null) {
            throw new \RuntimeException('config not loaded from file. Create a json file called blnk.json with your config ');
        }
        return self::$configStore;
    }

    /**
     * SetConfig stores the given configuration as-is in the singleton,
     * mirroring `config.ConfigStore.Store(&cnf)`.
     */
    public static function setConfig(self $config): void
    {
        self::$configStore = $config;
    }

    /**
     * MockConfig sets a mock configuration for testing purposes.
     * Mirrors Go `config.MockConfig`: validation errors are logged, not thrown.
     */
    public static function mockConfig(self $mockConfig): void
    {
        try {
            $mockConfig->validateAndAddDefaults();
        } catch (\Throwable $e) {
            Log::get()->error('error setting mock config', ['error' => $e->getMessage()]);
            return;
        }
        self::$configStore = $mockConfig;
    }

    /**
     * Applies every BLNK_* env override in the tree — the PHP equivalent of
     * `envconfig.Process("blnk", &cnf)`.
     */
    public function applyEnvOverrides(): void
    {
        $this->projectName = Env::getString('BLNK_PROJECT_NAME') ?? $this->projectName;
        $this->backupDir = Env::getString('BLNK_BACKUP_DIR') ?? $this->backupDir;
        $this->awsAccessKeyId = Env::getString('BLNK_AWS_ACCESS_KEY_ID') ?? $this->awsAccessKeyId;
        $this->s3Endpoint = Env::getString('BLNK_S3_ENDPOINT') ?? $this->s3Endpoint;
        $this->awsSecretAccessKey = Env::getString('BLNK_AWS_SECRET_ACCESS_KEY') ?? $this->awsSecretAccessKey;
        $this->s3BucketName = Env::getString('BLNK_S3_BUCKET_NAME') ?? $this->s3BucketName;
        $this->s3Region = Env::getString('BLNK_S3_REGION') ?? $this->s3Region;
        $this->typeSenseKey = Env::getString('BLNK_TYPESENSE_KEY') ?? $this->typeSenseKey;
        $this->tokenizationSecret = Env::getString('BLNK_TOKENIZATION_SECRET') ?? $this->tokenizationSecret;
        $this->enableTelemetry = Env::getBool('BLNK_ENABLE_TELEMETRY') ?? $this->enableTelemetry;
        $this->enableObservability = Env::getBool('BLNK_ENABLE_OBSERVABILITY') ?? $this->enableObservability;
        $this->monitoringDSN = Env::getString('BLNK_MONITORING_DSN') ?? $this->monitoringDSN;

        $this->server->applyEnvOverrides();
        $this->dataSource->applyEnvOverrides();
        $this->redis->applyEnvOverrides();
        $this->typeSense->applyEnvOverrides();
        $this->notification->applyEnvOverrides();
        $this->rateLimit->applyEnvOverrides();
        $this->transaction->applyEnvOverrides();
        $this->reconciliation->applyEnvOverrides();
        $this->queue->applyEnvOverrides();
    }

    /**
     * @throws \RuntimeException when required fields are missing.
     */
    public function validateAndAddDefaults(): void
    {
        $this->validateRequiredFields();

        $this->setDefaultValues();
        $this->trimWhitespace();
        $this->setupRateLimiting();

        if (!$this->server->secure) {
            Log::get()->warning(
                'SECURITY: server.secure is false — API authentication is DISABLED. Do not use this configuration in production.'
            );
        }

        // Validate tokenization secret length (AES-256 requires 32 bytes)
        if (strlen($this->tokenizationSecret) > 0 && strlen($this->tokenizationSecret) !== 32) {
            Log::get()->warning('tokenization secret should be 32 bytes for AES-256 encryption');
        }
    }

    /**
     * @throws \RuntimeException when data source or redis DNS is missing.
     */
    private function validateRequiredFields(): void
    {
        if ($this->dataSource->dns === '') {
            throw new \RuntimeException('data source DNS is required');
        }

        if ($this->redis->dns === '') {
            throw new \RuntimeException('redis DNS is required');
        }
    }

    private function setDefaultValues(): void
    {
        // Project defaults
        if ($this->projectName === '') {
            $this->projectName = 'Blnk Server';
            Log::get()->warning('project name is empty, setting default name');
        }

        // Server defaults
        if ($this->server->port === '') {
            $this->server->port = self::DEFAULT_PORT;
            Log::get()->warning('port not specified in config, setting default', ['port' => self::DEFAULT_PORT]);
        }

        if ($this->server->maxUploadSizeMB <= 0) {
            $this->server->maxUploadSizeMB = self::DEFAULT_MAX_UPLOAD_SIZE_MB;
        }

        if ($this->server->maxRequestBodySizeMB <= 0) {
            $this->server->maxRequestBodySizeMB = self::DEFAULT_MAX_REQUEST_BODY_SIZE_MB;
        }

        if ($this->server->uploadURLTimeoutSec <= 0) {
            $this->server->uploadURLTimeoutSec = self::DEFAULT_UPLOAD_URL_TIMEOUT_SEC;
        }

        if ($this->typeSenseKey === '') {
            $this->typeSenseKey = self::DEFAULT_TYPESENSE_KEY;
        }

        // Set module defaults
        $this->setRedisDefaults();
        $this->setDatabaseDefaults();
        $this->setTransactionDefaults();
        $this->setReconciliationDefaults();
        $this->setQueueDefaults();
        $this->setHashChainDefaults();

        if ($this->enableTelemetry) {
            Log::get()->info('telemetry enabled');
        } else {
            Log::get()->info('telemetry disabled');
        }

        if ($this->enableObservability) {
            Log::get()->info('observability enabled');
        } else {
            Log::get()->info('observability disabled');
        }
    }

    private function setHashChainDefaults(): void
    {
        // Enabled stays false unless explicitly turned on.
        if ($this->transaction->hashChain->pollInterval <= 0) {
            $this->transaction->hashChain->pollInterval = 5; // 5 * time.Second
        }
        if ($this->transaction->hashChain->batchSize <= 0) {
            $this->transaction->hashChain->batchSize = 1000;
        }
        if ($this->transaction->hashChain->trailingDelay <= 0) {
            $this->transaction->hashChain->trailingDelay = 30; // 30 * time.Second
        }
    }

    /**
     * Note: the Go version rescales sub-second `LockDuration`/`LockWaitTimeout`
     * values by `time.Second` because JSON numbers decode into `time.Duration`
     * as nanoseconds. The PHP port stores durations directly as seconds, so a
     * JSON value of e.g. 30 already means 30 seconds and no rescaling applies.
     */
    private function setTransactionDefaults(): void
    {
        $defaults = TransactionConfig::defaults();
        if ($this->transaction->batchSize === 0) {
            $this->transaction->batchSize = $defaults->batchSize;
        }
        if ($this->transaction->maxQueueSize === 0) {
            $this->transaction->maxQueueSize = $defaults->maxQueueSize;
        }
        if ($this->transaction->maxWorkers === 0) {
            $this->transaction->maxWorkers = $defaults->maxWorkers;
        }
        if ($this->transaction->lockDuration === 0) {
            $this->transaction->lockDuration = $defaults->lockDuration;
        }
        if ($this->transaction->lockWaitTimeout === 0) {
            $this->transaction->lockWaitTimeout = $defaults->lockWaitTimeout;
        }
        if (!$this->transaction->enableCoalescing) {
            $this->transaction->enableCoalescing = $defaults->enableCoalescing;
        }
        if ($this->transaction->indexQueuePrefix === '') {
            $this->transaction->indexQueuePrefix = $defaults->indexQueuePrefix;
        }
    }

    private function setReconciliationDefaults(): void
    {
        $defaults = ReconciliationConfig::defaults();
        if ($this->reconciliation->defaultStrategy === '') {
            $this->reconciliation->defaultStrategy = $defaults->defaultStrategy;
        }
        if ($this->reconciliation->progressInterval === 0) {
            $this->reconciliation->progressInterval = $defaults->progressInterval;
        }
        if ($this->reconciliation->maxRetries === 0) {
            $this->reconciliation->maxRetries = $defaults->maxRetries;
        }
        if ($this->reconciliation->retryDelay === 0) {
            $this->reconciliation->retryDelay = $defaults->retryDelay;
        }
    }

    /**
     * Note: the Go version multiplies a non-zero `HotPairTTL` by `time.Second`
     * (JSON numbers decode as nanoseconds); the PHP port already reads the JSON
     * number as seconds, so no multiplication is needed.
     */
    private function setQueueDefaults(): void
    {
        $defaults = QueueConfig::defaults();
        if ($this->queue->transactionQueue === '') {
            $this->queue->transactionQueue = $defaults->transactionQueue;
        }
        if ($this->queue->webhookQueue === '') {
            $this->queue->webhookQueue = $defaults->webhookQueue;
        }
        if ($this->queue->indexQueue === '') {
            $this->queue->indexQueue = $defaults->indexQueue;
        }
        if ($this->queue->inflightExpiryQueue === '') {
            $this->queue->inflightExpiryQueue = $defaults->inflightExpiryQueue;
        }
        if ($this->queue->inflightCommitQueue === '') {
            $this->queue->inflightCommitQueue = $defaults->inflightCommitQueue;
        }
        if ($this->queue->numberOfQueues === 0) {
            $this->queue->numberOfQueues = $defaults->numberOfQueues;
        }
        if ($this->queue->hotQueueName === '') {
            $this->queue->hotQueueName = $defaults->hotQueueName;
        }
        if ($this->queue->hotQueueConcurrency === 0) {
            $this->queue->hotQueueConcurrency = $defaults->hotQueueConcurrency;
        }
        if ($this->queue->hotPairTTL === 0) {
            $this->queue->hotPairTTL = $defaults->hotPairTTL;
        }
        if ($this->queue->hotPairLockContentionThreshold === 0) {
            $this->queue->hotPairLockContentionThreshold = $defaults->hotPairLockContentionThreshold;
        }
        if ($this->queue->maxRetryAttempts === 0) {
            $this->queue->maxRetryAttempts = $defaults->maxRetryAttempts;
        }
        if ($this->queue->monitoringPort === '') {
            $this->queue->monitoringPort = $defaults->monitoringPort;
        }
        if ($this->queue->webhookConcurrency === 0) {
            $this->queue->webhookConcurrency = $defaults->webhookConcurrency;
        }
        if ($this->queue->transactionWorkerConcurrency === 0) {
            $this->queue->transactionWorkerConcurrency = $defaults->transactionWorkerConcurrency;
        }
    }

    private function setRedisDefaults(): void
    {
        $defaults = RedisConfig::defaults();
        if ($this->redis->poolSize === 0) {
            $this->redis->poolSize = $defaults->poolSize;
        }
        if ($this->redis->minIdleConns === 0) {
            $this->redis->minIdleConns = $defaults->minIdleConns;
        }
    }

    private function setDatabaseDefaults(): void
    {
        $defaults = DataSourceConfig::defaults();
        if ($this->dataSource->maxOpenConns === 0) {
            $this->dataSource->maxOpenConns = $defaults->maxOpenConns;
        }
        if ($this->dataSource->maxIdleConns === 0) {
            $this->dataSource->maxIdleConns = $defaults->maxIdleConns;
        }
        if ($this->dataSource->connMaxLifetime === 0) {
            $this->dataSource->connMaxLifetime = $defaults->connMaxLifetime;
        }
        if ($this->dataSource->connMaxIdleTime === 0) {
            $this->dataSource->connMaxIdleTime = $defaults->connMaxIdleTime;
        }
    }

    private function trimWhitespace(): void
    {
        $this->projectName = trim($this->projectName);
        $this->server->port = trim($this->server->port);
        $this->dataSource->dns = trim($this->dataSource->dns);
        $this->redis->dns = trim($this->redis->dns);
    }

    /**
     * UploadDomainWhitelistHosts returns the parsed, trimmed, de-duplicated, lowercase
     * list of exact hostnames permitted as targets for URL-based reconciliation
     * uploads. Entries may be supplied as bare hostnames ("example.com") or full
     * URLs ("https://example.com/path"); the hostname is extracted either way.
     * An empty/unset whitelist yields an empty array, which the upload handler
     * treats as deny-by-default.
     *
     * @return string[]
     */
    public function uploadDomainWhitelistHosts(): array
    {
        $raw = trim($this->server->uploadDomainWhitelist);
        if ($raw === '') {
            return [];
        }

        $seen = [];
        $hosts = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            // Tolerate entries supplied as full URLs by extracting the hostname.
            $host = '';
            if (str_contains($entry, '://')) {
                $parsedHost = parse_url($entry, PHP_URL_HOST);
                if (is_string($parsedHost)) {
                    $host = $parsedHost;
                }
            } else {
                $host = $entry;
            }
            $host = strtolower(trim($host));
            if ($host === '') {
                continue;
            }
            if (isset($seen[$host])) {
                continue;
            }
            $seen[$host] = true;
            $hosts[] = $host;
        }
        return $hosts;
    }

    private function setupRateLimiting(): void
    {
        if ($this->rateLimit->requestsPerSecond === null && $this->rateLimit->burst === null) {
            $defaultRPS = 5000000.0;
            $defaultBurst = 10000000;

            $this->rateLimit->requestsPerSecond = $defaultRPS;
            $this->rateLimit->burst = $defaultBurst;

            Log::get()->info('rate limiting not configured, using defaults', [
                'rps' => $defaultRPS,
                'burst' => $defaultBurst,
            ]);
        }

        if ($this->rateLimit->requestsPerSecond !== null && $this->rateLimit->burst === null) {
            $defaultBurst = 2 * (int) $this->rateLimit->requestsPerSecond;
            $this->rateLimit->burst = $defaultBurst;
            Log::get()->warning('rate limit burst not specified, setting default', ['burst' => $defaultBurst]);
        }
        if ($this->rateLimit->requestsPerSecond === null && $this->rateLimit->burst !== null) {
            $defaultRPS = ((float) $this->rateLimit->burst) / 2;
            $this->rateLimit->requestsPerSecond = $defaultRPS;
            Log::get()->warning('rate limit RPS not specified, setting default', ['rps' => $defaultRPS]);
        }
        if ($this->rateLimit->cleanupIntervalSec === null) {
            $defaultCleanup = self::DEFAULT_CLEANUP_SEC;
            $this->rateLimit->cleanupIntervalSec = $defaultCleanup;
            Log::get()->warning('rate limit cleanup interval not specified, setting default', ['cleanup_interval_sec' => $defaultCleanup]);
        }
    }
}
