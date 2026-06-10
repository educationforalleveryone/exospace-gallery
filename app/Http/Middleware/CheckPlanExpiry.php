<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
                $limits = \App\Models\User::planLimits('free');
                $user->update([
                    'plan'           => 'free',
                    'max_galleries'  => $limits['max_galleries'],
                    'max_images'     => $limits['max_images'],
                ]);
                // Reload so current request sees updated plan
                Auth::setUser($user->fresh());

                if ($request->expectsJson()) {
                    return response()->json(['error' => 'Your plan has expired. Please renew.'], 402);
                }

                return redirect()->route('admin.galleries.index')
                    ->with('warning', 'Your plan has expired and has been downgraded to Free.');
            }
        }

        } catch (\Throwable $e) {}
        return $next($request);
    }
}