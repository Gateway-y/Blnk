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

use Blnk\Internal\Log;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;

/**
 * PostHogClient is the thin replacement for the `posthog-go` client used by
 * cmd/server.go (`posthog.NewWithConfig`, `client.Enqueue(posthog.Capture{...})`,
 * `client.Close()`): it posts capture events to the PostHog batch endpoint.
 *
 * Divergence (documented): posthog-go buffers events and flushes them from a
 * background goroutine; the PHP port sends each event synchronously (so
 * {@see enqueue()} throws on failure, which the heartbeat logs exactly as Go
 * logs the Enqueue error) and {@see close()} has nothing to flush.
 *
 * The 5-minute heartbeat goroutine of `sendHeartbeat` becomes the
 * {@see startHeartbeat()} / {@see tick()} pair: the command loops call tick()
 * regularly and the event is sent when the interval has elapsed.
 */
final class PostHogClient
{
    /** posthog-go default endpoint. */
    public const DefaultEndpoint = 'https://app.posthog.com';

    /** Path of the batch capture API. */
    private const BatchPath = '/batch/';

    /** Request timeout in seconds. */
    private const TimeoutSec = 10;

    private string $apiKey;

    private string $endpoint;

    private ClientInterface $http;

    private ?string $heartbeatID = null;

    private float $heartbeatIntervalSec = 300.0;

    private ?float $nextHeartbeatAt = null;

    private bool $closed = false;

    public function __construct(string $apiKey, string $endpoint = self::DefaultEndpoint, ?ClientInterface $http = null)
    {
        $this->apiKey = $apiKey;
        $this->endpoint = rtrim($endpoint, '/');
        $this->http = $http ?? new GuzzleClient(['timeout' => self::TimeoutSec, 'connect_timeout' => 5]);
    }

    /**
     * NewWithConfig mirrors `posthog.NewWithConfig(apiKey, posthog.Config{Endpoint: ...})`.
     */
    public static function newWithConfig(string $apiKey, string $endpoint): self
    {
        return new self($apiKey, $endpoint);
    }

    /**
     * Enqueue sends a capture event (`posthog.Capture`): keys `distinct_id`,
     * `event`, optional `timestamp` (\DateTimeInterface or RFC3339 string) and
     * `properties`.
     *
     * @param array<string, mixed> $capture
     * @throws \InvalidArgumentException when the event or distinct id is missing (posthog-go validation)
     * @throws \RuntimeException when the client is closed
     * @throws \Throwable on transport / HTTP failure
     */
    public function enqueue(array $capture): void
    {
        if ($this->closed) {
            throw new \RuntimeException('posthog: the client was closed');
        }
        $event = (string) ($capture['event'] ?? '');
        $distinctId = (string) ($capture['distinct_id'] ?? '');
        if ($event === '') {
            throw new \InvalidArgumentException('posthog: Capture.Event is required');
        }
        if ($distinctId === '') {
            throw new \InvalidArgumentException('posthog: Capture.DistinctId is required');
        }

        $timestamp = $capture['timestamp'] ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $message = [
            'type' => 'capture',
            'event' => $event,
            'distinct_id' => $distinctId,
            'timestamp' => self::formatTime($timestamp),
            'properties' => self::normalizeProperties($capture['properties'] ?? []),
        ];

        $this->http->request('POST', $this->endpoint . self::BatchPath, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['api_key' => $this->apiKey, 'batch' => [$message]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Close mirrors `client.Close()`: no further events are accepted.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->nextHeartbeatAt = null;
    }

    /**
     * startHeartbeat arms the periodic `server_heartbeat` capture (Go:
     * sendHeartbeat's `time.NewTicker(5 * time.Minute)` goroutine); the first
     * event is sent one interval after arming, as a Go ticker does.
     */
    public function startHeartbeat(string $heartbeatID, int|float $intervalSec = 300): void
    {
        $this->heartbeatID = $heartbeatID;
        $this->heartbeatIntervalSec = (float) $intervalSec;
        $this->nextHeartbeatAt = microtime(true) + $this->heartbeatIntervalSec;
    }

    /**
     * tick sends the heartbeat when its interval has elapsed; call it from the
     * command loop. Failures are logged ("Failed to send heartbeat"), as Go does.
     */
    public function tick(): void
    {
        if ($this->closed || $this->heartbeatID === null || $this->nextHeartbeatAt === null) {
            return;
        }
        $now = microtime(true);
        if ($now < $this->nextHeartbeatAt) {
            return;
        }
        $this->nextHeartbeatAt = $now + $this->heartbeatIntervalSec;

        $utcNow = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            $this->enqueue([
                'distinct_id' => $this->heartbeatID,
                'event' => 'server_heartbeat',
                'timestamp' => $utcNow,
                'properties' => [
                    'timestamp' => $utcNow,
                ],
            ]);
        } catch (\Throwable $err) {
            Log::get()->error(sprintf('Failed to send heartbeat: %s', $err->getMessage()));
        }
    }

    public function heartbeatID(): ?string
    {
        return $this->heartbeatID;
    }

    private static function formatTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.u\Z');
        }
        return (string) $value;
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private static function normalizeProperties(array $properties): array
    {
        foreach ($properties as $k => $v) {
            if ($v instanceof \DateTimeInterface) {
                $properties[$k] = self::formatTime($v);
            }
        }
        return $properties;
    }
}
