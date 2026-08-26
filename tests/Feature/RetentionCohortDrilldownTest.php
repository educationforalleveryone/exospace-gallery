<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ITERATION 7 — retention cohort drill-down.
 *
 * Clicking a cohort matrix cell opens the underlying user list,
 * PII-gated by the master-control group middleware + audit-logged
 * per view. The active-count math MUST reconcile with the matrix
 * cell (same bounded countActive() definition, derived live so the
 * drill-down reflects the moment of the click, not a 30/60-min
 * dashboard cache entry).
 */
class RetentionCohortDrilldownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // No withoutExceptionHandling — Laravel's handlers convert
        // AuthenticationException → login redirect, AuthorizationException
        // → 403, NotFoundHttpException → 404. That's the actual
        // production behavior we want to assert against.
        Cache::flush();
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

    private function thisMonday(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfWeek();
    }

    public function test_guests_are_redirected(): void
    {
        $cohort = $this->thisMonday()->subWeeks(2)->toDateString();
        $response = $this->get(route('super.retention.cohort', ['cohort' => $cohort, 'week' => 1]));
        $response->assertRedirect(route('login'));
    }

    public function test_regular_users_are_forbidden(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $cohort = $this->thisMonday()->subWeeks(2)->toDateString();

        $response = $this->actingAs($user)
            ->get(route('super.retention.cohort', ['cohort' => $cohort, 'week' => 1]));
        $response->assertForbidden();
    }

    public function test_invalid_date_returns_404(): void
    {
        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.retention.cohort', ['cohort' => 'not-a-date', 'week' => 0]));
        $response->assertNotFound();
    }

    public function test_non_monday_date_returns_404(): void
    {
        // Tuesday — cohort keys in the matrix are always Mondays.
        $tuesday = $this->thisMonday()->subWeeks(2)->addDays(1)->toDateString();

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.retention.cohort', ['cohort' => $tuesday, 'week' => 0]));
        $response->assertNotFound();
    }

    public function test_future_cohort_returns_404(): void
    {
        $future = $this->thisMonday()->addWeeks(2)->toDateString();

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.retention.cohort', ['cohort' => $future, 'week' => 0]));
        $response->assertNotFound();
    }

    public function test_future_week_index_returns_404(): void
    {
        // Cohort 2 weeks back, week index 5 — period hasn't started.
        $cohort = $this->thisMonday()->subWeeks(2)->toDateString();

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.retention.cohort', ['cohort' => $cohort, 'week' => 5]));
        $response->assertNotFound();
    }

    public function test_empty_cohort_renders_with_no_members(): void
    {
        // Cohort 2 weeks back, week 0 — but no users registered that week.
        $cohort = $this->thisMonday()->subWeeks(2)->toDateString();

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.retention.cohort', ['cohort' => $cohort, 'week' => 0]));

        $response->assertStatus(200);
        $response->assertSee('No members registered during this cohort', false);
        $response->assertSee('Cohort size', false);
        $response->assertSee('0', false);  // cohort size 0 + active count 0
    }

    public function test_drill_down_lists_cohort_members_with_activity_flag(): void
    {
        $weekStart = $this->thisMonday()->subWeeks(2);
        $weekEnd = $weekStart->copy()->addWeek();
        $periodStart = $weekStart->copy()->addWeek();  // week index 1
        $periodEnd = $periodStart->copy()->addWeek();

        // Three users in the cohort; one active in week 1 via login,
        // one active via a gallery update, one inactive.
        $active1 = User::factory()->create([
            'email'        => 'active1@example.com',
            'created_at'   => $weekStart->copy()->addDays(1),
            'last_login_at'=> $periodStart->copy()->addDays(2),
            'plan'         => 'free',
        ]);
        $active2 = User::factory()->create([
            'email'        => 'active2@example.com',
            'created_at'   => $weekStart->copy()->addDays(2),
            'last_login_at'=> null,  // no login
            'plan'         => 'pro',
        ]);
        // Give active2 a gallery updated in the period (use the
        // factory so all NOT-NULL fields populate — title, slug, etc).
        \App\Models\Gallery::factory()->create([
            'user_id'    => $active2->id,
            'is_active'  => true,
            'updated_at' => $periodStart->copy()->addDays(3),
        ]);
        $inactive = User::factory()->create([
            'email'        => 'inactive@example.com',
            'created_at'   => $weekStart->copy()->addDays(3),
            'last_login_at'=> null,
            'plan'         => 'studio',
        ]);

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.retention.cohort', [
                'cohort' => $weekStart->toDateString(),
                'week'   => 1,
            ]));

        $response->assertStatus(200);
        $response->assertSee('active1@example.com', false);
        $response->assertSee('active2@example.com', false);
        $response->assertSee('inactive@example.com', false);
        $response->assertSee('Cohort size', false);
        // Active count reconciles: 2 of 3 active.
        $response->assertSee('2', false);
        $response->assertSee('66.7%', false);  // retained_pct rounded 1dp

        // Audit row written — every view of the user list is attributable.
        $audit = AdminAuditLog::where('action', 'retention.cohort_viewed')->first();
        $this->assertNotNull($audit, 'retention.cohort_viewed audit row must be written');
        $this->assertSame($weekStart->toDateString(), $audit->payload['cohort_week_start'] ?? null);
        $this->assertSame(1, $audit->payload['week_index'] ?? null);
        $this->assertSame(3, $audit->payload['cohort_size'] ?? null);
        $this->assertSame(2, $audit->payload['active_count'] ?? null);
    }

    public function test_size_zero_cohort_renders_cleanly(): void
    {
        $weekStart = $this->thisMonday()->subWeeks(3);

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.retention.cohort', [
                'cohort' => $weekStart->toDateString(),
                'week'   => 0,
            ]));

        $response->assertStatus(200);
        // Active % reads as 0.0 on size-0 cohorts.
        $response->assertSee('0%', false);
    }

    public function test_matrix_cells_link_to_drill_down_for_size_nonzero_cohorts(): void
    {
        // Create a user in this week's cohort so the cell has someone to drill into.
        $weekStart = $this->thisMonday()->subWeeks(1);
        User::factory()->create([
            'created_at'   => $weekStart->copy()->addDays(1),
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));

        $response->assertStatus(200);
        // The drill-down route should appear as a cell href.
        $response->assertSee(route('super.retention.cohort', [
            'cohort' => $weekStart->toDateString(),
            'week'   => 0,
        ]), false);
    }

    public function test_matrix_cells_do_not_link_for_size_zero_cohorts(): void
    {
        // No users this week → matrix shows '–' with no link.
        $weekStart = $this->thisMonday()->subWeeks(1);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));

        $response->assertStatus(200);
        // The drill-down URL for that week must NOT appear (size-0).
        $response->assertDontSee(route('super.retention.cohort', [
            'cohort' => $weekStart->toDateString(),
            'week'   => 0,
        ]), false);
    }
}
