<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordChangedNoticeMail;
use App\Models\AdminAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     *
     * P3-9: Checks the new password against the last 5 password hashes
     * stored in password_histories. Prevents password reuse.
     *
     * D-4 FIX (Iter-004): Refactored to use the shared User::isPasswordInHistory()
     * and User::storePasswordInHistory() helpers. The logic is now identical
     * to NewPasswordController::store() (the forgot-password reset flow),
     * ensuring consistent enforcement of the reuse-prevention rule.
     *
     * ITERATION-8 (ship-readiness: the authenticated password-change flow):
     *
     *  1. Visible reuse error — the history-rejection exception was thrown
     *     into the DEFAULT error bag while the profile form renders only the
     *     'updatePassword' bag, so a rejected reuse attempt silently did
     *     nothing from the user's point of view. It now lands in the same
     *     bag the form already displays.
     *  2. Remember-me cycle — the reset flow (NewPasswordController) cycles
     *     remember_token on every password change; the authenticated change
     *     flow did not. A change now cycles it too, so remembered stolen
     *     devices cannot outlive the credential. The current session stays
     *     logged in (the app's documented model: a change never kills active
     *     sessions — there is no AuthenticateSession middleware in this app,
     *     so no session-invalidation product is introduced here either).
     *  3. Pending reset links die — a password-reset link requested BEFORE
     *     the change must not outlive it (the freed credential must not
     *     carry a valid broker token). Mirrors the email-change iteration's
     *     stale-token purge in ProfileController.
     *  4. Session ID regeneration after the change, matching the app's own
     *     login/registration convention (fresh ID on credential transitions).
     *  5. Security notice — PasswordChangedNoticeMail (branded, queued,
     *     link-free) is sent to the account's email address: if the change
     *     was made by a hijacked session, the real owner's inbox is the
     *     channel the attacker did not capture.
     *  6. Audit — 'password_changed' joins the established self-service
     *     security-action trail (mfa.enabled / mfa.disabled / email_changed).
     *     The auto-captured dirty attributes (password hash, remember token)
     *     pass through AdminAuditLog's PII scrubbing, and 'password_changed'
     *     is not on SendSuperAdminActionAlert's destructive list, so no
     *     super-admin email is triggered.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();

        // D-4 FIX: Use shared helper (mirrors NewPasswordController::store)
        if ($user->isPasswordInHistory($validated['password'])) {
            throw ValidationException::withMessages([
                'password' => 'You cannot reuse one of your last 5 passwords. Please choose a different password.',
            ])
                // ITERATION-8: the form renders the 'updatePassword' bag —
                // without this the rejection is invisible to the user.
                ->errorBag('updatePassword')
                ->redirectTo(back()->getTargetUrl());
        }

        // D-4 FIX: Use shared helper to store old password in history + prune
        $user->storePasswordInHistory();

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            // ITERATION-8: cycle the remember token so remembered devices
            // cannot survive the credential change (parity with the reset
            // flow). Guarded field — trusted authenticated context, the
            // documented forceFill pattern.
            'remember_token' => Str::random(60),
        ])->save();

        // ITERATION-8: any reset link issued for the OLD credential must not
        // outlive it — the address keeps a pending broker token otherwise.
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        // ITERATION-8: fresh session ID after a credential transition (same
        // convention as login/registration). Data is preserved, so the user
        // stays authenticated and the flash below still renders.
        $request->session()->regenerate();

        // ITERATION-8: compromise signal to the account's inbox (queued).
        Mail::to($user->email)->send(new PasswordChangedNoticeMail($user));

        // ITERATION-8: forensic visibility, matching the mfa.enabled /
        // email_changed precedent. No sensitive values passed — the dirty
        // attribute capture is PII-scrubbed by AdminAuditLog itself.
        AdminAuditLog::record('password_changed', $user);

        return back()->with('status', 'password-updated');
    }
}
