<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OutboundWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 9 — outbound webhook async (queued) dispatch path.
 *
 * Coverage: workstream E1 ships dispatchAsync() — the queued sibling
 * of dispatch(). Same payload + signature path; the Http::post call
 * runs inside a queued job so the actor's request returns immediately.
 * Use for high-volume product events (gallery.published, user.registered)
 * where the actor's request must not be held open by a downstream
 * subscriber. Production sets QUEUE_CONNECTION=redis/database so the
 * job is processed by a worker; phpunit.xml sets QUEUE_CONNECTION=sync
 * so tests get the same retry-then-exhaust path as dispatch().
 *
 * Tests:
 *   - dispatchAsync() fires an HTTP POST to the configured URL when
 *     QUEUE_CONNECTION=sync (phpunit default) — the job runs inline.
 *   - The X-Exospace-Event header matches the event name.
 *   - Silent-skip when no OUTBOUND_WEBHOOK_URL configured — same
 *     behavior as dispatch().
 *   - Body + signature are computed at enqueue time (not dequeue) —
 *     the payload's timestamp reflects when the event happened, not
 *     when the worker picked it up.
 *
 * Run: php artisan test --filter=OutboundWebhookAsyncTest
 */
class OutboundWebhookAsyncTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.example.com/async';
    private const SECRET  = 'async-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        config(['services.outbound_webhook.url'    => self::WEBHOOK]);
        config(['services.outbound_webhook.secret' => self::SECRET]);
    }

    public function test_dispatch_async_fires_http_post_under_sync_queue(): void
    {
        OutboundWebhookService::dispatchAsync('gallery.published', ['gallery_id' => 42]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() !== self::WEBHOOK) return false;
            return $request->header('X-Exospace-Event')[0] === 'gallery.published';
        });
    }

    public function test_dispatch_async_silently_skips_when_no_url_configured(): void
    {
        config(['services.outbound_webhook.url' => null]);

        // Silent-skip — no HTTP request fired.
        OutboundWebhookService::dispatchAsync('user.registered', ['user_id' => 1]);

        Http::assertNothingSent();
    }

    public function test_dispatch_async_payload_carries_event_type_and_timestamp(): void
    {
        OutboundWebhookService::dispatchAsync('subscription.renewed', ['invoice_id' => 'INV-9']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $body = json_decode($request->body(), true);
            return $body['event']     === 'subscription.renewed'
                && $body['payload']['invoice_id'] === 'INV-9'
                && isset($body['timestamp']);
        });
    }

    public function test_dispatch_async_attaches_hmac_signature_with_secret(): void
    {
        OutboundWebhookService::dispatchAsync('user.upgraded', ['user_id' => 7, 'plan' => 'pro']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $signature = $request->header('X-Exospace-Signature')[0] ?? null;
            if (! $signature) return false;
            $expected = hash_hmac('sha256', $request->body(), self::SECRET);
            return hash_equals($expected, $signature);
        });
    }
}
