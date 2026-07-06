<?php

namespace App\Console\Commands;

use App\Models\Gallery;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M-16: Onboarding funnel analytics.
 *
 * Tracks how users progress through the onboarding funnel:
 *   1. Registered (created_at)
 *   2. Created first gallery
 *   3. Uploaded first image
 *   4. Published gallery (is_active = true)
 *   5. Got first view
 *
 * Outputs a funnel report showing conversion rates between stages.
 * Run manually or schedule weekly for trend tracking.
 */
class OnboardingAnalytics extends Command
{
    protected $signature = 'exospace:onboarding-analytics {--days=30 : Analyze users from last N days}';
    protected $description = 'Generate onboarding funnel analytics report.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $this->info("Onboarding funnel (last {$days} days, since {$cutoff->format('Y-m-d')})");
        $this->newLine();

        // Stage 1: Registered
        $registered = User::where('created_at', '>=', $cutoff)->count();
        $this->info("1. Registered:           {$registered}");

        // Stage 2: Created first gallery
        $createdGallery = User::where('created_at', '>=', $cutoff)
            ->whereHas('galleries')
            ->count();
        $this->info("2. Created gallery:      {$createdGallery}  (" . $this->pct($createdGallery, $registered) . ")");

        // Stage 3: Uploaded first image
        $uploadedImage = User::where('users.created_at', '>=', $cutoff)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('gallery_images')
                    ->join('galleries', 'galleries.id', '=', 'gallery_images.gallery_id')
                    ->whereColumn('galleries.user_id', 'users.id')
                    ->whereNull('galleries.deleted_at')
                    ->whereNull('gallery_images.deleted_at');
            })
            ->count();
        $this->info("3. Uploaded image:       {$uploadedImage}  (" . $this->pct($uploadedImage, $createdGallery) . ")");

        // Stage 4: Published gallery
        $published = User::where('users.created_at', '>=', $cutoff)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('galleries')
                    ->whereColumn('galleries.user_id', 'users.id')
                    ->where('galleries.is_active', true)
                    ->whereNull('galleries.deleted_at');
            })
            ->count();
        $this->info("4. Published gallery:    {$published}  (" . $this->pct($published, $uploadedImage) . ")");

        // Stage 5: Got first view
        $gotViews = User::where('users.created_at', '>=', $cutoff)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('galleries')
                    ->whereColumn('galleries.user_id', 'users.id')
                    ->where('galleries.view_count', '>', 0)
                    ->whereNull('galleries.deleted_at');
            })
            ->count();
        $this->info("5. Got first view:       {$gotViews}  (" . $this->pct($gotViews, $published) . ")");

        $this->newLine();
        $this->info("Overall conversion: " . $this->pct($gotViews, $registered) . " (registered → first view)");

        // Time-to-first-gallery analysis
        $this->newLine();
        $this->info('Time to first gallery:');
        $ttf = DB::table('users')
            ->join('galleries', 'galleries.user_id', '=', 'users.id')
            ->where('users.created_at', '>=', $cutoff)
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, users.created_at, galleries.created_at)) as avg_hours, MIN(TIMESTAMPDIFF(HOUR, users.created_at, galleries.created_at)) as min_hours, MAX(TIMESTAMPDIFF(HOUR, users.created_at, galleries.created_at)) as max_hours')
            ->first();

        if ($ttf && $ttf->avg_hours !== null) {
            $this->info("  Average: " . round($ttf->avg_hours, 1) . " hours");
            $this->info("  Min:     {$ttf->min_hours} hours");
            $this->info("  Max:     {$ttf->max_hours} hours");
        } else {
            $this->info('  No data (no galleries created in this period)');
        }

        Log::info('OnboardingAnalytics: report generated', [
            'days' => $days,
            'registered' => $registered,
            'created_gallery' => $createdGallery,
            'uploaded_image' => $uploadedImage,
            'published' => $published,
            'got_views' => $gotViews,
        ]);

        return self::SUCCESS;
    }

    private function pct(int $numerator, int $denominator): string
    {
        if ($denominator === 0) return 'N/A';
        return round(($numerator / $denominator) * 100, 1) . '%';
    }
}
