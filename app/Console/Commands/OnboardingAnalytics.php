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
 * ITERATION-3: time-to-first-gallery and time-to-first-publish are now
 * computed from galleries.published_at (first-publish semantics) instead
 * of the is_active proxy, and the MySQL-only TIMESTAMPDIFF() was replaced
 * with portable PHP-side date math — the command previously crashed on
 * SQLite (CI) and locked itself to MySQL-only deployment shapes.
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

        // Time-to-first-gallery analysis (ITERATION-3: portable — the old
        // raw TIMESTAMPDIFF(HOUR, ...) worked only on MySQL and crashed the
        // command on SQLite; per-user firsts are computed in PHP instead,
        // which also fixes the old query's bias of averaging over EVERY
        // gallery instead of each user's FIRST gallery).
        $this->newLine();
        $this->info('Time to first gallery:');
        $ttfg = $this->diffStats(
            DB::table('users')
                ->join('galleries', 'galleries.user_id', '=', 'users.id')
                ->where('users.created_at', '>=', $cutoff)
                ->whereNull('galleries.deleted_at')
                ->select('users.id', 'users.created_at as user_created_at', 'galleries.created_at as gallery_created_at')
                ->get()
        );
        $this->printDiffStats($ttfg, 'hours');

        // ITERATION-3: time to first PUBLISHED exhibition (TTFE) — the
        // product's headline metric. Uses galleries.published_at (stamped
        // on first publish; backfilled to created_at for pre-iteration
        // live galleries), so a user who drafts for three weeks and then
        // publishes reports the true 3-week figure, not the is_active
        // proxy that cannot say WHEN.
        $this->newLine();
        $this->info('Time to first published exhibition (TTFE):');
        $ttfe = $this->diffStats(
            DB::table('users')
                ->join('galleries', 'galleries.user_id', '=', 'users.id')
                ->where('users.created_at', '>=', $cutoff)
                ->whereNotNull('galleries.published_at')
                ->whereNull('galleries.deleted_at')
                ->select('users.id', 'users.created_at as user_created_at', 'galleries.published_at as event_at')
                ->get()
        );
        $this->printDiffStats($ttfe, 'hours');

        Log::info('OnboardingAnalytics: report generated', [
            'days' => $days,
            'registered' => $registered,
            'created_gallery' => $createdGallery,
            'uploaded_image' => $uploadedImage,
            'published' => $published,
            'got_views' => $gotViews,
            'avg_hours_to_first_gallery' => $ttfg['avg'] ?? null,
            'avg_hours_to_first_publish' => $ttfe['avg'] ?? null,
        ]);

        return self::SUCCESS;
    }

    /**
     * Per-user FIRST event diff in hours: min / avg / max across users.
     * Portable date math (Carbon) — no driver-specific SQL functions.
     *
     * $rows: collection of {id, user_created_at, gallery_created_at|event_at}
     */
    private function diffStats($rows): array
    {
        $firstPerUser = [];
        foreach ($rows as $row) {
            $eventAt = $row->event_at ?? $row->gallery_created_at ?? null;
            if (! $eventAt || ! $row->user_created_at) {
                continue;
            }
            $hours = \Carbon\Carbon::parse($row->user_created_at)
                ->diffInHours(\Carbon\Carbon::parse($eventAt), false);
            if (! isset($firstPerUser[$row->id]) || $hours < $firstPerUser[$row->id]) {
                $firstPerUser[$row->id] = max($hours, 0);
            }
        }

        if (empty($firstPerUser)) {
            return [];
        }

        return [
            'min' => min($firstPerUser),
            'avg' => round(array_sum($firstPerUser) / count($firstPerUser), 1),
            'max' => max($firstPerUser),
        ];
    }

    private function printDiffStats(array $stats, string $unit): void
    {
        if (empty($stats)) {
            $this->info("  No data (no events in this period)");
            return;
        }
        $this->info("  Average: {$stats['avg']} {$unit}");
        $this->info("  Min:     {$stats['min']} {$unit}");
        $this->info("  Max:     {$stats['max']} {$unit}");
    }

    private function pct(int $numerator, int $denominator): string
    {
        if ($denominator === 0) return 'N/A';
        return round(($numerator / $denominator) * 100, 1) . '%';
    }
}
