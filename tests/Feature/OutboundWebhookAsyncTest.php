<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OutboundWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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
