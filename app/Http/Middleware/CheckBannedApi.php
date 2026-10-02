<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckBannedApi
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (is_null($user->banned_at)) {
            return $next($request);
        }

        try {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $user->id)
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('CheckBannedApi: failed to revoke API tokens', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        Log::info('CheckBannedApi: banned user blocked on API request', [
            'user_id' => $user->id,
        ]);

        $reason = $user->ban_reason ?: 'Your account has been suspended.';
        $reason = mb_substr(strip_tags($reason), 0, 200);

        return response()->json([
            'message' => "Your account has been banned. Reason: {$reason}",
        ], 403);
    }
}
