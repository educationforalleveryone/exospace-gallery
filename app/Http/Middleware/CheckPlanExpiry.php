<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckPlanExpiry
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $user = Auth::user();
            if (
                $user->plan !== 'free' &&
                $user->plan_expires_at !== null &&
                $user->plan_expires_at->isPast()
            ) {
                $user->update([
                    'plan'           => 'free',
                    'max_galleries'  => 1,
                    'max_images'     => 10,
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

        return $next($request);
    }
}