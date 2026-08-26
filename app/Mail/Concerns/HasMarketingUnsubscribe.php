<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Models\User;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * Iteration-007 (audit issues 9 + 10 / RFC 8058 + CAN-SPAM).
 *
 * Gmail and Yahoo enforce RFC 8058 ("One-Click Unsubscribe") for any sender
 * exceeding ~5,000 emails/day since February 2024. Non-compliance causes
 * deferral or rejection of marketing email. The previous codebase had a
 * GET/POST unsubscribe flow but:
 *   - Did NOT emit `List-Unsubscribe` or `List-Unsubscribe-Post` headers
 *     on any mailable, so Gmail/Yahoo never displayed the one-click button.
 *   - The POST endpoint required a CSRF token, so RFC 8058 mailto-style
 *     automated POSTs from Gmail would 419. RFC 8058 explicitly requires
 *     the POST to succeed without auth or CSRF.
 *   - No mailable passed `$unsubscribeUrl` to the layout, so the visible
 *     "Unsubscribe" link in the email footer was never rendered.
 *
 * This trait provides:
 *   - `unsubscribeHeaders(User $user): array` — returns the two RFC 8058
 *     headers for use in a Mailable's `envelope()` method.
 *   - `unsubscribeUrl(User $user): string` — returns the signed HTTPS URL
 *     for the visible footer link and the List-Unsubscribe header.
 *
 * The signed URL points at /unsubscribe/one-click/{user}?signature=...
 * which is a NEW route (added in routes/web.php) that:
 *   - Accepts POST without CSRF (excluded from VerifyCsrfToken)
 *   - Verifies the signed URL (HMAC with APP_KEY)
 *   - Sets marketing_consent = false
 *   - Returns HTTP 200 (RFC 8058 §3 — response should be 2xx)
 *
 * The link also works as a GET (browser visit) — the existing two-step
 * flow at /unsubscribe/{user} is preserved for the visible footer link,
 * so a user who clicks "Unsubscribe" in the email body still sees a
 * confirmation page. The List-Unsubscribe header is a separate URL that
 * Gmail's "Unsubscribe" button hits directly via POST.
 *
 * Reference: https://datatracker.ietf.org/doc/html/rfc8058
 */
trait HasMarketingUnsubscribe
{
    /**
     * RFC 8058 headers for every mailable using this trait.
     *
     * ITERATION-1 P0 FIX: the original implementation passed
     * `headers: [...]` as a named argument to `Envelope::__construct()` —
     * but the Envelope constructor has NO `headers` parameter, so sending
     * ANY of the six lifecycle emails threw
     * `Error: Unknown named parameter $headers` inside the queue worker.
     * In production (QUEUE_CONNECTION=redis) this silently killed the
     * ENTIRE lifecycle email chain: welcome, first-gallery, abandoned-cart,
     * inactive nudge, plan-expiring and plan-upgraded emails never sent.
     *
     * Laravel's supported mechanism for custom headers on a Mailable is a
     * `headers(): Headers` method — Mailable::ensureHeadersIsHydrated()
     * detects it and applies every entry of Headers::$text to the Symfony
     * message. Defining that method HERE means every mailable that uses
     * the trait emits the RFC 8058 headers automatically, with zero
     * per-mailable boilerplate.
     *
     * The mailable must expose `public User $user` (all six do).
     */
    public function headers(): Headers
    {
        return new Headers(
            text: $this->unsubscribeHeaders($this->user),
        );
    }

    /**
     * Build the signed one-click unsubscribe URL for the given user.
     *
     * This URL is used in BOTH the List-Unsubscribe header AND the
     * visible footer link. Using the same URL means a user who clicks
     * the footer link AND a user who clicks Gmail's "Unsubscribe" button
     * hit the same endpoint — easier to audit and debug.
     */
    protected function unsubscribeUrl(User $user): string
    {
        // Signed URL with 1-year expiry. Marketing emails may sit in a
        // user's inbox for weeks before they click; a short expiry would
        // produce 403 errors and frustrate the user. 1 year exceeds any
        // reasonable inbox retention.
        return URL::signedRoute('unsubscribe.one-click', ['user' => $user->id], now()->addYear());
    }

    /**
     * Return the RFC 8058 headers for the mailable.
     *
     * ITERATION-1 P0 FIX: do NOT pass this array as a named `headers:`
     * argument to `Envelope::__construct()` — that parameter does not
     * exist and throws `Unknown named parameter $headers` at send time.
     * The headers() method above is the supported application path; this
     * helper only builds the raw header map.
     *
     * @return array{List-Unsubscribe: string, List-Unsubscribe-Post: string}
     */
    protected function unsubscribeHeaders(User $user): array
    {
        $url = $this->unsubscribeUrl($user);

        // RFC 8058 §2: List-Unsubscribe-Post MUST be exactly the literal
        // "List-Unsubscribe=One-Click" for Gmail/Yahoo to recognize it.
        // RFC 2369 §3: List-Unsubscribe may contain a mailto: and/or https:
        // URL. We use https: only — mailto: requires the user's email
        // client to compose a message, which Gmail/Yahoo do not use for
        // the one-click button.
        return [
            'List-Unsubscribe'        => "<{$url}>",
            'List-Unsubscribe-Post'   => 'List-Unsubscribe=One-Click',
        ];
    }
}
