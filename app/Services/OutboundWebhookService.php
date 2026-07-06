<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * M-23: Outbound webhook service.
 *
 * Dispatches event notifications to external endpoints configured by the
 * user (or the founder). Unlike inbound webhooks (2Checkout IPN), these
 * are Exospace SENDING data TO an external service when something happens.
 *
 * Supported events:
 *   - gallery.published    — when a gallery goes live (is_active = true)
 *   - gallery.unpublished  — when a gallery is deactivated
 *   - user.upgraded        — when a user's plan changes to a higher tier
 *   - user.downgraded      — when a user's plan changes to a lower tier
 *   - user.registered      — when a new user signs up
 *   - subscription.cancelled — when a subscription is cancelled
 *   - subscription.renewed  — when a recurring payment succeeds
 *
 * Endpoint configuration:
 *   Set OUTBOUND_WEBHOOK_URL in .env to receive ALL events at a single
 *   endpoint. The payload includes the event type + entity data.
 *
 *   For per-event endpoints, extend this service with a config map:
 *   config('outbound_webhooks.gallery.published') => 'https://...'
 *
 * Security:
 *   - Each webhook includes an HMAC-SHA256 signature header (X-Exospace-Signature)
 *     computed with OUTBOUND_WEBHOOK_SECRET. The receiver verifies the
 *     signature to authenticate the payload.
 *   - Webhooks are retried 3 times with exponential backoff on failure.
 *   - Timeouts at 10 seconds to prevent hanging.
 */
class OutboundWebhookService
{
    private const TIMEOUT = 10;
    private const MAX_RETRIES = 3;

    /**
     * Dispatch a webhook event.
     *
     * @param  string $eventType  The event name (e.g. 'gallery.published')
     * @param  array  $payload    The event data
     * @param  string|null $url    Override URL (uses env OUTBOUND_WEBHOOK_URL by default)
     */
    public static function dispatch(string $eventType, array $payload, ?string $url = null): void
    {
        $url = $url ?? config('app.outbound_webhook_url') ?? env('OUTBOUND_WEBHOOK_URL');
        $secret = env('OUTBOUND_WEBHOOK_SECRET');

        if (! $url) {
            return; // No webhook URL configured — silently skip
        }

        $body = json_encode([
            'event'     => $eventType,
            'payload'   => $payload,
            'timestamp' => now()->toIso8601String(),
        ]);

        $signature = $secret
            ? hash_hmac('sha256', $body, $secret)
            : null;

        // Dispatch synchronously with retries (for low-volume events)
        // For high volume, this should be queued — but Exospace's event
        // volume is low enough that sync is fine.
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders(array_filter([
                        'Content-Type'       => 'application/json',
                        'X-Exospace-Event'   => $eventType,
                        'X-Exospace-Signature' => $signature,
                    ]))
                    ->post($url, json_decode($body, true));

                if ($response->successful()) {
                    Log::info('OutboundWebhook: dispatched successfully', [
                        'event'   => $eventType,
                        'url'     => $url,
                        'attempt' => $attempt,
                    ]);
                    return; // Success — no more retries
                }

                Log::warning('OutboundWebhook: non-2xx response', [
                    'event'   => $eventType,
                    'url'     => $url,
                    'status'  => $response->status(),
                    'attempt' => $attempt,
                ]);
            } catch (\Throwable $e) {
                Log::warning('OutboundWebhook: dispatch failed', [
                    'event'   => $eventType,
                    'url'     => $url,
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                ]);
            }

            // Exponential backoff: 1s, 3s, 9s
            if ($attempt < self::MAX_RETRIES) {
                sleep(pow(3, $attempt - 1));
            }
        }

        Log::error('OutboundWebhook: all retries exhausted', [
            'event' => $eventType,
            'url'   => $url,
        ]);
    }
}
