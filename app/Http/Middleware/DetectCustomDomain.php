<?php

namespace App\Http\Middleware;

use App\Models\Gallery;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $galleryId = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($host) {
            return Gallery::where('custom_domain', $host)
                ->whereNotNull('custom_domain_verified_at')
                ->value('id');
        });

        if ($galleryId) {
            $g = Gallery::query()
                ->whereKey($galleryId)
                ->with('venueTemplate:id,updated_at')
                ->first(['id', 'updated_at', 'venue_template_id']);
            $stamps = $g ? (($g->venueTemplate?->updated_at?->timestamp ?? '0') . ':' . $g->updated_at?->timestamp) : 'none';
            $galleryCacheKey = "custom_domain_gallery:{$galleryId}:{$stamps}";
            $gallery = Cache::remember($galleryCacheKey, now()->addMinutes(5), function () use ($galleryId) {
                return Gallery::with(['images', 'user', 'venueTemplate'])->find($galleryId);
            });

            if ($gallery && $gallery->is_active && $gallery->isCustomDomainVerified()
                && is_null($gallery->user?->banned_at)) {
                $request->attributes->set('resolved_gallery', $gallery);
            }
        }

        return $next($request);
    }
}
