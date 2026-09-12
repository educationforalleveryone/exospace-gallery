<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebhookDeliveryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const ENV_URL = 'https://env.example.com/exospace';
    private const ENV_SECRET = 'env-shared-secret';
    private const SUB_URL_A = 'https://sub-a.example.com/hook';
    private const SUB_URL_B = 'https://sub-b.example.com/hook';
    private const SUB_SECRET_A = 'per-sub-secret-a';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.outbound_webhook.url' => self::ENV_URL]);
        config(['services.outbound_webhook.secret' => self::ENV_SECRET]);
        config(['services.operational_alerts.webhook_url' => null]);
    }

    public function test_successful_dispatch_writes_ledger_row_with_http_status_and_attempt_count_1(): void
    {
        Http::fake([self::ENV_URL => Http::response(['ok' => true], 200)]);

        config(['services.operational_alerts.webhook_url' => null]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 1]);

        $row = WebhookDelivery::orderByDesc('id')->first();

        $this->assertNotNull($row, 'expected a ledger row to be written');
        $this->assertSame('gallery.published', $row->event_type);
        $this->assertSame(self::ENV_URL, $row->target_url);
        $this->assertNull($row->subscription_id, 'env URL dispatch has no DB subscription row');
        $this->assertSame(200, $row->http_status);
        $this->assertSame(1, $row->attempt_count);
        $this->assertTrue($row->success);
        $this->assertNull($row->error_message);
        $this->assertNotNull($row->delivered_at);
    }

    public function test_dispatch_to_db_subscription_threads_subscription_id_into_ledger_row(): void
    {
        Http::fake([
            self::ENV_URL  => Http::response(['ok' => true], 200),
            self::SUB_URL_A => Http::response(['ok' => true], 200),
        ]);
        config(['services.operational_alerts.webhook_url' => null]);

        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);

        \App\Services\OutboundWebhookService::dispatch('billing.recipient_added', ['recipient_email' => 'r@example.com']);

        $this->assertSame(2, WebhookDelivery::count());

        $envRow = WebhookDelivery::whereNull('subscription_id')->first();
        $subRow = WebhookDelivery::where('subscription_id', $sub->id)->first();

        $this->assertNotNull($envRow);
        $this->assertNotNull($subRow);
        $this->assertSame(self::ENV_URL, $envRow->target_url);
        $this->assertSame(self::SUB_URL_A, $subRow->target_url);
        $this->assertSame($sub->id, $subRow->subscription_id);
    }

    public function test_retry_exhausted_on_non_2xx_writes_failed_ledger_row_with_max_attempts(): void
    {
        Http::fake(fn () => Http::response(['err' => 'down'], 500));
        config(['services.operational_alerts.webhook_url' => null]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 7]);

        $row = WebhookDelivery::orderByDesc('id')->first();

        $this->assertNotNull($row);
        $this->assertSame(500, $row->http_status);
        $this->assertSame(\App\Services\OutboundWebhookService::MAX_RETRIES, $row->attempt_count);
        $this->assertFalse($row->success);
        $this->assertNotNull($row->error_message);
        $this->assertStringContainsString('500', $row->error_message);
    }

    public function test_connection_failure_writes_ledger_row_with_null_http_status_and_exception_message(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });
        config(['services.operational_alerts.webhook_url' => null]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 99]);

        $row = WebhookDelivery::orderByDesc('id')->first();

        $this->assertNotNull($row);
        $this->assertNull($row->http_status, 'all attempts threw exceptions — no HTTP status recorded');
        $this->assertSame(\App\Services\OutboundWebhookService::MAX_RETRIES, $row->attempt_count);
        $this->assertFalse($row->success);
        $this->assertNotNull($row->error_message);
        $this->assertStringContainsString('Connection refused', $row->error_message);
    }

    public function test_silent_skip_when_no_subscribers_writes_no_ledger_row(): void
    {
        // Override the setUp env URL → fresh-install state.
        config(['services.outbound_webhook.url' => null]);
        config(['services.operational_alerts.webhook_url' => null]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 1]);

        // Silent-skip means NO dispatch attempted → NO ledger row.
        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_ledger_write_failure_does_not_break_dispatch_path(): void
    {
        Http::fake([self::ENV_URL => Http::response(['ok' => true], 200)]);

        Schema::drop('webhook_deliveries');

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 5]);

        // The dispatch still happened — the receiver got the webhook.
        Http::assertSent(fn (\Illuminate\Http\Client\Request $r) => $r->url() === self::ENV_URL);

        $this->assertFalse(Schema::hasTable('webhook_deliveries'));
    }

    public function test_paused_subscription_writes_no_ledger_row_for_that_subscription(): void
    {
        Http::fake([
            self::ENV_URL  => Http::response(['ok' => true], 200),
            self::SUB_URL_A => Http::response(['ok' => true], 200),
        ]);

        $sub = WebhookSubscription::create([
            'event_type' => 'gallery.published',
            'target_url' => self::SUB_URL_A,
            'secret'     => null,
            'is_active'  => false, // PAUSED — no dispatch, no ledger row
            'added_by'   => null,
        ]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 42]);

        $this->assertSame(1, WebhookDelivery::count());
        $this->assertSame(0, WebhookDelivery::where('subscription_id', $sub->id)->count());
    }

    public function test_one_off_override_url_dispatch_writes_ledger_row_with_null_subscription_id(): void
    {
        Http::fake(['https://override.example.com/hook' => Http::response(['ok' => true], 200)]);

        \App\Services\OutboundWebhookService::dispatch('gallery.published', ['id' => 1], 'https://override.example.com/hook');

        $row = WebhookDelivery::orderByDesc('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('https://override.example.com/hook', $row->target_url);
        $this->assertNull($row->subscription_id);
        $this->assertTrue($row->success);
    }
}
