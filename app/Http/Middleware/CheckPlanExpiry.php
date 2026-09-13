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
                    $limits = User::planLimits('free');

                    $attributes = [
                        'plan'            => 'free',
                        'max_galleries'   => $limits['max_galleries'],
                        'max_images'      => $limits['max_images'],
                        'plan_expires_at' => now(),
                    ];

                    // A subscription that was never cancelled upstream must not
                    // keep reporting itself as active once the plan has expired.
                    if ($user->hasSubscription() && in_array($user->subscription_status, ['active', 'past_due'], true)) {
                        $attributes['subscription_status'] = 'expired';
                    }

                    $user->forceFill($attributes)->save();

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

                    // Never intercept the logout POST — the session must terminate.
                    if (! $request->routeIs('logout')) {
                        return redirect()->route('admin.galleries.index')
                            ->with('warning', 'Your plan has expired and has been downgraded to Free.');
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CheckPlanExpiry: exception while checking plan expiry', [
                'user_id' => Auth::id(),
                'error'   => $e->getMessage(),
            ]);
        }
        return $next($request);
    }
}
