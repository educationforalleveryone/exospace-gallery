<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Generates sitemap.xml and RSS feed for publicly-viewable galleries.
 *
 * Routes:
 *   GET /sitemap.xml   — sitemap index of public galleries
 *   GET /feed.xml      — RSS 2.0 feed of recently-updated galleries
 *
 * Both endpoints are cached for 30 minutes to keep DB load low. Cache is
 * keyed on the locale; force-refresh with `php artisan cache:clear`.
 */
class SitemapController extends Controller
{
    public function sitemap(): Response
    {
        $galleries = Cache::remember('sitemap:galleries', now()->addMinutes(30), function () {
            return Gallery::publiclyViewable()
                ->with('venueTemplate')
                ->has('images', '>=', 1)
                ->orderByDesc('updated_at')
                ->limit(500)
                ->get(['slug', 'title', 'updated_at']);
        });

        return response()
            ->view('sitemap', compact('galleries'))
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function feed(): Response
    {
        $galleries = Cache::remember('feed:galleries', now()->addMinutes(30), function () {
            return Gallery::publiclyViewable()
                ->with(['coverImage', 'user', 'venueTemplate'])
                ->has('images', '>=', 1)
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get();
        });

        return response()
            ->view('feed', compact('galleries'))
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}
