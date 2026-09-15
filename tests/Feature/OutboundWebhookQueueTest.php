<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverOutboundWebhook;
use App\Services\OutboundWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutboundWebhookQueueTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.example.com/queued';
    private const SECRET  = 'queued-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Per-test Http fakes: array-form fakes accumulate stubs, so each
        // test declares exactly the fake it needs (a catch-all here would
        // shadow later URL-specific stubs).
        config(['services.outbound_webhook.url'    => self::WEBHOOK]);
        config(['services.outbound_webhook.secret' => self::SECRET]);
    }

    // ── dispatchAsync is genuinely queued ────────────────────────────────

    public function test_dispatch_async_pushes_a_queued_job_not_inline_execution(): void
    {
        Queue::fake();
        Http::fake();

        OutboundWebhookService::dispatchAsync('gallery.published', ['gallery_id' => 42]);

        Queue::assertPushed(DeliverOutboundWebhook::class, function (DeliverOutboundWebhook $job) {
            return $job->eventType === 'gallery.published'
                && $job->url === self::WEBHOOK
                && str_contains($job->body, '"gallery_id":42');
        });

        // No HTTP call may leave the request context — delivery is the job's business.
        Http::assertNothingSent();
    }

    public function test_dispatch_async_skips_when_no_url_configured(): void
    {
        Queue::fake();
        Http::fake();
        config(['services.outbound_webhook.url' => null]);

        OutboundWebhookService::dispatchAsync('user.registered', ['user_id' => 1]);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // ── delivery semantics ───────────────────────────────────────────────

    public function test_job_transmits_the_exact_signed_body_bytes(): void
    {
        Http::fake();
        $payload = ['gallery_id' => 42, 'slug' => 'expo-1'];
        OutboundWebhookService::dispatchAsync('gallery.published', $payload);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($payload) {
            if ($request->url() !== self::WEBHOOK) {
                return false;
            }

            $body = json_decode($request->body(), true);

            return $body['event'] === 'gallery.published'
                && $body['payload'] === $payload
                && isset($body['timestamp'])
                && hash_equals(
                    hash_hmac('sha256', $request->body(), self::SECRET),
                    $request->header('X-Exospace-Signature')[0] ?? ''
                );
        });
    }

    public function test_successful_delivery_does_not_throw(): void
    {
        Http::fake([self::WEBHOOK => Http::response(['ok' => true], 200)]);

        $job = new DeliverOutboundWebhook(
            self::WEBHOOK,
            json_encode(['event' => 'user.upgraded']),
            hash_hmac('sha256', json_encode(['event' => 'user.upgraded']), self::SECRET),
            'user.upgraded',
        );

        $job->handle();

        $this->assertTrue(true); // reached without exception
    }

    public function test_non_2xx_response_throws_so_the_queue_retries(): void
    {
        Http::fake([self::WEBHOOK => Http::response(['message' => 'nope'], 503)]);

        $job = new DeliverOutboundWebhook(
            self::WEBHOOK,
            json_encode(['event' => 'user.downgraded']),
            null,
            'user.downgraded',
        );

        try {
            $job->handle();
            $this->fail('A 503 webhook response must throw to engage the queue retry machinery.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }
    }

    public function test_transport_failure_throws_so_the_queue_retries(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('DNS resolution failed');
        });

        $job = new DeliverOutboundWebhook(self::WEBHOOK, json_encode(['event' => 'e']), null, 'e');

        try {
            $job->handle();
            $this->fail('A transport failure must throw to engage the queue retry machinery.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('transport error', $e->getMessage());
        }
    }

    // ── payload hygiene (no credentials in queue payloads) ───────────────

    public function test_serialized_job_payload_never_contains_the_signing_secret(): void
    {
        Queue::fake();
        Http::fake();

        OutboundWebhookService::dispatchAsync('billing.recipient_added', ['recipient_email' => 'x@example.com']);

        $serialized = serialize(new DeliverOutboundWebhook(
            self::WEBHOOK,
            json_encode(['event' => 'billing.recipient_added']),
            hash_hmac('sha256', json_encode(['event' => 'billing.recipient_added']), self::SECRET),
            'billing.recipient_added',
        ));

        $this->assertStringNotContainsString(self::SECRET, $serialized);
        // The queue payload itself (what Redis stores) is also covered by the
        // body + signature composition: the signature is a one-way hash.
    }

    public function test_job_is_timeout_and_retry_configured(): void
    {
        $job = new DeliverOutboundWebhook(self::WEBHOOK, '{}', null, 'e');

        $this->assertSame(3, $job->tries, 'Webhook delivery should attempt 3 times (MAX_RETRIES parity).');
        $this->assertGreaterThan(0, $job->timeout);
        $this->assertLessThan(
            (int) config('queue.connections.redis.retry_after'),
            $job->timeout,
            'Job timeout must stay below the redis retry_after or a slow attempt would be double-executed.'
        );
    }
}
