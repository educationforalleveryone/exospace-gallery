<?php

namespace App\Http\Middleware;

use App\Jobs\ProcessPlanDowngrade;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CheckPlanExpiry
{
    public function handle(Request $request, Closure $next)
    {
        try {
            if (Auth::check()) {
                $user = Auth::user();
                if (
                    $user->plan !== 'free' &&
                    $user->plan_expires_at !== null &&
                    $user->plan_expires_at->isPast()
                ) {
                    // PERF-17 FIX: Synchronously flip the user's plan to 'free'
                    // (sub-millisecond UPDATE) and dispatch the slow per-gallery
                    // resource cleanup (Coolify HTTP calls, file deletes) to the
                    // queue via ProcessPlanDowngrade job.
                    //
                    // Previously the middleware called PlanDowngradeService::downgradeToFree()
                    // synchronously, which iterated ALL of the user's galleries
                    // and made an HTTP call to Coolify for each one with a custom
                    // domain. A Studio user with 50 custom-domain galleries would
                    // hang the request for 50-150 seconds (and likely 504).
                    //
                    // The DB update is done inline (not via the service) so we
                    // can dispatch the job BEFORE any heavy work. The job is
                    // idempotent: PlanDowngradeService's downgradeToFree()
                    // updates the user row (already 'free' — no-op) AND does
                    // the per-gallery cleanup. Re-running it is safe.
                    //
                    // The user row update uses forceFill because plan / max_*
                    // / plan_expires_at are guarded columns (task C09).
                    $limits = User::planLimits('free');
                    $user->forceFill([
                        'plan'            => 'free',
                        'max_galleries'   => $limits['max_galleries'],
                        'max_images'      => $limits['max_images'],
                        'plan_expires_at' => now(),
                    ])->save();

                    // Dispatch the slow cleanup work to the queue.
                    ProcessPlanDowngrade::dispatch($user->id, 'Plan expired');

                    // Reload so current request sees updated plan
                    Auth::setUser($user->fresh());

                    Log::info('CheckPlanExpiry: plan expired, downgraded + queued cleanup', [
                        'user_id' => $user->id,
                    ]);

                    if ($request->expectsJson()) {
                        return response()->json(['error' => 'Your plan has expired. Please renew.'], 402);
                    }

                    return redirect()->route('admin.galleries.index')
                        ->with('warning', 'Your plan has expired and has been downgraded to Free.');
                }
            }
        } catch (\Throwable $e) {
            // Fail-OPEN by historical design (so a DB blip doesn't lock every
            // user out), but at least log it so ops can see when this fires.
            // Audit H15 recommends failing closed for CheckBanned — for plan
            // expiry we keep fail-open because a transient DB error shouldn't
            // downgrade a paying user. Logged at warning level.
            Log::warning('CheckPlanExpiry: exception while checking plan expiry', [
                'user_id' => Auth::id(),
                'error'   => $e->getMessage(),
            ]);
        }
        return $next($request);
    }
}
