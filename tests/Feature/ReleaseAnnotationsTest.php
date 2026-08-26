<?php

namespace Tests\Feature;

use App\Models\OnboardingSnapshot;
use App\Models\User;
use App\Services\ReleaseCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ITERATION 6 — TTFE trend release annotations.
 *
 * The changelog (ChangelogController) held dated releases nobody could
 * correlate with metric movement. ReleaseCalendar extracts it as the
 * single source of truth; the Master Control TTFE chart annotates
 * releases that fall inside the charted window. These tests pin:
 *   1. The calendar exposes dated releases
 *   2. between() filters to the window and merges same-day releases
 *   3. The changelog page still renders from the shared service
 *   4. Master Control embeds the annotations when a chart is drawn
 *   5. No annotations payload before the trend exists (placeholder state)
 */
class ReleaseAnnotationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    private function seedTrendPoint(string $weeksAgo): void
    {
        OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 8, 'published' => 2,
            'ttfe_avg' => 60.0, 'ttfg_avg' => 20.0,
            'captured_at' => now()->subWeeks((int) $weeksAgo)->startOfHour(),
        ]);
    }

    private function seedTrendPointAt(string $date): void
    {
        OnboardingSnapshot::create([
            'window_days' => 30, 'registered' => 8, 'published' => 2,
            'ttfe_avg' => 60.0, 'ttfg_avg' => 20.0,
            'captured_at' => \Carbon\Carbon::parse($date)->startOfHour(),
        ]);
    }

    public function test_release_calendar_exposes_dated_releases(): void
    {
        $dates = ReleaseCalendar::releaseDates();

        $this->assertNotEmpty($dates);
        foreach ($dates as $release) {
            $this->assertArrayHasKey('version', $release);
            $this->assertArrayHasKey('date', $release);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $release['date']);
        }
    }

    public function test_between_filters_to_window_and_merges_same_day_releases(): void
    {
        // v1.0 (2026-06-30) and v1.5/v1.6/v1.7 (2026-07-04) are the seeded
        // calendar entries: outside a July window only the merged trio
        // appears; inside a wider window both appear, oldest first.
        $julyWindow = ReleaseCalendar::between(
            now()->startOfYear()->setDate(2026, 7, 5),
            now()->startOfYear()->setDate(2026, 7, 20),
        );

        $this->assertEmpty($julyWindow, 'releases after 2026-07-04 are outside the window');

        $wide = ReleaseCalendar::between(
            now()->startOfYear()->setDate(2026, 6, 1),
            now()->startOfYear()->setDate(2026, 7, 31),
        );

        $this->assertCount(2, $wide);
        $this->assertSame('v1.0', $wide[0]['version']);
        $this->assertSame('2026-06-30', $wide[0]['date']);
        $this->assertSame(
            'v1.7 · v1.6 · v1.5',
            $wide[1]['version'],
            'same-day releases merge into one annotation marker',
        );
        $this->assertSame('2026-07-04', $wide[1]['date']);
    }

    public function test_changelog_page_renders_from_the_shared_calendar(): void
    {
        $response = $this->get('/changelog');

        $response->assertOk()->assertSee('v1.0', false);
    }

    public function test_master_control_embeds_release_annotations_when_chart_drawn(): void
    {
        // Two trend points spanning the seeded release dates (late June /
        // early July 2026): the chart window [Jun 30 − 7d, Jul 14] contains
        // both v1.0 and the merged v1.5–v1.7 trio.
        $this->seedTrendPointAt('2026-07-07');
        $this->seedTrendPointAt('2026-07-14');

        $response = $this->actingAsMfaSuperAdmin()->get('/master-control');

        $response->assertOk()
            ->assertSee('ttfe-trend-chart', false)
            ->assertSee('"version":"v1.0"', false)
            // Same-day releases merge into ONE marker (v1.5–v1.7 shipped
            // together on 2026-07-04); json_encode escapes the middot, so
            // assert the merged entry via the escaped separator.
            ->assertSee('"v1.7 \u00b7 v1.6 \u00b7 v1.5"', false)
            ->assertSee('2 release markers', false);
    }

    public function test_master_control_has_no_annotation_payload_before_a_trend_exists(): void
    {
        $response = $this->actingAsMfaSuperAdmin()->get('/master-control');

        $response->assertOk()
            ->assertDontSee('id="ttfe-trend-chart"', false)
            // The script stub always renders, but with no trend the
            // annotation payload stays an empty array.
            ->assertSee('var releases = [];', false)
            ->assertDontSee('"version":', false);
    }
}
