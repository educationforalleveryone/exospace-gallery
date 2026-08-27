<?php

declare(strict_types=1);

namespace App\Ops\Http\Middleware;

use App\Ops\Models\OpsAccessGrant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OpsCenter — Iteration 5 — the access gate (Iteration 6: two tiers).
 *
 * Replaces 'super_admin' as the first gate on the /ops route group. The
 * bar for super-admins is UNCHANGED (they pass here; the group's existing
 * 'mfa' middleware still enforces MFA exactly as before). What changes:
 * users holding an ACTIVE GRANT (ops_access_grants) may enter the READ
 * surfaces of the control plane. Two tiers since Iteration 6:
 *
 *   viewer   (Iteration 5) — read-only, exactly as before.
 *   operator (Iteration 6) — the same read surfaces, plus the right to RUN
 *              read-only diagnostics — enforced separately by
 *              EnsureOpsOperator on the diagnostics-run route, NOT here.
 *              This gate stays tier-agnostic on purpose: both tiers read.
 *
 * Route-level split (routes/web.php) — not just UI hiding — keeps the
 * write surfaces super-admin-only:
 *   grant-accessible (GET): overview, applications, events, event
 *     detail, incidents list/detail, diagnostics catalog, run results.
 *   super-admin-only (nested 'super_admin' group): incident lifecycle
 *     POSTs, the whole Actions hub, the Credentials page and the Access
 *     management page.
 *   operator-tier (nested 'ops_operator' group): the diagnostics-run POST
 *     only — super-admins and active operator grants.
 *
 * Grant policy decisions, all fail-closed:
 *   - Kill switches: OPS_VIEWER_ACCESS_ENABLED=false (default true) and
 *     OPS_OPERATOR_ACCESS_ENABLED=false (default true) each fail-close
 *     their own tier instantly without touching grant rows — incident
 *     response levers, not data operations.
 *   - Grantees MUST have MFA enabled (regular users otherwise opt in).
 *     No MFA secret → redirect to /mfa/setup, same as super-admins get.
 *     Session verification (the 30-min TTL) is enforced by the group's
 *     'mfa' middleware right after this gate.
 *   - Email verification is enforced by the group's 'verified'
 *     middleware before this gate ever runs.
 *   - Any level other than 'viewer' / 'operator' grants nothing (future
 *     tiers must opt in here explicitly).
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

        // Resolve the active grant level ONCE (single indexed lookup) —
        // both the kill switches and the MFA bar below key off it.
        $level = OpsAccessGrant::activeLevelFor($user);

        if ($level === null) {
            abort(403, 'Unauthorized. This area is restricted.');
        }

        // Per-tier kill switches fail-close each tier independently
        // without touching the grant rows.
        if ($level === OpsAccessGrant::LEVEL_VIEWER
            && ! (bool) config('ops.access.viewer_enabled', true)) {
            abort(403, 'OpsCenter viewer access is disabled on this deployment.');
        }

        if ($level === OpsAccessGrant::LEVEL_OPERATOR
            && ! (bool) config('ops.access.operator_enabled', true)) {
            abort(403, 'OpsCenter operator access is disabled on this deployment.');
        }

        // Grantees must have MFA enabled — the control plane shows
        // operational detail, so the account bar matches super-admins.
        // (Redirect-to-setup mirrors RequireMfa's super-admin path; the
        // mfa.verify/mfa.setup routes themselves are excluded so the
        // redirect can never loop.)
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
