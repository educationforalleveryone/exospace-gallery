<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckBanned
{
    /**
     * (Task H07 / audit H15) — previously this middleware had an empty
     * `catch (\Throwable $e) {}` which fail-OPENED: if the DB was briefly
     * unreachable or a model cast threw, banned users were allowed through.
     *
     * P1-8 FIX: CheckBanned now FAILS CLOSED. If the DB is unreachable or
     * any exception occurs while checking ban status, the middleware returns
     * 503 (Service Unavailable) rather than letting the request through.
     * This prevents banned users from accessing the application during a
     * DB outage window.
     *
     * NOTE: CheckPlanExpiry middleware intentionally stays fail-OPEN — a
     * transient DB error should NOT downgrade a paying user. The two
     * middlewares have different security vs. availability tradeoffs:
     *   - CheckBanned: security > availability (fail closed)
     *   - CheckPlanExpiry: availability > security (fail open)
     *
     * (Task H07 / audit H16) — ban now purges ALL of the user's sessions,
     * not just the current one. Previously a banned user's other browser
     * sessions (laptop, mobile) remained valid until their next request
     * hit this middleware. Now we DELETE all rows from the `sessions`
     * table for this user_id immediately.
     */
    public function handle(Request $request, Closure $next)
    {
        // If not authenticated, skip ban check entirely — the auth middleware
        // will handle redirecting to login. This avoids a DB query for
        // anonymous requests (e.g. public gallery views).
        if (! Auth::check()) {
            return $next($request);
        }

        try {
            $user = Auth::user();

            // Re-read the user's banned_at from the DB to ensure we have
            // the freshest value (the Auth::user() model may be cached from
            // the session). Use a raw query to avoid model hydration
            // overhead on every request.
            $bannedAt = DB::table('users')
                ->where('id', $user->id)
                ->value('banned_at');

            if (! is_null($bannedAt)) {
                $reason = $user->ban_reason ?: 'Your account has been suspended.';

                // Purge ALL of the user's sessions, not just the current one.
                // (audit H16) — prevents banned users from continuing to
                // use the app from other browsers/devices for the remaining
                // session lifetime.
                try {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                } catch (\Throwable $e) {
                    Log::warning('CheckBanned: failed to purge user sessions', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                Log::info('CheckBanned: banned user blocked and logged out', [
                    'user_id' => $user->id,
                ]);

                return redirect()->route('login')
                                 ->withErrors(['email' => "Your account has been banned. Reason: {$reason}"]);
            }
        } catch (\Throwable $e) {
            // P1-8 FIX: FAIL CLOSED. Previously this block was empty (fail-open),
            // meaning banned users could access the app during a DB outage.
            // Now we return 503 so no user (banned or not) can access the app
            // when we can't verify their ban status. This is the correct
            // security tradeoff: better to temporarily deny all users than to
            // allow banned users through.
            Log::error('CheckBanned: exception while checking ban status — FAILING CLOSED (503)', [
                'user_id' => Auth::id(),
                'error'   => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'Service temporarily unavailable. Please try again.',
                ], 503);
            }

            // Use a plain HTML response rather than a view — avoids
            // ViewNotFoundException if resources/views/errors/503.blade.php
            // doesn't exist. The response is minimal but functional.
            return response(
                '<!DOCTYPE html><html><head><meta charset="UTF-8">'
                . '<title>Service Temporarily Unavailable</title>'
                . '<style>body{font-family:sans-serif;text-align:center;padding:50px;'
                . 'background:#0f1117;color:#e5e7eb;}'
                . 'h1{font-size:24px;margin-bottom:10px;}'
                . 'p{color:#9ca3af;}</style></head><body>'
                . '<h1>Service Temporarily Unavailable</h1>'
                . '<p>We are unable to verify account status right now. '
                . 'Please try again in a moment.</p>'
                . '</body></html>',
                503,
                ['Content-Type' => 'text/html']
            );
        }

        return $next($request);
    }
}
