<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 *
 * ITERATION-3 FIX (TOTP replay window): TOTP codes are now SINGLE-USE.
 * verifyKey() has no memory — the same six digits authenticated an
 * unlimited number of times inside its 30-second slice (up to ~90s with
 * the library's ±1 drift window), so a phished code stayed valid after
 * the legitimate login. Both entry points now use verifyKeyNewer() with
 * the last accepted OTP counter persisted on users.google2fa_ts: any
 * code matching a counter ≤ the stored one is rejected, and each success
 * advances the stored counter. Clock-drift tolerance is preserved.
 *
 * ITERATION-6 (MFA ship-readiness):
 *   - The "MFA verified" session flag is now BOUND TO THE USER
 *     (mfa_verified_user_id). session()->regenerate() keeps session DATA
 *     while rotating the ID — so a subsequent Auth::login() into the same
 *     session (e.g. the OAuth callback path) previously inherited the
 *     previous user's MFA-verified state. The middleware now rejects a
 *     flag that does not belong to the authenticated user, without any
 *     change to the login controllers.
 *   - disable(): the documented "You can disable it anytime" promise on
 *     /profile had no implementation — no route, no controller method, no
 *     UI. A user who lost both their device AND their backup codes was
 *     locked out of every MFA-gated area (all of /billing for regular
 *     users, all of /master-control for super-admins) with no self-serve
 *     recovery. Disablement requires the CURRENT PASSWORD (the app's
 *     established sudo pattern: profile delete + password update), which
 *     also makes it the safe recovery path when the device is lost — it
 *     works even with a fresh, unverified MFA session.
 *   - setup() no longer dumps an already-enabled user on /mfa/verify when
 *     their session is still MFA-verified (they are sent to /profile
 *     instead); an unverified session still goes to /mfa/verify, which is
 *     what a super-admin mid-challenge needs.
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
            // Already enabled. If this session has not completed an MFA
            // challenge yet (the usual super-admin-mid-challenge case), the
            // verify screen is exactly where this user belongs. If the
            // session IS verified, the verify screen is a dead end — send
            // them to their settings, where the real MFA controls live.
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
                // ITERATION-9: never surface package names to users — the log
                // has the technical detail; the message stays calm and useful.
                ->with('error', 'MFA couldn\'t be set up right now — this is a server configuration issue, not anything you did. Support has the details; please try again shortly.');
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
            $google2fa = new \PragmaRX\Google2FAQRCode\Google2FA;
            // ITERATION-3: verifyKeyNewer() instead of verifyKey() — returns
            // the matched OTP counter (int) we persist below, so the setup
            // code can never be replayed on the verify screen. oldTimestamp=0
            // is the "no prior use" baseline (real counters are ~5.9e7 in
            // 2026, always > 0) that forces the counter return value.
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

            // AUDIT-P1-4.2: Log MFA enable + backup code generation.
            // Security-relevant: MFA is the primary defense against
            // credential stuffing. Enabling it (and generating backup
            // codes) should be audited for forensic visibility.
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

    /**
     * P3-7: Show backup codes page (one-time view after MFA enable).
     */
    public function showBackupCodes(Request $request): View|RedirectResponse
    {
        if (! session('backup_codes')) {
            // ITERATION-6: regular users have no /master-control access —
            // sending them there 403s. Settings is where their MFA lives.
            return redirect()->route($request->user()?->is_super_admin ? 'super.index' : 'profile.edit');
        }

        return view('auth.mfa-backup-codes', [
            'codes' => session('backup_codes'),
        ]);
    }

    /**
     * Show the MFA verification page.
     */
    public function showVerify(Request $request): View|RedirectResponse
    {
        // ITERATION-6: a user WITHOUT MFA has nothing to verify — previously
        // this rendered a code form whose submission could only fail with a
        // generic error. Send them to their settings instead.
        if (! $request->user()->google2fa_secret) {
            return redirect()->route('profile.edit')
                ->with('status', 'MFA is not enabled on your account.');
        }

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
        // and normalise case — backup codes are generated uppercase; a user
        // typing one by hand may produce lowercase letters.
        $code = strtoupper(str_replace([' ', '-'], '', $code));

        try {
            // First try TOTP verification.
            //
            // ITERATION-3: single-use codes. google2fa_ts holds the OTP
            // counter of the last ACCEPTED code; verifyKeyNewer() only
            // matches counters strictly greater than it. NULL (MFA enabled
            // before this deploy) behaves like a fresh baseline — the first
            // verification stamps it. A replayed code silently fails with
            // the same "Invalid code" error as a wrong one (no oracle for
            // an attacker probing whether a code was already used).
            $google2fa = new \PragmaRX\Google2FAQRCode\Google2FA;
            $secret = decrypt($user->google2fa_secret);
            $lastUsed = $user->google2fa_ts !== null ? (int) $user->google2fa_ts : 0;
            $otpCounter = $google2fa->verifyKeyNewer($secret, $code, $lastUsed);

            // If TOTP fails, try backup code (P3-7) — backup codes are
            // already single-use (consumed on match), so replay protection
            // is symmetric on both entry paths.
            if ($otpCounter === false && strlen($code) === 10) {
                $valid = $this->tryBackupCode($user, $code);
            } else {
                $valid = $otpCounter !== false;
            }

            if (! $valid) {
                // ITERATION-11: distinguish an EXHAUSTED recovery-code set
                // from a merely-wrong code. The exhausted copy is shown only
                // for the 10-character backup-code input shape, so a failed
                // 6-digit TOTP attempt keeps the generic message, and only
                // on this authenticated challenge screen (an attacker with
                // the password already knows MFA is on from this very page;
                // guessing is capped by the endpoint throttle). A user whose
                // saved code no longer works must not be told their valid
                // code is "invalid" — the profile page documents the same
                // disable-and-re-enable model.
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

            // Persist the replay baseline. A 6-digit TOTP success always
            // yields an int counter; guard defensively anyway (backup-code
            // logins must not wipe a TOTP baseline with garbage).
            if ($otpCounter !== false && is_int($otpCounter)) {
                $user->forceFill(['google2fa_ts' => $otpCounter])->save();
            }

            // P3-8: Mark MFA verified with timestamp (ITERATION-6: bound to
            // the user — see markMfaVerified())
            $this->markMfaVerified($request);

            // SEC-4: Redirect target depends on user role.
            //   - Super-admins → /master-control (super-admin panel)
            //   - Regular users → intended URL (set by middleware) or
            //     /billing (the most common MFA-gated route for regular
            //     users — they typically enable MFA right before changing
            //     their plan).
            //
            // ITERATION-6 BUG: the old code called redirect()->intended()
            // TWICE — once unconditionally (super-admin branch) and once
            // for regular users. intended() PULLS url.intended out of the
            // session, so the first call consumed the stored destination
            // even when its response object was discarded; regular users
            // always fell back to /billing and deep links like
            // /billing/upgrade/pro were lost. One pull, one response,
            // role-aware default.
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

    /**
     * Disable MFA for the authenticated user. (ITERATION-6)
     *
     * This is the self-serve recovery path when the authenticator device
     * AND all backup codes are lost, and the completion of the product's
     * own "You can disable it anytime" promise on /profile.
     *
     * Security model (matches the app's existing sudo patterns):
     *   - Requires the CURRENT PASSWORD via the same 'current_password'
     *     rule used by profile deletion and password changes. No MFA
     *     session is required — a lost device must not lock the user out
     *     of disabling MFA. Password possession + an authenticated session
     *     is exactly the bar the app already accepts for destructive
     *     profile actions.
     *   - The route is throttled (throttle:6,1) so the password check
     *     cannot be brute-forced any faster than the MFA verify endpoint.
     *   - Every trace of the enrollment is removed: secret, enable
     *     timestamp, backup codes, replay baseline, pending setup secret,
     *     and the session's MFA-verified flags.
     *   - Super-admins: after disabling, RequireMfa forces them back into
     *     MFA SETUP on their next /master-control visit (existing
     *     enforcement — super-admins must keep MFA enabled). The audit
     *     log makes the disable event visible either way.
     */
    public function disable(Request $request): RedirectResponse
    {
        // Same error-bag pattern as self-serve account deletion — the modal
        // on /profile reads $errors->mfaDisable.
        $request->validateWithBag('mfaDisable', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // ITERATION-6: idempotency guard — disabling MFA on an account that
        // has none is a no-op (the UI never offers it, but the endpoint
        // must not write a misleading 'mfa.disabled' audit entry either).
        if (! $user->google2fa_secret) {
            return redirect()->route('profile.edit')
                ->with('status', 'MFA is not enabled on your account.');
        }

        // Capture BEFORE the forceFill below nulls the timestamp on the
        // in-memory model.
        $hadMfaSince = $user->mfa_enabled_at?->toIso8601String();

        $user->forceFill([
            'google2fa_secret' => null,
            'mfa_enabled_at' => null,
            'mfa_backup_codes' => null,
            'google2fa_ts' => null,
        ])->save();

        // AUDIT-P1-4.2 companion: disabling the account's primary defense
        // against credential stuffing is at least as security-relevant as
        // enabling it.
        AdminAuditLog::record('mfa.disabled', $user, [
            'had_mfa_since' => $hadMfaSince,
        ]);

        // Kill every MFA-related session artifact: verified flags (all
        // three keys, including the user binding), and any abandoned
        // enrollment secret.
        $request->session()->forget('mfa_verified');
        $request->session()->forget('mfa_verified_at');
        $request->session()->forget('mfa_verified_user_id');
        $request->session()->forget('mfa_pending_secret');
        $request->session()->forget('backup_codes');

        return redirect()->route('profile.edit')
            ->with('status', 'MFA has been disabled. You can enable it again anytime.');
    }

    // ── P3-7: Backup code helpers ──────────────────────────────────────

    /**
     * Generate 10 one-time backup codes.
     * Returns ['plaintext' => [...], 'hashed' => [...]].
     * Each code is 10 characters (groups of 5 separated by dash for display).
     *
     * ITERATION-11 (uniqueness): each code is guaranteed unique WITHIN its
     * set. Str::random() draws from random_bytes() (CSPRNG) over a 62-char
     * alphabet, so a collision inside a 10-code set is negligible — but a
     * duplicate would effectively be a TWO-USE code (consuming the first
     * hash would leave the twin hash behind, and the same plaintext would
     * verify again), so the guarantee is made explicitly rather than
     * assumed statistically.
     */
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

    /**
     * Try to use a backup code. If it matches, remove it from the array.
     * Returns true if a backup code matched.
     *
     * ITERATION-11 (REPLAY-RACE FIX): consumption is now ATOMIC. The
     * previous implementation did an unlocked read-modify-write against
     * the (possibly stale) in-memory user model, which broke single-use
     * semantics under concurrency:
     *
     *   - the SAME code submitted twice concurrently matched the same
     *     hash on both requests' stale reads → two MFA-verified sessions
     *     from one code;
     *   - two DIFFERENT codes submitted concurrently produced a lost
     *     update — A wrote [null, B] while B wrote [A, null] from the
     *     same pre-consumption snapshot, resurrecting the code A had
     *     just consumed.
     *
     * The read-check-write now runs inside a database transaction that
     * holds a row lock on the user (SELECT ... FOR UPDATE on the
     * production MySQL driver; SQLite serialises writers), and the
     * codes are re-read FRESH inside that transaction. A second request
     * therefore serialises behind the first and sees the consumed code
     * gone — the server, not browser state, is authoritative. The
     * consumption write and its audit entry commit atomically.
     */
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

                    // AUDIT-P1-4.3: Log backup code consumption. Each backup
                    // code is a one-time bypass of MFA — usage is security-
                    // relevant (could indicate lost device OR account takeover).
                    // Recorded INSIDE the transaction so the audit entry and
                    // the consumption commit — or roll back — together.
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

    // ── P3-8: MFA session timestamp ────────────────────────────────────

    /**
     * Mark the session as MFA-verified with a timestamp.
     * The RequireMfa middleware checks the flag, the timestamp AND the
     * user binding.
     *
     * ITERATION-6: the flag is bound to the authenticated user. PHP-facing
     * session data survives session ID regeneration (login flows call
     * session()->regenerate(), which rotates the ID but keeps the data),
     * so a later Auth::login() of a DIFFERENT user into the same session
     * would otherwise inherit the first user's MFA-verified state. The
     * middleware now rejects a flag stamped for someone else.
     */
    private function markMfaVerified(Request $request): void
    {
        $request->session()->put('mfa_verified', true);
        $request->session()->put('mfa_verified_at', now()->timestamp);
        $request->session()->put('mfa_verified_user_id', $request->user()->id);
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

        // ITERATION-6: user binding. Sessions stamped by markMfaVerified()
        // carry mfa_verified_user_id; a flag bound to a DIFFERENT
        // authenticated identity is rejected (session data survives session
        // ID regeneration, so a later Auth::login() into the same session
        // would otherwise inherit the first user's verified state).
        //
        // A flag WITHOUT a binding is treated as a legacy session (created
        // before this deploy): no production code path writes one anymore,
        // it is still bounded by the TTL below, and accepting it keeps the
        // existing session-embedding test conventions working unchanged.
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
