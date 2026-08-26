<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\RetentionSnapshot;
use App\Models\User;
use App\Services\CohortRetentionMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 6 — retention history: truthful measurement + persistence +
 * trend + delivery.
 *
 * The weekly report previously printed an inflated matrix to scheduler
 * stdout. These tests pin:
 *   1. Activity is BOUNDED — a user active only NOW does not inflate
 *      earlier weeks (the old users.updated_at >= period-start bug)
 *   2. Login activity and gallery activity both count, in-window only
 *   3. persist() writes complete cells only, idempotently
 *   4. trend() returns the latest complete cohort per capture
 *   5. The weekly command persists + posts to the operational channel
 *   6. Master Control renders the matrix + trend (or placeholders)
 *   7. cleanup-stale prunes retention snapshots older than 2 years
 */
class CohortRetentionHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        \Illuminate\Support\Facades\Cache::forget('retention:matrix:8');
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

    private function seedUserRegistered(\DateTimeInterface $at): User
    {
        return User::factory()->create(['created_at' => $at]);
    }

    // ── Measurement truth ───────────────────────────────────────────────

    public function test_activity_is_bounded_to_the_week_not_cumulative(): void
    {
        // User registered 4 weeks ago; logged in ONLY in the current week.
        // The old definition (users.updated_at >= period start) counted
        // them as active in EVERY week since registration.
        $user = $this->seedUserRegistered(now()->subWeeks(4)->startOfWeek()->addHours(3));
        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $user->id)
            ->update(['last_login_at' => now()]);

        $matrix = app(CohortRetentionMetricsService::class)->compute(8);

        // The cohort containing this user is 4 weeks back (oldest-first
        // list with 8 cohorts: index 3 = 4 weeks ago when including the
        // current partial week at index 7).
        $cohort = collect($matrix['cohorts'])->first(
            fn ($c) => $c['week_start'] === now()->subWeeks(4)->startOfWeek()->toDateString(),
        );
        $this->assertNotNull($cohort, 'cohort for the registered week exists');

        // W0..W3 are complete weeks that closed BEFORE the login happened.
        foreach ([0, 1, 2, 3] as $w) {
            $this->assertSame(
                0.0,
                $cohort['cells'][$w]['pct'],
                "W{$w} must not count activity that happened in a LATER week (bounded measurement)",
            );
        }

        // W4 (the current week) is where the login actually falls — it
        // counts there. Depending on day-of-week it may be the last cell.
        $this->assertGreaterThan(0, count($cohort['cells']));
        $lastIndex = array_key_last($cohort['cells']);
        $this->assertSame(100.0, $cohort['cells'][$lastIndex]['pct'], 'the current-week cell sees the login');
        $this->assertFalse($cohort['cells'][$lastIndex]['complete'], 'the current week is still open');
    }

    public function test_gallery_updates_count_as_activity(): void
    {
        $registeredAt = now()->subWeeks(3)->startOfWeek()->addHours(2);
        $user = $this->seedUserRegistered($registeredAt);

        // Gallery updated during the cohort's W1 window, user never logs in.
        $gallery = Gallery::create([
            'user_id'     => $user->id,
            'title'       => 'Retention ' . uniqid(),
            'slug'        => 'ret-' . uniqid(),
            'description' => 'x',
            'is_active'   => false,
        ]);
        $gallery->forceFill([
            'created_at' => $registeredAt->copy()->addHours(1),
            'updated_at' => $registeredAt->copy()->addWeek()->addHours(4),
        ])->save();

        $matrix = app(CohortRetentionMetricsService::class)->compute(8);

        $cohort = collect($matrix['cohorts'])->first(
            fn ($c) => $c['week_start'] === now()->subWeeks(3)->startOfWeek()->toDateString(),
        );
        $this->assertNotNull($cohort);
        $this->assertSame(100.0, $cohort['cells'][1]['pct'], 'a gallery update in W1 counts as W1 activity (no login needed)');
        $this->assertSame(0.0, $cohort['cells'][2]['pct'], 'no activity in W2');
    }

    public function test_cohort_membership_uses_half_open_week_boundaries(): void
    {
        // A user created exactly at a Monday 00:00 boundary belongs to the
        // LATER week only ([start, end) semantics — no double counting).
        $boundary = now()->startOfWeek()->subWeeks(2);
        $this->seedUserRegistered($boundary);

        $matrix = app(CohortRetentionMetricsService::class)->compute(8);

        $later = collect($matrix['cohorts'])->first(
            fn ($c) => $c['week_start'] === $boundary->toDateString(),
        );
        $earlier = collect($matrix['cohorts'])->first(
            fn ($c) => $c['week_start'] === $boundary->copy()->subWeek()->toDateString(),
        );

        $this->assertNotNull($later);
        $this->assertNotNull($earlier);
        $this->assertSame(1, $later['size']);
        $this->assertSame(0, $earlier['size'], 'boundary user is not counted in both adjacent cohorts');
    }

    // ── Persistence ─────────────────────────────────────────────────────

    public function test_persist_writes_complete_cells_only_and_is_idempotent(): void
    {
        $registeredAt = now()->subWeeks(3)->startOfWeek()->addHours(2);
        $user = $this->seedUserRegistered($registeredAt);
        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $user->id)
            ->update(['last_login_at' => $registeredAt->copy()->addDays(9)]); // W1 activity

        $service = app(CohortRetentionMetricsService::class);
        $first = $service->persist(8);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(
            1,
            RetentionSnapshot::where('cohort_week_start', $registeredAt->startOfWeek()->toDateString())
                ->where('week_index', 1)
                ->count(),
            'the complete W1 cell is persisted',
        );

        // The CURRENT week's cohort must never be persisted (its W0 is open).
        $this->assertSame(
            0,
            RetentionSnapshot::where('cohort_week_start', now()->startOfWeek()->toDateString())->count(),
            'partial cells are not persisted',
        );

        // Re-run within the capture hour updates, never duplicates.
        $rowsBefore = RetentionSnapshot::count();
        $service->persist(8);
        $this->assertSame($rowsBefore, RetentionSnapshot::count(), 'idempotent within the capture hour');

        $row = RetentionSnapshot::where('week_index', 1)->first();
        $this->assertSame(100.0, (float) $row->retained_pct);
    }

    public function test_trend_returns_latest_complete_cohort_per_capture_chronologically(): void
    {
        // Two historical captures a week apart, each with a newer W1 cohort.
        $olderCohort = now()->subWeeks(4)->startOfWeek();
        $newerCohort = now()->subWeeks(3)->startOfWeek();
        $captureA = now()->subWeeks(2)->startOfHour();
        $captureB = now()->subWeeks(1)->startOfHour();

        RetentionSnapshot::create([
            'cohort_week_start' => $olderCohort->toDateString(), 'week_index' => 1,
            'cohort_size' => 10, 'active_count' => 2, 'retained_pct' => 20.0, 'captured_at' => $captureA,
        ]);
        RetentionSnapshot::create([
            'cohort_week_start' => $newerCohort->toDateString(), 'week_index' => 1,
            'cohort_size' => 8, 'active_count' => 4, 'retained_pct' => 50.0, 'captured_at' => $captureB,
        ]);

        $trend = app(CohortRetentionMetricsService::class)->trend(1, 26);

        $this->assertCount(2, $trend);
        $this->assertSame(20.0, $trend[0]['retained_pct'], 'oldest capture first');
        $this->assertSame(50.0, $trend[1]['retained_pct']);
        $this->assertSame(
            $newerCohort->format('M j'),
            $trend[1]['cohort'],
            'each point names the cohort it measured',
        );
    }

    // ── Weekly command + delivery ───────────────────────────────────────

    public function test_weekly_command_persists_matrix_and_posts_to_slack(): void
    {
        $registeredAt = now()->subWeeks(3)->startOfWeek()->addHours(2);
        $user = $this->seedUserRegistered($registeredAt);
        \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $user->id)
            ->update(['last_login_at' => $registeredAt->copy()->addDays(9)]);

        Http::fake();
        config(['services.operational_alerts.webhook_url' => 'https://hooks.example.test/services/T000/B000/ret']);

        $this->artisan('exospace:cohort-retention', ['--weeks' => 8])
            ->expectsOutputToContain('Persisted')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, RetentionSnapshot::count(), 'the command persists the matrix history');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.test/services/T000/B000/ret'
                && str_contains($request->body(), 'Weekly retention report')
                && str_contains($request->body(), 'Week-1 retention');
        });
    }

    // ── Master Control ──────────────────────────────────────────────────

    public function test_master_control_renders_retention_matrix_and_trend(): void
    {
        $capture = now()->subWeeks(1)->startOfHour();
        $cohort = now()->subWeeks(3)->startOfWeek();

        RetentionSnapshot::create([
            'cohort_week_start' => $cohort->toDateString(), 'week_index' => 1,
            'cohort_size' => 10, 'active_count' => 3, 'retained_pct' => 30.0, 'captured_at' => $capture,
        ]);
        RetentionSnapshot::create([
            'cohort_week_start' => $cohort->toDateString(), 'week_index' => 1,
            'cohort_size' => 10, 'active_count' => 3, 'retained_pct' => 30.0, 'captured_at' => $capture->copy()->subWeek(),
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get('/master-control');

        $response->assertOk()
            ->assertSee('Weekly cohort retention', false)
            ->assertSee('retention-trend-chart', false)
            ->assertSee('login or gallery update in the week', false);
    }

    public function test_master_control_shows_retention_placeholder_before_second_snapshot(): void
    {
        $this->seedUserRegistered(now()->subDays(3));

        $response = $this->actingAsMfaSuperAdmin()->get('/master-control');

        // The live matrix renders (it is computed fresh) but the trend
        // placeholder explains that one point is not a trend.
        $response->assertOk()
            ->assertSee('Weekly cohort retention', false)
            ->assertDontSee('id="retention-trend-chart"', false);
    }

    // ── Hygiene ─────────────────────────────────────────────────────────

    public function test_cleanup_stale_prunes_retention_snapshots_older_than_two_years(): void
    {
        RetentionSnapshot::create([
            'cohort_week_start' => now()->subYears(3)->toDateString(), 'week_index' => 1,
            'cohort_size' => 5, 'active_count' => 1, 'retained_pct' => 20.0,
            'captured_at' => now()->subYears(3)->startOfHour(),
        ]);
        $recent = RetentionSnapshot::create([
            'cohort_week_start' => now()->subMonths(6)->toDateString(), 'week_index' => 1,
            'cohort_size' => 5, 'active_count' => 2, 'retained_pct' => 40.0,
            'captured_at' => now()->subMonths(6)->startOfHour(),
        ]);

        $this->artisan('exospace:cleanup-stale')->assertExitCode(0);

        $this->assertSame(1, RetentionSnapshot::count(), 'only the recent snapshot survives');
        $this->assertDatabaseHas('retention_snapshots', ['id' => $recent->id]);
    }
}
