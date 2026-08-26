<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OnboardingSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 9 — funnel-stage conversion-rate trend + >2σ anomaly rings.
 *
 * Coverage: workstream A closes the visibility gap that the 5-bar
 * onboarding funnel was a point value (one window) — a sudden stage
 * drop ("this week only 10% of new signups created a gallery vs the
 * 30% trailing avg") was invisible. The trend now exposes per-stage
 * counts (registered / created_gallery / uploaded_image / published /
 * got_views per snapshot), SystemController computes 4 stage-conversion
 * series + applies TrendAnomalies::detect to each, and the Master
 * Control page embeds the per-stage payload as JSON so the inline
 * Chart.js plugin can ring the right points.
 *
 * Tests:
 *   - Per-stage values are returned by OnboardingMetricsService::trend()
 *     (audit-fix E-2 backfill — registered/published were already there).
 *   - SystemController computes the 4 stage-conversion series from the
 *     trend and applies TrendAnomalies::detect to each.
 *   - A sudden stage-rate drop (low direction) is flagged in the payload.
 *   - A clean stage trend (noise-level blip) yields an empty payload.
 *   - Fresh install (< 2 snapshots) renders the placeholder, not the
 *     chart — no payload embedded at all.
 *   - Canvas accessibility (WCAG 1.1.1) — role="img" + aria-label
 *     computed server-side from the trend + anomaly count.
 *
 * Run: php artisan test --filter=FunnelStageTrendTest
 */
class FunnelStageTrendTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.slack.example/funnel';

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

    /**
     * Seed a snapshot with full per-stage counts. Caller can tune the
     * ratio of stages (e.g. drop created_gallery to make S1 conversion
     * drop). Default seed mirrors the iteration-7 trend test fixture
     * (5 registered, 4 created_gallery, 3 uploaded_image, 2 published,
     * 1 got_views) so cross-test comparisons are apples-to-apples.
     */
    private function seedSnapshot(
        string $captured,
        int $registered = 5,
        int $createdGallery = 4,
        int $uploadedImage = 3,
        int $published = 2,
        int $gotViews = 1,
        ?float $ttfeAvg = 10.0,
        int $window = 30,
    ): void {
        OnboardingSnapshot::create([
            'window_days'    => $window,
            'registered'     => $registered,
            'created_gallery'=> $createdGallery,
            'uploaded_image' => $uploadedImage,
            'published'      => $published,
            'got_views'      => $gotViews,
            'ttfg_min'       => $ttfeAvg !== null ? $ttfeAvg - 1.0 : null,
            'ttfg_avg'       => $ttfeAvg,
            'ttfg_max'       => $ttfeAvg !== null ? $ttfeAvg + 1.0 : null,
            'ttfe_min'       => $ttfeAvg !== null ? $ttfeAvg - 0.5 : null,
            'ttfe_avg'       => $ttfeAvg,
            'ttfe_max'       => $ttfeAvg !== null ? $ttfeAvg + 0.5 : null,
            'captured_at'    => $captured,
        ]);
    }

    // ── Per-stage backfill (audit-fix E-2) ───────────────────────────

    public function test_trend_returns_per_stage_counts_for_each_snapshot(): void
    {
        $this->seedSnapshot('2026-07-07 06:30:00', registered: 10, createdGallery: 8, uploadedImage: 6, published: 4, gotViews: 2);
        $this->seedSnapshot('2026-07-14 06:30:00', registered: 12, createdGallery: 9, uploadedImage: 7, published: 5, gotViews: 3);

        $trend = app(\App\Services\OnboardingMetricsService::class)->trend(30, 26);

        $this->assertCount(2, $trend);
        $this->assertSame(10, $trend[0]['registered']);
        $this->assertSame(8, $trend[0]['created_gallery']);
        $this->assertSame(6, $trend[0]['uploaded_image']);
        $this->assertSame(4, $trend[0]['published']);
        $this->assertSame(2, $trend[0]['got_views']);
        $this->assertSame(12, $trend[1]['registered']);
        $this->assertSame(9, $trend[1]['created_gallery']);
        $this->assertSame(7, $trend[1]['uploaded_image']);
        $this->assertSame(5, $trend[1]['published']);
        $this->assertSame(3, $trend[1]['got_views']);
    }

    // ── Master Control embed ──────────────────────────────────────────

    public function test_master_control_embeds_funnel_stage_payload_when_trend_has_two_points(): void
    {
        $this->seedSnapshot('2026-07-07 06:30:00');
        $this->seedSnapshot('2026-07-14 06:30:00');

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        // Chart canvas renders (>= 2 points).
        $response->assertSee('id="funnel-stage-trend-chart"', false);
        // 4-stage JSON payload embedded for the inline plugin. The `→`
        // arrow in each label is JSON-escaped to \u2192 (PHP json_encode
        // default). Asserting the keys + the stage ids is the stable
        // shape pin; the individual labels are user-facing copy.
        $response->assertSee('"key":"s1"', false);
        $response->assertSee('"key":"s2"', false);
        $response->assertSee('"key":"s3"', false);
        $response->assertSee('"key":"s4"', false);
        // The first stage label (Registered → Created gallery) is in
        // the JSON payload — the arrow is \u2192 in JSON.
        $response->assertSee('Registered \u2192 Created gallery', false);
    }

    public function test_master_control_embeds_anomaly_in_funnel_stage_payload_when_stage_drops(): void
    {
        // 5 snapshots: S1 (created_gallery/registered) drops from 80% to
        // 10% on week 5 (a >2σ drop from the trailing mean). The cascade
        // also produces a second anomaly: S2 (uploaded_image/created_
        // gallery) SPIKES because uploaded_image stays constant while
        // created_gallery drops (3/1 = 300%). That's an intentional
        // artifact of the test seed — the cascade itself surfaces two
        // anomalies on the same week, which the header counts as "2
        // anomalies". The low-direction S1 drop is the operator-
        // actionable one (amber = worse); the high-direction S2 spike
        // is a downstream artifact of the same root cause (created_
        // gallery dropped) — both flag correctly.
        $this->seedSnapshot('2026-07-07 06:30:00', registered: 10, createdGallery: 8);
        $this->seedSnapshot('2026-07-14 06:30:00', registered: 10, createdGallery: 8);
        $this->seedSnapshot('2026-07-21 06:30:00', registered: 10, createdGallery: 8);
        $this->seedSnapshot('2026-07-28 06:30:00', registered: 10, createdGallery: 8);
        $this->seedSnapshot('2026-08-04 06:30:00', registered: 10, createdGallery: 1); // stage drop

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        // The funnel-stage anomaly header count surfaces BOTH anomalies
        // (low drop on S1 + high spike on S2 from the same root cause).
        $response->assertSee('2 anomalies', false);
        // Direction 'low' = stage drop = worse (amber).
        $response->assertSee('"direction":"low"', false);
    }

    public function test_master_control_embeds_empty_anomaly_payload_on_clean_funnel_stage_trend(): void
    {
        // 5 snapshots — flat baseline, no stage drop. The header count
        // must NOT surface any anomaly suffix.
        $this->seedSnapshot('2026-07-07 06:30:00');
        $this->seedSnapshot('2026-07-14 06:30:00');
        $this->seedSnapshot('2026-07-21 06:30:00');
        $this->seedSnapshot('2026-07-28 06:30:00');
        $this->seedSnapshot('2026-08-04 06:30:00');

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        // The funnel-stage header shows "4 stages" with no anomaly suffix.
        // (The TTFE chart header has its own anomaly count from the iter-7
        // payload; we assert against the funnel-stage header specifically.)
        $response->assertSee('· 4 stages', false);
        // No direction payload embedded for the funnel-stage series.
        $response->assertDontSee('"direction":"low"', false);
        $response->assertDontSee('"direction":"high"', false);
    }

    public function test_master_control_no_funnel_stage_chart_when_trend_has_one_point(): void
    {
        $this->seedSnapshot('2026-07-07 06:30:00');

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        // The funnel-stage chart canvas does NOT render (< 2 snapshots).
        $response->assertDontSee('id="funnel-stage-trend-chart"', false);
    }

    public function test_funnel_stage_chart_canvas_has_role_img_and_aria_label_for_accessibility(): void
    {
        // The same WCAG 1.1.1 convention the iter-8 TTFE + retention
        // charts ship: role="img" + server-side aria-label so a screen
        // reader announces point count + anomaly count even though the
        // canvas itself has no DOM text.
        $this->seedSnapshot('2026-07-07 06:30:00');
        $this->seedSnapshot('2026-07-14 06:30:00');

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        $response->assertSee('id="funnel-stage-trend-chart" role="img"', false);
        $response->assertSee('aria-label="Funnel-stage conversion trend chart', false);
    }
}
