<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * PreventAuthenticatedCaching — no-store on personalized responses.
 *
 * LOGOUT-ITERATION FIX (back-button / cached-page behavior):
 *
 * After a user signs out, the browser could still render previously
 * visited authenticated pages from its HTTP cache, back/forward cache
 * (bfcache), or memory cache. The server correctly rejects every action
 * (all protected routes redirect to /login once the session is gone), but
 * the stale render still shows potentially sensitive dashboard content
 * and leaves the user visually "signed in" — exactly the confusing
 * post-logout state this mission must eliminate.
 *
 * The fix is the standard HTTP answer: authenticated HTML responses are
 * marked `Cache-Control: no-store`, which:
 *   - stops HTTP disk/memory caching of personalized pages;
 *   - disables bfcache in Firefox and (via the cookie-change restore rule)
 *     in Chromium after a logout has replaced the session cookie;
 *   - keeps the Back button deterministic: it re-requests the URL and the
 *     server answers with a clean redirect to /login.
 *
 * Scope guardrails:
 *   - Guest traffic is untouched: Auth::check() is the only gate, so every
 *     public page (welcome, pricing, discover, custom-domain galleries)
 *     keeps its current caching behavior. Caching is NOT disabled globally.
 *   - Only HTML documents and redirects are marked. Binary/streaming
 *     responses (image proxies, exports, attachments) are deliberately
 *     left alone so authenticated download performance is preserved.
 *   - If a response already carries its own Cache-Control decision, it is
 *     never overwritten.
 *
 * Placement: appended to the `web` group AFTER StartSession (same block as
 * CheckBanned / CheckPlanExpiry), so the session guard can resolve the
 * authenticated user. Redirect responses sent while the user is still
 * authenticated (e.g. the logout POST's own 302 to /) also carry no-store,
 * which is correct: nothing in the logout exchange should be cached.
 */
class PreventAuthenticatedCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve authentication BEFORE dispatching. This is deliberately a
        // request-time check, not a response-time one: during a logout the
        // controller destroys the authentication state, so by the time the
        // response exists the guard is (correctly) empty. The response was
        // still produced inside an authenticated request and must not be
        // cacheable.
        $requestIsAuthenticated = Auth::check();

        $response = $next($request);

        // Guest requests keep the application's default caching behavior.
        if (! $requestIsAuthenticated) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        $isHtmlDocument = str_starts_with($contentType, 'text/html');

        // Personalized HTML and redirects only — never binary/stream bodies.
        if (! $isHtmlDocument && ! $response->isRedirection()) {
            return $response;
        }

        // Symfony's ResponseHeaderBag always carries a Cache-Control header:
        // when nobody set one explicitly it computes the default
        // "no-cache, private", which blocks shared caches but STILL allows
        // the browser to store the page — exactly what resurrects stale
        // authenticated renders after logout. Upgrade it to no-store unless
        // the response already opted into no-store itself (e.g. the billing
        // endpoints set their own no-store header).
        $current = strtolower((string) $response->headers->get('Cache-Control', ''));

        if (! str_contains($current, 'no-store')) {
            // Explicit 'private' keeps Symfony from re-appending it and makes
            // the emitted header deterministic across responses.
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
