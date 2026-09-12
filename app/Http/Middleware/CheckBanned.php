<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckBanned
{
    public function handle(Request $request, Closure $next)
    {
        if (! Auth::check()) {
            return $next($request);
        }

        try {
            $user = Auth::user();

            $bannedAt = DB::table('users')
                ->where('id', $user->id)
                ->value('banned_at');

            if (! is_null($bannedAt)) {
                $reason = $user->ban_reason ?: 'Your account has been suspended.';

                $reason = mb_substr(strip_tags($reason), 0, 200);

                if (config('session.driver') === 'database') {
                    try {
                        DB::table('sessions')->where('user_id', $user->id)->delete();
                    } catch (\Throwable $e) {
                        Log::warning('CheckBanned: failed to purge user sessions', [
                            'user_id' => $user->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }

                try {
                    DB::table('personal_access_tokens')
                        ->where('tokenable_type', User::class)
                        ->where('tokenable_id', $user->id)
                        ->delete();
                } catch (\Throwable $e) {
                    Log::warning('CheckBanned: failed to revoke API tokens', [
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
            Log::error('CheckBanned: exception while checking ban status — FAILING CLOSED (503)', [
                'user_id' => Auth::id(),
                'error'   => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'Service temporarily unavailable. Please try again.',
                ], 503);
            }

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
