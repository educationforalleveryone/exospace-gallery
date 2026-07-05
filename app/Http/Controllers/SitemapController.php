<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\Gallery;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Generates sitemap.xml and RSS feed for publicly-viewable galleries.
 *
 * Routes:
 *   GET /sitemap.xml   — sitemap of public galleries + static pages + artist profiles
 *   GET /feed.xml      — RSS 2.0 feed of recently-updated galleries
 *
 * Both endpoints are cached for 30 minutes to keep DB load low. Cache is
 * keyed on the locale; force-refresh with `php artisan cache:clear`.
 *
 * (Task H13 / audit H38) — previously the sitemap only included galleries
 * + 3 static pages. Now includes:
 *   - All static pages (/, /discover, /pricing, /about, /contact, /privacy,
 *     /terms, /refund-policy, /payment-security)
 *   - All public galleries (was already included)
 *   - All artist profiles that have at least one publicly-viewable image
 *     (was missing)
 */
class SitemapController extends Controller
{
    /**
     * S-4 FIX: Sitemap index — paginates galleries into chunks of 500 URLs
     * per sub-sitemap. Google's per-sitemap cap is 50,000 URLs.
     * Route: GET /sitemap.xml (index) → links to /sitemap-{page}.xml
     * Route: GET /sitemap-{page}.xml (sub-sitemap with galleries)
     */
    public function sitemapIndex(): Response
    {
        $totalGalleries = Cache::flexible('sitemap:gallery-count', [now()->addMinutes(30), now()->addMinutes(60)], function () {
            return Gallery::publiclyViewable()->has('images', '>=', 1)->count();
        });

        $perPage = 500;
        $pages = (int) ceil($totalGalleries / $perPage);

        return response()
            ->view('sitemap-index', ['pages' => $pages, 'perPage' => $perPage])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function sitemapPage(int $page): Response
    {
        $perPage = 500;
        $offset = ($page - 1) * $perPage;

        $galleries = Cache::flexible("sitemap:galleries:{$page}", [now()->addMinutes(30), now()->addMinutes(60)], function () use ($perPage, $offset) {
            return Gallery::publiclyViewable()
                ->with('venueTemplate')
                ->has('images', '>=', 1)
                ->orderByDesc('updated_at')
                ->skip($offset)
                ->take($perPage)
                ->get(['slug', 'title', 'updated_at']);
        });

        $staticPages = [
            ['url' => route('welcome'), 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['url' => route('discover'), 'priority' => '0.9', 'changefreq' => 'daily'],
            ['url' => route('pricing'), 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['url' => route('about'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['url' => route('contact'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['url' => route('privacy'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => route('terms'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => route('refund'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => route('security'), 'priority' => '0.3', 'changefreq' => 'yearly'],
        ];

        // Include static pages only on page 1
        $includeStatic = ($page === 1);

        return response()
            ->view('sitemap', compact('galleries', 'staticPages', 'includeStatic'))
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function sitemap(): Response
    {
        $galleries = Cache::flexible('sitemap:galleries', [now()->addMinutes(30), now()->addMinutes(60)], function () {
            return Gallery::publiclyViewable()
                ->with('venueTemplate')
                ->has('images', '>=', 1)
                ->orderByDesc('updated_at')
                ->limit(500)
                ->get(['slug', 'title', 'updated_at']);
        });

        // (Task H13) — artist profiles that have at least one publicly-
        // viewable image. Artists with images only in PIN-protected or
        // scheduled galleries are excluded (their profile page would
        // show no works).
        $artists = Cache::flexible('sitemap:artists', [now()->addMinutes(30), now()->addMinutes(60)], function () {
            return Artist::whereHas('images.gallery', function ($q) {
                    $q->publiclyViewable();
                })
                ->orderBy('name')
                ->limit(500)
                ->get(['slug', 'name', 'updated_at']);
        });

        // (Task H13) — static pages. Previously only /, /discover, /pricing
        // were in the sitemap. Now all public-facing pages are included.
        $staticPages = [
            ['url' => route('welcome'), 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['url' => route('discover'), 'priority' => '0.9', 'changefreq' => 'daily'],
            ['url' => route('pricing'), 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['url' => route('about'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['url' => route('contact'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['url' => route('privacy'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => route('terms'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => route('refund'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['url' => route('security'), 'priority' => '0.3', 'changefreq' => 'yearly'],
        ];

        return response()
            ->view('sitemap', compact('galleries', 'artists', 'staticPages'))
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function feed(): Response
    {
        $galleries = Cache::flexible('feed:galleries', [now()->addMinutes(30), now()->addMinutes(60)], function () {
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
