<?php

declare(strict_types=1);

namespace App\Ops\Http\Middleware;

use App\Ops\Models\OpsAccessGrant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOpsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not authenticated — let the group's 'auth' middleware redirect.
        if (! $user) {
            return $next($request);
        }

        if ($user->is_super_admin) {
            return $next($request);
        }

        $level = OpsAccessGrant::activeLevelFor($user);

        if ($level === null) {
            abort(403, 'Unauthorized. This area is restricted.');
        }

        if ($level === OpsAccessGrant::LEVEL_VIEWER
            && ! (bool) config('ops.access.viewer_enabled', true)) {
            abort(403, 'OpsCenter viewer access is disabled on this deployment.');
        }

        if ($level === OpsAccessGrant::LEVEL_OPERATOR
            && ! (bool) config('ops.access.operator_enabled', true)) {
            abort(403, 'OpsCenter operator access is disabled on this deployment.');
        }

        if (empty($user->google2fa_secret)) {
            if ($request->routeIs('mfa.setup') || $request->routeIs('mfa.verify') || $request->routeIs('mfa.backup-codes')) {
                return $next($request);
            }

            return redirect()->route('mfa.setup')
                ->with('warning', 'Multi-factor authentication is required for OpsCenter access. Please set it up now.');
        }

        return $next($request);
    }
}
