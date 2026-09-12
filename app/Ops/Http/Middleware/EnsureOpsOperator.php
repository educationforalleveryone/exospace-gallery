<?php

declare(strict_types=1);

namespace App\Ops\Http\Middleware;

use App\Ops\Models\OpsAccessGrant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOpsOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Unauthorized. This area is restricted.');
        }

        if ($user->is_super_admin) {
            return $next($request);
        }

        if (! (bool) config('ops.access.operator_enabled', true)) {
            abort(403, 'OpsCenter operator access is disabled on this deployment.');
        }

        if (! OpsAccessGrant::hasActiveGrant($user, [OpsAccessGrant::LEVEL_OPERATOR])) {
            abort(403, 'Diagnostics can be run by super-admins and operator-level accounts only.');
        }

        return $next($request);
    }
}
