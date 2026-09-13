<?php

namespace App\Console\Commands;

use App\Mail\InactiveUserNudge;
use App\Mail\PlanExpiringSoon;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendLifecycleEmails extends Command
{
    protected $signature = 'exospace:send-lifecycle-emails';
    protected $description = 'Send lifecycle nudge emails (inactive users + plan-expiring-soon).';

    private const LOCK_KEY = 'cmd:lifecycle-emails';
    private const LOCK_TTL = 300;

    public function handle(): int
    {
        // Prevent concurrent runs from double-sending.
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

    private function sendInactiveNudges(): void
    {
        $cutoff = now()->subDays(7);

        $users = User::where('created_at', '<', $cutoff)
            ->whereNull('inactive_nudged_at')
            ->whereNull('banned_at')
            // Marketing consent required for this nudge
            ->where('marketing_consent', true)
            // Only send to verified emails
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
            // Defense-in-depth re-check
            if (! $user->marketing_consent || ! $user->email_verified_at) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new InactiveUserNudge($user));

                // Use inactive_nudged_at (not lifecycle_nudged_at)
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

    private function sendPlanExpiryReminders(): void
    {
        $cutoff = now()->addDays(7);

        $users = User::whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<=', $cutoff)
            ->where('plan_expires_at', '>', now())
            ->where('plan', '!=', 'free')
            ->whereNull('banned_at')
            ->whereNotNull('email_verified_at')
            ->limit(50)
            ->get();

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
            // Defense-in-depth re-check
            if (! $user->email_verified_at) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new PlanExpiringSoon($user));

                // Use plan_expiry_reminded_at (not lifecycle_nudged_at)
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
