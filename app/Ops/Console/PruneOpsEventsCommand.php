<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsReviewSnapshot;
use Illuminate\Console\Command;

/**
 * OpsCenter — ops:prune-events (retention).
 *
 * The documented retention policy for ops_events (see config/ops.php):
 *
 *   1. AUTO-RESOLVE: events with no recurrence for auto_resolve_days
 *      (default 7) are marked resolved. The problem stopped happening —
 *      the control plane should stop counting it as active.
 *
 *   2. DELETE: resolved events older than resolved_retention_days
 *      (default 90) are deleted. Resolved history is useful for trend
 *      questions but not forever.
 *
 *   3. OPEN EVENTS ARE NEVER DELETED. An ongoing problem must not vanish
 *      silently — resolve it first (auto-resolve handles the stale ones).
 *
 * ITERATION 3: also prunes ops_diagnostic_runs older than
 * ops.diagnostics.retention_days (default 30). Runs are point-in-time
 * snapshots, reproducible on demand, so short retention loses nothing.
 *
 * ITERATION 9: also prunes ops_review_snapshots older than
 * ops.weekly_review.snapshot_retention_days (default 365 — the long
 * memory is the point; one row per week keeps it trivially small).
 *
 * Scheduled daily at 03:35 (off-peak, deliberately after the 03:17
 * webhook-ledger prune and before the 04:00 maintenance batch — same
 * slotting convention as routes/console.php).
 */
class PruneOpsEventsCommand extends Command
{
    protected $signature = 'ops:prune-events';

    protected $description = 'Apply the ops retention policies (auto-resolve stale events, delete old resolved ones, prune diagnostic runs and review snapshots).';

    public function handle(): int
    {
        $autoResolveDays = max(1, (int) config('ops.retention.auto_resolve_days', 7));
        $retentionDays = max(1, (int) config('ops.retention.resolved_retention_days', 90));
        $diagnosticDays = max(1, (int) config('ops.diagnostics.retention_days', 30));
        $snapshotDays = max(1, (int) config('ops.weekly_review.snapshot_retention_days', 365));

        // 1. Auto-resolve: no recurrence for N days while still open.
        $resolved = OpsEvent::where('status', 'open')
            ->where('last_seen_at', '<', now()->subDays($autoResolveDays))
            ->update([
                'status' => 'resolved',
                'resolved_at' => now(),
            ]);

        // 2. Delete resolved events past retention.
        $deleted = OpsEvent::where('status', 'resolved')
            ->where('resolved_at', '<', now()->subDays($retentionDays))
            ->delete();

        // 3. Iteration 3: prune diagnostic runs past retention.
        $runsDeleted = 0;
        try {
            $runsDeleted = OpsDiagnosticRun::where('created_at', '<', now()->subDays($diagnosticDays))->delete();
        } catch (\Throwable) {
            // diagnostics table absent (pre-Iteration-3) — nothing to prune.
        }

        // 4. Iteration 9: prune review snapshots past retention.
        $snapshotsDeleted = 0;
        try {
            $snapshotsDeleted = OpsReviewSnapshot::where('created_at', '<', now()->subDays($snapshotDays))->delete();
        } catch (\Throwable) {
            // snapshots table absent (pre-Iteration-9) — nothing to prune.
        }

        $this->info("ops:prune-events — auto-resolved: {$resolved}, deleted: {$deleted}, diagnostic runs pruned: {$runsDeleted}, review snapshots pruned: {$snapshotsDeleted}.");

        return self::SUCCESS;
    }
}
