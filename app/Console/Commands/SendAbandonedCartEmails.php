<?php

namespace App\Console\Commands;

use App\Mail\AbandonedCartEmail;
use App\Models\PendingUpgrade;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAbandonedCartEmails extends Command
{
    protected $signature = 'exospace:abandoned-cart';

    protected $description = 'Send recovery emails for pending upgrades abandoned > 24 hours.';

    private const LOCK_KEY = 'cmd:abandoned-cart';

    private const LOCK_TTL = 300; // 5 minutes — generous for 100 emails

    private const FREQUENCY_CAP_DAYS = 7; // max 1 email per user per 7 days

    public function handle(): int
    {
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

        $pending = PendingUpgrade::with('user')
            ->where('pending_upgrades.status', 'pending')
            ->where('pending_upgrades.created_at', '<', $cutoff)
            ->whereNull('pending_upgrades.notified_at')
            ->where('pending_upgrades.expires_at', '>', now())
            // Only send to users who consented to marketing
            ->whereHas('user', function ($q) {
                $q->where('marketing_consent', true)
                    ->whereNotNull('email_verified_at')
                    ->whereNull('banned_at');
            })
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

        $byUser = $pending->groupBy('user_id');
        $this->info(sprintf(
            'Found %d abandoned carts across %d users (after consent + verification + frequency-cap filters).',
            $pending->count(),
            $byUser->count()
        ));

        $sent = 0;
        $skipped = 0;

        foreach ($byUser as $userId => $upgrades) {
            $user = $upgrades->first()->user;

            if (! $user) {
                $skipped++;

                continue;
            }

            // Checkouts the user's plan already covers are closed for good, so
            // they stop competing for the batch limit on later runs.
            foreach ($upgrades as $u) {
                if ($u->isCoveredByCurrentPlan()) {
                    $u->forceFill(['status' => 'expired'])->save();
                }
            }

            $upgrades = $upgrades->filter(fn (PendingUpgrade $u) => $u->isRecoverable());

            if ($upgrades->isEmpty()) {
                Log::info('AbandonedCart: skipped — no longer eligible', [
                    'user_id' => $user->id,
                ]);
                $skipped++;

                continue;
            }

            // Pick the most recent pending upgrade for this user.
            $upgrade = $upgrades->sortByDesc('created_at')->first();

            try {
                Mail::to($user->email)->send(new AbandonedCartEmail($user, $upgrade));

                foreach ($upgrades as $u) {
                    $u->forceFill(['notified_at' => now()])->save();
                }

                $sent++;
                Log::info('AbandonedCart: sent recovery email', [
                    'user_id' => $user->id,
                    'pending_upgrade_id' => $upgrade->id,
                    'plan' => $upgrade->plan,
                    'upgrades_marked' => $upgrades->count(),
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
