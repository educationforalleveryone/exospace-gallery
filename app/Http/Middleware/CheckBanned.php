<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckBanned
{
    public function handle(Request $request, Closure $next)
    {
        try {
        if (Auth::check() && ! is_null(Auth::user()->banned_at)) {
            $reason = Auth::user()->ban_reason ?: 'Your account has been suspended.';

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                             ->withErrors(['email' => "Your account has been banned. Reason: {$reason}"]);
        }

        } catch (\Throwable $e) {}
        return $next($request);
    }
}