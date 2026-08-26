<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 11 — per-subscription delivery history page.
 *
 * Coverage: the /master-control/webhooks/{subscription}/deliveries
 * page — paginated list of every webhook_deliveries row for this
 * subscription. The surface an operator uses when triaging "did the
 * security team receive the recipient_added webhook last Tuesday?"
 * instead of greping rotated laravel.log files.
 *
 * Read-only — no audit log row (mirrors the BillingController::index
 * precedent: "view list" ≠ "export PII"). Requires super-admin + MFA.
 *
 * Run: php artisan test --filter=WebhookDeliveryHistoryTest
 */
class WebhookDeliveryHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const ENV_URL = 'https://env.example.com/exospace';
    private const ENV_SECRET = 'env-shared-secret';
    private const SUB_URL_A = 'https://sub-a.example.com/hook';

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

    public function test_deliveries_page_requires_super_admin(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);

        // Anonymous user → redirect to login.
        $this->get(route('super.webhooks.deliveries', $sub))
            ->assertRedirect(route('login'));

        // Non-super-admin (verified + MFA) → 403.
        $nonSuper = User::factory()->withMfa()->create([
            'is_super_admin'    => false,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($nonSuper)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->get(route('super.webhooks.deliveries', $sub))
            ->assertForbidden();
    }

    public function test_deliveries_page_requires_mfa_even_for_super_admin(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);

        $admin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        // Super-admin WITHOUT MFA session → redirect to MFA verify.
        $this->actingAs($admin)
            ->get(route('super.webhooks.deliveries', $sub))
            ->assertRedirect(route('mfa.verify'));
    }

    public function test_deliveries_page_lists_deliveries_newest_first(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);

        // Three deliveries across 3 hours — the page should render them
        // newest first (id=3, then id=2, then id=1).
        $oldest = WebhookDelivery::create([
            'subscription_id' => $sub->id,
            'event_type'      => 'billing.recipient_added',
            'target_url'      => self::SUB_URL_A,
            'http_status'     => 200,
            'attempt_count'   => 1,
            'success'         => true,
            'error_message'   => null,
            'delivered_at'    => now()->subHours(3),
        ]);
        $middle = WebhookDelivery::create([
            'subscription_id' => $sub->id,
            'event_type'      => 'billing.recipient_added',
            'target_url'      => self::SUB_URL_A,
            'http_status'     => 500,
            'attempt_count'   => 3,
            'success'         => false,
            'error_message'   => 'Non-2xx response: HTTP 500',
            'delivered_at'    => now()->subHours(2),
        ]);
        $newest = WebhookDelivery::create([
            'subscription_id' => $sub->id,
            'event_type'      => 'billing.recipient_added',
            'target_url'      => self::SUB_URL_A,
            'http_status'     => 200,
            'attempt_count'   => 1,
            'success'         => true,
            'error_message'   => null,
            'delivered_at'    => now()->subHour(),
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.deliveries', $sub));

        $response->assertOk();
        // The page shows the subscription metadata tile.
        $response->assertSee('Subscription', false);
        $response->assertSee($sub->event_type, false);
        $response->assertSee($sub->target_url, false);

        // Use diffForHumans output as the unique lookup string — each
        // delivery row has a distinct "X hours ago" that's not used
        // elsewhere in the page (delivery ids aren't rendered).
        $content = $response->content();
        $newestPos = strpos($content, '1 hour ago');
        $middlePos = strpos($content, '2 hours ago');
        $oldestPos = strpos($content, '3 hours ago');

        $this->assertNotFalse($newestPos, 'newest delivery row not rendered');
        $this->assertNotFalse($middlePos, 'middle delivery row not rendered');
        $this->assertNotFalse($oldestPos, 'oldest delivery row not rendered');
        $this->assertLessThan($middlePos, $newestPos, 'newest should appear BEFORE middle');
        $this->assertLessThan($oldestPos, $middlePos, 'middle should appear BEFORE oldest');
    }

    public function test_deliveries_page_shows_latest_delivery_tile(): void
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
            'delivered_at'    => now()->subMinutes(5),
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.deliveries', $sub));

        $response->assertOk();
        $response->assertSee('Latest delivery', false);
        $response->assertSee('HTTP 200', false);
    }

    public function test_deliveries_page_empty_state_renders_friendly_message(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => self::SUB_URL_A,
            'secret'     => null, 'is_active' => true, 'added_by' => null,
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.deliveries', $sub));

        $response->assertOk();
        $response->assertSee('No deliveries recorded', false);
    }

    public function test_deliveries_page_returns_404_for_nonexistent_subscription(): void
    {
        $nonexistentId = 99999999;
        $this->actingAsMfaSuperAdmin()
            ->get(route('super.webhooks.deliveries', $nonexistentId))
            ->assertNotFound();
    }

    public function test_deliveries_page_renders_failed_delivery_with_error_message(): void
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
            'http_status'     => null,
            'attempt_count'   => \App\Services\OutboundWebhookService::MAX_RETRIES,
            'success'         => false,
            'error_message'   => 'Connection timed out',
            'delivered_at'    => now()->subHour(),
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.deliveries', $sub));

        $response->assertOk();
        $response->assertSee('failed', false);
        $response->assertSee('Connection timed out', false);
        $response->assertSee('attempt ' . \App\Services\OutboundWebhookService::MAX_RETRIES . '/' . \App\Services\OutboundWebhookService::MAX_RETRIES, false);
    }

    public function test_deliveries_page_does_not_write_audit_row(): void
    {
        // Mirrors the BillingController::index precedent: "view list"
        // ≠ "export PII" — the operator viewing the page doesn't move
        // data out of the system, so no audit row is written. Verified
        // by counting admin_audit_logs rows before/after the GET.
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
            'delivered_at'    => now(),
        ]);

        $auditBefore = \App\Models\AdminAuditLog::count();
        $this->actingAsMfaSuperAdmin()->get(route('super.webhooks.deliveries', $sub))->assertOk();
        $auditAfter = \App\Models\AdminAuditLog::count();

        $this->assertSame($auditBefore, $auditAfter, 'viewing the deliveries page should NOT write an audit row (read-only, mirrors BillingController::index)');
    }
}
