<?php

namespace App\Console\Commands;

use App\Mail\DunningEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * M-9: Send dunning emails for subscriptions with failed payments.
 *
 * Dunning = the process of recovering failed recurring payments via
 * email reminders. This command runs daily and sends the appropriate
 * dunning email to users whose subscription_status is 'past_due'.
 *
 * 3-email sequence:
 *   Step 1: Sent immediately when the first RECURRING_INSTALLMENT_FAILED
 *           webhook arrives (dispatched by WebhookController, not this
 *           command). This command handles steps 2 and 3.
 *   Step 2: Sent 3 days after step 1 (if still past_due).
 *   Step 3: Sent 7 days after step 1 (if still past_due). This is the
 *           final email before 2Checkout cancels the subscription.
 *
 * Why step 1 is sent by the webhook handler (not this command):
 *   The user should be notified ASAP when a payment fails — waiting for
 *   the daily cron could delay the notification by up to 24 hours. The
 *   webhook handler dispatches the email immediately via the queue.
 *
 * This command handles steps 2 and 3 because they require time-based
 * spacing (3 days, 7 days) that a webhook-driven approach can't easily
 * achieve (the webhook only fires once per failed attempt, and 2Checkout's
 * retry schedule varies).
 *
 * CAN-SPAM/GDPR: Dunning emails are TRANSACTIONAL (not marketing) — they
 * are sent regardless of marketing_consent because they're required to
 * fulfill the user's subscription contract. The user can't "unsubscribe"
 * from dunning emails — they can only fix their payment or cancel.
 *
 * Scheduled daily at 11am via routes/console.php (after the abandoned-cart
 * command at 10am, so the two email sends don't overlap).
 */
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

    /**
     * Send step 2 emails: users who received step 1 >= 3 days ago and
     * are still past_due.
     */
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

    /**
     * Send step 3 emails: users who received step 1 >= 7 days ago and
     * are still past_due. This is the final email before cancellation.
     */
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
