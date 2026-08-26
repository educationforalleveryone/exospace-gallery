<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\User;
use App\Services\OnboardingMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ITERATION 4 — onboarding funnel + TTFE surfaced on Master Control.
 *
 * OnboardingMetricsService is now the single source of truth shared by the
 * weekly console report and the live dashboard panel; these tests pin the
 * computation (cohort funnel counts, per-user FIRST-event TTFE/TTFG in
 * hours) and the dashboard surface (panel renders for super-admins with
 * the period selector; platform stats are cached).
 */
class OnboardingMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::forget('onboarding:metrics:30');
        Cache::forget('onboarding:metrics:7');
    }

    private function actingAsMfaSuperAdmin()
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin)->withSession([
            'mfa_verified'    => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    public function test_metrics_service_computes_funnel_and_ttfe(): void
    {
        $service = app(OnboardingMetricsService::class);

        // Cohort of 3 users in the window:
        //   A: registered 5d ago, gallery 4d ago, PUBLISHED 2d ago → TTFE 72h
        //   B: registered 5d ago, gallery 3d ago, never published   → funnel stops at stage 2
        //   C: registered 40d ago (OUTSIDE the 30d window)          → not counted at all
        $a = User::factory()->create(['created_at' => now()->subDays(5)]);
        $b = User::factory()->create(['created_at' => now()->subDays(5)]);
        User::factory()->create(['created_at' => now()->subDays(40)]);

        Gallery::create([
            'user_id'      => $a->id,
            'title'        => 'A Show', 'slug' => 'a-show',
            'description'  => 'x', 'is_active' => true,
            'published_at' => now()->subDays(2),
        ])->forceFill(['created_at' => now()->subDays(4)])->save();
        // A's SECOND gallery published later — must NOT distort per-user FIRST timing.
        Gallery::create([
            'user_id'      => $a->id,
            'title'        => 'A Second', 'slug' => 'a-second',
            'description'  => 'x', 'is_active' => true,
            'published_at' => now()->subDays(1),
        ])->forceFill(['created_at' => now()->subDays(3)])->save();
        Gallery::create([
            'user_id'     => $b->id,
            'title'       => 'B Draft', 'slug' => 'b-draft',
            'description' => 'x', 'is_active' => false,
        ])->forceFill(['created_at' => now()->subDays(3)])->save();

        $data = $service->compute(30);

        $this->assertSame(2, $data['registered'], 'only in-window users count (C excluded)');
        $this->assertSame(2, $data['created_gallery']);
        $this->assertSame(1, $data['published']);
        $this->assertSame(0, $data['got_views'], 'no gallery has views yet → zero users reached stage 5');

        // TTFE: A only — registered 5d ago, first published 2d ago → 72h.
        $this->assertNotNull($data['ttfe_hours']);
        $this->assertSame(72.0, $data['ttfe_hours']['avg']);
        $this->assertSame(72.0, $data['ttfe_hours']['min']);
        $this->assertSame(72.0, $data['ttfe_hours']['max']);

        // TTFG: A's first gallery 4d ago (24h), B's 3d ago (48h) → avg 36h.
        $this->assertNotNull($data['ttfg_hours']);
        $this->assertSame(36.0, $data['ttfg_hours']['avg']);
    }

    public function test_snapshot_is_cached_and_period_clamped(): void
    {
        $service = app(OnboardingMetricsService::class);

        $data = $service->snapshot(30);
        $this->assertSame(30, $data['days']);
        $this->assertTrue(Cache::has('onboarding:metrics:30'));

        // Out-of-range windows clamp instead of scanning absurd spans.
        $this->assertSame(365, $service->compute(9999)['days']);
        $this->assertSame(1, $service->compute(0)['days']);
    }

    public function test_master_control_shows_onboarding_panel_and_ttfe(): void
    {
        $user = User::factory()->create(['created_at' => now()->subDays(5)]);
        Gallery::create([
            'user_id'      => $user->id,
            'title'        => 'Panel Show', 'slug' => 'panel-show',
            'description'  => 'x', 'is_active' => true,
            'published_at' => now()->subDays(2),
        ])->forceFill(['created_at' => now()->subDays(4)])->save();

        $response = $this->actingAsMfaSuperAdmin()->get('/master-control');

        $response->assertOk()
            ->assertSee('Onboarding Funnel')
            ->assertSee('Time to first published exhibition (TTFE)')
            ->assertSee('3 days avg', false) // 72h renders as days (≥ 48h)
            ->assertSee('Registered')
            ->assertSee('30d');

        // The 9 platform stats are now cached, not re-scanned per hit.
        $this->assertTrue(Cache::has('master-control:platform-stats'));
    }

    public function test_master_control_period_selector_changes_cohort(): void
    {
        // Old user outside the 7d window but inside 30d.
        User::factory()->create(['created_at' => now()->subDays(20)]);

        $short = $this->actingAsMfaSuperAdmin()->get('/master-control?days=7');
        $short->assertOk();
        $this->assertTrue(Cache::has('onboarding:metrics:7'), '7d cohort cached separately');

        // Invalid period falls back to 30 — never an unbounded scan.
        $this->actingAsMfaSuperAdmin()->get('/master-control?days=9999')->assertOk();
        $data = app(OnboardingMetricsService::class)->snapshot(30);
        $this->assertSame(30, $data['days']);
    }

    public function test_weekly_command_consumes_the_service_uncached(): void
    {
        $this->artisan('exospace:onboarding-analytics', ['--days' => 30])
            ->expectsOutputToContain('Time to first published exhibition (TTFE)')
            ->assertExitCode(0);
    }
}
