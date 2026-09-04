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

namespace Blnk\Cmd;

use Blnk\Api\Api;
use Blnk\Api\Json;
use Blnk\Api\Middleware\MetricsAuth;
use Blnk\Config\Configuration;
use Blnk\Config\ServerConfig;
use Blnk\Core\ChainProcessor;
use Blnk\Core\LineageOutboxProcessor;
use Blnk\Database\Datasource;
use Blnk\Internal\Log;
use Blnk\Internal\Search\ReindexService;
use Blnk\Internal\Search\TypesenseClient;
use Blnk\Internal\Traces\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use Slim\App;

/**
 * ServerCommand is the port of cmd/server.go: the `blnk start` command and
 * its helpers (TLS/cert resolution, TypeSense bootstrap, PostHog heartbeat,
 * health check, router wiring, graceful shutdown).
 *
 * Serving model (documented divergence — PORTING.md "HTTP layer"): Go serves
 * the gin router from this process. PHP serves HTTP per request through the
 * front controller public/index.php (which mirrors {@see initializeRouter()}),
 * so `blnk start`:
 *
 *  1. builds the router once ({@see initializeRouter()}) to validate the wiring,
 *  2. runs the process-wide background work Go runs in goroutines — the
 *     lineage outbox processor, the hash-chain processor (when enabled) and
 *     the telemetry heartbeat — from its supervisor loop,
 *  3. launches PHP's built-in web server as a supervised child
 *     (`php -S 0.0.0.0:<port> -t public public/index.php`, see
 *     {@see BuiltinHttpServer}), streams its output and forwards SIGTERM/SIGINT
 *     for a graceful shutdown with the same 30s timeout as Go.
 *
 * Production: serve public/index.php with php-fpm behind nginx (or any
 * FastCGI front end; `fastcgi_finish_request` lets deferred work run after
 * the response) and run `BLNK_HTTP_SERVER=external blnk start` for the
 * background processors — the built-in server is single-threaded and meant
 * for development/compose setups.
 */
final class ServerCommand
{
    public const Use = 'start';
    public const Short = 'start blnk server';

    /** Fallback CertMagic storage path. */
    public const DefaultCertStoragePath = '/var/lib/blnk/certs';

    /** SQLite file persisting the telemetry heartbeat id. */
    public const HeartbeatDBPath = './heartbeat.db';

    /** PostHog project key and endpoint of the Blnk telemetry (cmd/server.go initializePostHog). */
    public const PostHogAPIKey = 'phc_XbsHF5iBSnPiTA96gl7xygazrwBa0r2Ut4vEHoBHNiG';
    public const PostHogEndpoint = 'https://us.i.posthog.com';

    /** Heartbeat period (Go: time.NewTicker(5 * time.Minute)). */
    public const HeartbeatIntervalSec = 300;

    /** Graceful shutdown timeout of the HTTP server (Go: 30 * time.Second). */
    public const ShutdownTimeoutSec = 30;

    /** TypeSense bootstrap retries (Go: retryWithBackoff(ctx, 5, 2*time.Second, ...)). */
    public const TypeSenseInitAttempts = 5;
    public const TypeSenseInitBaseDelaySec = 2;

    /** Collections whose TypeSense schema is migrated at startup. */
    public const TypeSenseCollections = ['ledgers', 'balances', 'transactions', 'identities', 'reconciliations'];

    /** Lineage outbox poll interval (LineageOutboxProcessor default: 1 * time.Second). */
    private const LineagePollIntervalSec = 1;

    /** How long one supervisor iteration waits for child output. */
    private const WaitSliceSec = 0.1;

    /**
     * resolveCertStoragePath returns the configured certificate storage path,
     * falling back to the default location when unset.
     */
    public static function resolveCertStoragePath(ServerConfig $conf): string
    {
        if ($conf->certStoragePath === '') {
            return self::DefaultCertStoragePath;
        }
        return $conf->certStoragePath;
    }

    /**
     * resolveTLSDomains returns the certificate domains, defaulting to localhost
     * when no domain is configured.
     *
     * @return string[]
     */
    public static function resolveTLSDomains(ServerConfig $conf): array
    {
        if ($conf->domain === '') {
            return ['localhost'];
        }
        return [$conf->domain];
    }

    /**
     * serveTLS starts an HTTPS server with TLS enabled using CertMagic for automatic certificate management.
     * It accepts a gin.Engine instance as the router and a ServerConfig struct for server configurations.
     * If no domain is specified, the server will default to running on localhost.
     *
     * PHP port: automatic ACME certificate management has no equivalent for
     * the built-in server; TLS is terminated by the reverse proxy in front of
     * public/index.php. The function resolves the same settings as Go and then
     * fails explicitly. (Go's `start` command never calls serveTLS either.)
     *
     * @throws \RuntimeException always
     */
    public static function serveTLS(App $r, ServerConfig $conf): void
    {
        $storagePath = self::resolveCertStoragePath($conf);

        // Define domain(s) for the certificate
        if ($conf->domain === '') {
            Log::get()->error('No domain specified, defaulting to localhost');
        }
        $domains = self::resolveTLSDomains($conf);

        throw new \RuntimeException(sprintf(
            'serveTLS: automatic TLS (CertMagic/ACME for %s, email %s, storage %s) is not available in the PHP port; terminate TLS at the reverse proxy in front of public/index.php',
            implode(',', $domains),
            $conf->email,
            $storagePath
        ));
    }

    /**
     * migrateTypeSenseSchema ensures that the necessary TypeSense schema is migrated for all required collections.
     * It takes a TypesenseClient as parameter.
     * This function loops through the predefined collections and migrates their schema in TypeSense.
     *
     * @throws \Throwable if an error occurs during migration
     */
    public static function migrateTypeSenseSchema(TypesenseClient $t): void
    {
        // Define the collections to migrate schema for
        $collections = self::TypeSenseCollections;

        // Migrate schema for each collection
        foreach ($collections as $c) {
            $t->migrateTypeSenseSchema($c); // Throws if an error occurs during migration
        }
    }

    public static function getOrCreateHeartbeatID(): string
    {
        return self::getOrCreateHeartbeatIDAt(self::HeartbeatDBPath);
    }

    /**
     * getOrCreateHeartbeatIDAt persists a stable heartbeat UUID in a SQLite file
     * at the given path, creating it on first use. Any storage failure falls
     * back to a fresh (non-persistent) UUID.
     *
     * (Uses PDO's sqlite driver; when ext-pdo_sqlite is missing the open fails
     * and the fallback applies, as for any other storage failure.)
     */
    public static function getOrCreateHeartbeatIDAt(string $path): string
    {
        try {
            $db = new \PDO('sqlite:' . $path);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Failed to open SQLite DB: %s', $err->getMessage()));
            return Uuid::uuid4()->toString(); // fallback to temp UUID
        }

        try {
            $db->exec('CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT)');
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Failed to create config table: %s', $err->getMessage()));
            return Uuid::uuid4()->toString();
        }

        try {
            $stmt = $db->query("SELECT value FROM config WHERE key = 'heartbeat_id'");
            $value = $stmt === false ? false : $stmt->fetchColumn();
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Failed to read heartbeat_id: %s', $err->getMessage()));
            return Uuid::uuid4()->toString();
        }

        if ($value === false || $value === null) { // sql.ErrNoRows
            $heartbeatID = Uuid::uuid4()->toString();
            try {
                $insert = $db->prepare('INSERT INTO config (key, value) VALUES (?, ?)');
                $insert->execute(['heartbeat_id', $heartbeatID]);
            } catch (\Throwable $err) {
                Log::get()->error(sprintf('Failed to insert heartbeat_id: %s', $err->getMessage()));
            }
            return $heartbeatID;
        }

        return (string) $value;
    }

    /**
     * sendHeartbeat initializes and maintains a periodic heartbeat to PostHog.
     *
     * PHP port: arms the client's heartbeat; the command loops call
     * {@see PostHogClient::tick()} which sends `server_heartbeat` every
     * {@see HeartbeatIntervalSec} seconds (Go: a ticker goroutine).
     */
    public static function sendHeartbeat(PostHogClient $client, string $heartbeatID): void
    {
        $client->startHeartbeat($heartbeatID, self::HeartbeatIntervalSec);
    }

    /**
     * healthCheckHandler reports UP when the database answers a ping, DOWN (503) otherwise.
     */
    public static function healthCheckHandler(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $cfg = Configuration::fetch();
        } catch (\Throwable) {
            return Json::write($response, 503, ['status' => 'DOWN', 'reason' => 'config unavailable']);
        }

        try {
            $ds = Datasource::getDBConnection($cfg);
        } catch (\Throwable) {
            return Json::write($response, 503, ['status' => 'DOWN', 'reason' => 'database unreachable']);
        }

        try {
            $ds->conn->query('SELECT 1'); // ds.Conn.PingContext(ctx) with a 3s budget
        } catch (\Throwable) {
            return Json::write($response, 503, ['status' => 'DOWN', 'reason' => 'database ping failed']);
        }

        return Json::write($response, 200, ['status' => 'UP']);
    }

    /**
     * initializeRouter builds the API router with the server-level routes
     * (/health, and /metrics behind MetricsAuth when the tracing layer exposes
     * a handler). The same wiring is performed per request by public/index.php,
     * which is what actually serves HTTP.
     */
    public static function initializeRouter(BlnkInstance $b): App
    {
        $router = Api::newApi($b->blnk)->router();
        $router->get('/health', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            return self::healthCheckHandler($request, $response);
        });
        $h = Tracer::metricsHandler();
        if (\is_callable($h)) {
            $secure = false;
            $token = '';
            try {
                $cfg = Configuration::fetch();
                $secure = $cfg->server->secure;
                $token = $cfg->server->metricsBearerToken;
            } catch (\Throwable) {
                // cfg == nil: open metrics with no token, as in Go
            }
            $router->get('/metrics', static function (ServerRequestInterface $request, ResponseInterface $response) use ($h): ResponseInterface {
                $result = $h($request, $response);
                return $result instanceof ResponseInterface ? $result : $response;
            })->add(MetricsAuth::metricsAuth($secure, $token));
        }
        return $router;
    }

    /**
     * initializeOpenTelemetry sets up the OTel SDK (a no-op logging stub in the
     * PHP port) and returns its shutdown function.
     *
     * @return callable(): void
     * @throws \RuntimeException "error setting up OTel SDK: ..."
     */
    public static function initializeOpenTelemetry(string $monitoringDSN): callable
    {
        try {
            return Tracer::setupOTelSDK('BLNK', $monitoringDSN);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('error setting up OTel SDK: %s', $err->getMessage()), 0, $err);
        }
    }

    /**
     * initializeTypeSense creates the TypeSense client, ensures the collections
     * exist and migrates their schema, retrying with backoff. Returns null (search
     * disabled) when no TypeSense DNS is configured.
     *
     * @param callable(): bool|null $canceled plays the role of ctx.Done() for the retry loop
     * @throws \RuntimeException when initialization keeps failing
     */
    public static function initializeTypeSense(Configuration $cfg, ?callable $canceled = null): ?TypesenseClient
    {
        if ($cfg->typeSense->dns === '') {
            Log::get()->warning('TypeSense DNS not configured. Search functionality will be disabled.');
            return null;
        }

        $newSearch = new TypesenseClient($cfg->typeSenseKey, [$cfg->typeSense->dns]);

        self::retryWithBackoff(self::TypeSenseInitAttempts, self::TypeSenseInitBaseDelaySec, static function () use ($newSearch): void {
            $newSearch->ensureCollectionsExist();
            self::migrateTypeSenseSchema($newSearch);
        }, $canceled);

        return $newSearch;
    }

    /**
     * retryWithBackoff runs fn up to attempts times with exponential backoff
     * starting at baseDelay. It stops early when the context is canceled and
     * returns the last error when all attempts fail.
     *
     * @param callable(): void $fn throws on failure
     * @param callable(): bool|null $canceled returns true once the "context" is canceled
     * @throws \RuntimeException "context canceled" or "failed to initialize TypeSense after N attempts: ..."
     */
    public static function retryWithBackoff(int $attempts, int|float $baseDelaySec, callable $fn, ?callable $canceled = null): void
    {
        $retryDelay = (float) $baseDelaySec;
        $err = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $fn();
                return;
            } catch (\Throwable $e) {
                $err = $e;
            }

            Log::get()->error(sprintf('TypeSense initialization failed (attempt %d/%d): %s. Retrying in %s...', $i + 1, $attempts, $err->getMessage(), self::durationString($retryDelay)));

            // select { case <-ctx.Done(): return ctx.Err(); case <-time.After(retryDelay): retryDelay *= 2 }
            if (self::sleepUnlessCanceled($retryDelay, $canceled)) {
                throw new \RuntimeException('context canceled');
            }
            $retryDelay *= 2;
        }
        throw new \RuntimeException(sprintf('failed to initialize TypeSense after %d attempts: %s', $attempts, $err !== null ? $err->getMessage() : ''));
    }

    /**
     * initializePostHog creates the PostHog client and starts the heartbeat.
     *
     * @return array{0: PostHogClient, 1: string} the client and the heartbeat id
     */
    public static function initializePostHog(): array
    {
        $client = PostHogClient::newWithConfig(self::PostHogAPIKey, self::PostHogEndpoint);
        $heartbeatID = self::getOrCreateHeartbeatID();
        self::sendHeartbeat($client, $heartbeatID);
        return [$client, $heartbeatID];
    }

    /**
     * startServer builds and starts the HTTP server, then blocks until SIGINT
     * or SIGTERM and shuts it down gracefully (30s timeout).
     *
     * @param array<string, string> $env environment overrides for the built-in server child (PHP-only)
     * @param callable(): void|null $onIdle PHP-only: work to run on every wait iteration (the
     *   background processors Go runs in goroutines next to the server)
     * @throws \RuntimeException "Server error: ..." when the server cannot start or dies, or the
     *   forced-shutdown error of gracefulShutdown
     */
    public static function startServer(App $router, string $port, array $env = [], ?callable $onIdle = null): void
    {
        $server = self::newHTTPServer($router, $port, $env);

        // Start server in goroutine
        Log::get()->info(sprintf('Server started on port %s', $port));
        try {
            $server->listenAndServe();
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('Server error: %s', $err->getMessage()), 0, $err);
        }

        // Wait for interrupt signal
        $quit = SignalTrap::install([SignalTrap::SIGINT, SignalTrap::SIGTERM]);
        try {
            self::gracefulShutdown($server, static fn (): bool => $quit->done(), self::ShutdownTimeoutSec, $onIdle);
        } finally {
            $quit->release();
        }
    }

    /**
     * newHTTPServer builds the API HTTP server for the given router and port.
     *
     * PHP port: the router is served by public/index.php in the child process
     * (or by php-fpm when BLNK_HTTP_SERVER=external); `$router` is the
     * already-validated in-process router and is not served from here.
     *
     * @param array<string, string> $env
     */
    public static function newHTTPServer(App $router, string $port, array $env = []): BuiltinHttpServer
    {
        $mode = getenv(BuiltinHttpServer::ModeEnv);
        $external = \is_string($mode) && strtolower(trim($mode)) === BuiltinHttpServer::ModeExternal;
        $docroot = \dirname(__DIR__, 2) . '/public';
        return new BuiltinHttpServer(':' . $port, $docroot, $env, $external);
    }

    /**
     * gracefulShutdown blocks until a signal arrives on quit, then shuts the
     * server down, giving outstanding requests up to timeout to complete.
     *
     * While waiting, the PHP port pumps the child's output and runs `$onIdle`
     * (the goroutine work of the Go server). If the child dies meanwhile the
     * wait ends with "Server error: ..." (Go: logrus.Fatalf in the serve goroutine).
     *
     * @param callable(): bool $quit true once SIGINT/SIGTERM was received
     * @param callable(): void|null $onIdle
     * @throws \RuntimeException
     */
    public static function gracefulShutdown(BuiltinHttpServer $server, callable $quit, int|float $timeoutSec, ?callable $onIdle = null): void
    {
        // <-quit
        while (!$quit()) {
            try {
                $server->pump(self::WaitSliceSec);
            } catch (\Throwable $err) {
                if ($quit()) {
                    break; // the signal reached the child first; proceed with the shutdown
                }
                throw new \RuntimeException(sprintf('Server error: %s', $err->getMessage()), 0, $err);
            }
            if ($onIdle !== null) {
                $onIdle();
            }
        }

        Log::get()->info('Shutting down server...');

        try {
            $server->shutdown((float) $timeoutSec);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Server forced to shutdown: %s', $err->getMessage()));
            throw $err;
        }

        Log::get()->info('Server exited gracefully');
    }

    /**
     * initializeTelemetryAndObservability (renamed from initializeObservability
     * to better reflect its purpose) sets up tracing when observability is
     * enabled and PostHog when telemetry is enabled.
     *
     * @return array{0: PostHogClient|null, 1: callable(): void} the PostHog client (or null) and the tracing shutdown function
     * @throws \RuntimeException "failed to initialize tracing: ..."
     */
    public static function initializeTelemetryAndObservability(Configuration $cfg): array
    {
        $phClient = null;
        $tracingShutdown = static function (): void {
        };

        // Initialize tracing if observability is enabled
        if ($cfg->enableObservability) {
            try {
                $tracingShutdown = self::initializeOpenTelemetry($cfg->remoteMonitoringDSN());
            } catch (\Throwable $err) {
                throw new \RuntimeException(sprintf('failed to initialize tracing: %s', $err->getMessage()), 0, $err);
            }
        }

        // Initialize PostHog if telemetry is enabled
        if ($cfg->enableTelemetry) {
            [$phClient] = self::initializePostHog();
        }

        return [$phClient, $tracingShutdown];
    }

    /**
     * run is the `start` command (serverCommands' Run): it sets up the API
     * routes, traces, and TypeSense client before launching the server, then
     * runs the lineage outbox / hash-chain processors next to it. Returns the
     * process exit code (Go: logrus.Fatal exits 1).
     */
    public static function run(BlnkInstance $b): int
    {
        /** @var array<int, callable(): void> $deferred Go `defer`s, run LIFO */
        $deferred = [];

        try {
            // Load configuration
            try {
                $cfg = Configuration::fetch();
            } catch (\Throwable $err) {
                Log::get()->error($err->getMessage());
                return 1; // Go continues with a nil cfg and panics right after: same exit status
            }

            // Initialize telemetry and observability before the router,
            // so MetricsHandler() is available when routes are registered.
            try {
                [$phClient, $shutdown] = self::initializeTelemetryAndObservability($cfg);
            } catch (\Throwable $err) {
                Log::get()->critical($err->getMessage());
                return 1;
            }
            $deferred[] = static function () use ($shutdown): void {
                try {
                    $shutdown();
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('Error during shutdown: %s', $err->getMessage()));
                }
            };
            if ($phClient !== null) {
                $deferred[] = static fn () => $phClient->close();
            }

            // Initialize router (after OTel so /metrics handler is available)
            $router = self::initializeRouter($b);

            // Initialize TypeSense
            $tsClient = null;
            try {
                $tsClient = self::initializeTypeSense($cfg);
            } catch (\Throwable $err) {
                Log::get()->error(sprintf('TypeSense initialization error: %s', $err->getMessage()));
            }
            if ($tsClient !== null) {
                try {
                    ReindexService::tryReindexIfNeeded($tsClient, $b->blnk->getDataSource());
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('reindex failed: %s', $err->getMessage()));
                }
            }

            // Close database connection pool on shutdown
            $deferred[] = static function () use ($cfg): void {
                try {
                    $ds = Datasource::getDBConnection($cfg);
                } catch (\Throwable) {
                    return; // Go: err == nil && ds != nil guard
                }
                Log::get()->info('Closing database connection pool...');
                try {
                    $ds->close();
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('Error closing database connection: %s', $err->getMessage()));
                }
            };

            // Start lineage outbox processor
            // This worker processes pending lineage entries that were captured atomically with transactions
            $lineageProcessor = LineageOutboxProcessor::newLineageOutboxProcessor($b->blnk);
            $lineageProcessor->start();
            $deferred[] = static fn () => $lineageProcessor->stop();

            // Start the hash-chain processor when enabled. It seals transactions
            // into a tamper-evident chain off the hot path.
            $chainProcessor = null;
            if ($cfg->transaction->hashChain->enabled) {
                $chainProcessor = ChainProcessor::newChainProcessor($b->blnk);
                $chainProcessor->start();
                $deferred[] = static fn () => $chainProcessor->stop();
            }

            // Start server
            $env = $b->configFile !== '' ? ['BLNK_CONFIG' => $b->configFile] : [];
            try {
                self::startServer($router, $cfg->server->port, $env, self::backgroundTicker($cfg, $lineageProcessor, $chainProcessor, $phClient));
            } catch (\Throwable $err) {
                Log::get()->critical($err->getMessage());
                return 1;
            }

            return 0;
        } finally {
            foreach (array_reverse($deferred) as $fn) {
                try {
                    $fn();
                } catch (\Throwable $err) {
                    Log::get()->error($err->getMessage());
                }
            }
        }
    }

    /**
     * backgroundTicker returns the `$onIdle` callback of {@see startServer()}:
     * it drives the processors Go runs in goroutines next to the HTTP server
     * — the lineage outbox processor every second, the hash-chain processor
     * every `hash_chain.poll_interval` seconds — and the telemetry heartbeat.
     *
     * @return callable(): void
     */
    private static function backgroundTicker(Configuration $cfg, LineageOutboxProcessor $lineageProcessor, ?ChainProcessor $chainProcessor, ?PostHogClient $phClient): callable
    {
        $lineageInterval = (float) self::LineagePollIntervalSec;
        $chainInterval = (float) ($cfg->transaction->hashChain->pollInterval > 0 ? $cfg->transaction->hashChain->pollInterval : 5);
        $nextLineage = microtime(true) + $lineageInterval;
        $nextChain = microtime(true) + $chainInterval;

        return static function () use ($lineageProcessor, $chainProcessor, $phClient, $lineageInterval, $chainInterval, &$nextLineage, &$nextChain): void {
            $now = microtime(true);
            if ($lineageProcessor->isRunning() && $now >= $nextLineage) {
                $nextLineage = $now + $lineageInterval;
                try {
                    $lineageProcessor->runOnce();
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('lineage outbox processing failed: %s', $err->getMessage()));
                }
            }
            if ($chainProcessor !== null && $chainProcessor->isRunning() && $now >= $nextChain) {
                $nextChain = $now + $chainInterval;
                try {
                    $chainProcessor->runOnce();
                } catch (\Throwable $err) {
                    Log::get()->error(sprintf('hash-chain processing failed: %s', $err->getMessage()));
                }
            }
            if ($phClient !== null) {
                $phClient->tick();
            }
        };
    }

    /**
     * sleepUnlessCanceled sleeps `$seconds` in small slices, returning true as
     * soon as `$canceled()` reports cancellation (Go: select on ctx.Done()).
     *
     * @param callable(): bool|null $canceled
     */
    private static function sleepUnlessCanceled(float $seconds, ?callable $canceled): bool
    {
        if ($canceled !== null && $canceled()) {
            return true;
        }
        $deadline = microtime(true) + $seconds;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return false;
            }
            usleep((int) min(100_000, (int) ceil($remaining * 1_000_000)));
            if ($canceled !== null && $canceled()) {
                return true;
            }
        }
    }

    /** durationString formats seconds the way Go prints a time.Duration with %v (e.g. "2s", "1.5s", "500ms"). */
    private static function durationString(float $seconds): string
    {
        if ($seconds < 1) {
            return rtrim(rtrim(sprintf('%.3F', $seconds * 1000), '0'), '.') . 'ms';
        }
        return rtrim(rtrim(sprintf('%.3F', $seconds), '0'), '.') . 's';
    }
}
