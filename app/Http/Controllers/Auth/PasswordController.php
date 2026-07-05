<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();

        // P3-9: Check new password against last 5 historical passwords
        $recentHashes = DB::table('password_histories')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->pluck('password_hash');

        foreach ($recentHashes as $oldHash) {
            if (Hash::check($validated['password'], $oldHash)) {
                throw ValidationException::withMessages([
                    'password' => 'You cannot reuse one of your last 5 passwords. Please choose a different password.',
                ])->redirectTo(back()->getTargetUrl());
            }
        }

        // P3-9: Store the current password hash in history before updating
        DB::table('password_histories')->insert([
            'user_id' => $user->id,
            'password_hash' => $user->getOriginal('password'),
            'created_at' => now(),
        ]);

        // Prune history to last 10 entries (keep 5 for checking + buffer)
        DB::table('password_histories')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->offset(10)
            ->delete();

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('status', 'password-updated');
    }
}
