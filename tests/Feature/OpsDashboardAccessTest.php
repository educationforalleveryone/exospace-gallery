<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpsCenter — Iteration 1 — dashboard authorization.
 *
 * The control plane sees errors, infrastructure state and context across
 * the whole platform: it is a high-value target and must sit behind the
 * SAME bar as Master Control (auth + verified + super_admin + mfa).
 */
class OpsDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function asMfaSuperAdmin()
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

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/ops')->assertRedirect('/login');
        $this->get('/ops/applications')->assertRedirect('/login');
        $this->get('/ops/events')->assertRedirect('/login');
    }

    public function test_regular_user_gets_403(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get('/ops')->assertStatus(403);
    }

    public function test_super_admin_without_mfa_verification_is_blocked(): void
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        // Authenticated, but the mfa_verified session flag is absent — the
        // RequireMfa middleware must gate the dashboard.
        $response = $this->actingAs($admin)->get('/ops');

        $this->assertNotSame(200, $response->status());
    }

    public function test_super_admin_with_mfa_can_view_all_pages(): void
    {
        $this->asMfaSuperAdmin()->get('/ops')->assertStatus(200);
        $this->asMfaSuperAdmin()->get('/ops/applications')->assertStatus(200);
        $this->asMfaSuperAdmin()->get('/ops/events')->assertStatus(200);
    }

    public function test_overview_renders_platform_status(): void
    {
        $response = $this->asMfaSuperAdmin()->get('/ops');

        $response->assertStatus(200);
        $response->assertSee('PLATFORM STATUS', false);
        $response->assertSee('Application Health', false);
    }

    public function test_events_page_renders_filters(): void
    {
        $response = $this->asMfaSuperAdmin()->get('/ops/events');

        $response->assertStatus(200);
        $response->assertSee('Apply filters', false);
    }

    public function test_event_detail_requires_existing_event(): void
    {
        $this->asMfaSuperAdmin()->get('/ops/events/999999')->assertStatus(404);
    }
}
