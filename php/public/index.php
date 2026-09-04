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

/*
 * Blnk HTTP front controller (PHP port).
 *
 * This is what php-fpm / `php -S 0.0.0.0:5001 -t public` serve. It mirrors the
 * `blnk start` command of cmd/server.go for one request: load the
 * configuration, open the datasource, build the Blnk service and the API
 * router, register the server-level routes (/health, /metrics), and run the
 * Slim application.
 *
 * Configuration file: BLNK_CONFIG (path) when set, otherwise ./blnk.json
 * relative to the project root (Go: the `--config` flag, default ./blnk.json).
 * BLNK_* environment variables override the file as in Go.
 */

use Blnk\Api\Api;
use Blnk\Api\Deferred;
use Blnk\Api\Json;
use Blnk\Api\Middleware\MetricsAuth;
use Blnk\Config\Configuration;
use Blnk\Core\Blnk;
use Blnk\Database\Datasource;
use Blnk\Internal\Log;
use Blnk\Internal\Notification\Notification;
use Blnk\Internal\Traces\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * loadInstance mirrors cmd/main.go `loadInstance` + `setupBlnk`: initialize
 * the configuration from the config file, open the datasource and build the
 * Blnk service.
 *
 * @throws \Throwable when the configuration, database or Redis cannot be initialized
 */
function blnk_load_instance(string $configFile): Blnk
{
    // Initialize configuration from the specified configuration file.
    Configuration::initConfig($configFile);

    // Fetch the configuration settings.
    $cnf = Configuration::fetch();

    try {
        // Initialize a new data source from the configuration.
        $db = Datasource::newDataSource($cnf);
    } catch (\Throwable $err) {
        throw new \RuntimeException(sprintf('error getting datasource: %s', $err->getMessage()), 0, $err);
    }

    try {
        // Create a new Blnk instance using the initialized data source.
        return Blnk::newBlnk($db);
    } catch (\Throwable $err) {
        Log::get()->error($err->getMessage());
        throw new \RuntimeException(sprintf('error creating blnk: %s', $err->getMessage()), 0, $err);
    }
}

/**
 * healthCheckHandler mirrors cmd/server.go: reports UP when the database
 * answers a ping, DOWN (503) otherwise.
 */
function blnk_health_check_handler(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
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
        $ds->conn->query('SELECT 1');
    } catch (\Throwable) {
        return Json::write($response, 503, ['status' => 'DOWN', 'reason' => 'database ping failed']);
    }

    return Json::write($response, 200, ['status' => 'UP']);
}

$configFile = getenv('BLNK_CONFIG');
if ($configFile === false || $configFile === '') {
    $configFile = dirname(__DIR__) . '/blnk.json';
}

try {
    $blnk = blnk_load_instance($configFile);
} catch (\Throwable $err) {
    Notification::notifyError($err);
    Log::get()->error(sprintf('error loading config: %s', $err->getMessage()));
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo Json::encode(['status' => 'DOWN', 'reason' => 'server not initialized']);
    exit(1);
}

// Initialize router (Go: initializeRouter)
$api = Api::newApi($blnk);
$app = $api->app();
$app->get('/health', 'blnk_health_check_handler');

// /metrics is served only when the tracing stub exposes a handler, guarded by
// the bearer-token middleware exactly as in cmd/server.go.
$metricsHandler = Tracer::metricsHandler();
if (is_callable($metricsHandler)) {
    $cfg = Configuration::fetch();
    $app->get('/metrics', static function (ServerRequestInterface $request, ResponseInterface $response) use ($metricsHandler): ResponseInterface {
        $result = $metricsHandler($request, $response);

        return $result instanceof ResponseInterface ? $result : $response;
    })->add(MetricsAuth::metricsAuth($cfg->server->secure, $cfg->server->metricsBearerToken));
}

$app->run();

// Work the Go handlers hand to goroutines (reindex, balance snapshots) runs
// after the response has reached the client.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
Deferred::run();
