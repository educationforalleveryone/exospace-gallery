<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Gallery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RetentionCsvExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
    }

    private function actingAsMfaSuperAdmin()
    {
        $admin = User::factory()->withMfa()->create([
            'is_super_admin'    => true,
            'email_verified_at' => now(),
        ]);

        return [$admin, $this->actingAs($admin)->withSession([
            'mfa_verified'    => true,
            'mfa_verified_at' => now()->timestamp,
        ])];
    }

    private function thisMonday(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfWeek();
    }

    public function test_guests_are_redirected(): void
    {
        $cohort = $this->thisMonday()->subWeeks(2)->toDateString();
        $response = $this->get(route('super.retention.cohort.export', ['cohort' => $cohort, 'week' => 1]));
        $response->assertRedirect(route('login'));
    }

    public function test_regular_users_are_forbidden(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $cohort = $this->thisMonday()->subWeeks(2)->toDateString();

        $response = $this->actingAs($user)
            ->get(route('super.retention.cohort.export', ['cohort' => $cohort, 'week' => 1]));
        $response->assertForbidden();
    }

    public function test_invalid_cohort_returns_404(): void
    {
        $response = $this->actingAsMfaSuperAdmin()[1]
            ->get(route('super.retention.cohort.export', ['cohort' => 'not-a-date', 'week' => 0]));
        $response->assertNotFound();
    }

    public function test_empty_cohort_streams_csv_with_header_only(): void
    {
        $weekStart = $this->thisMonday()->subWeeks(2);

        $response = $this->actingAsMfaSuperAdmin()[1]
            ->get(route('super.retention.cohort.export', [
                'cohort' => $weekStart->toDateString(),
                'week'   => 0,
            ]));

        $response->assertStatus(200);
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'CSV must be BOM-prefixed UTF-8');
        $this->assertStringContainsString('Exospace retention cohort export', $body);
        $this->assertStringContainsString('name,email,plan,registered_at,last_login_at,active_in_period,banned', $body);
        // Empty cohort → no member rows (header row + comment lines only).
        $this->assertStringNotContainsString('@example.com', $body);
    }

    public function test_populated_cohort_streams_csv_with_correct_rows_and_audit_row(): void
    {
        [$admin] = $this->actingAsMfaSuperAdmin();

        $weekStart = $this->thisMonday()->subWeeks(2);
        $weekEnd = $weekStart->copy()->addWeek();
        $periodStart = $weekStart->copy()->addWeek();  // week index 1
        $periodEnd = $periodStart->copy()->addWeek();

        $active1 = User::factory()->create([
            'email'         => 'active1@example.com',
            'created_at'    => $weekStart->copy()->addDays(1),
            'last_login_at' => $periodStart->copy()->addDays(2),
            'plan'          => 'free',
        ]);
        $active2 = User::factory()->create([
            'email'         => 'active2@example.com',
            'created_at'    => $weekStart->copy()->addDays(2),
            'last_login_at' => null,
            'plan'          => 'pro',
        ]);
        Gallery::factory()->create([
            'user_id'    => $active2->id,
            'is_active'  => true,
            'updated_at' => $periodStart->copy()->addDays(3),
        ]);
        $inactive = User::factory()->create([
            'email'         => 'inactive@example.com',
            'created_at'    => $weekStart->copy()->addDays(3),
            'last_login_at' => null,
            'plan'          => 'studio',
        ]);

        $response = $this->get(route('super.retention.cohort.export', [
            'cohort' => $weekStart->toDateString(),
            'week'   => 1,
        ]));

        $response->assertStatus(200);
        $body = $response->streamedContent();

        // All three members appear.
        $this->assertStringContainsString('active1@example.com', $body);
        $this->assertStringContainsString('active2@example.com', $body);
        $this->assertStringContainsString('inactive@example.com', $body);

        $this->assertStringContainsString('cohort_week_start=' . $weekStart->toDateString(), $body);
        $this->assertStringContainsString('week_index=1', $body);
        $this->assertStringContainsString('period_start=' . $periodStart->toDateString(), $body);
        $this->assertStringContainsString('period_end=' . $periodEnd->toDateString(), $body);

        $headerLine = 'name,email,plan,registered_at,last_login_at,active_in_period,banned';
        $this->assertStringContainsString($headerLine, $body);
        $this->assertStringNotContainsString('user_id', $body);

        $audit = AdminAuditLog::where('action', 'retention.cohort_exported')->latest('id')->first();
        $this->assertNotNull($audit, 'retention.cohort_exported audit row must be written');
        $this->assertSame($admin->id, $audit->target_id, 'audit target must be the admin (the actor)');
        $this->assertSame($weekStart->toDateString(), $audit->payload['cohort_week_start'] ?? null);
        $this->assertSame(1, $audit->payload['week_index'] ?? null);
        $this->assertSame(3, $audit->payload['cohort_size'] ?? null);
        $this->assertSame(2, $audit->payload['active_count'] ?? null, 'active count reconciles with the matrix cell');
    }

    public function test_csv_export_button_renders_on_cohort_page(): void
    {
        [$admin] = $this->actingAsMfaSuperAdmin();
        $weekStart = $this->thisMonday()->subWeeks(2);

        $response = $this->get(route('super.retention.cohort', [
            'cohort' => $weekStart->toDateString(),
            'week'   => 0,
        ]));

        $response->assertStatus(200);
        $response->assertSee(route('super.retention.cohort.export', [
            'cohort' => $weekStart->toDateString(),
            'week'   => 0,
        ]), false);
        $response->assertSee('Export CSV', false);
    }

    public function test_csv_header_includes_exported_at_line(): void
    {
        [$admin] = $this->actingAsMfaSuperAdmin();
        $weekStart = $this->thisMonday()->subWeeks(2);

        $active = User::factory()->create([
            'email'         => 'asof@example.com',
            'created_at'    => $weekStart->copy()->addDays(1),
            'last_login_at' => $weekStart->copy()->addWeeks(2),
            'plan'          => 'free',
        ]);

        $response = $this->get(route('super.retention.cohort.export', [
            'cohort' => $weekStart->toDateString(),
            'week'   => 1,
        ]));

        $response->assertStatus(200);
        $body = $response->streamedContent();

        // The as-of line carries an ISO-8601 timestamp.
        $this->assertStringContainsString('exported_at=', $body);
        $this->assertMatchesRegularExpression('/exported_at=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $body, 'exported_at must be an ISO-8601 timestamp');

        $this->assertStringContainsString('active_in_period is computed live at export time', $body);

        $this->assertStringContainsString('# Exospace retention cohort export', $body);
        $this->assertStringContainsString('# active_in_period is computed live', $body);
    }
}
