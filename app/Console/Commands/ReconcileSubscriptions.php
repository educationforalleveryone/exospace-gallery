<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\JobHeartbeatService;
use App\Services\OperationalAlertService;
use App\Services\PlanDowngradeService;
use App\Services\TwoCheckoutApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileSubscriptions extends Command
{
    protected $signature = 'exospace:reconcile-subscriptions
                            {--limit=200 : Maximum users to check per run}
                            {--dry-run : Report drift without changing anything}';

    protected $description = 'Reconcile local subscription/plan state against the 2Checkout API and fix or alert on drift.';

    public function handle(TwoCheckoutApiClient $client): int
    {
        if (! TwoCheckoutApiClient::isConfigured()) {
            $this->info('2Checkout API not configured — nothing to reconcile (local/CI).');

            app(JobHeartbeatService::class)->stamp('exospace:reconcile-subscriptions');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $users = User::query()
            ->whereIn('plan', ['pro', 'studio'])
            ->whereNotNull('subscription_id')
            ->orderByDesc('plan_started_at')
            ->limit($limit)
            ->get();

        if ($users->isEmpty()) {
            $this->info('No subscription-bearing paid users to reconcile.');
            app(JobHeartbeatService::class)->stamp('exospace:reconcile-subscriptions');
            return self::SUCCESS;
        }

        $this->info("Reconciling {$users->count()} subscription(s) against 2Checkout…");

        $downgraded = 0;
        $alerts = 0;
        $errors = 0;
        $checked = 0;

        foreach ($users as $user) {
            $checked++;

            $response = $client->getSubscription($user->subscription_id);

            if (! $response->successful()) {
                $errors++;
                Log::warning('ReconcileSubscriptions: subscription lookup failed', [
                    'user_id'         => $user->id,
                    'subscription_id' => $user->subscription_id,
                    'status'          => $response->status(),
                ]);
                continue; // conservative: never act on a failed lookup
            }

            $data = $response->json();
            if (! is_array($data)) {
                $errors++;
                Log::warning('ReconcileSubscriptions: unparseable subscription payload', [
                    'user_id'         => $user->id,
                    'subscription_id' => $user->subscription_id,
                ]);
                continue;
            }

            $dead = $this->subscriptionIsDead($data);
            if ($dead === null) {
                // Payload shape not recognised — treat as a lookup failure.
                $errors++;
                continue;
            }

            if (! $dead) {
                continue; // 2CO agrees with local state — nothing to do
            }

            // ── Drift: local claims active paid, 2CO says dead ──

            $localStillPaid = $user->plan_expires_at !== null && $user->plan_expires_at->isFuture();

            if ($localStillPaid) {
                $this->alertDrift($user, 'paid-period-still-active',
                    '2Checkout reports the subscription as ended, but the local plan is paid until '
                    . $user->plan_expires_at->toDateString() . '. No action taken — expiry will '
                    . 'downgrade the account at the end of the paid period. Verify the cancellation '
                    . 'was expected.');
                $alerts++;
                continue;
            }

            if ($dryRun) {
                $this->warn("[dry-run] would downgrade user {$user->id} ({$user->plan}, subscription {$user->subscription_id}) to free.");
                continue;
            }

            app(PlanDowngradeService::class)->downgradeToFree($user, 'Subscription reconciliation: 2Checkout reports subscription ended');

            AdminAuditLog::record('subscription.reconciled_downgrade', $user, [
                'subscription_id' => $user->subscription_id,
                'from_plan'       => $user->plan,
                'reason'          => '2CO reports subscription ended; local expiry already past',
            ]);

            $this->alertDrift($user, 'auto-downgraded',
                "User {$user->id} ({$user->email}) held plan '{$user->plan}' with a subscription 2Checkout "
                . "reports as ended, and the local paid period had already expired. Automatically downgraded "
                . 'to free (missed cancellation webhook).');
            $downgraded++;
        }

        $freeWithLiveRef = User::query()
            ->where('plan', 'free')
            ->whereNotNull('subscription_id')
            ->limit($limit)
            ->pluck('email', 'id');

        foreach ($freeWithLiveRef as $id => $email) {
            $this->info("User {$id} ({$email}) is on the free plan but still carries a subscription reference — verify manually whether a payment webhook was missed.");
            $alerts++;
        }
        if ($freeWithLiveRef->isNotEmpty()) {
            app(OperationalAlertService::class)->alert(
                'Subscription reconciliation: free users with live subscription references',
                $freeWithLiveRef->count() . " user(s) are on the free plan while still holding 2Checkout subscription "
                . 'references (possible missed payment webhooks — verify before granting): '
                . $freeWithLiveRef->take(10)->implode(', ') . ($freeWithLiveRef->count() > 10 ? '…' : ''),
                'warning',
                'reconcile-subscriptions:free-with-reference',
            );
        }

        // ── Run-level safety valve ──
        if ($checked > 0 && ($errors / $checked) > 0.33) {
            app(OperationalAlertService::class)->alert(
                'Subscription reconciliation aborted: 2Checkout API unreliable',
                "{$errors}/{$checked} subscription lookups failed this run. No further action was taken on "
                . 'those users. Check 2CO API credentials/network before the next run.',
                'critical',
                'reconcile-subscriptions:api-unreliable',
            );
            Log::error('ReconcileSubscriptions: error threshold exceeded, aborting report', [
                'errors' => $errors, 'checked' => $checked,
            ]);
        }

        $this->info("Done: {$checked} checked, {$downgraded} downgraded, {$alerts} alert(s), {$errors} lookup error(s).");
        Log::info('ReconcileSubscriptions: run complete', [
            'checked' => $checked, 'downgraded' => $downgraded,
            'alerts' => $alerts, 'errors' => $errors, 'dry_run' => $dryRun,
        ]);

        app(JobHeartbeatService::class)->stamp('exospace:reconcile-subscriptions');

        return self::SUCCESS;
    }

    private function subscriptionIsDead(array $data): ?bool
    {
        $enabled = $data['SubscriptionEnabled'] ?? null;

        if ($enabled !== null) {
            if ($enabled === false) {
                return true;
            }

            // Enabled, but an expiration date in the past is equally dead.
            $expiration = $data['ExpirationDate'] ?? null;
            if (is_string($expiration) && $expiration !== '') {
                try {
                    return \Carbon\Carbon::parse($expiration)->isPast();
                } catch (\Throwable) {
                    return null; // unparseable date — do not act on it
                }
            }

            return false;
        }

        // Fallback shape: some 2CO responses use a Status field.
        $status = $data['Status'] ?? $data['SubscriptionState'] ?? null;
        if (is_string($status)) {
            $status = strtolower(trim($status));
            if (in_array($status, ['active', 'enabled', 'live'], true)) {
                return false;
            }
            if (in_array($status, ['cancelled', 'canceled', 'expired', 'disabled', 'stopped'], true)) {
                return true;
            }
        }

        return null;
    }

    private function alertDrift(User $user, string $kind, string $message): void
    {
        $this->warn("Drift [{$kind}]: {$message}");
        app(OperationalAlertService::class)->alert(
            'Subscription reconciliation: ' . str_replace('-', ' ', $kind),
            $message,
            'warning',
            "reconcile-subscriptions:{$kind}:{$user->id}",
        );
    }
}
