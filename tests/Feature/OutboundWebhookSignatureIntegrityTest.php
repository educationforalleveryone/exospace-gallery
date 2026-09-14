<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OutboundWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundWebhookSignatureIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://integrity.example.com/hook';

    private const SECRET = 'integrity-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['services.outbound_webhook.url' => self::URL]);
        config(['services.outbound_webhook.secret' => self::SECRET]);
        config(['services.operational_alerts.webhook_url' => null]);
    }

    public function test_sync_signature_covers_exact_transmitted_bytes_for_tricky_payloads(): void
    {
        $payload = [
            'invoice_id' => 'INV/2026/01',
            'title' => 'Exposição de Verão — ünïcodé',
            'nested' => ['tags' => ['a', 'b'], 'price' => '29.00'],
        ];

        OutboundWebhookService::dispatch('gallery.published', $payload);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() !== self::URL) {
                return false;
            }

            $signature = $request->header('X-Exospace-Signature')[0] ?? null;
            if (! $signature) {
                return false;
            }

            $expected = hash_hmac('sha256', $request->body(), self::SECRET);

            return hash_equals($expected, $signature)
                && $request->hasHeader('Content-Type', 'application/json')
                && str_contains($request->body(), 'INV\\/2026\\/01');
        });
    }

    public function test_async_signature_covers_exact_transmitted_bytes_for_tricky_payloads(): void
    {
        $payload = [
            'invoice_id' => 'INV/2026/02',
            'title' => 'Grande fin d\'été',
            'amounts' => ['total' => '99.00'],
        ];

        OutboundWebhookService::dispatchAsync('user.upgraded', $payload);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() !== self::URL) {
                return false;
            }

            $signature = $request->header('X-Exospace-Signature')[0] ?? null;
            if (! $signature) {
                return false;
            }

            $expected = hash_hmac('sha256', $request->body(), self::SECRET);

            return hash_equals($expected, $signature)
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_events_without_signature_header_are_omitted_when_no_secret(): void
    {
        config(['services.outbound_webhook.secret' => null]);

        OutboundWebhookService::dispatch('gallery.published', ['id' => 1]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === self::URL
                && $request->header('X-Exospace-Signature') === [];
        });
    }
}
