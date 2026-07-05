<?php

namespace App\Http\Middleware;

use App\Http\Controllers\MfaController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require TOTP MFA verification. (Task H56)
 *
 * SEC-4 FIX: MFA is now available to ALL users (not just super-admins).
 *   - Super-admins: MFA is REQUIRED (existing behavior — they manage all
 *     users' data and can change plans).
 *   - Regular users: MFA is OPTIONAL (opt-in via /mfa/setup). Once enabled,
 *     the user is prompted to verify MFA when accessing sensitive routes
 *     (currently: /profile, /billing). Non-sensitive routes (admin dashboard,
 *     gallery view, analytics) do NOT require MFA re-verification — that
 *     would be too aggressive for a one-time-purchase SaaS where the user
 *     just wants to manage their galleries.
 *
 * P3-8: MFA session expires after 30 minutes. The RequireMfa middleware
 * checks both the mfa_verified flag AND the mfa_verified_at timestamp.
 * If the session is older than 30 minutes, the user must re-enter their
 * TOTP code (GitHub sudo-mode pattern).
 *
 * SEC-5 FIX: The session timestamp check now applies to regular users too
 * (was super-admin-only). Without the timestamp, a stolen session cookie
 * would grant indefinite MFA-verified access. With the 30-min TTL, the
 * attacker has at most 30 minutes before re-verification is required.
 */
class RequireMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not authenticated — skip (auth middleware handles redirect to login)
        if (! $user) {
            return $next($request);
        }

        $isSuperAdmin = $user->is_super_admin === true;
        $mfaEnabled   = ! empty($user->google2fa_secret);

        // ── Super-admin enforcement ───────────────────────────────────────
        // Super-admins MUST have MFA enabled. If they don't, redirect to
        // setup (existing behavior from Task H56).
        if ($isSuperAdmin) {
            if (! $mfaEnabled) {
                if (! $request->routeIs('mfa.setup') && ! $request->routeIs('mfa.verify') && ! $request->routeIs('mfa.backup-codes')) {
                    return redirect()->route('mfa.setup')
                        ->with('warning', 'Multi-factor authentication is required for super-admin accounts. Please set it up now.');
                }
                return $next($request);
            }
        } else {
            // ── Regular user enforcement (SEC-4) ──────────────────────────
            // Regular users who have NOT enabled MFA skip this middleware
            // entirely — MFA is opt-in for them. Only users who HAVE enabled
            // MFA are prompted to verify on sensitive routes.
            if (! $mfaEnabled) {
                return $next($request);
            }
        }

        // ── MFA verification check (applies to both super-admins + opted-in
        // regular users) — SEC-5: timestamp TTL now enforced for both. ──
        if (! MfaController::isMfaSessionValid($request)) {
            // Clear stale session flag
            $request->session()->forget('mfa_verified');
            $request->session()->forget('mfa_verified_at');

            if (! $request->routeIs('mfa.verify')) {
                return redirect()->route('mfa.verify')
                    ->with('info', 'Your MFA session has expired. Please re-enter your authenticator code.');
            }
        }

        return $next($request);
    }
}
