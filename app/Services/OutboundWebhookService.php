<?php

namespace App\Services;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OutboundWebhookService
{
    public const TIMEOUT = 10;
    public const MAX_RETRIES = 3;

    public static function dispatch(string $eventType, array $payload, ?string $url = null): void
    {
        if ($url !== null) {
            static::dispatchSingle($eventType, $payload, $url);
            return;
        }

        // Read from config (config:cache-safe).
        $envUrl = config('services.outbound_webhook.url');
        $globalSecret = config('services.outbound_webhook.secret');

        $subscriptions = Schema::hasTable('webhook_subscriptions')
            ? WebhookSubscription::forEvent($eventType)
            : collect();

        if ($subscriptions->isEmpty() && ! $envUrl) {
            return;
        }

        if ($envUrl) {
            static::dispatchSingle($eventType, $payload, $envUrl, $globalSecret);
        }

        foreach ($subscriptions as $sub) {
            $secret = $sub->secret !== null && $sub->secret !== ''
                ? $sub->secret
                : $globalSecret;

            static::dispatchSingle($eventType, $payload, $sub->target_url, $secret, $sub->id);
        }
    }

    private static function dispatchSingle(string $eventType, array $payload, string $url, ?string $secret = null, ?int $subscriptionId = null): void
    {
        $body = json_encode([
            'event'     => $eventType,
            'payload'   => $payload,
            'timestamp' => now()->toIso8601String(),
        ]);

        $signature = $secret
            ? hash_hmac('sha256', $body, $secret)
            : null;

        $lastHttpStatus = null;
        $lastError = null;
        $succeeded = false;
        $finalAttempt = 0;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $finalAttempt = $attempt;

            try {
                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders(array_filter([
                        'Content-Type'       => 'application/json',
                        'X-Exospace-Event'   => $eventType,
                        'X-Exospace-Signature' => $signature,
                    ]))
                    ->post($url, json_decode($body, true));

                $lastHttpStatus = $response->status();

                if ($response->successful()) {
                    Log::info('OutboundWebhook: dispatched successfully', [
                        'event'   => $eventType,
                        'url'     => $url,
                        'attempt' => $attempt,
                    ]);
                    $succeeded = true;
                    break; // Success — no more retries
                }

                $lastError = 'Non-2xx response: HTTP ' . $response->status();
                Log::warning('OutboundWebhook: non-2xx response', [
                    'event'   => $eventType,
                    'url'     => $url,
                    'status'  => $response->status(),
                    'attempt' => $attempt,
                ]);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
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

        if (! $succeeded) {
            Log::error('OutboundWebhook: all retries exhausted', [
                'event' => $eventType,
                'url'   => $url,
            ]);
        }

        try {
            if (Schema::hasTable('webhook_deliveries')) {
                WebhookDelivery::create([
                    'subscription_id' => $subscriptionId,
                    'event_type'      => $eventType,
                    'target_url'      => $url,
                    'http_status'     => $lastHttpStatus,
                    'attempt_count'   => $finalAttempt,
                    'success'         => $succeeded,
                    'error_message'   => $succeeded ? null : $lastError,
                    'delivered_at'    => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('OutboundWebhook: delivery ledger write failed', [
                'event'          => $eventType,
                'url'            => $url,
                'subscription_id' => $subscriptionId,
                'error'          => $e->getMessage(),
            ]);
        }
    }

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
