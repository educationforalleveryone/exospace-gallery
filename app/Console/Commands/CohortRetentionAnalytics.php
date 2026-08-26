<?php

namespace App\Console\Commands;

use App\Services\CohortRetentionMetricsService;
use App\Services\JobHeartbeatService;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * M-17: Cohort retention analytics.
 *
 * Groups users into weekly cohorts by registration date, then tracks what
 * % of each cohort is still active (logged in or updated a gallery) in
 * each subsequent week.
 *
 * ITERATION 6 — the measurement was fixed and the report finally reaches
 * humans and history (the same graduation TTFE got in Iteration 5):
 *
 *   Measurement: "active in week [start,end)" is now BOUNDED and truthful
 *   — users.last_login_at in the window (stamped by the StampLastLogin
 *   listener on the Login event) OR any gallery updated in the window.
 *   The old definition counted users.updated_at >= period start —
 *   unbounded (future activity inflated earlier weeks) and noisy (plan
 *   changes, marketing prefs and admin writes all bump that column).
 *
 *   Persistence: complete matrix cells (cohort × week, week closed) are
 *   snapshotted into retention_snapshots — the live cohort erodes over
 *   time (GDPR deletions, monthly PII anonymization), so point-in-time
 *   snapshots are the only faithful retention history. Master Control
 *   charts the W1/W2 trend from them.
 *
 *   Delivery: the report is posted to the operational Slack channel
 *   (info severity) — it previously existed only as scheduler stdout +
 *   one log line, which nobody read.
 *
 * SCHEDULE: weekly Monday 06:00 via routes/console.php.
 */
class CohortRetentionAnalytics extends Command
{
    protected $signature = 'exospace:cohort-retention {--weeks=8 : Number of weeks to analyze}';
    protected $description = 'Generate cohort retention analytics, persist the matrix history, and post the summary to the operational alert channel.';

    public function handle(CohortRetentionMetricsService $metrics, OperationalAlertService $alerts): int
    {
        $weeks = max(2, min(25, (int) $this->option('weeks')));
        $data = $metrics->compute($weeks);

        // Persist complete cells BEFORE reporting, so the persisted
        // history and the report can never disagree about what this week
        // looked like (idempotent within the capture hour).
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
                // Incomplete cells (their week hasn't closed) are marked —
                // a partial week must never read as a final retention rate.
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

        // Deliver where operators already look (info severity, deduped).
        // Delivery failure must never fail the command; the alert service
        // already swallows webhook errors.
        $alerts->alert(
            'Weekly retention report',
            $this->slackSummary($data),
            'info',
            'retention_weekly_report',
        );

        app(JobHeartbeatService::class)->stamp('exospace:cohort-retention');

        return self::SUCCESS;
    }

    /**
     * Compact multi-line summary for the alert channel — cohort sizes,
     * the W1 headline, and where the trend lives.
     *
     * @param  array{weeks: int, cohorts: array<int, array{week_start: string, label: string, size: int, cells: array<int, array{pct: float, active: int, complete: bool}>}>}  $data
     */
    private function slackSummary(array $data): string
    {
        $registered = array_sum(array_map(fn ($c) => $c['size'], $data['cohorts']));

        // Latest COMPLETE W1 cell — the headline "week-1 retention" number
        // (cohorts are oldest-first, so scan from the end; skip empty
        // cohorts and partial cells).
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
