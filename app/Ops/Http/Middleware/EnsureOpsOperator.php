<?php

declare(strict_types=1);

namespace App\Ops\Http\Middleware;

use App\Ops\Models\OpsAccessGrant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OpsCenter — Iteration 6 — the operator gate.
 *
 * Guards exactly ONE surface: POST /ops/diagnostics/run (plus the run
 * affordances in the UI). Who passes:
 *
 *   super-admins      — unchanged, they pass everything.
 *   operator grants   — users holding an ACTIVE 'operator' grant. Running a
 *                       read-only diagnostic is exactly the "diagnose
 *                       without blast radius" right this tier was designed
 *                       for: the checks are allow-listed, redacted, audited
 *                       (ops.diagnostic.run with the actor) and change no
 *                       infrastructure state.
 *
 * Who NEVER passes (fail-closed, route level — not UI hiding):
 *   viewers, regular users, guests (403 before any controller logic), and
 *   operators while OPS_OPERATOR_ACCESS_ENABLED=false (their own kill
 *   switch — an incident-response lever that revokes the tier globally
 *   without touching grant rows; super-admins are unaffected).
 *
 * The outer /ops group's middleware (auth, verified, ops_access, mfa) has
 * ALREADY run by the time this gate executes: an operator here is
 * authenticated, email-verified, grant-checked and MFA-verified. This
 * middleware only adds the tier check.
 */
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

        // Tier kill switch — separate from the viewer lever so one tier can
        // be fail-closed without touching the other.
        if (! (bool) config('ops.access.operator_enabled', true)) {
            abort(403, 'OpsCenter operator access is disabled on this deployment.');
        }

        if (! OpsAccessGrant::hasActiveGrant($user, [OpsAccessGrant::LEVEL_OPERATOR])) {
            abort(403, 'Diagnostics can be run by super-admins and operator-level accounts only.');
        }

        return $next($request);
    }
}
