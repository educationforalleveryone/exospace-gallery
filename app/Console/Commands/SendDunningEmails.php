<?php

namespace App\Console\Commands;

use App\Mail\DunningEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDunningEmails extends Command
{
    protected $signature = 'exospace:send-dunning';
    protected $description = 'Send dunning emails (steps 2 + 3) for subscriptions with failed payments.';

    private const LOCK_KEY = 'cmd:dunning';
    private const LOCK_TTL = 300; // 5 minutes

    // Days after step 1 to send each subsequent step
    private const STEP_2_DELAY_DAYS = 3;
    private const STEP_3_DELAY_DAYS = 7;

    public function handle(): int
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        try {
            $lock->block(10, function () {
                $this->sendStep2Emails();
                $this->sendStep3Emails();
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            $this->info('Another dunning run is in progress — skipping.');
            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    private function sendStep2Emails(): void
    {
        $cutoff = now()->subDays(self::STEP_2_DELAY_DAYS);

        $users = User::where('subscription_status', 'past_due')
            ->where('dunning_step', 1)
            ->where('dunning_last_sent_at', '<=', $cutoff)
            ->whereNotNull('email_verified_at')
            ->whereNull('banned_at')
            ->limit(100)
            ->get();

        if ($users->isEmpty()) {
            $this->info('No step-2 dunning emails to send.');
            return;
        }

        $sent = 0;
        foreach ($users as $user) {
            try {
                Mail::to($user->email)->send(new DunningEmail($user, 2));

                $user->forceFill([
                    'dunning_step'         => 2,
                    'dunning_last_sent_at' => now(),
                ])->save();

                $sent++;
                Log::info('Dunning: sent step 2 email', [
                    'user_id' => $user->id,
                    'email'   => $user->email,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Dunning: step 2 email send failed', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} step-2 dunning emails.");
    }

    private function sendStep3Emails(): void
    {
        $cutoff = now()->subDays(self::STEP_3_DELAY_DAYS);

        $users = User::where('subscription_status', 'past_due')
            ->where('dunning_step', 2)
            ->where('dunning_last_sent_at', '<=', $cutoff)
            ->whereNotNull('email_verified_at')
            ->whereNull('banned_at')
            ->limit(100)
            ->get();

        if ($users->isEmpty()) {
            $this->info('No step-3 dunning emails to send.');
            return;
        }

        $sent = 0;
        foreach ($users as $user) {
            try {
                Mail::to($user->email)->send(new DunningEmail($user, 3));

                $user->forceFill([
                    'dunning_step'         => 3,
                    'dunning_last_sent_at' => now(),
                ])->save();

                $sent++;
                Log::info('Dunning: sent step 3 email (final notice)', [
                    'user_id' => $user->id,
                    'email'   => $user->email,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Dunning: step 3 email send failed', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} step-3 dunning emails (final notices).");
    }
}
