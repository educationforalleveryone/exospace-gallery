<?php

namespace App\Console\Commands;

use App\Mail\AbandonedCartEmail;
use App\Models\PendingUpgrade;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Abandoned-cart recovery for pending upgrades. (Task H53)
 *
 * When a user clicks "Upgrade to Pro/Studio" but doesn't complete
 * checkout, a pending_upgrade row is created with status='pending'.
 * If the row is still pending after 24 hours, the user likely abandoned
 * the checkout.
 *
 * This command finds pending_upgrades older than 24 hours that haven't
 * been notified yet, sends a recovery email, and marks them as
 * 'notified' so we don't email twice.
 *
 * Scheduled daily at 10am via routes/console.php.
 *
 * 2Checkout-specific: the email includes the original 2Checkout buy
 * URL (with external-reference token) so the user can resume checkout
 * with one click. The token is still valid for 7 days.
 *
 * P0-3 FIX (audit): CAN-SPAM/GDPR compliance.
 *   - Only sends to users with marketing_consent=true
 *   - Only sends to users with email_verified_at != null
 *   - Per-user frequency cap: max 1 abandoned-cart email per 7 days
 *     (prevents spam when a user clicks Upgrade 10×)
 *   - Cache::lock prevents concurrent runs from double-sending
 *   - Email includes unsubscribe link + physical postal address
 */
class SendAbandonedCartEmails extends Command
{
    protected $signature = 'exospace:abandoned-cart';
    protected $description = 'Send recovery emails for pending upgrades abandoned > 24 hours.';

    private const LOCK_KEY = 'cmd:abandoned-cart';
    private const LOCK_TTL = 300; // 5 minutes — generous for 100 emails
    private const FREQUENCY_CAP_DAYS = 7; // max 1 email per user per 7 days

    public function handle(): int
    {
        // P0-3: prevent concurrent runs from double-sending.
        // Without this, two overlapping cron runs (multi-container Coolify
        // or a long run + a new cron tick) would both fetch the same
        // pending_upgrades rows and both send the email before
        // notified_at is set.
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        try {
            $lock->block(10, function () {
                $this->processAbandonedCarts();
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            $this->info('Another abandoned-cart run is in progress — skipping.');
            Log::info('AbandonedCart: lock busy, another run is in progress');
            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    private function processAbandonedCarts(): void
    {
        $cutoff = now()->subHours(24);
        $frequencyCutoff = now()->subDays(self::FREQUENCY_CAP_DAYS);

        // P0-3: Filter by marketing_consent + email_verified_at.
        // Join users to apply the consent + verification filters at the
        // SQL level (avoids loading users we'll skip).
        $pending = PendingUpgrade::where('pending_upgrades.status', 'pending')
            ->where('pending_upgrades.created_at', '<', $cutoff)
            ->whereNull('pending_upgrades.notified_at')
            ->where('pending_upgrades.expires_at', '>', now())
            // P0-3: only send to users who consented to marketing
            ->whereHas('user', function ($q) {
                $q->where('marketing_consent', true)
                  ->whereNotNull('email_verified_at')
                  ->whereNull('banned_at');
            })
            // P0-3: frequency cap — skip users who received an abandoned-cart
            // email in the last 7 days (checked via a separate pending_upgrades
            // row that was notified recently)
            ->whereDoesntHave('user.pendingUpgrades', function ($q) use ($frequencyCutoff) {
                $q->whereNotNull('notified_at')
                  ->where('notified_at', '>', $frequencyCutoff);
            })
            ->limit(100)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No abandoned carts to recover.');
            return;
        }

        // P0-3: de-duplicate by user — if a user has multiple pending
        // upgrades, send only ONE email (for the most recent upgrade).
        $byUser = $pending->groupBy('user_id');
        $this->info(sprintf(
            'Found %d abandoned carts across %d users (after consent + verification + frequency-cap filters).',
            $pending->count(),
            $byUser->count()
        ));

        $sent = 0;
        $skipped = 0;

        foreach ($byUser as $userId => $upgrades) {
            // Pick the most recent pending upgrade for this user.
            $upgrade = $upgrades->sortByDesc('created_at')->first();
            $user = $upgrade->user;

            if (! $user) {
                $skipped++;
                continue;
            }

            // P0-3: double-check consent + verification (defense-in-depth;
            // the SQL filter above should have caught this, but the user
            // may have unsubscribed between the query and now).
            if (! $user->marketing_consent || ! $user->email_verified_at) {
                Log::info('AbandonedCart: skipped — user revoked consent or unverified email', [
                    'user_id' => $user->id,
                ]);
                $skipped++;
                continue;
            }

            try {
                Mail::to($user->email)->send(new AbandonedCartEmail($user, $upgrade));

                // Mark ALL of this user's pending upgrades as notified,
                // so we don't send another email for a different upgrade
                // until the frequency cap window passes.
                foreach ($upgrades as $u) {
                    $u->forceFill(['notified_at' => now()])->save();
                }

                $sent++;
                Log::info('AbandonedCart: sent recovery email', [
                    'user_id'           => $user->id,
                    'pending_upgrade_id'=> $upgrade->id,
                    'plan'              => $upgrade->plan,
                    'upgrades_marked'   => $upgrades->count(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('AbandonedCart: email send failed', [
                    'pending_upgrade_id' => $upgrade->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        $this->info("Sent {$sent} abandoned-cart recovery emails (skipped {$skipped}).");
    }
}
