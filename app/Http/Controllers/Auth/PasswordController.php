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
                ->errorBag('updatePassword')
                ->redirectTo(back()->getTargetUrl());
        }

        // D-4 FIX: Use shared helper to store old password in history + prune
        $user->storePasswordInHistory();

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        $request->session()->regenerate();

        // ITERATION-8: compromise signal to the account's inbox (queued).
        Mail::to($user->email)->send(new PasswordChangedNoticeMail($user));

        AdminAuditLog::record('password_changed', $user);

        return back()->with('status', 'password-updated');
    }
}
