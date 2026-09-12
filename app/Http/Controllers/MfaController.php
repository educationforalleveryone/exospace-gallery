<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class MfaController extends Controller
{
    private const MFA_SESSION_TTL_MINUTES = 30;

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->google2fa_secret) {
            if (self::isMfaSessionValid($request)) {
                return redirect()->route('profile.edit')
                    ->with('status', 'MFA is already enabled on your account.');
            }

            return redirect()->route('mfa.verify');
        }

        try {
            $google2fa = new \PragmaRX\Google2FAQRCode\Google2FA;
            $secret = $google2fa->generateSecretKey();
            $qrCodeInline = $google2fa->getQRCodeInline(
                config('app.name', 'Exospace'),
                $user->email,
                $secret
            );

            session(['mfa_pending_secret' => $secret]);

            return view('auth.mfa-setup', [
                'secret' => $secret,
                'qrCodeData' => $qrCodeInline,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MfaController::setup failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('admin.dashboard')
                ->with('error', 'MFA couldn\'t be set up right now — this is a server configuration issue, not anything you did. Support has the details; please try again shortly.');
        }
    }

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
            $google2fa = new \PragmaRX\Google2FAQRCode\Google2FA;
            $otpCounter = $google2fa->verifyKeyNewer($secret, $request->input('code'), 0);

            if ($otpCounter === false) {
                return back()->withErrors(['code' => 'Invalid code. Please try again.']);
            }

            // P3-7: Generate 10 one-time backup codes
            $backupCodes = $this->generateBackupCodes();

            $user->forceFill([
                'google2fa_secret' => encrypt($secret),
                'mfa_enabled_at' => now(),
                'mfa_backup_codes' => $backupCodes['hashed'],
                // ITERATION-3: replay baseline for the freshly enabled secret.
                'google2fa_ts' => (int) $otpCounter,
            ])->save();

            AdminAuditLog::record('mfa.enabled', $user, [
                'backup_codes_generated' => 10,
                'mfa_enabled_at' => now()->toIso8601String(),
            ]);

            session()->forget('mfa_pending_secret');
            $this->markMfaVerified($request);

            // P3-7: Show backup codes once — redirect to a page that displays them
            return redirect()->route('mfa.backup-codes')
                ->with('backup_codes', $backupCodes['plaintext'])
                ->with('success', 'MFA enabled successfully. Save your backup codes below — you won\'t see them again.');

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MfaController::enable failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'MFA verification failed. Please try again or contact support if the problem persists.');
        }
    }

    public function showBackupCodes(Request $request): View|RedirectResponse
    {
        if (! session('backup_codes')) {
            return redirect()->route($request->user()?->is_super_admin ? 'super.index' : 'profile.edit');
        }

        return view('auth.mfa-backup-codes', [
            'codes' => session('backup_codes'),
        ]);
    }

    public function showVerify(Request $request): View|RedirectResponse
    {
        if (! $request->user()->google2fa_secret) {
            return redirect()->route('profile.edit')
                ->with('status', 'MFA is not enabled on your account.');
        }

        return view('auth.mfa-verify');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string|max:20',
        ]);

        $user = $request->user();
        $code = trim($request->input('code'));

        $code = strtoupper(str_replace([' ', '-'], '', $code));

        try {
            $google2fa = new \PragmaRX\Google2FAQRCode\Google2FA;
            $secret = decrypt($user->google2fa_secret);
            $lastUsed = $user->google2fa_ts !== null ? (int) $user->google2fa_ts : 0;
            $otpCounter = $google2fa->verifyKeyNewer($secret, $code, $lastUsed);

            if ($otpCounter === false && strlen($code) === 10) {
                $valid = $this->tryBackupCode($user, $code);
            } else {
                $valid = $otpCounter !== false;
            }

            if (! $valid) {
                $errorMessage = 'Invalid code. Please try again.';

                if (strlen($code) === 10) {
                    $remaining = count(array_filter(
                        $user->fresh()->mfa_backup_codes ?? [],
                        fn ($c) => $c !== null
                    ));

                    if ($remaining === 0) {
                        $errorMessage = 'All of your backup codes have been used. You can disable MFA from your settings (with your password) and re-enable it to generate a new set.';
                    }
                }

                return back()->withErrors(['code' => $errorMessage]);
            }

            if ($otpCounter !== false && is_int($otpCounter)) {
                $user->forceFill(['google2fa_ts' => $otpCounter])->save();
            }

            $this->markMfaVerified($request);

            $default = $user->is_super_admin ? route('super.index') : route('billing.index');

            return redirect()->intended($default)
                ->with('success', $user->is_super_admin
                    ? 'MFA verified. Welcome to the super-admin panel.'
                    : 'MFA verified. You can now access billing.');

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('MfaController::verify failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'MFA verification failed. Please try again or contact support if the problem persists.');
        }
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validateWithBag('mfaDisable', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if (! $user->google2fa_secret) {
            return redirect()->route('profile.edit')
                ->with('status', 'MFA is not enabled on your account.');
        }

        $hadMfaSince = $user->mfa_enabled_at?->toIso8601String();

        $user->forceFill([
            'google2fa_secret' => null,
            'mfa_enabled_at' => null,
            'mfa_backup_codes' => null,
            'google2fa_ts' => null,
        ])->save();

        AdminAuditLog::record('mfa.disabled', $user, [
            'had_mfa_since' => $hadMfaSince,
        ]);

        $request->session()->forget('mfa_verified');
        $request->session()->forget('mfa_verified_at');
        $request->session()->forget('mfa_verified_user_id');
        $request->session()->forget('mfa_pending_secret');
        $request->session()->forget('backup_codes');

        return redirect()->route('profile.edit')
            ->with('status', 'MFA has been disabled. You can enable it again anytime.');
    }

    private function generateBackupCodes(): array
    {
        $plaintext = [];
        $hashed = [];
        $raw = [];

        for ($i = 0; $i < 10; $i++) {
            do {
                $code = strtoupper(\Illuminate\Support\Str::random(5).\Illuminate\Support\Str::random(5));
            } while (in_array($code, $raw, true));

            $raw[] = $code;
            $plaintext[] = substr($code, 0, 5).'-'.substr($code, 5, 5);
            $hashed[] = Hash::make($code);
        }

        return ['plaintext' => $plaintext, 'hashed' => $hashed];
    }

    private function tryBackupCode($user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code) {
            $fresh = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $fresh) {
                return false;
            }

            $backupCodes = $fresh->mfa_backup_codes ?? [];
            if (empty($backupCodes)) {
                return false;
            }

            foreach ($backupCodes as $index => $hashedCode) {
                if ($hashedCode && Hash::check($code, $hashedCode)) {
                    // Consume the code — set to null in the array
                    $backupCodes[$index] = null;
                    $fresh->forceFill(['mfa_backup_codes' => $backupCodes])->save();

                    AdminAuditLog::record('mfa.backup_code_used', $fresh, [
                        'code_index' => $index,
                        'remaining_codes' => count(array_filter($backupCodes, fn ($c) => $c !== null)),
                    ]);

                    \Illuminate\Support\Facades\Log::info('MFA: backup code used', [
                        'user_id' => $fresh->id,
                        'code_index' => $index,
                    ]);

                    return true;
                }
            }

            return false;
        });
    }

    private function markMfaVerified(Request $request): void
    {
        $request->session()->put('mfa_verified', true);
        $request->session()->put('mfa_verified_at', now()->timestamp);
        $request->session()->put('mfa_verified_user_id', $request->user()->id);
    }

    public static function isMfaSessionValid(Request $request): bool
    {
        if (! $request->session()->get('mfa_verified')) {
            return false;
        }

        $boundUserId = $request->session()->get('mfa_verified_user_id');
        if ($boundUserId !== null && (int) $boundUserId !== (int) $request->user()?->id) {
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
