<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityP1Test extends TestCase
{
    use RefreshDatabase;

    // ── P1-7: /profile and /billing require verified email ─────────────

    public function test_profile_redirects_unverified_user_to_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/profile');

        // The 'verified' middleware redirects to the verification notice page
        $response->assertRedirect(route('verification.notice'));
    }

    public function test_profile_export_redirects_unverified_user(): void
    {
        // PII export should NOT be accessible to unverified users
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/profile/export');

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_billing_redirects_unverified_user_to_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/billing');

        $response->assertRedirect(route('verification.notice'));
    }

    public function test_billing_upgrade_redirects_unverified_user(): void
    {
        // Unverified users should NOT be able to mint a pending_upgrade
        config(['services.2checkout.product_id_pro' => 'PRO-001']);

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/billing/upgrade/pro');

        $response->assertRedirect(route('verification.notice'));
        // No pending_upgrade should have been created
        $this->assertDatabaseCount('pending_upgrades', 0);
    }

    public function test_profile_accessible_to_verified_user(): void
    {
        $user = User::factory()->create(); // email_verified_at = now() by default

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
    }

    public function test_billing_accessible_to_verified_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/billing');

        $response->assertOk();
    }

    public function test_mfa_verify_get_route_is_accessible(): void
    {
        $user = User::factory()->superAdmin()->withMfa()->create();

        $response = $this->actingAs($user)->get('/mfa/verify');

        $response->assertOk();
    }

    public function test_mfa_verify_post_is_throttled(): void
    {
        $user = User::factory()->superAdmin()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->post('/mfa/verify', ['code' => '123456']);
        }

        // 7th request should be throttled
        $response = $this->actingAs($user)
            ->post('/mfa/verify', ['code' => '123456']);

        $response->assertStatus(429); // Too Many Requests
    }

    public function test_mfa_setup_post_is_throttled(): void
    {
        // The POST /mfa/setup route should also be throttled
        $user = User::factory()->superAdmin()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->post('/mfa/setup', ['code' => '123456']);
        }

        $response = $this->actingAs($user)
            ->post('/mfa/setup', ['code' => '123456']);

        $response->assertStatus(429);
    }

    public function test_banned_user_is_still_blocked(): void
    {
        // Verify the existing ban behavior still works after P1-8 changes
        $user = User::factory()->banned()->create();

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
    }

    public function test_non_banned_user_is_not_affected(): void
    {
        // Verify non-banned users can still access the app
        $user = User::factory()->create([
            'banned_at' => null,
        ]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk();
    }
}
