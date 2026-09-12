<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('admin.dashboard', absolute: false).'?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        $intended = $request->session()->get('url.intended');
        $path = $intended ? (string) parse_url($intended, PHP_URL_PATH) : '';
        if (rtrim($path, '/') === '/dashboard') {
            $request->session()->forget('url.intended');
            $intended = null;
        }

        if ($intended) {
            return redirect()->to($intended)->with('status', 'email-verified');
        }

        return redirect()
            ->to(route('admin.dashboard', absolute: false).'?verified=1')
            ->with('status', 'email-verified');
    }
}
