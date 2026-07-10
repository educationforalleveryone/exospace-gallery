<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\Gallery;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Iteration-011 regression tests for performance hot-fixes:
 *   - E-1: GalleryImage media eager-load + per-instance memoization
 *   - E-2: DashboardController uses analytics_daily (not raw events) for historical
 *   - E-5: NPS dashboard uses a single SQL aggregate (not Collection math)
 */
class PerformanceHotfixesTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function e1_gallery_view_eager_loads_image_media(): void
    {
        // E-1 fix: the GalleryViewController::show eager-load chain must
        // include 'images.media' so Spatie doesn't issue a per-image DB
        // query when getSrcsetAttribute / conversionUrl / getPublicUrl
        // are called in the view.
        $source = file_get_contents(base_path('app/Http/Controllers/GalleryViewController.php'));
        $this->assertStringContainsString("'images.media'", $source, 'E-1: GalleryViewController must eager-load images.media');
    }

    /** @test */
    public function e1_gallery_image_model_memoizes_media_resolution(): void
    {
        // E-1 fix: GalleryImage must memoize the resolved Media object on
        // the model instance, so getSrcsetAttribute + conversionUrl +
        // getPublicUrl in the same render share a single resolution.
        $source = file_get_contents(base_path('app/Models/GalleryImage.php'));
        $this->assertStringContainsString('memoizedMedia', $source, 'E-1: GalleryImage must have memoizedMedia property');
        $this->assertStringContainsString('getMemoizedMedia', $source, 'E-1: GalleryImage must have getMemoizedMedia method');
    }

    /** @test */
    public function e1_get_srcset_does_not_requery_media_when_called_twice(): void
    {
        // Functional test: calling getSrcsetAttribute twice on the same
        // instance should hit the memoization cache on the second call.
        // We verify by counting DB queries.
        $gallery = Gallery::factory()->create(['is_active' => true]);
        $user    = User::factory()->create();
        $image   = \App\Models\GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
            'path'       => 'galleries/test/image.jpg',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        // First call resolves media (may issue a query if not eager-loaded).
        $srcset1 = $image->getSrcsetAttribute();

        $queriesAfterFirst = count(DB::getQueryLog());

        // Second call should NOT issue any additional queries — memoization.
        $srcset2 = $image->getSrcsetAttribute();

        $queriesAfterSecond = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($srcset1, $srcset2, 'Same srcset returned both times');
        $this->assertSame($queriesAfterFirst, $queriesAfterSecond, 'E-1: Second getSrcsetAttribute call must not issue DB queries (memoized)');
    }

    /** @test */
    public function e2_dashboard_uses_analytics_daily_not_raw_events_for_historical(): void
    {
        // E-2 fix: DashboardController::index must read from analytics_daily
        // for historical data (not raw analytics_events). The audit said the
        // dashboard ran 3 COUNT queries + 1 DATE() GROUP BY against raw events.
        $source = file_get_contents(base_path('app/Http/Controllers/Admin/DashboardController.php'));

        $this->assertStringContainsString("DB::table('analytics_daily')", $source, 'E-2: DashboardController must query analytics_daily table');
        $this->assertStringContainsString("Cache::flexible", $source, 'E-2: DashboardController must cache the analytics result');
    }

    /** @test */
    public function e2_dashboard_shows_correct_view_counts_with_rollup_data(): void
    {
        // Functional test: populate analytics_daily + raw events for today,
        // then verify the dashboard sums them correctly.
        $user    = User::factory()->create();
        $gallery = Gallery::factory()->create([
            'user_id'  => $user->id,
            'team_id'  => null,
            'is_active' => true,
        ]);

        // Populate analytics_daily for yesterday (6 days ago).
        $yesterday = now()->subDay()->toDateString();
        DB::table('analytics_daily')->insert([
            'gallery_id'      => $gallery->id,
            'date'            => $yesterday,
            'views'           => 50,
            'unique_visitors' => 30,
            'focuses'         => 10,
            'tour_starts'     => 5,
            'avg_dwell_seconds' => 60.0,
        ]);

        // Populate raw events for today.
        AnalyticsEvent::create([
            'gallery_id'    => $gallery->id,
            'event'         => 'view',
            'session_token' => 'session-1',
            'created_at'    => now(),
        ]);
        AnalyticsEvent::create([
            'gallery_id'    => $gallery->id,
            'event'         => 'view',
            'session_token' => 'session-2',
            'created_at'    => now(),
        ]);

        // Clear any cached dashboard data from prior tests.
        \Illuminate\Support\Facades\Cache::flush();

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertViewHas('viewsToday', 2);     // from raw events
        $response->assertViewHas('views7', 52);        // 50 (rollup) + 2 (today)
    }

    /** @test */
    public function e5_nps_dashboard_uses_single_aggregate_query(): void
    {
        // E-5 fix: NPS dashboard must NOT call SurveyResponse::...->get().
        // Must use DB::table with selectRaw for COUNT/SUM aggregates.
        $source = file_get_contents(base_path('app/Http/Controllers/SurveyController.php'));

        $this->assertStringContainsString("DB::table('survey_responses')", $source, 'E-5: must use DB::table for aggregate');
        $this->assertStringContainsString("SUM(CASE WHEN score >= 9", $source, 'E-5: must use SUM(CASE WHEN) for promoters');
        $this->assertStringContainsString("SUM(CASE WHEN score BETWEEN 7 AND 8", $source, 'E-5: must use SUM(CASE WHEN) for passives');
        $this->assertStringContainsString("SUM(CASE WHEN score <= 6", $source, 'E-5: must use SUM(CASE WHEN) for detractors');
        $this->assertStringContainsString("AVG(score)", $source, 'E-5: must use AVG(score) for average');
    }

    /** @test */
    public function e5_nps_dashboard_calculates_correct_scores(): void
    {
        // Call the controller method directly (bypasses super-admin/MFA
        // middleware) to verify the SQL aggregate produces correct stats.
        $controller = app(\App\Http\Controllers\SurveyController::class);
        $request    = \Illuminate\Http\Request::create('/master-control/nps', 'GET');

        // 4 promoters (9-10), 2 passives (7-8), 2 detractors (0-6).
        // NPS = (4 - 2) / 8 * 100 = 25
        foreach ([10, 10, 9, 9, 7, 8, 0, 6] as $score) {
            SurveyResponse::create([
                'user_id'      => User::factory()->create()->id,
                'survey_type'  => 'nps',
                'score'        => $score,
                'triggered_at' => now(),
                'responded_at' => now(),
            ]);
        }

        $response = $controller->npsDashboard($request);

        $stats = $response->getData()['stats'] ?? null;
        // The view data may be accessed via the view's shared data.
        $viewData = $response instanceof \Illuminate\View\View ? $response->getData() : [];
        $stats = $viewData['stats'] ?? null;

        $this->assertNotNull($stats);
        $this->assertSame(8, $stats['total']);
        $this->assertSame(4, $stats['promoters']);
        $this->assertSame(2, $stats['passives']);
        $this->assertSame(2, $stats['detractors']);
        $this->assertSame(25, $stats['nps_score']);
        $this->assertSame(7.4, $stats['avg_score']);
    }

    /** @test */
    public function e5_nps_dashboard_handles_empty_responses(): void
    {
        $controller = app(\App\Http\Controllers\SurveyController::class);
        $request    = \Illuminate\Http\Request::create('/master-control/nps', 'GET');

        $response = $controller->npsDashboard($request);

        $viewData = $response instanceof \Illuminate\View\View ? $response->getData() : [];
        $stats = $viewData['stats'] ?? null;

        $this->assertNotNull($stats);
        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['nps_score']);
        $this->assertSame(0.0, $stats['avg_score']);
    }

    /** @test */
    public function e5_nps_dashboard_does_not_load_all_responses_into_collection(): void
    {
        // Defensive: confirm the source no longer contains the old ->get()
        // pattern that loaded every response into a Collection.
        $source = file_get_contents(base_path('app/Http/Controllers/SurveyController.php'));

        // The npsDashboard method must not contain `->get();` followed by
        // `->where('score'` (the old Collection-filtering pattern).
        // Find the npsDashboard method body.
        $start = strpos($source, 'function npsDashboard');
        $this->assertNotFalse($start, 'npsDashboard method must exist');

        $methodBody = substr($source, $start);
        $this->assertStringNotContainsString("\$allResponses =", $methodBody, 'E-5: must not assign $allResponses (Collection of all rows)');
        $this->assertStringNotContainsString("\$allResponses->where('score'", $methodBody, 'E-5: must not filter Collection by score (use SQL aggregate instead)');
    }
}
