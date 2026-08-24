<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\VenueTemplate;
use App\Services\Seo\SchemaBuilder;
use App\Support\Seo\CanonicalUrl;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public directory of featured / open exhibitions.
 *
 * Route: GET /discover
 *
 * Shows galleries that are:
 *   - is_active = true
 *   - Not PIN-protected (publicly viewable)
 *   - Currently open (within their schedule window, or unscheduled)
 *   - Have at least one image (no empty exhibitions in the directory)
 *
 * Supports sorting by: featured (default), views, newest, recently updated.
 * Supports filtering by venue_template.
 *
 * SEO OS (Iteration 2):
 *   - Canonical policy: /discover?sort=…&venue=… canonicalize to the clean
 *     /discover URL (they are alternate VIEWS of the same content — audit
 *     C4/M4). Paginated pages self-canonicalize with only ?page= preserved.
 *   - rel=prev/next emitted for the default (unfiltered) pagination.
 *   - Filtered/sorted variants are noindex,follow: crawlable graph links
 *     remain usable, but search engines keep a single indexable copy.
 */
class DiscoverController extends Controller
{
    public function index(Request $request): View
    {
        $sort = $request->string('sort', 'featured')->toString();
        $venueId = $request->string('venue');

        // Any non-default sort/venue makes this an alternate view.
        $isFilteredView = $venueId !== '' || !in_array($sort, ['featured', ''], true);

        $query = Gallery::publiclyViewable()
            ->with(['coverImage', 'venueTemplate', 'user'])
            ->has('images', '>=', 1)
            ->whereDoesntHave('user', fn($q) => $q->whereNotNull('banned_at'));

        // Filter by venue
        if ($venueId) {
            $query->where('venue_template_id', $venueId);
        }

        // Sort
        $query->when($sort === 'views', fn($q) => $q->orderByDesc('view_count'))
              ->when($sort === 'newest', fn($q) => $q->orderByDesc('created_at'))
              ->when($sort === 'updated', fn($q) => $q->orderByDesc('updated_at'))
              ->unless(in_array($sort, ['views', 'newest', 'updated']), function ($q) {
                  // Default: featured galleries first (Round 4 — is_featured column
                  // on galleries, controlled via super-admin /master-control/featured),
                  // then by view_count.
                  return $q->orderByDesc('is_featured')
                           ->orderByDesc('view_count');
              });

        $galleries = $query->paginate(24)->withQueryString();

        // Venues for the filter dropdown
        $venues = VenueTemplate::active()
            ->published()
            ->orderBy('sort_order')
            ->pluck('name', 'id');

        // ── SEO (Iteration 2) ─────────────────────────────────────────────
        $baseUrl = CanonicalUrl::path('/discover');
        $page = max(1, (int) $request->input('page', 1));

        if ($isFilteredView) {
            // Alternate view: canonical to the clean hub URL, noindex,follow.
            $canonical = $baseUrl;
            $robots = 'noindex,follow';
            $prev = $next = null;
        } else {
            // Default view: self-canonical with the page param, prev/next.
            $canonical = $page > 1 ? $baseUrl . '?page=' . $page : $baseUrl;
            $robots = null;
            $pagination = CanonicalUrl::paginationLinks($baseUrl, $page, $galleries->hasMorePages());
            $prev = $pagination['prev'];
            $next = $pagination['next'];
        }

        $title = config('seo.site_name', 'Exospace') . ' — Discover 3D Art Exhibitions';
        $description = 'Walk through virtual galleries curated by artists, photographers, and institutions from around the world. Featured 3D exhibitions on ' . config('seo.site_name', 'Exospace') . '.';

        $seo = new \App\Support\Seo\SeoData(
            title: $title,
            description: $description,
            canonicalUrl: $canonical,
            robots: $robots,
            ogTitle: 'Discover 3D Art Exhibitions',
            ogDescription: $description,
            ogImage: asset((string) config('seo.og.default_image', 'img/og-default.png')),
            prevUrl: $prev,
            nextUrl: $next,
        );

        // Iteration 3: CollectionPage graph (replaces the template-level
        // ItemList component usage — one graph, built centrally, real data
        // only, first page of results).
        if (!$isFilteredView && $page === 1) {
            $schema = app(SchemaBuilder::class);
            $seo = $seo->with(['jsonLd' => [
                $schema->hubCollectionPage(
                    'Discover 3D Art Exhibitions',
                    $baseUrl,
                    $galleries->getCollection(),
                ),
            ]]);
        }

        return view('discover.index', [
            'galleries' => $galleries,
            'venues' => $venues,
            'sort' => $sort,
            'venueId' => $venueId,
            'seoData' => $seo,
        ]);
    }
}
