<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * CONV-6: If a ?redirect= query param is present, store it as the
     * session's intended URL so redirect()->intended() honors it after
     * successful login. This lets the pricing page deep-link to
     * /login?redirect=billing/upgrade/pro so the user lands on the
     * 2Checkout checkout page immediately after authenticating.
     */
    public function create(Request $request): View
    {
        $redirect = $request->query('redirect');
        if ($redirect && is_string($redirect)) {
            // Sanitize: only allow relative paths (no protocol/host) to
            // prevent open-redirect attacks.
            if (! str_starts_with($redirect, '/') && ! filter_var($redirect, FILTER_VALIDATE_URL)) {
                $redirect = '/' . ltrim($redirect, '/');
            }
            // Only accept relative paths (no http://, https://, //evil.com)
            if (str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
                $request->session()->put('url.intended', $redirect);
            }
        }

        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
