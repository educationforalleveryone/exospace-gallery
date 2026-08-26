<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Services\ArtisanCommandRunner;
use App\Services\JobHeartbeatService;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ITERATION 7 — backup jobs with heartbeats + Slack alerting.
 *
 * The gap: spatie/laravel-backup was scheduled in Iteration 6 (daily
 * 01:00 --only-db, weekly Sun 01:30 --only-files, daily 02:00 clean)
 * but the wrapper Command was NOT registered with the heartbeat
 * monitor. So:
 *
 *   - If the scheduler entry was lost in a deploy (config caching
 *     ate the routes/console.php edit, an onOneServer mutex stuck
 *     after a crash, a typo in the cron) — the Iteration-6
 *     backup-health check would only notice when the newest zip on
 *     a disk exceeded 26h. That's a 26h detection delay on a daily
 *     backup, a 7-day+ delay on the weekly files backup.
 *   - Spatie's own mail notifications fire on backup FAILURE — but
 *     only to BACKUP_NOTIFICATION_EMAIL, only if mail is configured,
 *     and only if the failure happens INSIDE spatie's run (a
 *     command-never-runs failure doesn't notify anyone).
 *
 * This wrapper closes both: it calls the underlying spatie command
 * through an injectable runner, stamps a heartbeat on success (so the
 * 5-min checkJobHeartbeats() picks up a dead job well within maxAge),
 * and posts a Slack alert on failure (so the operational channel
 * hears about a missed backup the moment the wrapper exits non-zero —
 * same channel that hears about failed webhooks + billing delivery
 * failures + stale jobs). Total-failure path leaves the heartbeat
 * unstamped AND exits non-zero — the heartbeat monitor is the second
 * net (catches a wrapper that itself crashed before alerting).
 *
 * Why a wrapper rather than Schedule::onSuccess/onFailure hooks: the
 * wrapper is one unit-testable seam (the runner is swappable), it
 * keeps the stamp + alert + audit logic together with the call
 * site, and it survives a future refactor of routes/console.php.
 *
 * Three types (separate heartbeat keys + alert severities):
 *
 *   db     — backup:run --only-db     daily 01:00  · maxAge 36h   · critical on failure
 *   files  — backup:run --only-files weekly Sun 01:30 · maxAge 192h · critical on failure
 *   clean  — backup:clean            daily 02:00  · maxAge 36h   · warning on failure
 *            (clean failures are less critical — the disk-usage
 *            check catches accumulation independently; we still
 *            alert + leave the heartbeat unstamped, but at lower
 *            severity to match.)
 */
class RunMonitoredBackup extends Command
{
    protected $signature = 'exospace:backup
                            {type : db | files | clean}';

    protected $description = 'Run a monitored spatie backup job (db | files | clean) with heartbeat stamping + Slack alerting.';

    /**
     * Per-type configuration: underlying spatie command, parameters,
     * heartbeat key, Slack severity on failure, and a human label
     * for alert copy.
     */
    private const TYPES = [
        'db' => [
            'command'  => 'backup:run',
            'params'   => ['--only-db' => true],
            'heartbeat'=> 'exospace:backup:db',
            'severity' => 'critical',
            'label'    => 'Daily database backup',
        ],
        'files' => [
            'command'  => 'backup:run',
            'params'   => ['--only-files' => true],
            'heartbeat'=> 'exospace:backup:files',
            'severity' => 'critical',
            'label'    => 'Weekly file backup',
        ],
        'clean' => [
            'command'  => 'backup:clean',
            'params'   => [],
            'heartbeat'=> 'exospace:backup:clean',
            'severity' => 'warning',
            'label'    => 'Backup cleanup',
        ],
    ];

    public function handle(ArtisanCommandRunner $runner, JobHeartbeatService $heartbeats, OperationalAlertService $alerts): int
    {
        $type = (string) $this->argument('type');

        if (! array_key_exists($type, self::TYPES)) {
            $this->error("Unknown backup type '{$type}'. Valid: db, files, clean.");
            return self::FAILURE;
        }

        $config = self::TYPES[$type];
        $this->info("Running {$config['label']} ({$config['command']})...");

        $exit = $runner($config['command'], $config['params']);

        if ($exit === 0) {
            $heartbeats->stamp($config['heartbeat']);
            $this->info("{$config['label']} completed — heartbeat stamped.");
            return self::SUCCESS;
        }

        $this->error("{$config['label']} failed (exit {$exit}).");

        // ITERATION 8: append the last ~300 chars of spatie's stdout
        // to the Slack alert copy (audit-fix C-2). The captured output
        // lives on the runner instance (ArtisanCommandRunner::lastOutput)
        // — the runner is the testable seam, so the output capture goes
        // through the same seam the exit code does. A fake runner in
        // tests can preset both the exit code AND the captured output.
        // Truncate to 300 chars (the underlying diagnostic can be
        // pages long on a real failure — the operator needs the
        // tail, which usually names the missing dep / full disk /
        // broken credential).
        $diagnostic = $runner->lastOutput();
        $tail = strlen($diagnostic) > 300
            ? substr($diagnostic, -300)
            : $diagnostic;
        $tailBlock = $tail !== ''
            ? "\n\nUnderlying spatie output (last 300 chars):\n```\n" . trim($tail) . "\n```"
            : '';

        $alerts->alert(
            "{$config['label']} failed",
            "The monitored spatie `{$config['command']}` invocation exited with code {$exit}. "
            . "The heartbeat is left unstamped — the JobHeartbeat monitor will also surface this as a "
            . "stale job within its maxAge window. Check storage/logs/laravel.log + the spatie "
            . "backup:list output; the underlying failure (no mysqldump, full disk, broken disk "
            . "credential, etc.) is logged there." . $tailBlock,
            $config['severity'],
            'backup_failed:' . $type,
        );

        Log::error('RunMonitoredBackup: spatie command failed', [
            'type'         => $type,
            'command'      => $config['command'],
            'exit_code'    => $exit,
            'heartbeat'    => $config['heartbeat'],
        ]);

        // Audit the failure — system actor (no admin triggered this;
        // the scheduler did). The wrapper itself isn't a model; we
        // follow the SendBillingExport convention of targeting the
        // newest Transaction row (a real row that proves the install
        // has data, falling back to no audit row on a fresh install
        // with zero transactions — same convention: a system actor's
        // audit row needs a real target, not a fabricated one).
        //
        // ITERATION 8 FIX: the previous implementation read
        // config('exospace.system_audit_email', 'system@exospace.gallery')
        // and looked up that User row — but no config/exospace.php exists
        // and the fallback email isn't seeded as a User on a default
        // install, so the audit row NEVER wrote. The Iter-7 doc claimed
        // "best-effort audit logging" but the actual code was silent
        // dead-code on a default install. Mirroring SendBillingExport's
        // Transaction-target convention unifies the system-actor audit
        // trail (the billing-export + backup-failure audit rows now use
        // the SAME targeting logic — both attributable to "the newest
        // real row in the system at the time of the event").
        //
        // We use a null-safe try/catch — audit logging must never
        // break the failure path (the alert already went out).
        try {
            $target = \App\Models\Transaction::orderByDesc('id')->first();

            if ($target !== null) {
                AdminAuditLog::record('backup.failed', $target, [
                    'type'       => $type,
                    'command'    => $config['command'],
                    'exit_code'  => $exit,
                    'heartbeat'  => $config['heartbeat'],
                ]);
            } else {
                // No transactions on a fresh install → no PII to
                // attribute the failure to; log the skip explicitly so
                // the absence of an audit row is explainable.
                Log::info('RunMonitoredBackup: audit row skipped — no transactions exist to target.');
            }
        } catch (\Throwable $e) {
            Log::warning('RunMonitoredBackup: audit row skipped', ['error' => $e->getMessage()]);
        }

        // No heartbeat stamp — the heartbeat monitor becomes the
        // second net for the case where this very wrapper crashed
        // before alerting.
        return self::FAILURE;
    }
}
