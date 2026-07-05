<?php

namespace App\Console\Commands;

use App\Mail\InactiveUserNudge;
use App\Mail\PlanExpiringSoon;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send lifecycle nudge emails. (Task H55)
 *
 * Two types of emails:
 *   1. "You haven't published in 7 days" — for users who registered
 *      >7 days ago but have 0 published (is_active) galleries.
 *      Sent once per user (tracked via 'inactive_nudged_at').
 *      MARKETING — requires marketing_consent + email_verified_at.
 *
 *   2. "Your plan expires soon" — for admin-granted plans that expire
 *      within 7 days. Sent once per user per expiry window
 *      (tracked via 'plan_expiry_reminded_at').
 *      TRANSACTIONAL (account status) — does NOT require marketing_consent,
 *      but still requires email_verified_at.
 *
 * Scheduled daily at 9am via routes/console.php.
 *
 * P0-3 FIX (audit): CAN-SPAM/GDPR compliance.
 *   - Inactive nudge: requires marketing_consent=true + email_verified_at
 *   - Plan-expiry reminder: requires email_verified_at (transactional,
 *     no consent needed, but still shouldn't send to unverified addresses)
 *   - Cache::lock prevents concurrent runs from double-sending
 *   - Both emails include unsubscribe link + physical postal address
 *
 * P0-7 FIX (audit): Split lifecycle_nudged_at into two separate columns.
 *   Previously, both flows used the same `lifecycle_nudged_at` column,
 *   which caused a silent bug: a user who was inactive-nudged within
 *   14 days of their plan expiry was filtered out of the plan-expiry
 *   flow, so they NEVER received a renewal warning. Now each flow uses
 *   its own column:
 *     - inactive_nudged_at       — set by sendInactiveNudges()
 *     - plan_expiry_reminded_at  — set by sendPlanExpiryReminders()
 */
class SendLifecycleEmails extends Command
{
    protected $signature = 'exospace:send-lifecycle-emails';
    protected $description = 'Send lifecycle nudge emails (inactive users + plan-expiring-soon).';

    private const LOCK_KEY = 'cmd:lifecycle-emails';
    private const LOCK_TTL = 300;

    public function handle(): int
    {
        // P0-3: prevent concurrent runs from double-sending.
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        try {
            $lock->block(10, function () {
                $this->sendInactiveNudges();
                $this->sendPlanExpiryReminders();
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            $this->info('Another lifecycle-emails run is in progress — skipping.');
            Log::info('LifecycleEmails: lock busy, another run is in progress');
            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    /**
     * Send "You haven't published in 7 days" to users who registered
     * >7 days ago, have 0 active galleries, and haven't been nudged yet.
     *
     * P0-3: MARKETING — requires marketing_consent=true + email_verified_at.
     * P0-7: Uses 'inactive_nudged_at' (not the shared lifecycle_nudged_at).
     */
    private function sendInactiveNudges(): void
    {
        $cutoff = now()->subDays(7);

        $users = User::where('created_at', '<', $cutoff)
            ->whereNull('inactive_nudged_at')
            ->whereNull('banned_at')
            // P0-3: marketing consent required for this nudge
            ->where('marketing_consent', true)
            // P0-3: only send to verified emails
            ->whereNotNull('email_verified_at')
            ->whereDoesntHave('galleries', function ($q) {
                $q->where('is_active', true);
            })
            ->limit(100)
            ->get();

        if ($users->isEmpty()) {
            $this->info('No inactive users to nudge.');
            return;
        }

        $sent = 0;
        foreach ($users as $user) {
            // P0-3: defense-in-depth re-check
            if (! $user->marketing_consent || ! $user->email_verified_at) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new InactiveUserNudge($user));

                // P0-7: use inactive_nudged_at (not lifecycle_nudged_at)
                $user->forceFill(['inactive_nudged_at' => now()])->save();
                $sent++;

                Log::info('LifecycleEmail: sent inactive nudge', [
                    'user_id' => $user->id,
                ]);
            } catch (\Throwable $e) {
                Log::warning('LifecycleEmail: inactive nudge failed', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} inactive-user nudge emails.");
    }

    /**
     * Send "Your plan expires soon" to users whose plan_expires_at is
     * within 7 days. Only applies to admin-granted plans (webhook plans
     * are lifetime with plan_expires_at = null).
     *
     * P0-3: TRANSACTIONAL — does NOT require marketing_consent (the email
     * is about account status, not marketing). Still requires email_verified_at.
     *
     * P0-7: Uses 'plan_expiry_reminded_at' (not the shared
     * lifecycle_nudged_at). This fixes the bug where a user who was
     * inactive-nudged within 14 days of their plan expiry was silently
     * skipped from this flow. Each flow now has its own independent
     * tracking column, so an inactive-nudge no longer suppresses a
     * plan-expiry reminder.
     */
    private function sendPlanExpiryReminders(): void
    {
        $cutoff = now()->addDays(7);

        $users = User::whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<=', $cutoff)
            ->where('plan_expires_at', '>', now())
            ->where('plan', '!=', 'free')
            ->whereNull('banned_at')
            // P0-3: only send to verified emails (transactional, but
            // sending to unverified addresses is still bad practice)
            ->whereNotNull('email_verified_at')
            ->limit(50)
            ->get();

        // P0-7: filter out users who have already been reminded about
        // THIS expiry window. A user is "already reminded" if
        // plan_expiry_reminded_at is set AND it's after
        // (plan_expires_at - 14 days). This is a per-expiry-window dedup:
        // if the user re-purchases and gets a new plan_expires_at, the
        // old plan_expiry_reminded_at timestamp will be before the new
        // window and they'll get a fresh reminder.
        //
        // IMPORTANT (P0-7): This filter now uses 'plan_expiry_reminded_at'
        // INDEPENDENTLY of 'inactive_nudged_at'. Previously, both flows
        // shared 'lifecycle_nudged_at', which meant an inactive-nudge
        // suppressed the plan-expiry reminder. That bug is now fixed.
        $users = $users->filter(function ($user) {
            $reminded = $user->plan_expiry_reminded_at;
            if ($reminded && $reminded > $user->plan_expires_at?->subDays(14)) {
                return false; // already reminded in this expiry window
            }
            return true;
        });

        if ($users->isEmpty()) {
            $this->info('No plan-expiry reminders to send.');
            return;
        }

        $sent = 0;
        foreach ($users as $user) {
            // P0-3: defense-in-depth re-check
            if (! $user->email_verified_at) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new PlanExpiringSoon($user));

                // P0-7: use plan_expiry_reminded_at (not lifecycle_nudged_at)
                $user->forceFill(['plan_expiry_reminded_at' => now()])->save();
                $sent++;

                Log::info('LifecycleEmail: sent plan-expiry reminder', [
                    'user_id'    => $user->id,
                    'expires_at' => $user->plan_expires_at?->toDateString(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('LifecycleEmail: plan-expiry reminder failed', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} plan-expiry reminder emails.");
    }
}
