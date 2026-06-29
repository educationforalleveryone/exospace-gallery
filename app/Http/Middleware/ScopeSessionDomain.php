<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dynamically scopes the session cookie domain to the request host.
 *
 * THE BUG THIS FIXES
 * ------------------
 * `SESSION_DOMAIN=exospace.gallery` in .env scopes the session cookie to
 * the primary domain. When a visitor hits `https://gallery.janedoe.com`
 * (a Studio-plan custom domain), the session cookie is set on
 * `.exospace.gallery` — which the browser refuses to send back on the
 * next request to `gallery.janedoe.com`. Result: PIN verification
 * (`session("pin_verified_{$gallery->id}")`) doesn't persist across
 * page reloads, and visitors get redirected to the PIN screen in a loop.
 *
 * THE FIX
 * -------
 * This middleware runs BEFORE the session middleware (registered as
 * `prepend()` in bootstrap/app.php so it's first in the stack). It
 * inspects the Host header:
 *
 *   - If the host is the primary APP_URL host (or localhost, or a
 *     known CDN host) — leave SESSION_DOMAIN alone (default behaviour).
 *
 *   - If the host is anything else — assume it's a custom domain and
 *     override `config('session.domain')` at runtime to that host.
 *     The session middleware (which runs later) reads from
 *     `config('session.domain')` to set the cookie domain, so the
 *     cookie is now scoped to the visitor's actual host and persists
 *     correctly.
 *
 * Edge case: This middleware is also safe for embed mode (?embed=1),
 * because embed mode skips PIN entirely.
 *
 * Edge case: The CSRF token cookie is also affected — it's scoped via
 * the same `session.domain` config, so it gets fixed too.
 */
class ScopeSessionDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $host = strtolower($host);
        $host = explode(':', $host)[0];
        $host = preg_replace('/^www\./', '', $host);

        // Resolve the primary app host from APP_URL
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $appHost = strtolower($appHost ?? '');
        $appHost = preg_replace('/^www\./', '', $appHost);

        // Skip on primary domain, localhost, or IP addresses
        if (!$host
            || $host === $appHost
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || filter_var($host, FILTER_VALIDATE_IP)
        ) {
            return $next($request);
        }

        // This is a custom-domain request. Override the session domain
        // at runtime so the cookie is scoped to the visitor's host.
        // The leading dot allows the cookie to be sent to www. subdomains
        // too (browsers ignore the leading dot for modern cookies but
        // it's harmless and backward-compatible).
        config(['session.domain' => '.' . $host]);

        // Also fix the Sanctum stateful domain if Sanctum is in use,
        // so API requests from the custom domain are treated as
        // first-party (no CORS preflight, cookie accepted).
        $sanctumStateful = config('sanctum.stateful');
        if (is_array($sanctumStateful)) {
            if (!in_array($host, $sanctumStateful, true)) {
                $sanctumStateful[] = $host;
                config(['sanctum.stateful' => $sanctumStateful]);
            }
        }

        return $next($request);
    }
}
