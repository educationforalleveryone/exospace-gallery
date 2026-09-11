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
     *
     * LOGIN-ITERATION HARDENING: the sanitization below is now an explicit
     * ALLOW-LIST (was: two subtly-coupled starts_with/filter_var checks).
     * Accepted: relative paths only — optionally auto-prefixed with "/"
     * for bare relative targets like "billing/upgrade/pro". Rejected
     * (falls back to the default dashboard redirect):
     *   - absolute URLs (https://evil.example) — unchanged behavior;
     *   - protocol-relative URLs (//evil.example) — unchanged behavior;
     *   - backslash-confused targets (/\evil.example) — previously stored,
     *     now rejected up front: WHATWG URL parsing treats "\" as "/", so
     *     scheme-relative forms must never reach the intended URL;
     *   - values containing control characters (CR/LF/NUL) — rejected
     *     outright (a bare relative path never contains them) so nothing
     *     header-injection-shaped can ever be persisted into the session;
     *   - absurd lengths (> 2048 chars).
     */
    public function create(Request $request): View
    {
        $redirect = $request->query('redirect');

        if (is_string($redirect) && $redirect !== '' && mb_strlen($redirect) <= 2048) {
            // Normalize backslash separators ("/\evil" → "//evil" so the
            // scheme-relative check below sees it).
            $redirect = str_replace('\\', '/', $redirect);

            // Reject (rather than silently strip) anything containing
            // control characters (CR/LF/NUL/...): legitimate paths never
            // contain them, and stripping would leave a garbage path
            // stored as the user's intended destination.
            if (preg_match('/[\x00-\x1F\x7F]/', $redirect) === 1) {
                return view('auth.login');
            }

            $redirect = trim($redirect);

            // Bare relative path (e.g. "billing/upgrade/pro") → prefix "/".
            if ($redirect !== '' && ! str_starts_with($redirect, '/')) {
                $redirect = '/'.$redirect;
            }

            // Accept only same-site relative paths: exactly one leading
            // slash, never "//" (protocol-relative), never an absolute URL.
            if ($redirect !== ''
                && str_starts_with($redirect, '/')
                && ! str_starts_with($redirect, '//')
                && ! str_contains($redirect, '://')) {
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
