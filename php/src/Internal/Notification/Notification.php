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

namespace Blnk\Internal\Notification;

use Blnk\Config\Configuration;
use Blnk\Internal\Log;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Port of internal/notification/notification.go: Slack and webhook error
 * notification dispatch.
 *
 * Concurrency divergence (documented): Go's NotifyError runs the notification
 * in a goroutine to avoid blocking; per PORTING.md ("Concurrency") the PHP
 * port runs it synchronously — every failure is swallowed and logged, so the
 * caller still never fails because of a notification.
 */
final class Notification
{
    /**
     * WebhookSender defines the callable signature for sending webhooks:
     * fn (string $event, mixed $payload): void, throwing on error
     * (Go: func(event string, payload interface{}) error).
     *
     * @var callable|null
     */
    private static $webhookSender = null;

    /**
     * slackNotification sends an error message to a Slack webhook.
     * It formats the error details and the current time into a Slack message payload.
     *
     * Parameters:
     * - $err: The error to be reported via Slack.
     *
     * The function retrieves configuration for the Slack webhook URL, formats the error,
     * and sends it as a JSON payload to the Slack webhook.
     */
    public static function slackNotification(\Throwable $err): void
    {
        // Build the Slack message payload as structured arrays and json_encode
        // so error text containing quotes, backslashes, or newlines is safely
        // encoded instead of corrupting (or injecting into) the JSON template.
        $payloadStruct = [
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => 'Error From Blnk 🐞', 'emoji' => true],
                ],
                [
                    'type' => 'section',
                    'fields' => [['type' => 'mrkdwn', 'text' => sprintf("*Error:*\n%s", $err->getMessage())]],
                ],
                [
                    'type' => 'section',
                    // Go formats time.Now() with time.RFC822 ("02 Jan 06 15:04 MST").
                    'fields' => [['type' => 'mrkdwn', 'text' => sprintf("*Time:*\n%s", (new \DateTimeImmutable('now'))->format('d M y H:i T'))]],
                ],
            ],
        ];

        try {
            $encoded = json_encode($payloadStruct, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            Log::get()->error($e->getMessage());

            return;
        }

        // Fetch the configuration, including the Slack webhook URL
        try {
            $conf = Configuration::fetch();
        } catch (\Throwable $e) {
            Log::get()->error($e->getMessage());

            return;
        }

        // Send the request and handle the response (Go: request.Call — JSON
        // content type, 30s timeout, response body decoded and discarded).
        try {
            $client = new GuzzleClient(['timeout' => 30]);
            $client->request('POST', (string) $conf->notification->slack->webhookUrl, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $encoded,
            ]);
        } catch (\Throwable $e) {
            Log::get()->error($e->getMessage());
        }
    }

    /**
     * registerWebhookSender registers a function to handle webhook sending.
     *
     * @param callable(string, mixed): void $sender
     */
    public static function registerWebhookSender(callable $sender): void
    {
        self::$webhookSender = $sender;
    }

    /**
     * getWebhookSender returns the currently registered webhook sender, if any.
     */
    private static function getWebhookSender(): ?callable
    {
        return self::$webhookSender;
    }

    /**
     * notifyError sends an error notification through the configured notification system.
     * It logs the error locally and sends a notification via Slack (if configured).
     *
     * Parameters:
     * - $systemError: The error to notify.
     */
    public static function notifyError(\Throwable $systemError): void
    {
        // Log the error locally
        Log::get()->error($systemError->getMessage());

        // Fetch the configuration
        try {
            $conf = Configuration::fetch();
        } catch (\Throwable $e) {
            Log::get()->error($e->getMessage());

            return;
        }

        // If Slack is configured, send the error notification to Slack
        if ((string) $conf->notification->slack->webhookUrl !== '') {
            self::slackNotification($systemError);
        }

        // If a webhook sender is registered and webhook URL is configured, send the webhook
        $sender = self::getWebhookSender();
        if ($sender !== null && (string) $conf->notification->webhook->url !== '') {
            $payload = [
                'error' => $systemError->getMessage(),
                'time' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::RFC3339),
            ];
            try {
                $sender('system.error', $payload);
            } catch (\Throwable $e) {
                Log::get()->error(sprintf('Error sending webhook notification: %s', $e->getMessage()));
            }
        }
    }
}
