<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * MFA (TOTP) setup + verification controller. (Task H56)
 *
 * Prerequisite: `composer require pragmarx/google2fa-qrcode`
 *
 * Flow:
 *   1. Super-admin visits /mfa/setup → generates a TOTP secret +
 *      QR code (as a data URI for inline display).
 *   2. User scans the QR with Google Authenticator / Authy / 1Password.
 *   3. User enters the 6-digit code → POST /mfa/setup verifies it and
 *      stores the secret (encrypted) in google2fa_secret.
 *   4. On next /master-control/* visit, the RequireMfa middleware
 *      redirects to /mfa/verify.
 *   5. User enters 6-digit code → POST /mfa/verify checks it and marks
 *      the session as MFA-verified.
 *
 * NOTE: This controller uses PragmaRX\Google2FA\Google2FA and
 * PragmaRX\Google2FAQRCode\Google2FA for QR generation. If the package
 * isn't installed, the methods throw — install the package first.
 */
class MfaController extends Controller
{
    /**
     * Show the MFA setup page with QR code.
     */
    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // If already set up, redirect to verify
        if ($user->google2fa_secret) {
            return redirect()->route('mfa.verify');
        }

        // Generate a new TOTP secret (not yet stored — stored only after
        // the user verifies they can generate valid codes)
        try {
            $google2fa = new \PragmaRX\Google2FA\Google2FA();
            $secret = $google2fa->generateSecretKey();

            // Generate QR code as inline data URI
            $qrCodeUrl = $google2fa->getQRCodeUrl(
                config('app.name', 'Exospace'),
                $user->email,
                $secret
            );

            // Store the secret in the session temporarily (not in the DB yet)
            session(['mfa_pending_secret' => $secret]);

            return view('auth.mfa-setup', compact('secret', 'qrCodeUrl'));
        } catch (\Throwable $e) {
            return redirect()->route('admin.dashboard')
                ->with('error', 'MFA setup requires the pragmarx/google2fa-qrcode package. Run: composer require pragmarx/google2fa-qrcode');
        }
    }

    /**
     * Verify the TOTP code and enable MFA.
     */
    public function enable(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|digits:6',
        ]);

        $user = $request->user();
        $secret = session('mfa_pending_secret');

        if (! $secret) {
            return redirect()->route('mfa.setup')
                ->with('error', 'MFA setup session expired. Please try again.');
        }

        try {
            $google2fa = new \PragmaRX\Google2FA\Google2FA();
            $valid = $google2fa->verifyKey($secret, $request->input('code'));

            if (! $valid) {
                return back()->withErrors(['code' => 'Invalid code. Please try again.']);
            }

            // Store the secret (encrypted via Laravel's encryptor)
            $user->forceFill([
                'google2fa_secret' => encrypt($secret),
                'mfa_enabled_at'   => now(),
            ])->save();

            // Clear the pending secret + mark session as verified
            session()->forget('mfa_pending_secret');
            session(['mfa_verified' => true]);

            return redirect()->route('super.index')
                ->with('success', 'MFA enabled successfully. You\'ll need to enter a code from your authenticator app each time you log in to the super-admin panel.');

        } catch (\Throwable $e) {
            return back()->with('error', 'MFA verification failed: ' . $e->getMessage());
        }
    }

    /**
     * Show the MFA verification page.
     */
    public function showVerify(): View
    {
        return view('auth.mfa-verify');
    }

    /**
     * Verify the TOTP code and mark the session as MFA-verified.
     */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|digits:6',
        ]);

        $user = $request->user();

        try {
            $google2fa = new \PragmaRX\Google2FA\Google2FA();
            $secret = decrypt($user->google2fa_secret);
            $valid = $google2fa->verifyKey($secret, $request->input('code'));

            if (! $valid) {
                return back()->withErrors(['code' => 'Invalid code. Please try again.']);
            }

            session(['mfa_verified' => true]);

            return redirect()->intended(route('super.index'))
                ->with('success', 'MFA verified. Welcome to the super-admin panel.');

        } catch (\Throwable $e) {
            return back()->with('error', 'MFA verification failed: ' . $e->getMessage());
        }
    }
}
