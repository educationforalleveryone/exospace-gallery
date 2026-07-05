<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * MFA (TOTP) setup + verification controller. (Task H56)
 *
 * P3-7: Backup codes — 10 one-time codes generated on MFA enable.
 * P3-8: MFA re-verification — session flag includes timestamp,
 *   expires after 30 minutes.
 *
 * Flow:
 *   1. Super-admin visits /mfa/setup → generates a TOTP secret +
 *      QR code (as a base64 PNG data URI for inline display).
 *   2. User scans the QR with Google Authenticator / Authy / 1Password.
 *   3. User enters the 6-digit code → POST /mfa/setup verifies it and
 *      stores the secret (encrypted) in google2fa_secret.
 *   4. 10 backup codes are generated and shown once — user must save them.
 *   5. On next /master-control/* visit, the RequireMfa middleware
 *      redirects to /mfa/verify.
 *   6. User enters 6-digit code → POST /mfa/verify checks it and marks
 *      the session as MFA-verified (with timestamp, valid 30 min).
 *   7. User can also enter a backup code instead of a TOTP code.
 */
class MfaController extends Controller
{
    private const MFA_SESSION_TTL_MINUTES = 30;

    /**
     * Show the MFA setup page with QR code.
     */
    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->google2fa_secret) {
            return redirect()->route('mfa.verify');
        }

        try {
            $google2fa = new \PragmaRX\Google2FAQRCode\Google2FA();
            $secret = $google2fa->generateSecretKey();
            $qrCodeInline = $google2fa->getQRCodeInline(
                config('app.name', 'Exospace'),
                $user->email,
                $secret
            );

            session(['mfa_pending_secret' => $secret]);

            return view('auth.mfa-setup', [
                'secret'     => $secret,
                'qrCodeData' => $qrCodeInline,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MfaController::setup failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return redirect()->route('admin.dashboard')
                ->with('error', 'MFA setup could not be completed. Please ensure the pragmarx/google2fa-qrcode package is installed. Contact support if the problem persists.');
        }
    }

    /**
     * Verify the TOTP code and enable MFA.
     * P3-7: Also generates 10 one-time backup codes.
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
            $google2fa = new \PragmaRX\Google2FAQRCode\Google2FA();
            $valid = $google2fa->verifyKey($secret, $request->input('code'));

            if (! $valid) {
                return back()->withErrors(['code' => 'Invalid code. Please try again.']);
            }

            // P3-7: Generate 10 one-time backup codes
            $backupCodes = $this->generateBackupCodes();

            $user->forceFill([
                'google2fa_secret' => encrypt($secret),
                'mfa_enabled_at'   => now(),
                'mfa_backup_codes' => $backupCodes['hashed'],
            ])->save();

            session()->forget('mfa_pending_secret');
            $this->markMfaVerified($request);

            // P3-7: Show backup codes once — redirect to a page that displays them
            return redirect()->route('mfa.backup-codes')
                ->with('backup_codes', $backupCodes['plaintext'])
                ->with('success', 'MFA enabled successfully. Save your backup codes below — you won\'t see them again.');

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MfaController::enable failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return back()->with('error', 'MFA verification failed. Please try again or contact support if the problem persists.');
        }
    }

    /**
     * P3-7: Show backup codes page (one-time view after MFA enable).
     */
    public function showBackupCodes(Request $request): View|RedirectResponse
    {
        if (! session('backup_codes')) {
            return redirect()->route('super.index');
        }

        return view('auth.mfa-backup-codes', [
            'codes' => session('backup_codes'),
        ]);
    }

    /**
     * Show the MFA verification page.
     */
    public function showVerify(): View
    {
        return view('auth.mfa-verify');
    }

    /**
     * Verify the TOTP code (or backup code) and mark the session as MFA-verified.
     * P3-8: Session flag includes a timestamp; expires after 30 minutes.
     * P3-7: Accepts backup codes as an alternative to TOTP.
     */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string|max:20',
        ]);

        $user = $request->user();
        $code = trim($request->input('code'));

        // Remove spaces/dashes from the code (backup codes may be formatted)
        $code = str_replace([' ', '-'], '', $code);

        try {
            // First try TOTP verification
            $google2fa = new \PragmaRX\Google2FAQRCode\Google2FA();
            $secret = decrypt($user->google2fa_secret);
            $valid = $google2fa->verifyKey($secret, $code);

            // If TOTP fails, try backup code (P3-7)
            if (! $valid && strlen($code) === 10) {
                $valid = $this->tryBackupCode($user, $code);
            }

            if (! $valid) {
                return back()->withErrors(['code' => 'Invalid code. Please try again.']);
            }

            // P3-8: Mark MFA verified with timestamp
            $this->markMfaVerified($request);

            // SEC-4: Redirect target depends on user role.
            //   - Super-admins → /master-control (super-admin panel)
            //   - Regular users → intended URL (set by middleware) or
            //     /billing (the most common MFA-gated route for regular
            //     users — they typically enable MFA right before changing
            //     their plan).
            $intended = redirect()->intended(route('super.index'));

            if (! $user->is_super_admin) {
                // For regular users, redirect()->intended() falls back to
                // /billing if no intended URL is set (i.e. they visited
                // /mfa/verify directly). If they were redirected from
                // /billing by the mfa middleware, intended() returns
                // /billing — perfect.
                return redirect()->intended(route('billing.index'))
                    ->with('success', 'MFA verified. You can now access billing.');
            }

            return $intended
                ->with('success', 'MFA verified. Welcome to the super-admin panel.');

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MfaController::verify failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return back()->with('error', 'MFA verification failed. Please try again or contact support if the problem persists.');
        }
    }

    // ── P3-7: Backup code helpers ──────────────────────────────────────

    /**
     * Generate 10 one-time backup codes.
     * Returns ['plaintext' => [...], 'hashed' => [...]].
     * Each code is 10 characters (groups of 5 separated by dash for display).
     */
    private function generateBackupCodes(): array
    {
        $plaintext = [];
        $hashed = [];

        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(\Illuminate\Support\Str::random(5) . \Illuminate\Support\Str::random(5));
            $plaintext[] = substr($code, 0, 5) . '-' . substr($code, 5, 5);
            $hashed[] = Hash::make($code);
        }

        return ['plaintext' => $plaintext, 'hashed' => $hashed];
    }

    /**
     * Try to use a backup code. If it matches, remove it from the array.
     * Returns true if a backup code matched.
     */
    private function tryBackupCode($user, string $code): bool
    {
        $backupCodes = $user->mfa_backup_codes ?? [];
        if (empty($backupCodes)) {
            return false;
        }

        foreach ($backupCodes as $index => $hashedCode) {
            if ($hashedCode && Hash::check($code, $hashedCode)) {
                // Consume the code — set to null in the array
                $backupCodes[$index] = null;
                $user->forceFill(['mfa_backup_codes' => $backupCodes])->save();

                \Illuminate\Support\Facades\Log::info('MFA: backup code used', [
                    'user_id' => $user->id,
                    'code_index' => $index,
                ]);
                return true;
            }
        }

        return false;
    }

    // ── P3-8: MFA session timestamp ────────────────────────────────────

    /**
     * Mark the session as MFA-verified with a timestamp.
     * The RequireMfa middleware checks both the flag and the timestamp.
     */
    private function markMfaVerified(Request $request): void
    {
        $request->session()->put('mfa_verified', true);
        $request->session()->put('mfa_verified_at', now()->timestamp);
    }

    /**
     * Check if the MFA session is still valid (within TTL).
     * Used by the RequireMfa middleware.
     */
    public static function isMfaSessionValid(Request $request): bool
    {
        if (! $request->session()->get('mfa_verified')) {
            return false;
        }

        $verifiedAt = $request->session()->get('mfa_verified_at');
        if (! $verifiedAt) {
            // Legacy session without timestamp — treat as expired
            return false;
        }

        return (now()->timestamp - $verifiedAt) < (self::MFA_SESSION_TTL_MINUTES * 60);
    }
}
