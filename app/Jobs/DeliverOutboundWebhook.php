<?php

namespace App\Jobs;

use App\Services\OutboundWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Delivers a single outbound webhook notification from the queue.
 *
 * The transmitted body bytes are fixed at dispatch time and the HMAC
 * signature is computed over exactly those bytes, so every attempt signs
 * the identical payload. Non-2xx and transport errors throw to hand the
 * delivery back to the queue for the remaining attempts; once attempts
 * are exhausted the job lands in failed_jobs where the ops tooling
 * surfaces it.
 */
class DeliverOutboundWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> seconds between attempts 1→2 and 2→3 */
    public array $backoff = [5, 15];

    // One attempt is a single request with OutboundWebhookService::TIMEOUT;
    // 30s leaves ample headroom without approaching the queue retry_after.
    public int $timeout = 30;

    public function __construct(
        public readonly string $url,
        public readonly string $body,
        public readonly ?string $signature,
        public readonly string $eventType,
    ) {}

    public function handle(): void
    {
        try {
            // The HMAC is computed over $body, so the transmitted bytes must
            // be exactly $body — never a re-encoded copy of the decoded array.
            $response = Http::timeout(OutboundWebhookService::TIMEOUT)
                ->withHeaders(array_filter([
                    'Content-Type'         => 'application/json',
                    'X-Exospace-Event'     => $this->eventType,
                    'X-Exospace-Signature' => $this->signature,
                ]))
                ->send('post', $this->url, ['body' => $this->body]);
        } catch (\Throwable $e) {
            Log::warning('OutboundWebhook: dispatch failed (async)', [
                'event'   => $this->eventType,
                'url'     => $this->url,
                'attempt' => $this->attempts(),
                'error'   => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Outbound webhook transport error on attempt {$this->attempts()}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->successful()) {
            Log::info('OutboundWebhook: dispatched successfully (async)', [
                'event'   => $this->eventType,
                'url'     => $this->url,
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        Log::warning('OutboundWebhook: non-2xx response (async)', [
            'event'   => $this->eventType,
            'url'     => $this->url,
            'status'  => $response->status(),
            'attempt' => $this->attempts(),
        ]);

        throw new RuntimeException(
            "Outbound webhook rejected on attempt {$this->attempts()}: HTTP {$response->status()}"
        );
    }
}
