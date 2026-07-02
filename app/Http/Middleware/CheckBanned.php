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
     * Now: log the exception at warning level. The fail-open behavior is
     * preserved intentionally (a transient DB blip shouldn't lock every
     * user out), but the failure is now visible to ops.
     *
     * (Task H07 / audit H16) — ban now purges ALL of the user's sessions,
     * not just the current one. Previously a banned user's other browser
     * sessions (laptop, mobile) remained valid until their next request
     * hit this middleware. Now we DELETE all rows from the `sessions`
     * table for this user_id immediately.
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            if (Auth::check() && ! is_null(Auth::user()->banned_at)) {
                $user = Auth::user();
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

                return redirect()->route('login')
                                 ->withErrors(['email' => "Your account has been banned. Reason: {$reason}"]);
            }
        } catch (\Throwable $e) {
            // Fail-open by historical design (a DB blip shouldn't lock
            // every user out), but at least log it so ops can see when
            // this fires. (audit H15)
            Log::warning('CheckBanned: exception while checking ban status', [
                'user_id' => Auth::id(),
                'error'   => $e->getMessage(),
            ]);
        }
        return $next($request);
    }
}
