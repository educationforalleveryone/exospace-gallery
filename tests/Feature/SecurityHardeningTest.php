<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{

    private function actingAsFullAdmin(User $user): self
    {
        return $this->actingAs($user)->withSession([
            'mfa_verified'          => true,
            'mfa_verified_at'       => now()->timestamp,
            'auth.password_confirmed_at' => now()->timestamp,
        ]);
    }

    use RefreshDatabase;

    public function test_d1_scope_session_domain_rejects_unverified_host(): void
    {
        $response = $this->get('http://evil-gallery.com/');

        $response->assertStatus(404);
    }

    public function test_d1_scope_session_domain_accepts_verified_custom_domain(): void
    {
        // D-1 FIX: verified custom domains should be served (not 404)
        $gallery = Gallery::factory()->create([
            'custom_domain' => 'gallery.test-example.com',
            'custom_domain_verified_at' => now(),
            'is_active' => true,
        ]);

        $response = $this->get('http://gallery.test-example.com/');

        // Should NOT be 404 — the verified custom domain should be served
        $this->assertNotEquals(404, $response->status(),
            'D-1: Verified custom domain should not return 404.');
    }

    public function test_d3_confirm_password_route_has_throttle(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('password.confirm.submit');
        $this->assertNotNull($route, 'password.confirm.submit route must exist.');

        $middleware = $route->gatherMiddleware();
        $hasThrottle = false;
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'throttle')) {
                $hasThrottle = true;
                break;
            }
        }

        $this->assertTrue($hasThrottle,
            'D-3: POST /confirm-password must have throttle middleware. Found: ' . json_encode($middleware));
    }

    public function test_d7_team_invitation_show_does_not_leak_account_exists_for_guests(): void
    {
        // D-7 FIX: unauthenticated visitors should NOT see $accountExists
        $team = \App\Models\Team::factory()->create();
        $invitation = \App\Models\TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'email' => 'invited@example.com',
            'token' => \App\Models\TeamInvitation::hashToken(\App\Models\TeamInvitation::generateToken()),
            'expires_at' => now()->addDays(7),
        ]);

        // Create a user with the invited email (account exists)
        User::factory()->create(['email' => 'invited@example.com']);

        $plaintextToken = $invitation->token; // This is the hash — for the test, we need plaintext
        $plaintextToken = 'test-plaintext-token-1234567890';
        $invitation->update(['token' => \App\Models\TeamInvitation::hashToken($plaintextToken)]);

        // ITERATION-1 FIX: the route is signed — build a proper URL.
        $response = $this->get(
            \Illuminate\Support\Facades\URL::signedRoute('team-invitations.show', ['token' => $plaintextToken])
        );

        $response->assertStatus(200);
        // The view should NOT have $accountExists set for unauthenticated visitors
        $response->assertViewHas('accountExists', null);
    }

    public function test_d8_security_headers_generates_csp_nonce(): void
    {
        // D-8 FIX: the SecurityHeaders middleware should generate a per-request nonce
        $response = $this->get('/');

        $nonce = request()->attributes->get('csp_nonce');
        $this->assertNotEmpty($nonce,
            'D-8: SecurityHeaders middleware should set csp_nonce in request attributes.');

        $this->assertNotEquals('', $nonce, 'D-8: nonce should not be empty.');
    }

    public function test_d8_csp_nonce_helper_returns_value(): void
    {
        // D-8 FIX: the csp_nonce() helper should return the nonce
        $this->get('/');

        $this->assertNotEmpty(csp_nonce(),
            'D-8: csp_nonce() helper should return the nonce after a request.');
    }

    public function test_d10_last_super_admin_cannot_be_revoked(): void
    {
        // D-10 FIX: the only super-admin cannot be revoked
        $superAdmin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
            'has_password' => true,
        ]);

        $secondAdmin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
            'has_password' => true,
        ]);

        $this->actingAsFullAdmin($secondAdmin)
            ->post(route('super.toggleSuperAdmin', $superAdmin));

        // Let's make secondAdmin the only super-admin
        $secondAdmin->forceFill(['is_super_admin' => true])->save();
        $superAdmin->forceFill(['is_super_admin' => false])->save();

        // For this test, verify the guard logic directly:
        $count = User::where('is_super_admin', true)->count();
        $this->assertEquals(1, $count, 'Setup: should have exactly 1 super-admin.');

        $this->actingAsFullAdmin($secondAdmin)
            ->post(route('super.toggleSuperAdmin', $secondAdmin))
            ->assertForbidden();

        $actor = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($actor);
        $controller = app(\App\Http\Controllers\SuperAdmin\SystemController::class);
        $request = \Illuminate\Http\Request::create('/master-control/users/' . $secondAdmin->id . '/toggle-super-admin', 'POST');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $redirect = $controller->toggleSuperAdmin($secondAdmin->fresh());
        $this->assertTrue($redirect->getSession()->has('error'),
            'D-10: revoking the ONLY super-admin must be refused by the last-admin guard.');

        $secondAdmin->refresh();
        $this->assertTrue($secondAdmin->is_super_admin,
            'D-10: The only super-admin should not be revoked.');
    }

    public function test_d10_super_admin_can_be_revoked_when_multiple_exist(): void
    {
        $admin1 = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
            'has_password' => true,
        ]);
        $admin2 = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
            'has_password' => true,
        ]);

        // admin1 revokes admin2 (admin2 was granted > 24h ago, so no cooldown)
        \App\Models\AdminAuditLog::create([
            'actor_id' => $admin1->id,
            'target_type' => User::class,
            'target_id' => $admin2->id,
            'action' => 'super_admin_toggled',
            'payload' => json_encode(['from' => false, 'to' => true]),
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subDays(2), // > 24h ago — no cooldown
        ]);

        $response = $this->actingAsFullAdmin($admin1)
            ->post(route('super.toggleSuperAdmin', $admin2));

        $response->assertSessionHas('success');
        $admin2->refresh();
        $this->assertFalse($admin2->is_super_admin,
            'D-10: Super-admin should be revoked when multiple exist and cooldown has passed.');
    }
}
