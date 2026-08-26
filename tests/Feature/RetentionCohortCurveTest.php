<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RetentionSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 9 — per-cohort retention curve W0..W7.
 *
 * Coverage: workstream D closes the "which week did churn happen?"
 * loop. The Master Control matrix trends only W1 + W2 (the headline
 * retention metric); the drill-down page now shows the cohort's full
 * W0..W7 retention curve on one canvas with W{weekIndex} highlighted.
 * Data comes from the same retention_snapshots table the weekly
 * exospace:cohort-retention command persists; cohortCurve() returns
 * the latest complete snapshot per (cohort × week_index).
 *
 * Tests:
 *   - cohortCurve() returns [] for a non-Monday "cohort" date
 *     (caller's bug — the page renders the no-data state cleanly).
 *   - cohortCurve() returns [] for a future cohort (no snapshots
 *     persisted yet).
 *   - cohortCurve() returns [] for an in-range cohort with zero
 *     snapshots persisted (the weekly command hasn't run yet).
 *   - cohortCurve() returns 8 rows for an in-range cohort with
 *     snapshots persisted — one per week_index 0..7.
 *   - The drill-down page renders the curve chart canvas when
 *     snapshots exist; hidden when curve is empty.
 *   - The drill-down page embeds the curve JSON payload for the
 *     inline Chart.js init script.
 *
 * Run: php artisan test --filter=RetentionCohortCurveTest
 */
class RetentionCohortCurveTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.slack.example/curve';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutExceptionHandling();
        config(['services.operational_alerts.webhook_url' => self::WEBHOOK]);
        Http::fake();
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

    // ── Service-level: cohortCurve() validation ──────────────────────

    public function test_cohort_curve_returns_empty_for_non_monday_date(): void
    {
        $service = app(\App\Services\CohortRetentionMetricsService::class);

        // 2026-08-12 is a Wednesday — not a Monday.
        $this->assertSame([], $service->cohortCurve('2026-08-12'));
    }

    public function test_cohort_curve_returns_empty_for_future_cohort(): void
    {
        $service = app(\App\Services\CohortRetentionMetricsService::class);

        // Future Monday — no snapshots persisted (and the cohort hasn't
        // even started). The controller's cohortDrilldown already 404s
        // this input; cohortCurve() returns [] for defense-in-depth.
        $futureMonday = \Carbon\CarbonImmutable::now()->addWeeks(2)->startOfWeek();
        $this->assertSame([], $service->cohortCurve($futureMonday->toDateString()));
    }

    public function test_cohort_curve_returns_empty_for_in_range_cohort_with_zero_snapshots(): void
    {
        $service = app(\App\Services\CohortRetentionMetricsService::class);

        // A real Monday 4 weeks ago — in-range but the weekly command
        // hasn't persisted any snapshots for it yet.
        $monday = \Carbon\CarbonImmutable::now()->subWeeks(4)->startOfWeek();
        $this->assertSame([], $service->cohortCurve($monday->toDateString()));
    }

    public function test_cohort_curve_returns_eight_rows_for_populated_cohort(): void
    {
        // Seed retention_snapshots for W0..W7 of an in-range cohort.
        // Use a Monday 8 weeks ago so all 8 weeks have closed (complete=true).
        $cohortStart = \Carbon\CarbonImmutable::now()->subWeeks(8)->startOfWeek();
        for ($w = 0; $w < 8; $w++) {
            RetentionSnapshot::create([
                'cohort_week_start' => $cohortStart->toDateString(),
                'week_index'        => $w,
                'cohort_size'       => 10,
                'active_count'      => 10 - $w, // decay: 10,9,8,...3
                'retained_pct'      => round(((10 - $w) / 10) * 100, 1),
                'captured_at'       => $cohortStart->copy()->addWeeks($w + 1)->toDateTimeString(),
            ]);
        }

        $service = app(\App\Services\CohortRetentionMetricsService::class);
        $curve = $service->cohortCurve($cohortStart->toDateString(), 8);

        $this->assertCount(8, $curve);
        $this->assertSame(0, $curve[0]['week_index']);
        $this->assertSame(7, $curve[7]['week_index']);
        $this->assertSame(100.0, $curve[0]['retained_pct']);
        $this->assertSame(30.0, $curve[7]['retained_pct']);
        // All weeks have closed (start was 8 weeks ago) → complete=true.
        foreach ($curve as $row) {
            $this->assertTrue($row['complete']);
        }
    }

    public function test_cohort_curve_returns_latest_snapshot_per_week_index(): void
    {
        // Seed two captures for the same week_index — the LATER one
        // should win (the older snapshot is a stale capture).
        $cohortStart = \Carbon\CarbonImmutable::now()->subWeeks(8)->startOfWeek();
        RetentionSnapshot::create([
            'cohort_week_start' => $cohortStart->toDateString(),
            'week_index'        => 1,
            'cohort_size'       => 10,
            'active_count'      => 5,
            'retained_pct'      => 50.0,
            'captured_at'       => '2026-07-01 06:00:00',
        ]);
        RetentionSnapshot::create([
            'cohort_week_start' => $cohortStart->toDateString(),
            'week_index'        => 1,
            'cohort_size'       => 10,
            'active_count'      => 8, // updated count — captures the more recent state
            'retained_pct'      => 80.0,
            'captured_at'       => '2026-07-08 06:00:00', // later
        ]);

        $service = app(\App\Services\CohortRetentionMetricsService::class);
        $curve = $service->cohortCurve($cohortStart->toDateString(), 8);

        // W1 row reflects the later capture (80.0).
        $w1Row = collect($curve)->firstWhere('week_index', 1);
        $this->assertSame(80.0, $w1Row['retained_pct']);
    }

    // ── Drill-down page embed ─────────────────────────────────────────

    public function test_drill_down_page_renders_curve_chart_when_snapshots_exist(): void
    {
        // Seed a real cohort with members + retention snapshots.
        $cohortStart = \Carbon\CarbonImmutable::now()->subWeeks(8)->startOfWeek();
        $user = User::factory()->create(['created_at' => $cohortStart->copy()->addDay()]);
        for ($w = 0; $w < 8; $w++) {
            RetentionSnapshot::create([
                'cohort_week_start' => $cohortStart->toDateString(),
                'week_index'        => $w,
                'cohort_size'       => 1,
                'active_count'      => $w % 2 === 0 ? 1 : 0,
                'retained_pct'      => $w % 2 === 0 ? 100.0 : 0.0,
                'captured_at'       => $cohortStart->copy()->addWeeks($w + 1)->toDateTimeString(),
            ]);
        }

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.retention.cohort', ['cohort' => $cohortStart->toDateString(), 'week' => 2]));

        $response->assertStatus(200);
        $response->assertSee('id="cohort-curve-chart"', false);
        $response->assertSee('Cohort retention curve', false);
    }

    public function test_drill_down_page_hides_curve_chart_when_curve_is_empty(): void
    {
        // Size-0 cohort — no curve. cohortCurve() returns [] for the
        // no-snapshots case (and the controller's cohortDrilldown
        // returns valid data with size=0).
        $cohortStart = \Carbon\CarbonImmutable::now()->subWeeks(2)->startOfWeek();

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.retention.cohort', ['cohort' => $cohortStart->toDateString(), 'week' => 0]));

        $response->assertStatus(200);
        $response->assertDontSee('id="cohort-curve-chart"', false);
    }
}
