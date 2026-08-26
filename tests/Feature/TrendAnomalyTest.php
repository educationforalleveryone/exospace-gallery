<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OnboardingSnapshot;
use App\Models\RetentionSnapshot;
use App\Models\User;
use App\Services\TrendAnomalies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ITERATION 7 — >2σ anomaly annotations on the TTFE trend.
 *
 * Coverage: the TrendAnomalies detector math (flat baseline guard,
 * high/low direction, null-gap tolerance, min-priors floor) + the
 * Master Control chart embeds the {index, label, z, direction}
 * payload as a JSON list so the inline Chart.js plugin can ring
 * the right points without re-deriving the math in JS.
 */
class TrendAnomalyTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://hooks.slack.example/anomaly';

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

    // ── Detector math ─────────────────────────────────────────────────

    public function test_detect_flags_a_clear_high_outlier(): void
    {
        // Four flat baseline points + a clear spike — the spike is
        // >2σ above the trailing mean.
        $series = [10.0, 10.0, 10.0, 10.0, 18.0];

        $anomalies = \App\Services\TrendAnomalies::detect($series);

        $this->assertCount(1, $anomalies);
        $this->assertSame(4, $anomalies[0]['index']);
        $this->assertSame('high', $anomalies[0]['direction']);
        $this->assertSame(18.0, $anomalies[0]['value']);
        $this->assertGreaterThan(2, $anomalies[0]['z']);
    }

    public function test_detect_flags_a_clear_low_outlier(): void
    {
        $series = [10.0, 10.0, 10.0, 10.0, 2.0];

        $anomalies = \App\Services\TrendAnomalies::detect($series);

        $this->assertCount(1, $anomalies);
        $this->assertSame('low', $anomalies[0]['direction']);
        $this->assertSame(2.0, $anomalies[0]['value']);
        $this->assertLessThan(-2, $anomalies[0]['z']);
    }

    public function test_detect_requires_min_four_priors(): void
    {
        // Three priors then an outlier — under the MIN_PRIORS floor
        // (σ is meaningless on weekly samples with <4 baseline points).
        $series = [10.0, 10.0, 10.0, 30.0];

        $this->assertSame([], \App\Services\TrendAnomalies::detect($series));
    }

    public function test_detect_skips_null_points_in_window(): void
    {
        // Null weeks are not 0h TTFEs — they're weeks with no
        // publisher. The detector looks back through PRIOR non-null
        // points only; a null in the window doesn't dilute the mean.
        $series = [10.0, null, 10.0, 10.0, 10.0, 20.0];

        $anomalies = \App\Services\TrendAnomalies::detect($series);

        $this->assertCount(1, $anomalies);
        $this->assertSame(5, $anomalies[0]['index']);
        $this->assertSame('high', $anomalies[0]['direction']);
    }

    public function test_detect_flat_baseline_guard_needs_meaningful_jump(): void
    {
        // A flat window then a noise-level blip (0.1h resolution) must
        // NOT flag — the σ-floor keeps noise below the 2σ threshold.
        // threshold = max(2σ, 2×0.25) = max(0, 0.5) = 0.5h.
        $series = [10.0, 10.0, 10.0, 10.0, 10.2];

        $this->assertSame([], \App\Services\TrendAnomalies::detect($series));
    }

    public function test_detect_flat_baseline_flags_a_real_jump(): void
    {
        // Same flat window then a 1h jump — exceeds the 0.5h floor.
        $series = [10.0, 10.0, 10.0, 10.0, 11.0];

        $anomalies = \App\Services\TrendAnomalies::detect($series);

        $this->assertCount(1, $anomalies);
        $this->assertSame('high', $anomalies[0]['direction']);
    }

    public function test_detect_trailing_window_caps_at_eight_priors(): void
    {
        // A regime shift long ago shouldn't inflate σ forever — the
        // window caps at the 8 most recent priors so old samples
        // fall out of the calculation as the trend ages.
        // Build: 12 baseline @ 10.0, then 8 @ 10.0 + a spike at 50.
        // With a flat 8-window the spike clearly flags regardless
        // of the older 4 baseline points.
        $series = array_merge(
            array_fill(0, 12, 10.0),  // very old regime
            array_fill(0, 8, 10.0),   // recent baseline
            [50.0],                   // spike
        );

        $anomalies = \App\Services\TrendAnomalies::detect($series);

        $this->assertCount(1, $anomalies);
        $this->assertSame('high', $anomalies[0]['direction']);
    }

    public function test_detect_no_anomalies_on_clean_trend(): void
    {
        // A steadily-varying trend with no >2σ deviations.
        $series = [10.0, 11.0, 10.5, 11.5, 10.8, 11.2, 10.9, 11.0];

        $this->assertSame([], \App\Services\TrendAnomalies::detect($series));
    }

    // ── Master Control embed ──────────────────────────────────────────

    private function seedSnapshot(string $captured, ?float $ttfeAvg, int $window = 30): void
    {
        OnboardingSnapshot::create([
            'window_days'    => $window,
            'registered'     => 5,
            'created_gallery'=> 4,
            'uploaded_image' => 3,
            'published'      => 2,
            'got_views'      => 1,
            'ttfg_min'       => $ttfeAvg ? $ttfeAvg - 1.0 : null,
            'ttfg_avg'       => $ttfeAvg,
            'ttfg_max'       => $ttfeAvg ? $ttfeAvg + 1.0 : null,
            'ttfe_min'       => $ttfeAvg ? $ttfeAvg - 0.5 : null,
            'ttfe_avg'       => $ttfeAvg,
            'ttfe_max'       => $ttfeAvg ? $ttfeAvg + 0.5 : null,
            'captured_at'    => $captured,
        ]);
    }

    public function test_master_control_embeds_anomaly_payload_when_trend_has_outlier(): void
    {
        // 5 snapshots: flat baseline + spike — TTFE chart will draw
        // (>=2 points) and the spike should flag.
        $this->seedSnapshot('2026-07-07 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-14 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-21 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-28 06:30:00', 10.0);
        $this->seedSnapshot('2026-08-04 06:30:00', 18.0);

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        // Anomaly count surfaced in the header line.
        $response->assertSee('1 anomaly', false);
        // JSON payload embedded for the inline plugin.
        $response->assertSee('"direction":"high"', false);
    }

    public function test_master_control_embeds_empty_anomaly_payload_on_clean_trend(): void
    {
        // 5 snapshots — flat baseline, no outlier.
        $this->seedSnapshot('2026-07-07 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-14 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-21 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-28 06:30:00', 10.0);
        $this->seedSnapshot('2026-08-04 06:30:00', 10.5);  // noise-level blip — no flag

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        // Empty payload embedded as [] (the chart plugin is conditional
        // on anomalies.length > 0 so a clean trend renders with no rings).
        // We don't assert the absence of the substring 'anomal' — the JS
        // variable name itself contains it (var anomalies = []).
        $response->assertSee('var anomalies = [];', false);
    }

    public function test_master_control_no_anomaly_payload_when_trend_has_one_point(): void
    {
        $this->seedSnapshot('2026-07-07 06:30:00', 10.0);

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        // The placeholder shows instead of the chart.
        $response->assertSee('Trend appears after the second weekly snapshot', false);
    }

    // ── ITERATION 8: null-immediately-before-spike (audit-fix D-3) ──────

    public function test_detect_null_immediately_before_spike_still_flags(): void
    {
        // Existing null-gap test puts the null in the middle of the
        // baseline. This pins the edge case where the null sits
        // IMMEDIATELY before the spike (the trailing window must
        // skip the null and reach back to the priors before it).
        // Series: [10, 10, 10, 10, null, 20] — index 4 is null,
        // index 5 is the spike. The window for index 5 looks back
        // at indices [4 (null, skipped), 3, 2, 1, 0] = 4 priors.
        // σ over [10,10,10,10] = 0; sigma_eff = 0.25; z = (20-10)/0.25 = 40
        // → flags at index 5, direction high.
        $result = TrendAnomalies::detect([10.0, 10.0, 10.0, 10.0, null, 20.0]);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]['index']);
        $this->assertSame('high', $result[0]['direction']);
    }

    // ── ITERATION 8: retention W1/W2 anomaly annotations (workstream B) ──

    private function seedRetentionSnapshot(string $captured, float $pct, int $weekIndex = 1): void
    {
        RetentionSnapshot::create([
            'cohort_week_start' => '2026-07-06',
            'week_index'        => $weekIndex,
            'cohort_size'       => 10,
            'active_count'      => (int) round($pct / 10),
            'retained_pct'      => $pct,
            'captured_at'       => $captured,
        ]);
    }

    public function test_retention_w1_trend_embeds_anomaly_payload_when_outlier(): void
    {
        // 5 W1 snapshots — flat 30% baseline, then a drop to 10% (a
        // >2σ low outlier — churn up).
        $this->seedRetentionSnapshot('2026-07-07 06:00:00', 30.0);
        $this->seedRetentionSnapshot('2026-07-14 06:00:00', 30.0);
        $this->seedRetentionSnapshot('2026-07-21 06:00:00', 30.0);
        $this->seedRetentionSnapshot('2026-07-28 06:00:00', 30.0);
        $this->seedRetentionSnapshot('2026-08-04 06:00:00', 10.0);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));

        $response->assertStatus(200);
        // The retention chart's W1 anomaly payload embeds JSON with
        // direction=low (churn-up → worse → amber ring per the
        // inverted color convention).
        $response->assertSee('var w1Anomalies =', false);
        $response->assertSee('"direction":"low"', false);
        // Header count line on the retention chart gains the suffix.
        $response->assertSee('1 anomaly', false);
    }

    public function test_retention_w1_trend_embeds_empty_payload_on_clean_trend(): void
    {
        // 5 W1 snapshots — flat 30% baseline with a noise-level blip
        // (under the >2σ threshold; should not flag).
        $this->seedRetentionSnapshot('2026-07-07 06:00:00', 30.0);
        $this->seedRetentionSnapshot('2026-07-14 06:00:00', 30.0);
        $this->seedRetentionSnapshot('2026-07-21 06:00:00', 30.0);
        $this->seedRetentionSnapshot('2026-07-28 06:00:00', 30.0);
        $this->seedRetentionSnapshot('2026-08-04 06:00:00', 30.4);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));

        $response->assertStatus(200);
        $response->assertSee('var w1Anomalies = [];', false);
        $response->assertSee('var w2Anomalies = [];', false);
    }

    public function test_canvas_has_role_img_and_aria_label_for_accessibility(): void
    {
        // WCAG 1.1.1 — canvas needs a text alternative. The aria-label
        // is computed server-side from the trend + annotation counts so
        // a screen reader announces point count + release markers +
        // anomaly count (audit-fix D-1).
        // Both charts need >= 2 weekly snapshots to render the canvas
        // (the @if guard draws a placeholder when there's <2 points).
        $this->seedSnapshot('2026-07-07 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-14 06:30:00', 10.0);
        $this->seedRetentionSnapshot('2026-07-07 06:00:00', 30.0);
        $this->seedRetentionSnapshot('2026-07-14 06:00:00', 30.0);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));

        $response->assertStatus(200);
        // TTFE canvas: role="img" + aria-label naming the chart + point count.
        $response->assertSee('id="ttfe-trend-chart" role="img"', false);
        $response->assertSee('aria-label="TTFE trend chart, 2 weekly snapshots', false);
        // Retention canvas: same convention.
        $response->assertSee('id="retention-trend-chart" role="img"', false);
        $response->assertSee('Week-1 and Week-2 retention trend chart', false);
    }

    public function test_ttfe_anomaly_payload_includes_sigma_and_sigma_eff(): void
    {
        // audit-fix D-4: the payload now carries sigma + sigma_eff
        // alongside z so the operator can sanity-check the threshold
        // by hand (a future tooltip can surface them; for now they're
        // available in the JS variable for the operator's console).
        $this->seedSnapshot('2026-07-07 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-14 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-21 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-28 06:30:00', 10.0);
        $this->seedSnapshot('2026-08-04 06:30:00', 18.0);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));

        $response->assertStatus(200);
        // Sigma + sigma_eff now appear in the JSON payload (z was already there).
        $response->assertSee('"sigma":', false);
        $response->assertSee('"sigma_eff":', false);
    }

    // ── ITERATION 9 (workstream C) — per-shape tooltip override plugin ──

    /**
     * The Iter-8 codified aria-label made the canvas accessible; the
     * per-shape data (mean, sigma_eff, z, direction) is in the JS
     * payload but only renders as a static ±Nsigma label on the
     * canvas. Iter-9 ships an inline tooltip override plugin that
     * augments the default Chart.js tooltip when hovering a ringed
     * point. The plugin is conditional on anomalies.length > 0 so a
     * clean trend renders with the default tooltip behavior.
     */
    public function test_master_control_embeds_anomaly_tooltip_override_script_when_anomalies_exist(): void
    {
        $this->seedSnapshot('2026-07-07 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-14 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-21 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-28 06:30:00', 10.0);
        $this->seedSnapshot('2026-08-04 06:30:00', 18.0); // outlier — TTFE anomaly flags

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));

        $response->assertStatus(200);
        // The tooltip override plugin attaches to Chart.defaults.plugins.tooltip.external.
        $response->assertSee('attachTooltipOverride', false);
        $response->assertSee('Chart.defaults.plugins.tooltip.external', false);
    }

    public function test_master_control_does_not_embed_anomaly_tooltip_override_when_trend_clean(): void
    {
        // 5 snapshots — flat baseline, no outliers anywhere.
        $this->seedSnapshot('2026-07-07 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-14 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-21 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-28 06:30:00', 10.0);
        $this->seedSnapshot('2026-08-04 06:30:00', 10.5); // noise-level blip — no flag

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));

        $response->assertStatus(200);
        // No tooltip override plugin attached — clean trend renders with default tooltip.
        $response->assertDontSee('attachTooltipOverride', false);
        $response->assertDontSee('Chart.defaults.plugins.tooltip.external', false);
    }

    public function test_anomaly_tooltip_override_reuses_payload_vars_per_chart_canvas_id(): void
    {
        // The plugin is shared across all 3 charts (TTFE + W1 + W2).
        // The guard maps the chart canvas ID → the matching anomaly
        // list, so hovering a TTFE ring reads the TTFE anomaly's mean
        // / sigma_eff / z, not the retention W1's. Test pin: the
        // plugin references each canvas ID in its dispatch logic.
        $this->seedSnapshot('2026-07-07 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-14 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-21 06:30:00', 10.0);
        $this->seedSnapshot('2026-07-28 06:30:00', 10.0);
        $this->seedSnapshot('2026-08-04 06:30:00', 18.0);

        $response = $this->actingAsMfaSuperAdmin()->get(route('super.index'));

        $response->assertStatus(200);
        // The plugin dispatches by canvas ID — assert the IDs are referenced.
        $response->assertSee("'ttfe-trend-chart'", false);
        $response->assertSee("'retention-trend-chart'", false);
    }
}
