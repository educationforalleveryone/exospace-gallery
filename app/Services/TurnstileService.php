<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * P3-19: Cloudflare Turnstile verification service.
 *
 * Turnstile is Cloudflare's privacy-respecting CAPTCHA alternative —
 * invisible to most users (no "select the traffic lights" puzzle),
 * free, and verifies via a server-side siteverify call.
 *
 * Why Turnstile over reCAPTCHA:
 *   - No Google tracking (GDPR-friendlier)
 *   - Free with no quotas
 *   - Invisible by default (managed challenge mode) — better UX
 *   - One line of HTML + one server-side call
 *
 * Configuration:
 *   Set in .env:
 *     TURNSTILE_SITE_KEY=1x00000000000000000000AA   (public, in HTML)
 *     TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA  (private, server)
 *
 *   Get keys at: https://dash.cloudflare.com/?to=/:account/turnstile
 *
 *   When BOTH keys are empty/unset (the default), the service is DISABLED —
 *   verify() returns true unconditionally. This is the safe default for local
 *   dev (no captcha prompts) and lets the founder opt-in by setting the keys.
 *
 * Usage in a controller:
 *
 *   if (! app(TurnstileService::class)->verify($request->input('cf-turnstile-response'))) {
 *       return back()->withErrors(['captcha' => 'Please complete the captcha.']);
 *   }
 *
 * Usage in a Blade view (must include the script + the div):
 *
 *   <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
 *   <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
 */
class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct() {}

    /**
     * Verify a Turnstile token from the request.
     *
     * @param  string|null  $token  The cf-turnstile-response field from the form.
     *                              Null/empty when Turnstile is not rendered or
     *                              the user didn't trigger it.
     * @param  string|null  $remoteIp  The visitor's IP (optional, used by
     *                                  Cloudflare for analytics only).
     * @return bool  True if the token is valid OR if Turnstile is disabled
     *               (no site_key configured). False if the token is invalid.
     */
    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        $secretKey = config('services.turnstile.secret_key');

        // If Turnstile is not configured, allow all (fail-open for dev).
        // This is intentional: the founder must opt-in by setting keys.
        // If you want fail-closed (block all when unconfigured), change the
        // default to false here.
        if (! $secretKey) {
            return true;
        }

        if (! $token) {
            // Token is missing but Turnstile IS configured → reject.
            return false;
        }

        try {
            $response = Http::asForm()->post(self::VERIFY_URL, [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => $remoteIp,
            ]);

            $body = $response->json();

            if (! is_array($body) || ! isset($body['success'])) {
                Log::warning('TurnstileService: unexpected response shape', [
                    'status' => $response->status(),
                    'body'   => $body,
                ]);
                return false;
            }

            if (! $body['success']) {
                Log::info('TurnstileService: verification failed', [
                    'errors'   => $body['error-codes'] ?? [],
                    'remoteip' => $remoteIp,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // Network error contacting Cloudflare — fail-open so we don't
            // block all form submissions during a Cloudflare outage.
            // Log at warning level so ops can see the rate of failures.
            Log::warning('TurnstileService: siteverify call failed (fail-open)', [
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Is Turnstile enabled (i.e., is a site_key configured)?
     * Used by Blade views to conditionally render the widget + script.
     */
    public function isEnabled(): bool
    {
        return (bool) config('services.turnstile.site_key');
    }
}
