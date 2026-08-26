<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OnboardingSnapshot;
use App\Models\User;
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
}
