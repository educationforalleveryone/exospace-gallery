<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Route-lock matrix for the master-control area.
 *
 * Deeper guarantees are covered elsewhere: MFA setup-force and challenge
 * (MfaLifecycleTest), password.confirm windows (PasswordConfirmationTest),
 * escalation cooldowns (SecurityHardeningTest), impersonation audit
 * (ImpersonationSafetyTest), ops tiers (OpsAccessControlTest). This class
 * pins the OUTERMOST gate itself: no request without the super_admin flag
 * may reach any master-control surface, for GET or mutating verbs.
 */
class SuperAdminRouteLockTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedRegularUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function representativeSurfaces(User $target): array
    {
        return [
            ['/master-control', 'get'],
            ["/master-control/users/{$target->id}/galleries", 'get'],
            ['/master-control/venues', 'get'],
            ['/master-control/venues/create', 'get'],
            ['/master-control/featured', 'get'],
            ['/master-control/seo', 'get'],
            ['/master-control/pending-upgrades', 'get'],
            ['/master-control/billing', 'get'],
            ['/master-control/billing/export', 'get'],
            ['/master-control/webhooks', 'get'],
            ['/master-control/feedback', 'get'],
            ['/master-control/nps', 'get'],
            ['/master-control/affiliates', 'get'],
        ];
    }

    public function test_area_requires_authentication(): void
    {
        $this->get('/master-control')
            ->assertRedirect(route('login'));

        // Mutating verb — same lock, never a leak-through.
        $this->post('/master-control/stop-impersonating')
            ->assertRedirect(route('login'));
    }

    public function test_verified_non_super_admin_is_rejected_across_the_area(): void
    {
        $target = $this->verifiedRegularUser();
        $visitor = $this->verifiedRegularUser();

        foreach ($this->representativeSurfaces($target) as [$url, $method]) {
            $this->actingAs($visitor)
                ->{$method}($url)
                ->assertStatus(403, "GET {$url} must be closed to non-super-admins.");
        }
    }

    public function test_mutating_verbs_are_rejected_before_any_state_change(): void
    {
        $target = $this->verifiedRegularUser();
        $visitor = $this->verifiedRegularUser();

        // ban (password.confirm-protected) and toggle (not) — the group's
        // super_admin gate must fire before either reaches its controller.
        $this->actingAs($visitor)
            ->post("/master-control/users/{$target->id}/ban", ['reason' => 'nope'])
            ->assertStatus(403);

        $this->actingAs($visitor)
            ->post("/master-control/users/{$target->id}/plan", ['plan' => 'studio'])
            ->assertStatus(403);

        $this->assertNull($target->refresh()->banned_at, 'a non-super-admin must not be able to ban anyone.');
        $this->assertSame('free', $target->refresh()->plan, 'a non-super-admin must not be able to change plans.');
    }

    public function test_unverified_super_admin_is_held_at_verification(): void
    {
        // The 'verified' middleware sits in front of the area: an account
        // with the flag but without a verified e-mail never gets in.
        $unverified = User::factory()->create([
            'is_super_admin' => true,
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($unverified)->get('/master-control');

        $this->assertNotSame(200, $response->status(), 'unverified super-admins must not load the area.');
    }
}
