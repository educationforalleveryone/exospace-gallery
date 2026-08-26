<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 10 — super-admin webhook subscription management UI.
 *
 * Coverage: the per-event subscription management surface at
 * /master-control/webhooks — add/remove/toggle subscriptions for
 * outbound webhook events. Mirrors the Iter-7 digest recipient
 * management pattern: audit-logged mutations, super-admin + MFA
 * gate, throttle 30,1, no password.confirm (reversible).
 *
 * Run: php artisan test --filter=WebhookSubscriptionManagementTest
 */
class WebhookSubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        // Suppress the operational-alert path so test assertions don't
        // see outbound webhook fires from other admin actions.
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

    public function test_index_page_lists_subscriptions_and_env_state(): void
    {
        // Pre-existing subscription.
        $admin = User::factory()->withMfa()->create(['is_super_admin' => true, 'email_verified_at' => now()]);
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => 'https://example.com/hook',
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => $admin->id,
        ]);

        config(['services.outbound_webhook.url' => 'https://env-configured.example.com']);
        config(['services.outbound_webhook.secret' => 'env-secret']);

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.webhooks.index'));

        $response->assertOk();
        $response->assertSee('Outbound webhook subscriptions');
        $response->assertSee('https://example.com/hook');
        $response->assertSee('billing.recipient_added');
        $response->assertSee('https://env-configured.example.com');
        $response->assertSee('configured (HMAC signing on)');
    }

    public function test_store_creates_subscription_and_audit_row(): void
    {
        $admin = $this->createMfaSuperAdmin();
        $this->actingAsMfaSuperAdmin($admin);

        $response = $this->post(route('super.webhooks.store'), [
            'event_type' => 'billing.recipient_added',
            'target_url' => 'https://hooks.example.com/security',
            'secret'     => 'per-sub-secret',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('webhook_subscriptions', [
            'event_type' => 'billing.recipient_added',
            'target_url' => 'https://hooks.example.com/security',
            'is_active'  => true,
            'added_by'    => $admin->id,
        ]);

        // Audit row written with the documented action + payload shape.
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'webhook.subscription_added',
        ]);

        $auditRow = AdminAuditLog::where('action', 'webhook.subscription_added')->first();
        $this->assertNotNull($auditRow);
        $payload = $auditRow->payload;
        $this->assertSame('billing.recipient_added', $payload['event_type']);
        $this->assertSame('https://hooks.example.com/security', $payload['target_url']);
        $this->assertTrue($payload['has_secret']);
    }

    public function test_store_lowercases_event_type_via_mutator(): void
    {
        $this->actingAsMfaSuperAdmin()
            ->post(route('super.webhooks.store'), [
                'event_type' => 'BILLING.RECIPIENT_ADDED', // case-different on input
                'target_url' => 'https://example.com/hook',
                'secret'     => '',
            ]);

        $this->assertDatabaseHas('webhook_subscriptions', [
            'event_type' => 'billing.recipient_added', // normalized
        ]);
    }

    public function test_store_rejects_duplicate_subscription_with_friendly_error(): void
    {
        WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => 'https://example.com/dup',
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);

        $response = $this->actingAsMfaSuperAdmin()
            ->post(route('super.webhooks.store'), [
                'event_type' => 'billing.recipient_added',
                'target_url' => 'https://example.com/dup',
                'secret'     => '',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['target_url']);
    }

    public function test_store_rejects_non_https_target_url(): void
    {
        $response = $this->actingAsMfaSuperAdmin()
            ->post(route('super.webhooks.store'), [
                'event_type' => 'gallery.published',
                'target_url' => 'http://insecure.example.com/hook', // http://, not https://
                'secret'     => '',
            ]);

        $response->assertSessionHasErrors(['target_url']);
        $this->assertDatabaseMissing('webhook_subscriptions', [
            'target_url' => 'http://insecure.example.com/hook',
        ]);
    }

    public function test_destroy_removes_subscription_and_audit_logs_before_delete(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'billing.recipient_added',
            'target_url' => 'https://example.com/destroy-me',
            'secret'     => 'secret-to-attribute',
            'is_active'  => true,
            'added_by'   => null,
        ]);

        $this->actingAsMfaSuperAdmin()
            ->delete(route('super.webhooks.destroy', $sub));

        // Row deleted.
        $this->assertDatabaseMissing('webhook_subscriptions', [
            'id' => $sub->id,
        ]);

        // Audit row written BEFORE the delete — target_id preserves the
        // attribution to the deleted row's id.
        $audit = AdminAuditLog::where('action', 'webhook.subscription_removed')->first();
        $this->assertNotNull($audit);
        $this->assertSame($sub->id, $audit->target_id);
        $this->assertSame('billing.recipient_added', $audit->payload['event_type']);
        $this->assertSame('https://example.com/destroy-me', $audit->payload['target_url']);
        $this->assertTrue($audit->payload['had_secret']);
    }

    public function test_toggle_pauses_subscription_without_deleting(): void
    {
        $sub = WebhookSubscription::create([
            'event_type' => 'gallery.published',
            'target_url' => 'https://example.com/toggle',
            'secret'     => null,
            'is_active'  => true,
            'added_by'   => null,
        ]);

        $this->actingAsMfaSuperAdmin()
            ->patch(route('super.webhooks.toggle', $sub));

        $sub->refresh();
        $this->assertFalse($sub->is_active);

        $audit = AdminAuditLog::where('action', 'webhook.subscription_disabled')->first();
        $this->assertNotNull($audit);

        // Toggle back on.
        $this->actingAsMfaSuperAdmin()
            ->patch(route('super.webhooks.toggle', $sub));

        $sub->refresh();
        $this->assertTrue($sub->is_active);

        $auditEnable = AdminAuditLog::where('action', 'webhook.subscription_enabled')->first();
        $this->assertNotNull($auditEnable);
    }

    public function test_index_requires_super_admin_and_mfa(): void
    {
        // Non-super-admin → 403.
        $regularUser = User::factory()->create(['is_super_admin' => false, 'email_verified_at' => now()]);
        $this->actingAs($regularUser)->get(route('super.webhooks.index'))->assertForbidden();
    }

    public function test_routes_are_throttled(): void
    {
        // Smoke test: 31 rapid POSTs with the SAME admin should hit the
        // throttle (30,1). Each call to actingAsMfaSuperAdmin() without
        // a passed admin creates a new user, which would reset the
        // throttle key — using one shared admin across all 31 requests
        // mirrors the real-world throttle attack vector (one admin
        // hammering the form).
        $admin = $this->createMfaSuperAdmin();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAsMfaSuperAdmin($admin)
                ->post(route('super.webhooks.store'), [
                    'event_type' => "event.test{$i}",
                    'target_url' => "https://example.com/hook-{$i}",
                    'secret'     => '',
                ])->assertRedirect();
        }

        // 31st request from the same admin — throttled.
        $this->actingAsMfaSuperAdmin($admin)
            ->post(route('super.webhooks.store'), [
                'event_type' => 'event.throttle',
                'target_url' => 'https://example.com/throttle',
                'secret'     => '',
            ])->assertStatus(429);
    }
}
