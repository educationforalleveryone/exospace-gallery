<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * D-4 FIX (Iter-004): Now checks password history (reuse prevention)
     * AND stores the old password in history. Previously, the forgot-password
     * reset flow did NOT check password_histories — an attacker (or a user
     * complying with a rotation policy) could bypass the reuse check by
     * going through /forgot-password and setting their new password to be
     * identical to their last 5 passwords.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                // D-4 FIX: Check new password against last 5 historical passwords.
                // This mirrors the check in PasswordController::update() (the
                // profile password-change flow). Without this, the forgot-password
                // flow bypasses the reuse-prevention rule.
                if ($user->isPasswordInHistory($request->password)) {
                    throw ValidationException::withMessages([
                        'password' => 'You cannot reuse one of your last 5 passwords. Please choose a different password.',
                    ])->redirectTo(back()->getTargetUrl());
                }

                // D-4 FIX: Store the current password hash in history BEFORE
                // updating. This mirrors the behavior in PasswordController.
                $user->storePasswordInHistory();

                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                    // C-2 FIX (Iter-001): maintain has_password column
                    'has_password' => true,
                    'password_set_at' => now(),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
