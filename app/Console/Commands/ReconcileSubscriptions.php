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

/**
 * ITERATION-3: subscription reconciliation against the 2Checkout API.
 *
 * WHY: webhooks are the only thing keeping local plan state in sync with
 * 2CO, and webhooks get missed — endpoint downtime, mis-routed INS URLs,
 * 2CO-side delivery gaps. The classic failure: a customer cancels at the
 * bank / 2CO marks the subscription dead, the cancellation webhook never
 * arrives, and the local account keeps paid entitlements forever (direct
 * revenue leak). The opposite drift (2CO active, local free after a missed
 * payment webhook) under-serves a paying customer.
 *
 * WHAT IT DOES (deliberately asymmetric, fail-safe in the user's favour
 * where automation is unsafe):
 *
 *   Local state claims active paid plan + subscription_id, 2CO says the
 *   subscription is DISABLED/expired:
 *     - if the local plan_expires_at has ALSO passed (or is null — the
 *       "should still be active" claim rests solely on a webhook we now
 *       know was missed) → AUTO-DOWNGRADE to free via
 *       PlanDowngradeService (same cleanup path as every other
 *       downgrade), audit-logged + alerted;
 *     - if local plan_expires_at is still in the future (the customer
 *       paid through that date — e.g. cancelled-for-period-end) →
 *       ALERT ONLY, no action. CheckPlanExpiry handles the boundary
 *       when the paid period genuinely ends.
 *
 *   Local plan is free but the user still carries a subscription_id that
 *   2CO reports as ACTIVE → ALERT ONLY. A missed payment webhook means
 *       someone PAID and isn't getting entitlements — but auto-granting
 *       from a stale reference can double-grant after refunds/chargebacks;
 *       support verifies and grants manually.
 *
 * SAFETY: any API failure (network error, non-200, unparseable body) is
 * counted and SKIPPED — never downgraded on a failed lookup. If more than
 * a third of lookups fail, the run aborts with an alert (the API is lying
 * or the network is broken; bulk action on bad data is worse than no
 * action). No-ops cleanly when the 2CO API is unconfigured (local/CI).
 *
 * SCHEDULE: daily 04:10 (offset from the 04:00 cleanup batch) via
 * routes/console.php. --limit caps the batch (default 200) so a cron tick
 * never runs long; drift beyond the cap reconciles on subsequent runs.
 */
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

            // ITERATION 6: a completed no-op still proves the scheduler ran
            // the job — stamp the heartbeat so the cadence monitor can tell
            // "feature off" apart from "job silently dead".
            app(JobHeartbeatService::class)->stamp('exospace:reconcile-subscriptions');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        // Users whose local state claims an ACTIVE paid subscription. A
        // null subscription_status on a paid plan is treated as active too
        // — pre-M-1 rows never set it.
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
                // The customer paid through plan_expires_at (e.g. cancelled
                // at period end). Let the paid period run out naturally.
                $this->alertDrift($user, 'paid-period-still-active',
                    '2Checkout reports the subscription as ended, but the local plan is paid until '
                    . $user->plan_expires_at->toDateString() . '. No action taken — expiry will '
                    . 'downgrade the account at the end of the paid period. Verify the cancellation '
                    . 'was expected.');
                $alerts++;
                continue;
            }

            // Local state believes the plan should be active only because a
            // webhook was missed. Downgrade (unless dry-run).
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

        // ── Missed-payment direction: local free + still-live reference ──
        // ALERT ONLY — never auto-grant (stale references after refunds or
        // chargebacks make automatic upgrades unsafe; support verifies).
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

        // ITERATION 6: successful completion (including runs that ended on
        // the API-unreliable safety valve — those already paged) counts as
        // "the job ran"; only a job that never finishes goes silent.
        app(JobHeartbeatService::class)->stamp('exospace:reconcile-subscriptions');

        return self::SUCCESS;
    }

    /**
     * Interpret a 2CO v6.0 subscription payload.
     *
     * Returns true  → subscription is definitively dead (disabled or past
     *                 its expiration date),
     *         false → subscription is alive,
     *         null  → payload shape not recognised (caller treats as a
     *                 failed lookup — never acts on it).
     */
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
