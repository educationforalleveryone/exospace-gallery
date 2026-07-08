<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            ])->redirectTo(back()->getTargetUrl());
        }

        // D-4 FIX: Use shared helper to store old password in history + prune
        $user->storePasswordInHistory();

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('status', 'password-updated');
    }
}
