<?php

namespace App\Console\Commands;

use App\Services\JobHeartbeatService;
use App\Services\OnboardingMetricsService;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OnboardingAnalytics extends Command
{
    protected $signature = 'exospace:onboarding-analytics {--days=30 : Analyze users from last N days}';

    protected $description = 'Generate onboarding funnel analytics report, snapshot the metric history, and post the summary to the operational alert channel.';

    private const SNAPSHOT_WINDOWS = [7, 30, 90];

    public function handle(OnboardingMetricsService $metrics, OperationalAlertService $alerts): int
    {
        $days = (int) $this->option('days');
        $data = $metrics->compute($days);

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

        $alerts->alert(
            'Weekly onboarding report',
            $this->slackSummary($data),
            'info',
            'onboarding_weekly_report',
        );

        // Cadence proof for the per-job heartbeat monitor.
        app(JobHeartbeatService::class)->stamp('exospace:onboarding-analytics');

        return self::SUCCESS;
    }

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
