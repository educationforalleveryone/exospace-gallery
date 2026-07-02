<?php

namespace App\Console\Commands;

use App\Mail\AbandonedCartEmail;
use App\Models\PendingUpgrade;
use App\Models\User;
use Illuminate\Console\Command;
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
 */
class SendAbandonedCartEmails extends Command
{
    protected $signature = 'exospace:abandoned-cart';
    protected $description = 'Send recovery emails for pending upgrades abandoned > 24 hours.';

    public function handle(): int
    {
        $cutoff = now()->subHours(24);

        $pending = PendingUpgrade::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->whereNull('notified_at')
            ->where('expires_at', '>', now()) // token still valid
            ->limit(100)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No abandoned carts to recover.');
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($pending as $upgrade) {
            $user = $upgrade->user;
            if (! $user) continue;

            try {
                Mail::to($user->email)->send(new AbandonedCartEmail($user, $upgrade));

                // Mark as notified so we don't email twice
                $upgrade->forceFill(['notified_at' => now()])->save();

                $sent++;
                Log::info('AbandonedCart: sent recovery email', [
                    'user_id'           => $user->id,
                    'pending_upgrade_id'=> $upgrade->id,
                    'plan'              => $upgrade->plan,
                ]);
            } catch (\Throwable $e) {
                Log::warning('AbandonedCart: email send failed', [
                    'pending_upgrade_id' => $upgrade->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} abandoned-cart recovery emails.");
        return self::SUCCESS;
    }
}
