<?php

declare(strict_types=1);

namespace App\Ops\Http\Middleware;

use App\Ops\Models\OpsAccessGrant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OpsCenter — Iteration 5 — the access gate.
 *
 * Replaces 'super_admin' as the first gate on the /ops route group. The
 * bar for super-admins is UNCHANGED (they pass here; the group's existing
 * 'mfa' middleware still enforces MFA exactly as before). What changes:
 * users holding an ACTIVE VIEWER GRANT (ops_access_grants) may enter the
 * READ surfaces of the control plane.
 *
 * Route-level split (routes/web.php) — not just UI hiding — keeps the
 * write surfaces super-admin-only:
 *   viewer-accessible (GET): overview, applications, events, event
 *     detail, incidents list/detail, diagnostics catalog, run results.
 *   super-admin-only (nested 'super_admin' group): every POST
 *     (incident lifecycle, diagnostic runs, actions), the Actions hub,
 *     the Credentials page and the Access management page.
 *
 * Viewer policy decisions, all fail-closed:
 *   - Kill switch: OPS_VIEWER_ACCESS_ENABLED=false (default true) makes
 *     this middleware ignore grants entirely — instant global revoke.
 *   - Viewers MUST have MFA enabled (regular users otherwise opt in).
 *     No MFA secret → redirect to /mfa/setup, same as super-admins get.
 *     Session verification (the 30-min TTL) is enforced by the group's
 *     'mfa' middleware right after this gate.
 *   - Email verification is enforced by the group's 'verified'
 *     middleware before this gate ever runs.
 *   - Any level other than 'viewer' grants nothing (future tiers must
 *     opt in here explicitly).
 */
class EnsureOpsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not authenticated — let the group's 'auth' middleware redirect.
        if (! $user) {
            return $next($request);
        }

        // Super-admins: the bar is exactly what it always was. The 'mfa'
        // middleware downstream keeps its super-admin enforcement.
        if ($user->is_super_admin) {
            return $next($request);
        }

        // Kill switch fail-closes viewer access without touching the
        // grant rows (an incident response lever, not a data operation).
        if (! (bool) config('ops.access.viewer_enabled', true)) {
            abort(403, 'OpsCenter viewer access is disabled on this deployment.');
        }

        if (! OpsAccessGrant::hasActiveViewerGrant($user)) {
            abort(403, 'Unauthorized. This area is restricted.');
        }

        // Viewers must have MFA enabled — the control plane shows
        // operational detail, so the account bar matches super-admins.
        // (Redirect-to-setup mirrors RequireMfa's super-admin path; the
        // mfa.verify/mfa.setup routes themselves are excluded so the
        // redirect can never loop.)
        if (empty($user->google2fa_secret)) {
            if ($request->routeIs('mfa.setup') || $request->routeIs('mfa.verify') || $request->routeIs('mfa.backup-codes')) {
                return $next($request);
            }

            return redirect()->route('mfa.setup')
                ->with('warning', 'Multi-factor authentication is required for OpsCenter viewer access. Please set it up now.');
        }

        return $next($request);
    }
}
