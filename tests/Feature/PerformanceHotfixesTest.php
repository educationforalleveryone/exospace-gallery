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

class PerformanceHotfixesTest extends TestCase
{
    use RefreshDatabase;

    public function e1_gallery_view_eager_loads_image_media(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/GalleryViewController.php'));
        $this->assertStringContainsString("'images.media'", $source, 'E-1: GalleryViewController must eager-load images.media');
    }

    public function e1_gallery_image_model_memoizes_media_resolution(): void
    {
        $source = file_get_contents(base_path('app/Models/GalleryImage.php'));
        $this->assertStringContainsString('memoizedMedia', $source, 'E-1: GalleryImage must have memoizedMedia property');
        $this->assertStringContainsString('getMemoizedMedia', $source, 'E-1: GalleryImage must have getMemoizedMedia method');
    }

    public function e1_get_srcset_does_not_requery_media_when_called_twice(): void
    {
        $gallery = Gallery::factory()->create(['is_active' => true]);
        $user    = User::factory()->create();
        $image   = \App\Models\GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
            'path'       => 'galleries/test/image.jpg',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $srcset1 = $image->getSrcsetAttribute();

        $queriesAfterFirst = count(DB::getQueryLog());

        $srcset2 = $image->getSrcsetAttribute();

        $queriesAfterSecond = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($srcset1, $srcset2, 'Same srcset returned both times');
        $this->assertSame($queriesAfterFirst, $queriesAfterSecond, 'E-1: Second getSrcsetAttribute call must not issue DB queries (memoized)');
    }

    public function e2_dashboard_uses_analytics_daily_not_raw_events_for_historical(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Admin/DashboardController.php'));

        $this->assertStringContainsString("DB::table('analytics_daily')", $source, 'E-2: DashboardController must query analytics_daily table');
        $this->assertStringContainsString("Cache::flexible", $source, 'E-2: DashboardController must cache the analytics result');
    }

    public function e2_dashboard_shows_correct_view_counts_with_rollup_data(): void
    {
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

    public function e5_nps_dashboard_uses_single_aggregate_query(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/SurveyController.php'));

        $this->assertStringContainsString("DB::table('survey_responses')", $source, 'E-5: must use DB::table for aggregate');
        $this->assertStringContainsString("SUM(CASE WHEN score >= 9", $source, 'E-5: must use SUM(CASE WHEN) for promoters');
        $this->assertStringContainsString("SUM(CASE WHEN score BETWEEN 7 AND 8", $source, 'E-5: must use SUM(CASE WHEN) for passives');
        $this->assertStringContainsString("SUM(CASE WHEN score <= 6", $source, 'E-5: must use SUM(CASE WHEN) for detractors');
        $this->assertStringContainsString("AVG(score)", $source, 'E-5: must use AVG(score) for average');
    }

    public function e5_nps_dashboard_calculates_correct_scores(): void
    {
        $controller = app(\App\Http\Controllers\SurveyController::class);
        $request    = \Illuminate\Http\Request::create('/master-control/nps', 'GET');

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

    public function e5_nps_dashboard_does_not_load_all_responses_into_collection(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/SurveyController.php'));

        $start = strpos($source, 'function npsDashboard');
        $this->assertNotFalse($start, 'npsDashboard method must exist');

        $methodBody = substr($source, $start);
        $this->assertStringNotContainsString("\$allResponses =", $methodBody, 'E-5: must not assign $allResponses (Collection of all rows)');
        $this->assertStringNotContainsString("\$allResponses->where('score'", $methodBody, 'E-5: must not filter Collection by score (use SQL aggregate instead)');
    }
}
