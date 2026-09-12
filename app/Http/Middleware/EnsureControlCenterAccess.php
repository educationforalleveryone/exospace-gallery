<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureControlCenterAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = config('test-center.admin_emails', []);

        $allowed = array_values(array_filter(array_map(
            static fn (string $e): string => mb_strtolower(trim($e)),
            is_array($raw) ? $raw : explode(',', (string) $raw)
        )));

        if ($allowed === []) {
            abort(404);
        }

        $user = $request->user();

        if (! $user || ! in_array(mb_strtolower((string) $user->email), $allowed, true)) {
            abort(403);
        }

        return $next($request);
    }
}
