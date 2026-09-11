<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     *
     * VERIFICATION-ITERATION FIX: the previously decorative `?verified=1`
     * query parameter had no consumer (nothing read it) and was dropped by
     * the /dashboard → admin.dashboard hop anyway, so a successful
     * verification landed on the dashboard with NO acknowledgment. The
     * controller now flashes `status: email-verified`, which the app's
     * unified toast component (resources/views/components/toast.blade.php,
     * flashLabels map) renders as a success toast on the landing page.
     *
     * Redirect semantics: a stored intended destination (the page the user
     * was bounced from by the verified middleware, or a pre-signup
     * ?redirect= destination) still wins; the default fallback is
     * admin.dashboard directly (one hop, so the flash survives to render).
     * An intended /dashboard is normalized to the same one-hop target
     * because the alias route is itself a redirect that would otherwise
     * age the flash out. The already-verified branch stays silent and
     * idempotent.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            // Already verified — silent idempotent redirect, no repeated
            // success toast for a re-clicked or replayed link.
            return redirect()->intended(route('admin.dashboard', absolute: false).'?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        // Resolve the landing target. A previously stored intended
        // destination wins (the page the user was bounced from by the
        // verified middleware, or a pre-signup ?redirect= destination).
        // EXCEPTION: the /dashboard ALIAS route is itself a redirect to
        // admin.dashboard — routing the flash through that second hop ages
        // the success flash out before the landing page renders, so an
        // intended /dashboard is treated as the plain dashboard target.
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
