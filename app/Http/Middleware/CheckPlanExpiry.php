<?php

namespace App\Http\Middleware;

use App\Services\PlanDowngradeService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CheckPlanExpiry
{
    public function __construct(
        private readonly PlanDowngradeService $downgradeService,
    ) {}

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
                    // Downgrade via the centralized service so Studio-only
                    // resources (custom_domain, custom_logo_path, curtain_logo_path,
                    // audio_path) are cleaned up — not just the plan column.
                    // Without this, an expired Studio user keeps their custom
                    // domain routing forever (task C05).
                    $this->downgradeService->downgradeToFree($user, 'Plan expired');

                    // Reload so current request sees updated plan
                    Auth::setUser($user->fresh());

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
