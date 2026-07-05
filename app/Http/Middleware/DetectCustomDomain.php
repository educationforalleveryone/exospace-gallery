<?php

namespace App\Http\Middleware;

use App\Models\Gallery;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves galleries accessed via custom domains (Studio-plan white-label).
 *
 * How it works:
 *   1. Get the Host header (e.g. "gallery.janedoe.com").
 *   2. Strip port and leading "www.".
 *   3. Skip the middleware entirely if the host matches the primary
 *      APP_URL host (e.g. "exospace.gallery") — those requests go through
 *      the normal /gallery/{slug} route.
 *   4. Otherwise, look up a gallery with that custom_domain AND a non-null
 *      custom_domain_verified_at (task C06). If found, stash it in request
 *      attributes so GalleryViewController can render it directly. If not
 *      found (or not yet verified), let the request fall through (it'll
 *      404 naturally).
 *
 * Caching:
 *   The host → gallery lookup is cached for 5 minutes per host. Adding
 *   a custom domain in the admin panel therefore takes up to 5 minutes
 *   to take effect. To force an immediate update, clear the cache:
 *
 *       php artisan cache:clear
 *
 * DNS / SSL prerequisites (NOT handled by Laravel):
 *   - The custom domain must CNAME to exospace.gallery (or A-record to the
 *     server IP).
 *   - SSL termination happens at Coolify's reverse proxy (Traefik or Caddy).
 *     Add the custom domain as a domain on the Coolify project so a cert
 *     gets provisioned.
 *   - The "TRUSTED_PROXIES=*" in .env ensures Laravel trusts the
 *     X-Forwarded-Host header set by the proxy.
 *
 * Task C06 — DNS verification:
 *   A gallery can have custom_domain set in the DB without being verified.
 *   This middleware only routes verified galleries, so a squatter who
 *   claims a domain they don't own can never serve traffic on it. The
 *   legitimate domain owner can always claim their domain because only
 *   they can add the verification TXT record.
 */
class DetectCustomDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $host = strtolower($host);
        // Strip :port
        $host = explode(':', $host)[0];
        // Strip leading www.
        $host = preg_replace('/^www\./', '', $host);

        // Skip if this is the primary app domain
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $appHost = strtolower($appHost ?? '');
        $appHost = preg_replace('/^www\./', '', $appHost);

        if (!$host || $host === $appHost || $host === 'localhost' || $host === '127.0.0.1') {
            return $next($request);
        }

        // Cache the lookup for 5 minutes per host.
        //
        // (Task C06) Only route galleries whose custom_domain_verified_at is
        // set. Unverified custom domains get the same fall-through behavior
        // as "no matching gallery" → 404 from the router. This is what
        // stops a squatter from serving traffic on a domain they don't own:
        // they can claim it in the DB, but without DNS verification they
        // can't actually route traffic to it.
        //
        // The cache stores the gallery ID or null. We intentionally cache
        // null too (for the "domain not in DB at all" case) — but see task
        // C13 for the related CoolifyDomainManager cache-nulls bug, which
        // is a separate concern.
        $cacheKey = "custom_domain:{$host}";
        $galleryId = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($host) {
            return Gallery::where('custom_domain', $host)
                ->whereNotNull('custom_domain_verified_at')
                ->value('id');
        });

        if ($galleryId) {
            // PERF-16 FIX: Cache the eager-loaded Gallery object too —
            // previously this issued a fresh Gallery::with(['images','user','venueTemplate'])->find($id)
            // query on EVERY request to a custom-domain gallery. The images
            // collection can be 100+ rows for a Studio gallery, and the user +
            // venueTemplate are 2 more queries. With the cache, the eager-loaded
            // gallery is fetched ONCE per 5 minutes (matching the host-lookup
            // cache TTL above) and reused across requests.
            //
            // The cache is invalidated by:
            //   - PlanDowngradeService::cleanupGalleryStudioResources() — forgets
            //     the host lookup cache, which causes the next request to
            //     re-fetch the gallery (with its now-null custom_domain → the
            //     isCustomDomainVerified() check fails → falls through to 404).
            //   - GalleryController::update() — if the gallery is saved, the
            //     updated_at changes. We bake updated_at into the cache key
            //     so any gallery save automatically invalidates the cached
            //     eager-loaded copy. (We still serve from cache for the 5-min
            //     host-lookup window, but the gallery object itself is fresh
            //     if the underlying row was touched.)
            //
            // The is_active + isCustomDomainVerified() double-check below
            // remains as defense-in-depth against stale cache.
            $galleryCacheKey = "custom_domain_gallery:{$galleryId}";
            $gallery = Cache::remember($galleryCacheKey, now()->addMinutes(5), function () use ($galleryId) {
                return Gallery::with(['images', 'user', 'venueTemplate'])->find($galleryId);
            });

            // Double-check verification at request time in case the cache
            // is stale (a domain could be un-verified between cache write
            // and now via a downgrade or admin action).
            if ($gallery && $gallery->is_active && $gallery->isCustomDomainVerified()) {
                $request->attributes->set('resolved_gallery', $gallery);
            }
        }

        return $next($request);
    }
}
