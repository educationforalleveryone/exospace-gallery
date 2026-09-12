<?php

namespace App\Console\Commands;

use App\Services\CohortRetentionMetricsService;
use App\Services\JobHeartbeatService;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CohortRetentionAnalytics extends Command
{
    protected $signature = 'exospace:cohort-retention {--weeks=8 : Number of weeks to analyze}';
    protected $description = 'Generate cohort retention analytics, persist the matrix history, and post the summary to the operational alert channel.';

    public function handle(CohortRetentionMetricsService $metrics, OperationalAlertService $alerts): int
    {
        $weeks = max(2, min(25, (int) $this->option('weeks')));
        $data = $metrics->compute($weeks);

        $persisted = $metrics->persist($weeks);
        $this->info("Persisted {$persisted} complete retention cell(s) to retention_snapshots.");

        // Print the retention matrix (scheduler log / manual runs).
        $this->info("Cohort retention (last {$weeks} weeks)");
        $this->newLine();

        $this->info(str_pad('Cohort', 12) . str_pad('Size', 8) . implode('', array_map(fn ($w) => str_pad("W{$w}", 8), range(0, $weeks - 1))));
        $this->info(str_repeat('-', 12 + 8 + $weeks * 8));

        foreach ($data['cohorts'] as $cohort) {
            $row = str_pad($cohort['label'], 12) . str_pad((string) $cohort['size'], 8);
            foreach ($cohort['cells'] as $cell) {
                $value = $cell['pct'] > 0 ? $cell['pct'] . '%' : '-';
                $row .= str_pad($cell['complete'] ? $value : $value . '*', 8);
            }
            $this->info($row);
        }

        $this->newLine();
        $this->info('W0 = registration week, W1 = 1 week after, etc.');
        $this->info('Values = % of cohort active during that week (login or gallery update). * = week not closed yet.');
        $this->info('Active = last_login_at in week OR gallery updated in week (both bounded, Iteration 6).');

        Log::info('CohortRetentionAnalytics: report generated', [
            'weeks'      => $weeks,
            'cohorts'    => count($data['cohorts']),
            'persisted'  => $persisted,
        ]);

        $alerts->alert(
            'Weekly retention report',
            $this->slackSummary($data),
            'info',
            'retention_weekly_report',
        );

        app(JobHeartbeatService::class)->stamp('exospace:cohort-retention');

        return self::SUCCESS;
    }

    private function slackSummary(array $data): string
    {
        $registered = array_sum(array_map(fn ($c) => $c['size'], $data['cohorts']));

        $w1 = null;
        for ($i = count($data['cohorts']) - 1; $i >= 0; $i--) {
            $cells = $data['cohorts'][$i]['cells'];
            if (isset($cells[1]) && $cells[1]['complete'] && $data['cohorts'][$i]['size'] > 0) {
                $w1 = ['label' => $data['cohorts'][$i]['label'], 'pct' => $cells[1]['pct']];
                break;
            }
        }

        $lines = [
            sprintf(
                'Last %d weekly cohorts: %d user(s) registered.',
                count($data['cohorts']),
                $registered,
            ),
            $w1 !== null
                ? sprintf("Week-1 retention: %s%% (cohort of %s) — login or gallery activity in their first follow-up week.", $w1['pct'], $w1['label'])
                : 'Week-1 retention: no complete W1 cell yet (needs cohorts ≥2 weeks old).',
            'Active = last_login_at in week OR gallery updated in week (Iteration-6 truthful measurement).',
            'Trend history: Master Control → Retention.',
        ];

        return implode("\n", $lines);
    }
}
