<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\OnboardingSnapshot;
use App\Models\User;
use App\Services\OnboardingMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 5 — TTFE history: onboarding snapshots + trend read model.
 *
 * The weekly report previously existed only as scheduler stdout and one
 * log line; the headline metric had no memory. These tests pin:
 *   1. persistSnapshot() writes truthful rows for a window
 *   2. Re-runs within the capture hour UPDATE (idempotent), never duplicate
 *   3. trend() returns chronological rows for the Master Control chart
 *   4. The weekly command persists all three dashboard windows (7/30/90)
 *      AND delivers the report to the operational alert channel
 *   5. Master Control renders the trend chart (≥2 points) / placeholder (<2)
 *   6. cleanup-stale prunes snapshots older than 2 years
 */
class OnboardingSnapshotTrendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::forget('onboarding:metrics:30');
        Cache::forget('onboarding:metrics:7');
        Cache::forget('onboarding:metrics:90');
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

    private function seedPublisher(string $registered, string $galleryCreated, string $published): User
    {
        $user = User::factory()->create(['created_at' => $registered]);
        Gallery::create([
            'user_id'      => $user->id,
            'title'        => 'Snap ' . uniqid(),
            'slug'         => 'snap-' . uniqid(),
            'description'  => 'x',
            'is_active'    => true,
            'published_at' => $published,
        ])->forceFill(['created_at' => $galleryCreated])->save();

        return $user;
    }

    public function test_persist_snapshot_writes_truthful_row(): void
    {
        // Registered 6d ago, published 2d ago → TTFE 96h.
        $this->seedPublisher(now()->subDays(6), now()->subDays(5), now()->subDays(2));

        $snapshot = app(OnboardingMetricsService::class)->persistSnapshot(30);

        $this->assertSame(30, $snapshot->window_days);
        $this->assertSame(1, $snapshot->registered);
        $this->assertSame(1, $snapshot->published);
        $this->assertEquals(96.0, (float) $snapshot->ttfe_avg);
        $this->assertNotNull($snapshot->captured_at);
        $this->assertTrue(
            $snapshot->captured_at->equalTo(now()->startOfHour()),
            'captured_at is truncated to the capture hour for idempotent re-runs',
        );
    }

    public function test_persist_snapshot_is_idempotent_within_the_capture_hour(): void
    {
        $this->seedPublisher(now()->subDays(6), now()->subDays(5), now()->subDays(2));

        $service = app(OnboardingMetricsService::class);
        $first = $service->persistSnapshot(30);
        $second = $service->persistSnapshot(30);

        $this->assertSame(1, OnboardingSnapshot::where('window_days', 30)->count(), 're-run within the hour updates, never duplicates');
        $this->assertSame($first->id, $second->id);
    }

    public function test_trend_returns_chronological_rows_per_window(): void
    {
        // Two historical points + one persisted now — the chart feed.
        OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 8, 'published' => 2,
            'ttfe_avg' => 60.0, 'ttfg_avg' => 20.0,
            'captured_at' => now()->subWeeks(2)->startOfHour(),
        ]);
        OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 10, 'published' => 3,
            'ttfe_avg' => 48.0, 'ttfg_avg' => 16.0,
            'captured_at' => now()->subWeeks(1)->startOfHour(),
        ]);
        // Different window must not leak into the 30d trend.
        OnboardingSnapshot::create([
            'window_days' => 7, 'registered' => 2, 'published' => 1,
            'ttfe_avg' => 5.0, 'ttfg_avg' => 2.0,
            'captured_at' => now()->subWeeks(1)->startOfHour(),
        ]);

        $trend = app(OnboardingMetricsService::class)->trend(30);

        $this->assertCount(2, $trend);
        $this->assertSame(60.0, $trend[0]['ttfe_avg'], 'oldest first — Chart.js feeds rows as-is');
        $this->assertSame(48.0, $trend[1]['ttfe_avg']);
        $this->assertSame(8, $trend[0]['registered']);
        $this->assertSame(3, $trend[1]['published']);
    }

    public function test_weekly_command_persists_all_three_windows_and_delivers_to_slack(): void
    {
        $this->seedPublisher(now()->subDays(6), now()->subDays(5), now()->subDays(2));

        Http::fake();
        config(['services.operational_alerts.webhook_url' => 'https://hooks.example.test/services/T000/B000/xxx']);

        $this->artisan('exospace:onboarding-analytics', ['--days' => 30])
            ->expectsOutputToContain('Time to first published exhibition (TTFE)')
            ->assertExitCode(0);

        // All three dashboard windows snapshot — every Master Control trend has data.
        foreach ([7, 30, 90] as $window) {
            $this->assertSame(
                1,
                OnboardingSnapshot::where('window_days', $window)->count(),
                "window {$window}d persisted",
            );
        }

        // The report reached the operational channel — the delivery gap that
        // made the weekly report invisible is closed.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.test/services/T000/B000/xxx'
                && str_contains($request->body(), 'Weekly onboarding report')
                && str_contains($request->body(), 'TTFE avg');
        });
    }

    public function test_master_control_renders_trend_chart_with_two_plus_points(): void
    {
        OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 8, 'published' => 2,
            'ttfe_avg' => 60.0, 'ttfg_avg' => 20.0,
            'captured_at' => now()->subWeeks(2)->startOfHour(),
        ]);
        OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 10, 'published' => 3,
            'ttfe_avg' => 48.0, 'ttfg_avg' => 16.0,
            'captured_at' => now()->subWeeks(1)->startOfHour(),
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get('/master-control');

        $response->assertOk()
            ->assertSee('ttfe-trend-chart', false)
            ->assertSee('TTFE / TTFG trend', false)
            ->assertSee('2 points recorded', false);
    }

    public function test_master_control_shows_placeholder_before_second_snapshot(): void
    {
        OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 8, 'published' => 2,
            'ttfe_avg' => 60.0, 'ttfg_avg' => 20.0,
            'captured_at' => now()->startOfHour(),
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get('/master-control');

        // A single point is not a trend — the placeholder explains when the
        // chart appears instead of rendering a misleading one-point line.
        $response->assertOk()
            ->assertDontSee('id="ttfe-trend-chart"', false)
            ->assertSee('Trend appears after the second weekly snapshot', false);
    }

    public function test_cleanup_stale_prunes_snapshots_older_than_two_years(): void
    {
        OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 1, 'published' => 1,
            'captured_at' => now()->subYears(3)->startOfHour(),
        ]);
        $recent = OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 2, 'published' => 1,
            'captured_at' => now()->subMonths(6)->startOfHour(),
        ]);

        $this->artisan('exospace:cleanup-stale')->assertExitCode(0);

        $this->assertDatabaseMissing('onboarding_snapshots', ['id' => $recent->id - 1]);
        $this->assertDatabaseHas('onboarding_snapshots', ['id' => $recent->id]);
        $this->assertSame(1, OnboardingSnapshot::count(), 'only the recent snapshot survives');
    }
}
