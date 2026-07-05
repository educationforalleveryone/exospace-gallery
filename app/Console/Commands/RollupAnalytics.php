<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Roll up raw analytics_events into analytics_daily, then prune old events.
 *
 * S-1 FIX (audit): Previously used an N+1 loop — for each gallery × day
 * combination, it ran 5 separate COUNT/AVG queries (6 queries × N galleries
 × N days = potentially hundreds of thousands of queries). Now uses a
 * single INSERT ... SELECT ... GROUP BY query that aggregates all
 * gallery-day combinations in one pass.
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
     * S-1 FIX: Aggregate raw events into analytics_daily using a single
     * INSERT ... ON DUPLICATE KEY UPDATE query with conditional aggregation.
     *
     * This replaces the previous N+1 loop (6 queries per gallery × day)
     * with ONE query regardless of how many galleries or days are involved.
     */
    private function rollup(): void
    {
        $days = $this->option('days');
        $startDate = $days ? now()->subDays((int) $days)->toDateString() : null;

        if (! $startDate) {
            $earliestEvent = AnalyticsEvent::min('created_at');
            if (! $earliestEvent) {
                $this->info('No events to roll up.');
                return;
            }
            $startDate = date('Y-m-d', strtotime($earliestEvent));
        }

        $endDate = now()->startOfDay();
        $this->info("Rolling up events from {$startDate} to {$endDate->toDateString()}...");

        // S-1: Single INSERT ... ON DUPLICATE KEY UPDATE with conditional aggregation.
        // SUM(CASE WHEN event='view' THEN 1 ELSE 0 END) counts views.
        // COUNT(DISTINCT CASE WHEN event='view' THEN session_token END) counts unique visitors.
        // AVG(CASE WHEN event='view' AND dwell_seconds IS NOT NULL THEN dwell_seconds END) computes avg dwell.
        $inserted = DB::affectingStatement("
            INSERT INTO analytics_daily (gallery_id, date, views, unique_visitors, focuses, tour_starts, avg_dwell_seconds, created_at, updated_at)
            SELECT
                gallery_id,
                DATE(created_at) as date,
                SUM(CASE WHEN event = 'view' THEN 1 ELSE 0 END) as views,
                COUNT(DISTINCT CASE WHEN event = 'view' THEN session_token END) as unique_visitors,
                SUM(CASE WHEN event = 'focus' THEN 1 ELSE 0 END) as focuses,
                SUM(CASE WHEN event = 'tour_start' THEN 1 ELSE 0 END) as tour_starts,
                COALESCE(ROUND(AVG(CASE WHEN event = 'view' AND dwell_seconds IS NOT NULL THEN dwell_seconds END), 2), 0) as avg_dwell_seconds,
                NOW(),
                NOW()
            FROM analytics_events
            WHERE created_at >= ? AND created_at < ?
            GROUP BY gallery_id, DATE(created_at)
            ON DUPLICATE KEY UPDATE
                views = VALUES(views),
                unique_visitors = VALUES(unique_visitors),
                focuses = VALUES(focuses),
                tour_starts = VALUES(tour_starts),
                avg_dwell_seconds = VALUES(avg_dwell_seconds),
                updated_at = NOW()
        ", [$startDate, $endDate]);

        $this->info("Rolled up analytics for period {$startDate} to {$endDate->toDateString()} ({$inserted} rows affected).");

        Log::info('RollupAnalytics: rollup complete', [
            'start_date' => $startDate,
            'end_date' => $endDate->toDateString(),
            'rows_affected' => $inserted,
        ]);
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

        AnalyticsEvent::where('created_at', '<', $cutoff)
            ->chunkById(1000, function ($events) {
                $ids = $events->pluck('id');
                AnalyticsEvent::whereIn('id', $ids)->delete();
            });

        Log::info('RollupAnalytics: pruned old events', [
            'count'  => $count,
            'cutoff' => $cutoff->toDateString(),
        ]);
        $this->info("Pruned {$count} old events.");
    }
}
