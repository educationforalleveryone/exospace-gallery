<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Services\Seo\InternalLinkingService;
use App\Services\Seo\SchemaBuilder;
use App\Support\Seo\Breadcrumb;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public-facing artist profile pages.
 *
 * Route: GET /artist/{slug}
 *
 * Shows the artist's bio, portrait, social links, and all their works
 * across all public galleries. Each work links to its artwork page
 * (SEO OS Iteration 2) with the gallery as context.
 *
 * SEO (Iteration 2 — fixes audit C1):
 *   - Rendered on the PUBLIC layout (the old version used x-guest-layout,
 *     which emitted noindex,nofollow — artist profiles were invisible to
 *     search engines).
 *   - Unique title/description/canonical via SeoManager::forArtist().
 *   - Person JSON-LD built only from REAL profile data.
 *   - Breadcrumbs: Home → Artists → {name}.
 *   - Quality rule: an artist with ZERO publicly-viewable works gets
 *     noindex (thin page) — see docs/SEO_AUDIT.md §8.
 */
class ArtistProfileController extends Controller
{
    public function __construct(
        private SeoManager $seo,
        private SchemaBuilder $schema,
        private InternalLinkingService $linking,
    ) {}

    public function show(Request $request, string $slug): View
    {
        $artist = Artist::where('slug', $slug)->firstOrFail();

        // Load only images from publicly-viewable galleries.
        // (Task H06 / audit H11) — checks is_active + no pin + within
        // schedule window via the same publiclyViewable scope used by
        // discover/sitemap.
        $images = $artist->images()
            ->with(['gallery.venueTemplate', 'gallery.user', 'artist', 'media'])
            ->whereHas('gallery', function ($q) {
                $q->publiclyViewable();
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // Group by gallery
        $galleries = $images->groupBy('gallery_id')->map(function ($imgs) {
            return [
                'gallery' => $imgs->first()->gallery,
                'images'  => $imgs,
            ];
        })->filter(fn ($g) => $g['gallery'] !== null)
          ->sortByDesc(fn ($g) => $g['gallery']->updated_at);

        // Gallery titles are displayed per group; suppress the unused
        // collection when a gallery was soft-deleted mid-flight.
        $galleries = $galleries->values();

        $exhibitionCount = $galleries->count();
        $workCount = $images->count();

        // Quality rule: no public works → noindex.
        $robots = $workCount === 0 ? 'noindex,follow' : null;

        $seo = $this->seo->forArtist($artist, $workCount, $exhibitionCount)
            ->with(['robots' => $robots]);

        // Person schema — REAL data only, built by the central SchemaBuilder
        // (Iteration 3). The ItemList of works is appended when public works
        // exist, capped at 25 to keep the graph small.
        $graphs = [$this->schema->person($artist, $seo->canonicalUrl)];

        if ($workCount > 0) {
            $graphs[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => 'Artworks by ' . $artist->name . ' on ' . config('seo.site_name', 'Exospace'),
                'numberOfItems' => $workCount,
                'itemListElement' => $images->take(25)->values()->map(fn ($img, $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'url' => url('/gallery/' . $img->gallery->slug . '/artwork/' . $img->id),
                    'name' => $img->title ?: $img->original_name ?: 'Untitled',
                ])->all(),
            ];
        }

        $seo = $seo->with(['jsonLd' => $graphs]);

        // Iteration 3: related artists (shared exhibitions) — internal
        // linking between artist profiles.
        $relatedArtists = $workCount > 0 ? $this->linking->relatedArtists($artist) : collect();

        $breadcrumbs = Breadcrumb::trail([
            ['Home', url('/')],
            ['Artists', route('artists.index')],
            [$artist->name],
        ]);

        return view('artists.show', [
            'artist' => $artist,
            'galleries' => $galleries,
            'seoData' => $seo,
            'breadcrumbs' => $breadcrumbs,
            'workCount' => $workCount,
            'exhibitionCount' => $exhibitionCount,
            'relatedArtists' => $relatedArtists,
            // Iteration 7: preload the portrait as the LCP image.
            'preloadImage' => $artist->portrait_url,
        ]);
    }
}
