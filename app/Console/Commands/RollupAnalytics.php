<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use App\Models\Gallery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Roll up raw analytics_events into analytics_daily, then prune old events.
 *
 * (Task H30 / audit H31) — the analytics_events table grows unboundedly.
 * This command:
 *   1. Aggregates events from the last N days (default: all unrolled days)
 *      into analytics_daily (one row per gallery per day).
 *   2. Prunes raw events older than the retention window (default: 90 days).
 *
 * Scheduled daily via routes/console.php.
 *
 * Usage:
 *   php artisan exospace:rollup-analytics              # roll up + prune
 *   php artisan exospace:rollup-analytics --days=7     # roll up last 7 days
 *   php artisan exospace:rollup-analytics --retention=180  # prune older than 180 days
 *   php artisan exospace:rollup-analytics --prune-only  # skip rollup
 */
class RollupAnalytics extends Command
{
    protected $signature = 'exospace:rollup-analytics
                            {--days= : Number of days to roll up (default: all unrolled)}
                            {--retention=90 : Days to retain raw events before pruning}
                            {--prune-only : Skip rollup, only prune old events}';

    protected $description = 'Roll up analytics_events into analytics_daily, then prune old events.';

    public function handle(): int
    {
        $retentionDays = (int) $this->option('retention');
        $pruneOnly = $this->option('prune-only');

        if (! $pruneOnly) {
            $this->rollup();
        }

        $this->prune($retentionDays);

        $this->info('Analytics rollup complete.');
        return self::SUCCESS;
    }

    /**
     * Aggregate raw events into analytics_daily.
     *
     * For each gallery × day combination, count views, unique visitors
     * (distinct session_token), focuses, tour_starts, and average dwell.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE so re-running for a day
     * that's already been rolled up updates the counts (idempotent).
     */
    private function rollup(): void
    {
        $days = $this->option('days');
        $startDate = $days ? now()->subDays((int) $days)->toDateString() : null;

        // Find the earliest unrolled event date
        if (! $startDate) {
            $earliestEvent = AnalyticsEvent::min('created_at');
            if (! $earliestEvent) {
                $this->info('No events to roll up.');
                return;
            }
            $startDate = date('Y-m-d', strtotime($earliestEvent));
        }

        $endDate = now()->subDay()->toDateString(); // don't roll up today
        $this->info("Rolling up events from {$startDate} to {$endDate}...");

        // Get distinct gallery × day combinations
        $combinations = AnalyticsEvent::select(
                DB::raw('gallery_id'),
                DB::raw('DATE(created_at) as date')
            )
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<', now()->startOfDay())
            ->groupBy('gallery_id', 'date')
            ->get();

        $rolled = 0;
        foreach ($combinations as $combo) {
            $dayEvents = AnalyticsEvent::where('gallery_id', $combo->gallery_id)
                ->whereDate('created_at', $combo->date);

            $views = (clone $dayEvents)->where('event', 'view')->count();
            $uniqueVisitors = (clone $dayEvents)->where('event', 'view')
                ->distinct('session_token')->count('session_token');
            $focuses = (clone $dayEvents)->where('event', 'focus')->count();
            $tourStarts = (clone $dayEvents)->where('event', 'tour_start')->count();
            $avgDwell = (clone $dayEvents)->where('event', 'view')
                ->whereNotNull('dwell_seconds')->avg('dwell_seconds') ?? 0;

            // Upsert into analytics_daily
            DB::table('analytics_daily')->updateOrInsert(
                ['gallery_id' => $combo->gallery_id, 'date' => $combo->date],
                [
                    'views'            => $views,
                    'unique_visitors'  => $uniqueVisitors,
                    'focuses'          => $focuses,
                    'tour_starts'      => $tourStarts,
                    'avg_dwell_seconds'=> round($avgDwell, 2),
                    'updated_at'       => now(),
                    'created_at'       => now(),
                ]
            );
            $rolled++;
        }

        $this->info("Rolled up {$rolled} gallery-day combinations.");
    }

    /**
     * Delete raw events older than the retention window.
     */
    private function prune(int $retentionDays): void
    {
        $cutoff = now()->subDays($retentionDays);
        $count = AnalyticsEvent::where('created_at', '<', $cutoff)->count();

        if ($count === 0) {
            $this->info("No events to prune (retention: {$retentionDays} days).");
            return;
        }

        $this->info("Pruning {$count} events older than {$retentionDays} days...");

        // Delete in chunks to avoid locking the table
        AnalyticsEvent::where('created_at', '<', $cutoff)
            ->chunkById(1000, function ($events) {
                $ids = $events->pluck('id');
                AnalyticsEvent::whereIn('id', $ids)->delete();
            });

        Log::info('RollupAnalytics: pruned old events', [
            'count'    => $count,
            'cutoff'   => $cutoff->toDateString(),
        ]);
        $this->info("Pruned {$count} old events.");
    }
}
