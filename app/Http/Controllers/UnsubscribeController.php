<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * P0-3 FIX (audit): One-click unsubscribe endpoint for marketing emails.
 *
 * CAN-SPAM §316.4 requires "a clear and conspicuous explanation of how
 * the recipient can opt out" in every commercial email. GDPR Art. 21
 * requires the right to object to marketing processing.
 *
 * The unsubscribe link in every marketing email points here:
 *   GET /unsubscribe/{user}?signature=...
 *
 * The route is protected by Laravel's `signed` middleware — the signature
 * is HMAC-signed with the APP_KEY, so only the app can generate valid
 * unsubscribe links. An attacker cannot forge a link to unsubscribe
 * another user.
 *
 * Flow:
 *   1. Email recipient clicks the unsubscribe link.
 *   2. The `signed` middleware verifies the signature.
 *   3. This controller shows a confirmation page (GET) — the user sees
 *      "You're about to unsubscribe" with a confirm button.
 *   4. The user clicks "Confirm" → POST /unsubscribe/{user} sets
 *      marketing_consent = false.
 *   5. The user sees "You've been unsubscribed" confirmation.
 *
 * Why a two-step (GET + POST) flow:
 *   - Email clients sometimes pre-fetch links (anti-phishing). A single-
 *     step GET that immediately unsubscribes would be triggered by the
 *     pre-fetch, unsubscribing the user without their click. The POST
 *     step requires a real user action.
 *   - The GET page also lets the user see what they're unsubscribing
 *     from, preventing accidental unsubscribes from forwarded emails.
 */
class UnsubscribeController extends Controller
{
    /**
     * Show the unsubscribe confirmation page.
     * The route middleware ('signed') has already verified the signature.
     */
    public function show(Request $request, User $user): View
    {
        // If already unsubscribed, show a friendly "already unsubscribed" page.
        if (! $user->marketing_consent) {
            return view('unsubscribe', [
                'user'     => $user,
                'already'  => true,
            ]);
        }

        return view('unsubscribe', [
            'user'     => $user,
            'already'  => false,
        ]);
    }

    /**
     * Process the unsubscribe confirmation.
     * Sets marketing_consent = false on the user.
     */
    public function confirm(Request $request, User $user): RedirectResponse
    {
        $user->forceFill(['marketing_consent' => false])->save();

        return redirect()->route('unsubscribe.done')
                         ->with('status', 'unsubscribed');
    }

    /**
     * Show the "You've been unsubscribed" confirmation page.
     */
    public function done(Request $request): View
    {
        $status = session('status');

        // Only show the success page if the user came from the confirm flow
        // (which sets 'status' => 'unsubscribed' in the session).
        // Direct access to /unsubscribe-done without the session flag shows
        // an "invalid request" page.
        if (! in_array($status, ['unsubscribed', 'already_unsubscribed'], true)) {
            return view('unsubscribe-done', ['valid' => false]);
        }

        return view('unsubscribe-done', ['valid' => true]);
    }
}
