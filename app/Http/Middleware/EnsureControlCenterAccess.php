<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the Testing Control Center.
 *
 * Access model (fail-closed):
 *   1. `CONTROL_CENTER_ADMINS` unset / empty ⇒ the whole section behaves as
 *      if it does not exist (404). No hint surface for attackers.
 *   2. Otherwise: authenticated users whose e-mail matches the list exactly
 *      (case-insensitive) may pass. Everyone else gets 403.
 *
 * Deliberately NOT a policy/gate on roles: the product intentionally keeps a
 * single decoupled "who runs QA" list, so shipping engineers never mutate
 * app RBAC to let a release engineer see dashboards.
 */
class EnsureControlCenterAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // Config value is an array by default, but tolerate a raw comma-list
        // override for edge deployments (Array-to-string trap fixed here).
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
