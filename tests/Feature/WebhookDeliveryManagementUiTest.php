<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookDeliveryManagementUiTest extends TestCase
{
    use RefreshDatabase;

    private const ENV_URL = 'https://env.example.com/exospace';
    private const ENV_SECRET = 'env-shared-secret';
    private const SUB_URL_A = 'https://sub-a.example.com/hook';
    private const SUB_URL_B = 'https://sub-b.example.com/hook';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        config(['services.outbound_webhook.url' => self::ENV_URL]);
        config(['services.outbound_webhook.secret' => self::ENV_SECRET]);
        config(['services.operational_alerts.webhook_url' => null]);
    }

    private function createMfaSuperAdmin(): User
    {
        return User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);
    }

    private function actingAsMfaSuperAdmin(?User $admin = null): self
    {
        $admin ??= $this->createMfaSuperAdmin();

        return $this->actingAs($admin)->withSession([
            'mfa_verified'    => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    public function test_index_shows_no_count_tiles_when_no_subscriptions_exist(): void
    {
        $response = $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.index'));

        $response->assertOk();
        $response->assertDontSee('Per-event subscription counts');
    }

    public function test_index_shows_per_event_count_tiles_with_active_and_paused_counts(): void
    {
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_B,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);
        WebhookSubscription::create([
            'event_type' => 'gallery.published',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);
        WebhookSubscription::create([
            'event_type' => 'gallery.published',
            'target_url' => self::SUB_URL_B,
            'secret'     => null, 'is_active' => false, 'added_by' => null,
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.index'));

        $response->assertOk();
        $response->assertSee('Per-event subscription counts', false);
        // billing.recipient_added tile: 2 active, no paused.
        $response->assertSee('billing.recipient_added', false);
        $response->assertSee('2 active', false);
        // gallery.published tile: 1 active, 1 paused.
        $response->assertSee('gallery.published', false);
        $response->assertSee('1 active', false);
        $response->assertSee('1 paused', false);
    }

    public function test_index_shows_dash_for_last_delivery_when_subscription_has_no_deliveries(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.index'));

        $response->assertOk();
        // The "Last delivery" column header should be present.
        $response->assertSee('Last delivery', false);
        $response->assertSee('>', false); // sanity check the cell rendered
        $this->assertSame(0, WebhookDelivery::where('subscription_id', $sub->id)->count());
    }

    public function test_index_shows_checkmark_and_http_status_for_last_successful_delivery(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);

        WebhookDelivery::create([
            'subscription_id' => $sub->id,
            'event_type'      => 'billing.recipient_added',
            'target_url'      => self::SUB_URL_A,
            'http_status'     => 200,
            'attempt_count'   => 1,
            'success'         => true,
            'error_message'   => null,
            'delivered_at'    => now()->subMinutes(3),
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.index'));

        $response->assertOk();
        $response->assertSee('HTTP 200', false);
        $response->assertSee('attempt 1/' . \App\Services\OutboundWebhookService::MAX_RETRIES, false);
    }

    public function test_index_shows_x_and_http_status_for_last_failed_delivery(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);

        WebhookDelivery::create([
            'subscription_id' => $sub->id,
            'event_type'      => 'billing.recipient_added',
            'target_url'      => self::SUB_URL_A,
            'http_status'     => 500,
            'attempt_count'   => 3,
            'success'         => false,
            'error_message'   => 'Non-2xx response: HTTP 500',
            'delivered_at'    => now()->subHour(),
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.index'));

        $response->assertOk();
        $response->assertSee('HTTP 500', false);
        $response->assertSee('attempt 3/' . \App\Services\OutboundWebhookService::MAX_RETRIES, false);
    }

    public function test_index_shows_history_link_per_subscription(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.index'));

        $response->assertOk();
        // The History action button + the per-row deliveries link.
        $response->assertSee(route('super.webhooks.deliveries', $sub), false);
        $response->assertSee('History', false);
    }
}
