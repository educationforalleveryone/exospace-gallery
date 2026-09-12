<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OnboardingSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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

    public function test_master_control_embeds_funnel_stage_payload_when_trend_has_two_points(): void
    {
        $this->seedSnapshot('2026-07-07 06:30:00');
        $this->seedSnapshot('2026-07-14 06:30:00');

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        // Chart canvas renders (>= 2 points).
        $response->assertSee('id="funnel-stage-trend-chart"', false);
        $response->assertSee('"key":"s1"', false);
        $response->assertSee('"key":"s2"', false);
        $response->assertSee('"key":"s3"', false);
        $response->assertSee('"key":"s4"', false);
        $response->assertSee('Registered \u2192 Created gallery', false);
    }

    public function test_master_control_embeds_anomaly_in_funnel_stage_payload_when_stage_drops(): void
    {
        $this->seedSnapshot('2026-07-07 06:30:00', registered: 10, createdGallery: 8);
        $this->seedSnapshot('2026-07-14 06:30:00', registered: 10, createdGallery: 8);
        $this->seedSnapshot('2026-07-21 06:30:00', registered: 10, createdGallery: 8);
        $this->seedSnapshot('2026-07-28 06:30:00', registered: 10, createdGallery: 8);
        $this->seedSnapshot('2026-08-04 06:30:00', registered: 10, createdGallery: 1); // stage drop

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        $response->assertSee('2 anomalies', false);
        // Direction 'low' = stage drop = worse (amber).
        $response->assertSee('"direction":"low"', false);
    }

    public function test_master_control_embeds_empty_anomaly_payload_on_clean_funnel_stage_trend(): void
    {
        $this->seedSnapshot('2026-07-07 06:30:00');
        $this->seedSnapshot('2026-07-14 06:30:00');
        $this->seedSnapshot('2026-07-21 06:30:00');
        $this->seedSnapshot('2026-07-28 06:30:00');
        $this->seedSnapshot('2026-08-04 06:30:00');

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
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
        $this->seedSnapshot('2026-07-07 06:30:00');
        $this->seedSnapshot('2026-07-14 06:30:00');

        $response = $this->actingAsMfaSuperAdmin()
            ->get(route('super.index'));

        $response->assertStatus(200);
        $response->assertSee('id="funnel-stage-trend-chart" role="img"', false);
        $response->assertSee('aria-label="Funnel-stage conversion trend chart', false);
    }
}
