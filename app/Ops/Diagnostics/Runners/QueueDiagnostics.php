<?php

declare(strict_types=1);

namespace App\Ops\Diagnostics\Runners;

use App\Ops\Diagnostics\DiagnosticResult;
use App\Ops\Diagnostics\RunsDiagnostics;
use App\Ops\Models\OpsApplication;
use App\Services\JobHeartbeatService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OpsCenter — QueueDiagnostics (Iteration 3).
 *
 * queue.health | queue.failed-jobs
 *
 * Reuses the EXISTING worker monitoring inputs (ADR-6, no second system):
 * the jobs/failed_jobs tables OperationalAlertService already watches and
 * the JobHeartbeatService cadence registry. Thresholds mirror the alerting
 * service (failed >10 warning / >50 critical; oldest job >10 min critical)
 * so the dashboard and Slack never disagree about what "bad" means.
 *
 * Read-only: SELECTs only. Retrying failed jobs is deliberately NOT offered
 * as a one-click action (a retry can re-run side effects); the diagnostics
 * tell you WHAT is failing — the conscious fix happens through the deploy
 * pipeline or a deliberate operator action.
 */
class QueueDiagnostics implements RunsDiagnostics
{
    public function runDiagnostic(string $id, ?OpsApplication $application): DiagnosticResult
    {
        return match ($id) {
            'queue.health' => $this->health(),
            'queue.failed-jobs' => $this->failedJobs(),
            default => DiagnosticResult::inconclusive(
                'Unknown queue diagnostic',
                'This diagnostic id is not implemented by the queue runner.',
            ),
        };
    }

    // ── queue.health ────────────────────────────────────────────────────

    private function health(): DiagnosticResult
    {
        $findings = [];

        // Tables exist at all (pre-migration window).
        try {
            $pending = (int) DB::table('jobs')->count();
        } catch (Throwable $e) {
            return DiagnosticResult::inconclusive(
                'Queue tables unavailable',
                'The jobs/failed_jobs tables could not be read: '.mb_substr($e->getMessage(), 0, 200).'. If migrations have not run, the queue subsystem is not provisioned yet — run Migration status.',
                ['database.migration-status'],
            );
        }

        // Pending backlog.
        $oldest = DB::table('jobs')->orderBy('id')->first();
        $oldestAgeMinutes = $oldest !== null ? (int) floor((time() - (int) $oldest->available_at) / 60) : 0;

        $findings[] = [
            'label' => 'Pending backlog',
            'status' => $pending === 0 ? 'pass' : ($oldestAgeMinutes > 10 ? 'fail' : 'pass'),
            'detail' => $pending === 0
                ? 'No jobs waiting — the queue is drained.'
                : sprintf('%d job(s) pending; the oldest has been waiting %d min%s.', $pending, $oldestAgeMinutes, $oldestAgeMinutes > 10 ? ' — workers are NOT keeping up (critical threshold is 10 min; workers may be down or dead)' : ''),
        ];

        // Failed jobs (thresholds mirror OperationalAlertService).
        try {
            $failed = (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            $failed = 0;
        }

        $findings[] = [
            'label' => 'Failed jobs',
            'status' => $failed > 50 ? 'fail' : ($failed > 10 ? 'warn' : 'pass'),
            'detail' => sprintf(
                '%d failed job(s) on record.%s',
                $failed,
                $failed > 50 ? ' Above the critical threshold (50) — the alerting service pages for this.' : ($failed > 10 ? ' Above the warning threshold (10).' : ' Within normal range.'),
            ),
        ];

        // Heartbeats (reuse JobHeartbeatService — the existing system).
        try {
            $heartbeats = app(JobHeartbeatService::class);
            $stale = [];
            $healthy = [];
            foreach (JobHeartbeatService::MONITORED_JOBS as $job => $maxAge) {
                $status = $heartbeats->status($job);
                if ($status === 'stale') {
                    $stale[] = $job;
                } elseif ($status === 'ok') {
                    $healthy[] = $job;
                }
            }

            $findings[] = [
                'label' => 'Scheduled-job heartbeats',
                'status' => $stale !== [] ? 'warn' : 'pass',
                'detail' => $stale !== []
                    ? sprintf('%d of %d monitored job(s) missed their cadence: %s. The scheduler may be down or the jobs are failing before they can stamp.', count($stale), count(JobHeartbeatService::MONITORED_JOBS), implode(', ', array_slice($stale, 4)))
                    : sprintf('%d monitored job(s) reporting on cadence.', count($healthy)),
            ];
        } catch (Throwable) {
            $findings[] = [
                'label' => 'Scheduled-job heartbeats',
                'status' => 'skip',
                'detail' => 'Heartbeat store unavailable (Redis unreachable?) — the Redis diagnostic has the definitive answer.',
            ];
        }

        $connection = (string) config('queue.default');
        $findings[] = [
            'label' => 'Queue configuration',
            'status' => 'pass',
            'detail' => 'Connection: '.$connection.' — background work (emails, exports, image processing) runs through this.',
        ];

        $workersDown = $pending > 0 && $oldestAgeMinutes > 10;
        $degraded = $failed > 10 || ($stale ?? []) !== [];

        $summary = match (true) {
            $workersDown => 'Queue backlog growing — workers appear down',
            $degraded => 'Queue degraded',
            default => 'Queue healthy',
        };

        return DiagnosticResult::fromFindings(
            $summary,
            $findings,
            $workersDown
                ? 'Jobs are piling up and the oldest has been waiting more than 10 minutes. Evidence suggests the queue WORKERS are down or dead (not the queue transport itself — Redis carries it). Check the worker container on the Applications page and run Container health for it. Emails, exports and other background work is NOT being processed while this persists.'
                : ($degraded
                    ? 'The queue is processing, but failed jobs are above the warning threshold or monitored scheduled jobs have missed their cadence. Run Failed jobs to see WHICH jobs are failing, and Scheduler freshness for the cadence side.'
                    : 'Backlog is under control, failed jobs are within thresholds and the monitored scheduled jobs are on cadence. Background work is flowing normally.'),
            $workersDown ? ['container.health', 'redis.connectivity'] : ($failed > 10 ? ['queue.failed-jobs'] : []),
        );
    }

    // ── queue.failed-jobs ───────────────────────────────────────────────

    private function failedJobs(): DiagnosticResult
    {
        try {
            $total = (int) DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            return DiagnosticResult::inconclusive(
                'failed_jobs table unavailable',
                'The failed_jobs table could not be read: '.mb_substr($e->getMessage(), 0, 200).'.',
                ['database.migration-status'],
            );
        }

        if ($total === 0) {
            return DiagnosticResult::fromFindings(
                'No failed jobs',
                [[
                    'label' => 'Failed jobs',
                    'status' => 'pass',
                    'detail' => 'The failed_jobs table is empty — nothing has exhausted its retry attempts.',
                ]],
                'Background work is not failing at the job level. If work still seems missing, the cause is more likely upstream (jobs never dispatched) — the scheduler diagnostic covers the cadence side.',
                ['queue.health', 'app.scheduler'],
            );
        }

        $findings = [];

        $recent = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        $oldest = DB::table('failed_jobs')->orderBy('failed_at')->first();
        $oldestAge = $oldest !== null && $oldest->failed_at !== null
            ? \Illuminate\Support\Carbon::parse($oldest->failed_at)->diffForHumans()
            : 'unknown';

        $findings[] = [
            'label' => 'Failed jobs on record',
            'status' => $total > 50 ? 'fail' : (($total > 10 || $recent > 0) ? 'warn' : 'pass'),
            'detail' => sprintf('%d total, %d in the last 24 h; oldest failed %s.', $total, $recent, $oldestAge),
        ];

        // Top failing jobs, grouped by queue + first exception line (the
        // exception class line carries the WHY without dumping payloads).
        try {
            $top = DB::table('failed_jobs')
                ->select('queue', 'exception', DB::raw('count(*) as n'))
                ->groupBy('queue', 'exception')
                ->orderByDesc('n')
                ->limit(5)
                ->get();

            foreach ($top as $row) {
                $exceptionLine = $row->exception !== null
                    ? trim(explode("\n", (string) $row->exception)[0] ?? '')
                    : '';
                $findings[] = [
                    'label' => 'Failing on queue "'.$row->queue.'"',
                    'status' => ((int) $row->n) > 10 ? 'warn' : 'pass',
                    'detail' => sprintf('%d failure(s). %s', (int) $row->n, mb_substr($exceptionLine, 0, 220)),
                ];
            }
        } catch (Throwable) {
            $findings[] = [
                'label' => 'Failure breakdown',
                'status' => 'skip',
                'detail' => 'Could not group the failures (unexpected exception column shape).',
            ];
        }

        return DiagnosticResult::fromFindings(
            sprintf('%d failed job(s), %d in the last 24 h', $total, $recent),
            $findings,
            $recent > 0
                ? 'Background jobs are actively failing. The findings show WHICH queue and the leading exception line — the full payload and stack live in the database and the control plane\'s error events. Common causes: an external dependency the job calls is down, or a payload shape changed with a deploy while old jobs were still queued. Failed jobs are never auto-retried by this dashboard: fix the cause first, then retry deliberately (php artisan queue:retry from a terminal, or re-dispatch from code).'
                : 'There are failed jobs on record, but none in the last 24 hours — this is a historical pile, not an active fire. Cleaning it up is optional housekeeping; the counts matter to the alerting thresholds (10 warning / 50 critical).',
            ['queue.health', 'app.recent-errors'],
        );
    }
}
