<?php

namespace App\Console\Commands;

use App\Services\JobHeartbeatService;
use App\Services\OnboardingMetricsService;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * M-16: Onboarding funnel analytics.
 *
 * Tracks how users progress through the onboarding funnel:
 *   1. Registered (created_at)
 *   2. Created first gallery
 *   3. Uploaded first image
 *   4. Published gallery
 *   5. Got first view
 *
 * ITERATION-3: time-to-first-gallery and time-to-first-publish are now
 * computed from galleries.published_at (first-publish semantics) instead
 * of the is_active proxy, and the MySQL-only TIMESTAMPDIFF() was replaced
 * with portable PHP-side date math — the command previously crashed on
 * SQLite (CI) and locked itself to MySQL-only deployment shapes.
 *
 * ITERATION-4: the computation moved into OnboardingMetricsService (shared
 * with the Master Control onboarding panel, so the weekly report and the
 * live dashboard can never disagree). This command is now a thin reporter
 * that pulls FRESH (uncached) numbers — a weekly report must reflect the
 * week's actual state, not a possibly-stale dashboard cache entry.
 *
 * ITERATION-5: the report finally reaches humans and history.
 *   - Persistence: all three dashboard windows (7/30/90) are snapshotted
 *     into onboarding_snapshots, so Master Control can chart the TTFE
 *     trend instead of only the current window's point value. The live
 *     cohort erodes (GDPR deletions, PII anonymization, analytics rollup
 *     pruning) — the snapshot table is the faithful record.
 *   - Delivery: the report is pushed to the operational Slack channel
 *     (info severity) via OperationalAlertService — the same channel that
 *     carries the SEO audit and health alerts. Previously the report
 *     existed only as scheduler stdout + one log line, which nobody read.
 */
class OnboardingAnalytics extends Command
{
    protected $signature = 'exospace:onboarding-analytics {--days=30 : Analyze users from last N days}';

    protected $description = 'Generate onboarding funnel analytics report, snapshot the metric history, and post the summary to the operational alert channel.';

    /**
     * Dashboard windows snapshotted every run — must match the Master
     * Control period selector so every trend has data.
     */
    private const SNAPSHOT_WINDOWS = [7, 30, 90];

    public function handle(OnboardingMetricsService $metrics, OperationalAlertService $alerts): int
    {
        $days = (int) $this->option('days');
        $data = $metrics->compute($days);

        // ITERATION-5: persist history for every dashboard window BEFORE
        // reporting, so the trend chart and the report can never disagree
        // about what this week looked like.
        $snapshots = [];
        foreach (self::SNAPSHOT_WINDOWS as $window) {
            $snapshots[] = $metrics->persistSnapshot($window);
        }
        $this->info('Persisted ' . count($snapshots) . ' onboarding snapshot(s) (windows: ' . implode(', ', self::SNAPSHOT_WINDOWS) . ' days).');

        $this->info("Onboarding funnel (last {$data['days']} days, since " . now()->subDays($data['days'])->format('Y-m-d') . ")");
        $this->newLine();

        $this->info("1. Registered:           {$data['registered']}");
        $this->info("2. Created gallery:      {$data['created_gallery']}  (" . $this->pct($data['created_gallery'], $data['registered']) . ")");
        $this->info("3. Uploaded image:       {$data['uploaded_image']}  (" . $this->pct($data['uploaded_image'], $data['created_gallery']) . ")");
        $this->info("4. Published gallery:    {$data['published']}  (" . $this->pct($data['published'], $data['uploaded_image']) . ")");
        $this->info("5. Got first view:       {$data['got_views']}  (" . $this->pct($data['got_views'], $data['published']) . ")");

        $this->newLine();
        $this->info("Overall conversion: " . $this->pct($data['got_views'], $data['registered']) . " (registered → first view)");

        $this->newLine();
        $this->info('Time to first gallery:');
        $this->printDiffStats($data['ttfg_hours'], 'hours');

        // TTFE — the product's headline metric (first PUBLISH, not first
        // gallery creation; see Iteration-3 notes on published_at).
        $this->newLine();
        $this->info('Time to first published exhibition (TTFE):');
        $this->printDiffStats($data['ttfe_hours'], 'hours');

        Log::info('OnboardingAnalytics: report generated', [
            'days' => $data['days'],
            'registered' => $data['registered'],
            'created_gallery' => $data['created_gallery'],
            'uploaded_image' => $data['uploaded_image'],
            'published' => $data['published'],
            'got_views' => $data['got_views'],
            'avg_hours_to_first_gallery' => $data['ttfg_hours']['avg'] ?? null,
            'avg_hours_to_first_publish' => $data['ttfe_hours']['avg'] ?? null,
        ]);

        // ITERATION-5: deliver the weekly report where operators already
        // look — the operational alert channel (info severity). Delivery
        // failure must never fail the command; OperationalAlertService
        // already swallows webhook errors.
        $alerts->alert(
            'Weekly onboarding report',
            $this->slackSummary($data),
            'info',
            'onboarding_weekly_report',
        );

        // ITERATION 6: cadence proof for the per-job heartbeat monitor.
        app(JobHeartbeatService::class)->stamp('exospace:onboarding-analytics');

        return self::SUCCESS;
    }

    /**
     * Compact multi-line summary for the alert channel — the funnel with
     * step conversion plus the headline TTFE/TTFG numbers.
     *
     * @param  array<string, mixed>  $data
     */
    private function slackSummary(array $data): string
    {
        $lines = [
            sprintf(
                'Funnel (last %d days): %d registered → %d galleries → %d uploads → %d published → %d with views',
                $data['days'],
                $data['registered'],
                $data['created_gallery'],
                $data['uploaded_image'],
                $data['published'],
                $data['got_views'],
            ),
            sprintf(
                'Overall conversion: %s · TTFG avg: %s · TTFE avg: %s',
                $this->pct($data['got_views'], $data['registered']),
                isset($data['ttfg_hours']['avg']) ? $data['ttfg_hours']['avg'] . 'h' : 'n/a',
                isset($data['ttfe_hours']['avg']) ? $data['ttfe_hours']['avg'] . 'h' : 'n/a',
            ),
            'Trend history: Master Control → Onboarding Funnel & TTFE.',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  null|array{min: float, avg: float, max: float}  $stats
     */
    private function printDiffStats(?array $stats, string $unit): void
    {
        if ($stats === null) {
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
