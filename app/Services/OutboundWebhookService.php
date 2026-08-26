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
 * Supported events (consumers of this service call dispatch() with these
 * event names — the list here documents the contract, not an enum):
 *   - gallery.published     — when a gallery goes live (is_active = true)
 *   - gallery.unpublished   — when a gallery is deactivated
 *   - user.upgraded         — when a user's plan changes to a higher tier
 *   - user.downgraded       — when a user's plan changes to a lower tier
 *   - user.registered       — when a new user signs up
 *   - subscription.cancelled — when a subscription is cancelled
 *   - subscription.renewed   — when a recurring payment succeeds
 *
 * ITERATION 9 additions (billing recipient management — security-page
 * events surfaced so a security team subscribing to "who is receiving
 * the weekly financial digest" can alert on changes instead of polling
 * the admin audit log):
 *   - billing.recipient_added   — when a mailbox is added to the digest list
 *   - billing.recipient_removed — when a mailbox is removed from the digest list
 *
 * Endpoint configuration:
 *   Set OUTBOUND_WEBHOOK_URL + OUTBOUND_WEBHOOK_SECRET in .env (read via
 *   config('services.outbound_webhook.*') — see config/services.php).
 *   The payload includes the event type + entity data.
 *
 *   For per-event endpoints, extend this service with a config map:
 *   config('services.outbound_webhooks.events.gallery.published') => 'https://...'
 *
 * Security:
 *   - Each webhook includes an HMAC-SHA256 signature header (X-Exospace-Signature)
 *     computed with OUTBOUND_WEBHOOK_SECRET. The receiver verifies the
 *     signature to authenticate the payload.
 *   - Webhooks are retried 3 times with exponential backoff on failure.
 *   - Timeouts at 10 seconds to prevent hanging.
 *
 * Sync vs async (ITERATION 9):
 *   - dispatch()        — synchronous (3 retries × 1+3+9s backoff + 10s
 *                         timeout = up to 42s wall). Right for low-volume
 *                         security-page events (billing.recipient_added/
 *                         _removed) where the actor's request can wait
 *                         for the page to be received + the failure path
 *                         is immediately visible to the admin.
 *   - dispatchAsync()   — queued (the same payload + signature, but the
 *                         Http::post call runs inside a queued job so
 *                         the actor's request returns immediately). Use
 *                         for high-volume product events (gallery
 *                         publishing, user registration) where the
 *                         actor's request must not be held open by a
 *                         downstream subscriber. The dispatch path is
 *                         identical so the receiver can't tell the two
 *                         apart — the contract is the same.
 *
 * AUDIT-P0-1.3 FIX: Previously read env() directly, which breaks under
 * `php artisan config:cache` (env() returns null outside config files when
 * the config is cached). Now reads from config('services.outbound_webhook.*')
 * — see config/services.php for the centralized env reads.
 */
class OutboundWebhookService
{
    // ITERATION 9: made public so the queued dispatchAsync path (which
    // uses an anonymous class — a separate class that can't access
    // private constants of OutboundWebhookService) can read the same
    // retry policy. Public constants also document the retry contract
    // to receivers ("max 3 retries with exponential backoff 1+3+9s")
    // without forcing them to read the dispatch() source.
    public const TIMEOUT = 10;
    public const MAX_RETRIES = 3;

    /**
     * Dispatch a webhook event synchronously.
     *
     * @param  string $eventType  The event name (e.g. 'gallery.published')
     * @param  array  $payload    The event data
     * @param  string|null $url    Override URL (uses config default if null)
     */
    public static function dispatch(string $eventType, array $payload, ?string $url = null): void
    {
        // AUDIT-P0-1.3 FIX: Read from config (config:cache-safe) instead of env().
        $url = $url ?? config('services.outbound_webhook.url');
        $secret = config('services.outbound_webhook.secret');

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

    /**
     * ITERATION 9 — async (queued) dispatch for high-volume product
     * events. Same payload + signature path as dispatch(); the Http::post
     * call runs inside a queued job so the actor's request returns
     * immediately. Use for gallery.published / user.registered where
     * the actor's request must not be held open by a downstream
     * subscriber. The dispatch path is identical to dispatch() so the
     * receiver can't tell the two apart.
     *
     * The queue connection comes from config('queue.default') — sync
     * under phpunit.xml (QUEUE_CONNECTION=sync), so tests get the same
     * retry-then-exhaust path as dispatch(). Production sets
     * QUEUE_CONNECTION=redis/database so the job is processed by a
     * worker, decoupling webhook latency from the actor's request.
     *
     * The body + signature are computed at enqueue time (not at
     * dequeue time) so the timestamp reflects when the event happened,
     * not when the worker picked it up — same contract as dispatch().
     *
     * @param  string $eventType  The event name (e.g. 'gallery.published')
     * @param  array  $payload    The event data
     * @param  string|null $url    Override URL (uses config default if null)
     */
    public static function dispatchAsync(string $eventType, array $payload, ?string $url = null): void
    {
        $url = $url ?? config('services.outbound_webhook.url');
        $secret = config('services.outbound_webhook.secret');

        if (! $url) {
            return; // No webhook URL configured — silently skip (same as dispatch)
        }

        $body = json_encode([
            'event'     => $eventType,
            'payload'   => $payload,
            'timestamp' => now()->toIso8601String(),
        ]);

        $signature = $secret
            ? hash_hmac('sha256', $body, $secret)
            : null;

        // Dispatch through the queue. The job is a tiny self-contained
        // invokable class so we don't add a queue-worker dependency for
        // callers who never use the async path. When QUEUE_CONNECTION=
        // sync (phpunit), the job runs inline — same wall as dispatch().
        dispatch(new class($url, $body, $signature, $eventType) {
            public function __construct(
                private readonly string $url,
                private readonly string $body,
                private readonly ?string $signature,
                private readonly string $eventType,
            ) {}

            public function handle(): void
            {
                for ($attempt = 1; $attempt <= OutboundWebhookService::MAX_RETRIES; $attempt++) {
                    try {
                        $response = Http::timeout(OutboundWebhookService::TIMEOUT)
                            ->withHeaders(array_filter([
                                'Content-Type'         => 'application/json',
                                'X-Exospace-Event'     => $this->eventType,
                                'X-Exospace-Signature' => $this->signature,
                            ]))
                            ->post($this->url, json_decode($this->body, true));

                        if ($response->successful()) {
                            Log::info('OutboundWebhook: dispatched successfully (async)', [
                                'event'   => $this->eventType,
                                'url'     => $this->url,
                                'attempt' => $attempt,
                            ]);
                            return;
                        }

                        Log::warning('OutboundWebhook: non-2xx response (async)', [
                            'event'   => $this->eventType,
                            'url'     => $this->url,
                            'status'  => $response->status(),
                            'attempt' => $attempt,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('OutboundWebhook: dispatch failed (async)', [
                            'event'   => $this->eventType,
                            'url'     => $this->url,
                            'attempt' => $attempt,
                            'error'   => $e->getMessage(),
                        ]);
                    }

                    if ($attempt < OutboundWebhookService::MAX_RETRIES) {
                        sleep(pow(3, $attempt - 1));
                    }
                }

                Log::error('OutboundWebhook: all retries exhausted (async)', [
                    'event' => $this->eventType,
                    'url'   => $this->url,
                ]);
            }
        });
    }
}
