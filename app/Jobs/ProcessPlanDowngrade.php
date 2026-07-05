<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\PlanDowngradeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * PERF-17 FIX: Plan expiry downgrade is now async.
 *
 * Previously, CheckPlanExpiry middleware called PlanDowngradeService::downgradeToFree()
 * synchronously on every request from a user with an expired plan. That service:
 *   1. Saves the user row (fast — single UPDATE).
 *   2. Chunks through ALL of the user's galleries (could be 100+ for a Studio user),
 *      and for each one:
 *      - Calls Coolify's HTTP API to remove the domain (network call, 1-3s).
 *      - Deletes 0-3 files from disk (fast).
 *      - Saves the gallery row (fast).
 *
 * For a Studio user with 50 galleries using custom domains, that's 50 sequential
 * HTTP calls to Coolify taking 50-150 seconds — the user's request hangs until
 * it completes. Coolify's request timeout is 60 seconds, so the user sees a 504
 * and the downgrade is left half-finished (some galleries cleaned up, some not).
 *
 * Now:
 *   1. CheckPlanExpiry middleware sets the user's plan='free' + plan_expires_at
 *      SYNCHRONOUSLY (single UPDATE — sub-millisecond), so the request sees the
 *      updated state immediately and the user gets the "your plan expired" redirect.
 *   2. The middleware dispatches THIS job to handle the per-gallery cleanup
 *      (Coolify calls, file deletes) on the queue.
 *
 * The job is idempotent: PlanDowngradeService only cleans up Studio-only fields
 * that are non-null. If the job runs twice (e.g. retry after a failure), the
 * second run finds nulls and does nothing.
 *
 * Failures: the job has 3 tries + exponential backoff. If all 3 fail, the
 * galleries keep their custom_domain in the DB but the user is already on Free —
 * the only consequence is that DetectCustomDomain keeps serving the gallery on
 * the old domain until an admin manually clears it. That's a degraded state,
 * not a billing-incorrect state.
 */
class ProcessPlanDowngrade implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Number of times to retry on failure */
    public int $tries = 3;

    /** Backoff in seconds: 60s, 180s, 540s */
    public int $backoff = 60;

    public function __construct(
        public readonly int $userId,
        public readonly string $reason,
    ) {}

    public function handle(PlanDowngradeService $service): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            Log::info('ProcessPlanDowngrade: user not found (deleted?)', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        // Defensive: if the user has been re-upgraded since this job was
        // dispatched (e.g. admin manually upgraded them back to Studio), skip
        // the cleanup entirely. The job was for an expiry that no longer applies.
        if ($user->plan !== 'free') {
            Log::info('ProcessPlanDowngrade: user is no longer on free — skipping cleanup', [
                'user_id' => $this->userId,
                'plan'    => $user->plan,
            ]);
            return;
        }

        Log::info('ProcessPlanDowngrade: starting gallery cleanup', [
            'user_id' => $this->userId,
            'reason'  => $this->reason,
        ]);

        // PlanDowngradeService::downgradeToFree() updates the user row (idempotent
        // — already 'free') AND iterates galleries for cleanup. The user-row
        // update is cheap and harmless to repeat.
        $service->downgradeToFree($user, $this->reason);

        Log::info('ProcessPlanDowngrade: cleanup complete', [
            'user_id' => $this->userId,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessPlanDowngrade: job failed after retries', [
            'user_id' => $this->userId,
            'reason'  => $this->reason,
            'error'   => $e->getMessage(),
        ]);
    }
}
