<?php

namespace App\Http\Middleware;

use App\Http\Controllers\MfaController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require TOTP MFA verification for super-admin routes. (Task H56)
 *
 * P3-8: MFA session expires after 30 minutes. The RequireMfa middleware
 * now checks both the mfa_verified flag AND the mfa_verified_at timestamp.
 * If the session is older than 30 minutes, the user must re-enter their
 * TOTP code (GitHub sudo-mode pattern).
 */
class RequireMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only enforce MFA for super-admins
        if (! $user || ! $user->is_super_admin) {
            return $next($request);
        }

        // If MFA is not set up yet, allow access (first-time setup)
        if (! $user->google2fa_secret) {
            if (! $request->routeIs('mfa.setup') && ! $request->routeIs('mfa.verify') && ! $request->routeIs('mfa.backup-codes')) {
                return redirect()->route('mfa.setup')
                    ->with('warning', 'Multi-factor authentication is required for super-admin accounts. Please set it up now.');
            }
            return $next($request);
        }

        // P3-8: Check if MFA session is still valid (within 30-minute TTL)
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
