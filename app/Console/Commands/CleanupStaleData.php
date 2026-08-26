<?php

namespace App\Console\Commands;

use App\Models\PendingUpgrade;
use App\Models\TeamInvitation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Clean up stale data: expired pending upgrades, expired team invitations,
 * and stale webhook ledger rows. (Task H61; webhook retention added in
 * ITERATION 4.)
 *
 * - Pending upgrades older than 7 days (token expired) → marked 'expired'
 * - Team invitations past their expires_at → deleted
 * - processed_webhooks older than 90 days → deleted (GDPR bound on the
 *   stored IPN payloads — customer_email/customer_name — that power the
 *   billing review page's replay tooling. 90 days covers the 2Checkout
 *   dispute/refund window; the admin_audit_logs trail retains the
 *   decision history WITHOUT raw PII beyond that horizon.)
 *
 * Scheduled daily at 4am via routes/console.php.
 */
class CleanupStaleData extends Command
{
    protected $signature = 'exospace:cleanup-stale';
    protected $description = 'Clean up expired pending upgrades, team invitations, and stale webhook ledger rows.';

    public function handle(): int
    {
        $this->cleanupPendingUpgrades();
        $this->cleanupTeamInvitations();
        $this->cleanupWebhookLedger();

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

    /**
     * ITERATION 4: prune the webhook ledger at 90 days. Guarded by the
     * payload column's existence so the cleanup is a clean no-op on
     * databases that haven't run the Iteration-4 migration yet (rolling
     * deploy safety).
     */
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
}
