<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * P3-11 FIX: Per-user upgrade/downgrade lock.
 *
 * Previously, the only locks in the upgrade/downgrade flow were per-invoice
 * (Cache::lock("2co:upgrade:{$invoiceId}", 60)) inside WebhookController.
 * That protects against a single webhook being processed twice (idempotency),
 * but it does NOT protect against:
 *
 *   1. The user clicking "Upgrade to Pro" twice in rapid succession — two
 *      pending_upgrades are created, two 2Checkout redirects happen, the user
 *      might pay twice. (Both webhooks would succeed, but the user is double-
 *      charged — annoying to refund.)
 *
 *   2. A webhook completing an upgrade WHILE an admin simultaneously downgrades
 *      the user via SystemController::updatePlan. The webhook writes plan=pro,
 *      the admin writes plan=free, the final state depends on which UPDATE
 *      lands last — a classic last-writer-wins race.
 *
 *   3. CheckPlanExpiry firing on a user mid-upgrade. The user is on Free,
 *      pays for Pro via 2Checkout, but before the webhook lands, the
 *      middleware sees plan_expires_at in the past and downgrades. Then the
 *      webhook lands and upgrades them again. Confusing audit trail.
 *
 * This service provides a per-user lock that the three mutating paths
 * (BillingController::upgrade, WebhookController, SystemController::updatePlan,
 * CheckPlanExpiry) should acquire before touching the user's plan column.
 *
 * Lock semantics:
 *   - Keyed by user_id (one lock per user — covers all upgrade/downgrade paths).
 *   - TTL: 60 seconds (long enough for a webhook + DB transaction, short enough
 *     that a crashed holder doesn't block the user for long).
 *   - Acquire with ->block(5) — wait up to 5 seconds for a concurrent holder
 *     to release. If still locked after 5s, fail with a user-friendly message.
 *   - Auto-released when the closure returns OR throws (Laravel's lock helper
 *     handles this via try/finally internally).
 *
 * Usage:
 *
 *   $result = $lockService->withUserLock($user, function () use (...) {
 *       // ... do plan-changing work ...
 *       return 'success';
 *   });
 *
 *   if ($result === PlanLockService::LOCK_BUSY) {
 *       return back()->with('warning', 'Another billing operation is in progress…');
 *   }
 */
class PlanLockService
{
    /** Lock TTL in seconds — generous enough for a webhook + DB transaction. */
    public const LOCK_TTL = 60;

    /** How long to wait for a busy lock before giving up (seconds). */
    public const LOCK_WAIT = 5;

    /** Sentinel returned by withUserLock when the lock could not be acquired. */
    public const LOCK_BUSY = '__PLAN_LOCK_BUSY__';

    /**
     * Acquire the per-user plan lock, run the closure, release the lock.
     *
     * @param  int      $userId
     * @param  callable $callback  Receives no args, returns whatever (sent to caller).
     * @return mixed    The closure's return value, or self::LOCK_BUSY if the
     *                  lock could not be acquired within self::LOCK_WAIT seconds.
     */
    public function withUserLock(int $userId, callable $callback): mixed
    {
        $lock = Cache::lock("plan_lock:user:{$userId}", self::LOCK_TTL);

        try {
            $acquired = $lock->block(self::LOCK_WAIT, function () use ($callback) {
                // Lock acquired — run the critical section.
                return $callback();
            });
            // Laravel's block($seconds, $callback) returns the closure's return
            // value if acquired, or false if the lock could not be acquired
            // within $seconds.
            return $acquired === false ? self::LOCK_BUSY : $acquired;
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('PlanLockService: lock busy, concurrent upgrade/downgrade blocked', [
                'user_id' => $userId,
            ]);
            return self::LOCK_BUSY;
        }
    }

    /**
     * Check (without acquiring) whether a per-user plan lock is currently held.
     * Used for diagnostics / surfacing "another operation in progress" without
     * trying to acquire.
     */
    public function isLocked(int $userId): bool
    {
        return Cache::has("plan_lock:user:{$userId}");
    }
}
