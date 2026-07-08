<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Gallery;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
 *   - If the host is a VERIFIED custom domain — override
 *     `config('session.domain')` at runtime to that host. The session
 *     middleware (which runs later) reads from `config('session.domain')`
 *     to set the cookie domain, so the cookie is now scoped to the
 *     visitor's actual host and persists correctly.
 *
 *   - If the host is NOT a verified custom domain — return 404. This
 *     prevents brand-impersonation attacks where an attacker points an
 *     arbitrary domain at the server and Exospace serves its HTML on
 *     that domain.
 *
 * ITERATION-004 FIX (audit D-1): Previously, this middleware trusted ANY
 * non-primary Host header and overrode the session domain for it. An
 * attacker who registers `evil-gallery.com` and A-records it at the
 * Exospace server IP got their host added to `sanctum.stateful` and
 * got cookie scope `.evil-gallery.com` — brand impersonation +
 * credentialed-API first-party treatment for `evil-gallery.com`.
 *
 * The fix consults the same `custom_domain_verified_at` lookup as
 * `DetectCustomDomain`. Only verified custom domains get the cookie-scope
 * override. Unverified/non-existent hosts get 404.
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

        // D-1 FIX (Iter-004): Verify the host is a VERIFIED custom domain
        // before overriding the session domain. This prevents brand-
        // impersonation attacks where an attacker points an arbitrary
        // domain at the server.
        //
        // We use the same cache key as DetectCustomDomain so the lookup
        // is shared (5-min TTL). The lookup checks for a gallery with
        // custom_domain = $host AND custom_domain_verified_at IS NOT NULL.
        $cacheKey = "custom_domain:{$host}";
        $galleryId = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($host) {
            return Gallery::where('custom_domain', $host)
                ->whereNotNull('custom_domain_verified_at')
                ->value('id');
        });

        if (! $galleryId) {
            // D-1 FIX: The host is NOT a verified custom domain. Return 404
            // to prevent brand impersonation. Do NOT serve Exospace content
            // on unverified hostnames.
            //
            // We log the rejected host for monitoring (helps detect
            // scanning/abuse attempts), but we don't expose any info to
            // the requester.
            \Illuminate\Support\Facades\Log::info('ScopeSessionDomain: rejected unverified host', [
                'host' => $host,
                'ip'   => $request->ip(),
            ]);

            return response()->make('', 404);
        }

        // This is a VERIFIED custom-domain request. Override the session
        // domain at runtime so the cookie is scoped to the visitor's host.
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
