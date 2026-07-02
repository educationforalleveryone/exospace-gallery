<?php

namespace App\Console\Commands;

use App\Models\PendingUpgrade;
use App\Models\TeamInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Clean up stale data: expired pending upgrades + expired team invitations.
 * (Task H61)
 *
 * - Pending upgrades older than 7 days (token expired) → marked 'expired'
 * - Team invitations past their expires_at → deleted
 *
 * Scheduled daily at 4am via routes/console.php.
 */
class CleanupStaleData extends Command
{
    protected $signature = 'exospace:cleanup-stale';
    protected $description = 'Clean up expired pending upgrades and team invitations.';

    public function handle(): int
    {
        $this->cleanupPendingUpgrades();
        $this->cleanupTeamInvitations();

        $this->info('Stale data cleanup complete.');
        return self::SUCCESS;
    }

    /**
     * Mark expired pending_upgrades as 'expired' (don't delete — keep for
     * analytics/audit). The 7-day expiry matches the token validity window.
     */
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

    /**
     * Delete expired team invitations. The invitation's isExpired()
     * check in the controller prevents acceptance, but the rows accumulate.
     */
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
}
