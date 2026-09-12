<?php

namespace App\Http\Middleware;

use App\Http\Controllers\MfaController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        if ($isSuperAdmin) {
            if (! $mfaEnabled) {
                if (! $request->routeIs('mfa.setup') && ! $request->routeIs('mfa.verify') && ! $request->routeIs('mfa.backup-codes')) {
                    return redirect()->route('mfa.setup')
                        ->with('warning', 'Multi-factor authentication is required for super-admin accounts. Please set it up now.');
                }
                return $next($request);
            }
        } else {
            if (! $mfaEnabled) {
                return $next($request);
            }
        }

        if (! MfaController::isMfaSessionValid($request)) {
            // Clear stale session flag
            $request->session()->forget('mfa_verified');
            $request->session()->forget('mfa_verified_at');
            $request->session()->forget('mfa_verified_user_id');

            if (! $request->routeIs('mfa.verify')) {
                if ($request->isMethod('GET')) {
                    redirect()->setIntendedUrl($request->fullUrl());
                }

                return redirect()->route('mfa.verify')
                    ->with('info', 'Your MFA session has expired. Please re-enter your authenticator code.');
            }
        }

        return $next($request);
    }
}
