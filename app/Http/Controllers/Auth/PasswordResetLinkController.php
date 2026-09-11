<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * RESET-ITERATION FIX (account enumeration): the previous implementation
     * surfaced the broker's status verbatim, so an unknown email rendered
     * "We can't find a user with that email address." while a known one
     * rendered the success message — letting anyone probe which emails have
     * Exospace accounts (the same leak class removed from the login flow in
     * the login iteration).
     *
     * The response is now identical regardless of account existence. This
     * costs nothing for real users (the link is only ever emailed to the
     * account owner) and reveals nothing to attackers. RESET_THROTTLED is
     * masked as well: the broker only throttles emails that resolved to an
     * existing user, so showing "Please wait before retrying" would also
     * leak existence. IP-level abuse is already handled by the route's
     * throttle:5,60 middleware.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Attempt the send, but never branch the user-facing response on the
        // outcome. Laravel's broker performs the enumeration-sensitive work
        // (user lookup, per-email throttle, token generation, email dispatch)
        // and always returns a status here; real failures to hand off to the
        // mail transport throw, so a returned status means "handled".
        Password::sendResetLink(
            $request->only('email')
        );

        return back()->with('status', __(
            'If an account exists for that email address, a password reset link is on its way.'
        ));
    }
}
