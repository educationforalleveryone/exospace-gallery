<?php

namespace App\Http\Middleware;

use App\Models\Gallery;
use App\Support\ResilientCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        $cacheKey = "custom_domain:{$host}";
        $galleryId = ResilientCache::remember($cacheKey, now()->addMinutes(5), function () use ($host) {
            return Gallery::where('custom_domain', $host)
                ->whereNotNull('custom_domain_verified_at')
                ->value('id');
        });

        if ($galleryId) {
            // Fresh read on every request: its row stamps decide which cached
            // payload generation is valid. The owner's ban stamp rides along
            // so a banned user's exhibition drops off their domain without
            // waiting out the payload TTL. banned_at has no datetime cast on
            // the model, so the raw column value is used for the stamp.
            $g = Gallery::query()
                ->whereKey($galleryId)
                ->with(['venueTemplate:id,updated_at', 'user:id,banned_at'])
                ->first(['id', 'updated_at', 'venue_template_id', 'user_id']);
            $stamps = $g
                ? (($g->venueTemplate?->updated_at?->timestamp ?? '0') . ':' . $g->updated_at?->timestamp . ':' . ($g->user?->getRawOriginal('banned_at') ?? '0'))
                : 'none';
            $galleryCacheKey = "custom_domain_gallery:{$galleryId}:{$stamps}";
            $gallery = ResilientCache::remember($galleryCacheKey, now()->addMinutes(5), function () use ($galleryId) {
                // Match the eager-load contract of the gallery-view consumer
                // (images.artist + images.media) so a cache hit never triggers
                // per-image lazy loads while rendering.
                return Gallery::with(['images.artist', 'images.media', 'user', 'venueTemplate'])->find($galleryId);
            });

            if ($gallery && $gallery->is_active && $gallery->isCustomDomainVerified()
                && is_null($gallery->user?->banned_at)) {
                $request->attributes->set('resolved_gallery', $gallery);
            }
        }

        return $next($request);
    }
}
