<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require TOTP MFA verification for super-admin routes. (Task H56)
 *
 * This middleware checks if the authenticated super-admin has completed
 * MFA verification in the current session. If not, they're redirected
 * to the MFA verification page.
 *
 * MFA verification is session-scoped: once verified, the user doesn't
 * need to re-enter their TOTP code for the rest of the session (or
 * until the session expires).
 *
 * Setup flow:
 *   1. Super-admin visits /profile/mfa → sees QR code
 *   2. Scans with Google Authenticator / Authy / 1Password
 *   3. Enters the 6-digit code to verify
 *   4. Secret is stored encrypted in google2fa_secret
 *   5. On next /master-control/* visit, RequireMfa redirects to /mfa/verify
 *   6. User enters 6-digit code → session marked as MFA-verified
 *
 * Prerequisite: `composer require pragmarx/google2fa-qrcode`
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
            // Redirect to MFA setup if not configured
            if (! $request->routeIs('mfa.setup') && ! $request->routeIs('mfa.verify')) {
                return redirect()->route('mfa.setup')
                    ->with('warning', 'Multi-factor authentication is required for super-admin accounts. Please set it up now.');
            }
            return $next($request);
        }

        // MFA is set up — check if verified in this session
        if (! session('mfa_verified')) {
            if (! $request->routeIs('mfa.verify')) {
                return redirect()->route('mfa.verify')
                    ->with('info', 'Please enter your authenticator code to access the super-admin panel.');
            }
        }

        return $next($request);
    }
}
