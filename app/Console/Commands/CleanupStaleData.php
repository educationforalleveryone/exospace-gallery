<?php

namespace App\Console\Commands;

use App\Models\PendingUpgrade;
use App\Models\TeamInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupStaleData extends Command
{
    protected $signature = 'exospace:cleanup-stale';
    protected $description = 'Clean up expired pending upgrades, team invitations, stale webhook ledger rows, and aged analytics snapshots.';

    public function handle(): int
    {
        $this->cleanupPendingUpgrades();
        $this->cleanupTeamInvitations();
        $this->cleanupWebhookLedger();
        $this->cleanupOnboardingSnapshots();
        $this->cleanupRetentionSnapshots();

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
            $this->info('Webhook ledger: payload column absent (pre-Iteration-4 schema) — nothing to prune.');
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
            $this->info('Onboarding snapshots: table absent (pre-Iteration-5 schema) — nothing to prune.');
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
            $this->info('Retention snapshots: table absent (pre-Iteration-6 schema) — nothing to prune.');
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
}
