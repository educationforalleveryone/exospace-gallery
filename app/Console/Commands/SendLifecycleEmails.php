<?php

namespace App\Console\Commands;

use App\Mail\InactiveUserNudge;
use App\Mail\PlanExpiringSoon;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send lifecycle nudge emails. (Task H55)
 *
 * Two types of emails:
 *   1. "You haven't published in 7 days" — for users who registered
 *      >7 days ago but have 0 published (is_active) galleries.
 *      Sent once per user (tracked via the 'lifecycle_nudged_at' column).
 *
 *   2. "Your plan expires soon" — for admin-granted plans that expire
 *      within 7 days. Sent once per user per expiry window.
 *
 * Scheduled daily at 9am via routes/console.php.
 */
class SendLifecycleEmails extends Command
{
    protected $signature = 'exospace:send-lifecycle-emails';
    protected $description = 'Send lifecycle nudge emails (inactive users + plan-expiring-soon).';

    public function handle(): int
    {
        $this->sendInactiveNudges();
        $this->sendPlanExpiryReminders();

        return self::SUCCESS;
    }

    /**
     * Send "You haven't published in 7 days" to users who registered
     * >7 days ago, have 0 active galleries, and haven't been nudged yet.
     */
    private function sendInactiveNudges(): void
    {
        $cutoff = now()->subDays(7);

        $users = User::where('created_at', '<', $cutoff)
            ->whereNull('lifecycle_nudged_at')
            ->whereNull('banned_at')
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
            try {
                Mail::to($user->email)->send(new InactiveUserNudge($user));

                $user->forceFill(['lifecycle_nudged_at' => now()])->save();
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
     */
    private function sendPlanExpiryReminders(): void
    {
        $cutoff = now()->addDays(7);

        $users = User::whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<=', $cutoff)
            ->where('plan_expires_at', '>', now())
            ->where('plan', '!=', 'free')
            ->whereNull('banned_at')
            ->whereDoesntHave('galleries', fn($q) => $q->where('is_active', true))
            ->limit(50)
            ->get();

        // Also check users who haven't been notified about this expiry
        $users = $users->filter(function ($user) {
            // Only send if not already notified for this expiry window
            $notified = $user->lifecycle_nudged_at;
            if ($notified && $notified > $user->plan_expires_at?->subDays(14)) {
                return false; // already notified in this expiry window
            }
            return true;
        });

        if ($users->isEmpty()) {
            $this->info('No plan-expiry reminders to send.');
            return;
        }

        $sent = 0;
        foreach ($users as $user) {
            try {
                Mail::to($user->email)->send(new PlanExpiringSoon($user));

                $user->forceFill(['lifecycle_nudged_at' => now()])->save();
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
