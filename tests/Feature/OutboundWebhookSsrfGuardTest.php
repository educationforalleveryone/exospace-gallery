<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundWebhookSsrfGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        config(['services.operational_alerts.webhook_url' => null]);
    }

    private function actingAsMfaSuperAdmin(): self
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin)->withSession([
            'mfa_verified' => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    // ── Guard unit checks (pure, no DNS) ────────────────────────────────

    public function test_guard_rejects_non_routable_ip_literals(): void
    {
        $blocked = [
            'https://127.0.0.1/hook',            // loopback
            'https://10.1.2.3/hook',             // RFC1918 private
            'https://172.16.0.9/hook',           // RFC1918 private
            'https://172.31.255.255/hook',       // RFC1918 private (upper)
            'https://192.168.1.1/hook',          // RFC1918 private
            'https://169.254.169.254/hook',      // link-local metadata service
            'https://0.0.0.0/hook',              // this-host
            'https://100.64.0.1/hook',           // carrier-grade NAT
            'https://224.0.0.1/hook',            // multicast
            'https://240.0.0.1/hook',            // reserved
            'https://[::1]/hook',                // IPv6 loopback
            'https://[fe80::1]/hook',            // IPv6 link-local
            'https://[fc00::1]/hook',            // IPv6 unique-local
            'https://[::ffff:127.0.0.1]/hook',   // IPv4-mapped loopback
            'https://[::ffff:10.0.0.1]/hook',    // IPv4-mapped private
        ];

        foreach ($blocked as $url) {
            $this->assertFalse(
                OutboundUrlGuard::isPubliclyRoutable($url),
                "Guard must block non-routable target: {$url}"
            );
        }
    }

    public function test_guard_accepts_public_ip_literals_and_hostname_urls(): void
    {
        $allowed = [
            'https://8.8.8.8/hook',
            'https://172.32.0.1/hook',   // just outside RFC1918
            'https://100.128.0.1/hook',  // just outside carrier-grade NAT
            'https://2606:4700::1111/hook',
            'https://hooks.example.com/hook',
            'https://example.com/hook',
        ];

        foreach ($allowed as $url) {
            $this->assertTrue(
                OutboundUrlGuard::isPubliclyRoutable($url),
                "Guard must allow publicly routable target: {$url}"
            );
        }
    }

    public function test_guard_blocks_local_and_internal_looking_hostnames(): void
    {
        $blocked = [
            'https://localhost/hook',
            'https://metadata.localhost/hook',
            'https://printer.local/hook',
            'https://metadata.google.internal/hook',
        ];

        foreach ($blocked as $url) {
            $this->assertFalse(
                OutboundUrlGuard::isPubliclyRoutable($url),
                "Guard must block internal-looking hostname: {$url}"
            );
        }
    }

    // ── Store endpoint enforcement ──────────────────────────────────────

    public function test_store_rejects_subscription_pointing_at_the_metadata_service(): void
    {
        $this->actingAsMfaSuperAdmin()
            ->post(route('super.webhooks.store'), [
                'event_type' => 'gallery.published',
                'target_url' => 'https://169.254.169.254/latest/meta-data/',
            ])
            ->assertSessionHasErrors(['target_url']);

        $this->assertDatabaseCount('webhook_subscriptions', 0);
    }

    public function test_store_rejects_subscription_pointing_at_loopback(): void
    {
        $this->actingAsMfaSuperAdmin()
            ->post(route('super.webhooks.store'), [
                'event_type' => 'gallery.published',
                'target_url' => 'https://127.0.0.1:8000/hook',
            ])
            ->assertSessionHasErrors(['target_url']);

        $this->assertDatabaseCount('webhook_subscriptions', 0);
    }

    public function test_store_rejects_subscription_pointing_at_a_private_range(): void
    {
        $this->actingAsMfaSuperAdmin()
            ->post(route('super.webhooks.store'), [
                'event_type' => 'gallery.published',
                'target_url' => 'https://192.168.10.20/hook',
            ])
            ->assertSessionHasErrors(['target_url']);

        $this->assertDatabaseCount('webhook_subscriptions', 0);
    }

    public function test_store_rejects_subscription_pointing_at_an_internal_hostname(): void
    {
        $this->actingAsMfaSuperAdmin()
            ->post(route('super.webhooks.store'), [
                'event_type' => 'gallery.published',
                'target_url' => 'https://coordinator.internal/hook',
            ])
            ->assertSessionHasErrors(['target_url']);

        $this->assertDatabaseCount('webhook_subscriptions', 0);
    }

    public function test_store_still_accepts_a_public_hostname(): void
    {
        $this->actingAsMfaSuperAdmin()
            ->post(route('super.webhooks.store'), [
                'event_type' => 'gallery.published',
                'target_url' => 'https://hooks.example.com/ok',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('webhook_subscriptions', [
            'event_type' => 'gallery.published',
            'target_url' => 'https://hooks.example.com/ok',
        ]);
    }
}
