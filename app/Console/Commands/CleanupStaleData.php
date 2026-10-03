<?php

namespace App\Console\Commands;

use App\Models\PendingUpgrade;
use App\Models\TeamInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupStaleData extends Command
{
    // Mirrors the docker-start.sh scheduler loop policy: 10MB per log, keep
    // the last 5 rotations (scheduler.log.1 … scheduler.log.5).
    private const SCHEDULER_LOG_MAX_BYTES = 10485760;

    private const SCHEDULER_LOG_BACKUPS = 5;

    protected $signature = 'exospace:cleanup-stale';
    protected $description = 'Clean up expired pending upgrades, team invitations, stale webhook ledger rows, aged analytics snapshots, and rotated scheduler logs.';

    public function handle(): int
    {
        $this->cleanupPendingUpgrades();
        $this->cleanupTeamInvitations();
        $this->cleanupWebhookLedger();
        $this->cleanupOnboardingSnapshots();
        $this->cleanupRetentionSnapshots();
        $this->rotateSchedulerLog();

        $this->info('Stale data cleanup complete.');

        app(\App\Services\JobHeartbeatService::class)->stamp('exospace:cleanup-stale');

        return self::SUCCESS;
    }

    private function cleanupPendingUpgrades(): void
    {
        $expired = PendingUpgrade::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        if ($expired > 0) {
            $this->info("Marked {$expired} expired pending upgrades.");
            Log::info('CleanupStaleData: expired pending upgrades', ['count' => $expired]);
        } else {
            $this->info('No expired pending upgrades.');
        }
    }

    private function cleanupTeamInvitations(): void
    {
        $deleted = TeamInvitation::where('expires_at', '<', now())->delete();

        if ($deleted > 0) {
            $this->info("Deleted {$deleted} expired team invitations.");
            Log::info('CleanupStaleData: deleted expired invitations', ['count' => $deleted]);
        } else {
            $this->info('No expired team invitations.');
        }
    }

    private function cleanupWebhookLedger(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('processed_webhooks', 'payload')) {
            $this->info('Webhook ledger: payload column absent (legacy schema) — nothing to prune.');
            return;
        }

        $deleted = \Illuminate\Support\Facades\DB::table('processed_webhooks')
            ->where('processed_at', '<', now()->subDays(90))
            ->delete();

        if ($deleted > 0) {
            $this->info("Pruned {$deleted} webhook ledger rows older than 90 days.");
            Log::info('CleanupStaleData: pruned stale webhook ledger rows', ['count' => $deleted]);
        } else {
            $this->info('No stale webhook ledger rows.');
        }
    }

    private function cleanupOnboardingSnapshots(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('onboarding_snapshots')) {
            $this->info('Onboarding snapshots: table absent (legacy schema) — nothing to prune.');
            return;
        }

        $deleted = \Illuminate\Support\Facades\DB::table('onboarding_snapshots')
            ->where('captured_at', '<', now()->subYears(2))
            ->delete();

        if ($deleted > 0) {
            $this->info("Pruned {$deleted} onboarding snapshots older than 2 years.");
            Log::info('CleanupStaleData: pruned aged onboarding snapshots', ['count' => $deleted]);
        } else {
            $this->info('No aged onboarding snapshots.');
        }
    }

    private function cleanupRetentionSnapshots(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('retention_snapshots')) {
            $this->info('Retention snapshots: table absent (legacy schema) — nothing to prune.');
            return;
        }

        $deleted = \Illuminate\Support\Facades\DB::table('retention_snapshots')
            ->where('captured_at', '<', now()->subYears(2))
            ->delete();

        if ($deleted > 0) {
            $this->info("Pruned {$deleted} retention snapshots older than 2 years.");
            Log::info('CleanupStaleData: pruned aged retention snapshots', ['count' => $deleted]);
        } else {
            $this->info('No aged retention snapshots.');
        }
    }

    /**
     * scheduler.log is appended by the container's scheduler tick (`php artisan
     * schedule:run >> scheduler.log 2>&1`) and never rotates by itself. The
     * in-container loop in docker-start.sh rotates it before each tick, but
     * that loop is skipped when BYPASS_SCHEDULER=true (the Coolify scheduled
     * task drives schedule:run instead) — so the retention check runs here,
     * daily, with the same 10MB / 5-backups policy.
     */
    private function rotateSchedulerLog(): void
    {
        $logPath = storage_path('logs/scheduler.log');

        if (! is_file($logPath)) {
            $this->info('Scheduler log: not present yet — nothing to rotate.');
            return;
        }

        $size = @filesize($logPath);

        if ($size === false || $size <= self::SCHEDULER_LOG_MAX_BYTES) {
            $this->info('Scheduler log: within rotation threshold.');
            return;
        }

        for ($i = self::SCHEDULER_LOG_BACKUPS - 1; $i >= 1; $i--) {
            $from = $logPath.'.'.$i;
            $to = $logPath.'.'.($i + 1);

            if (is_file($from) && ! @rename($from, $to)) {
                Log::warning('CleanupStaleData: could not shift scheduler log rotation', ['file' => $from]);
            }
        }

        if (! @rename($logPath, $logPath.'.1')) {
            Log::warning('CleanupStaleData: could not rotate scheduler log', ['file' => $logPath]);

            return;
        }

        $this->info('Scheduler log: rotated at '.round($size / 1024 / 1024, 1).'MB.');
        Log::info('CleanupStaleData: rotated scheduler log', ['bytes' => $size]);
    }
}
