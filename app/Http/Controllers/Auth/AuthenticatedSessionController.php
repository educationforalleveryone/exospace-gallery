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
    public function create(Request $request): View
    {
        $redirect = $request->query('redirect');

        if (is_string($redirect) && $redirect !== '' && mb_strlen($redirect) <= 2048) {
            $redirect = str_replace('\\', '/', $redirect);

            if (preg_match('/[\x00-\x1F\x7F]/', $redirect) === 1) {
                return view('auth.login');
            }

            $redirect = trim($redirect);

            // Bare relative path (e.g. "billing/upgrade/pro") → prefix "/".
            if ($redirect !== '' && ! str_starts_with($redirect, '/')) {
                $redirect = '/'.$redirect;
            }

            if ($redirect !== ''
                && str_starts_with($redirect, '/')
                && ! str_starts_with($redirect, '//')
                && ! str_contains($redirect, '://')) {
                $request->session()->put('url.intended', $redirect);
            }
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
