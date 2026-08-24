<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\VenueTemplate;
use App\Services\Seo\SchemaBuilder;
use App\Support\Seo\Breadcrumb;
use App\Support\Seo\CanonicalUrl;
use App\Support\Seo\SeoData;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public venue-template showcase (SEO OS Iteration 2).
 *
 * Routes:
 *   GET /venues          — hub: all published, active venue templates
 *   GET /venues/{slug}   — venue page + the public exhibitions using it
 *
 * Venue templates are a genuine entity with real data (name, description,
 * category, capacity, preview assets) and they are how curators choose the
 * look of their exhibitions. Public venue pages give search engines a
 * crawlable hub type and give prospective customers a "what can I build"
 * gallery — every page links to live exhibitions using the venue.
 *
 * Only venues that are active + published AND have at least one publicly
 * viewable exhibition are shown individually; draft venues or empty venues
 * would be thin pages.
 */
class PublicVenueController extends Controller
{
    private const PER_PAGE = 24;

    public function __construct(
        private SeoManager $seo,
        private SchemaBuilder $schema,
    ) {}

    public function index(): View
    {
        $venues = VenueTemplate::active()
            ->published()
            ->withCount([
                'galleries as public_galleries_count' => fn ($q) => $q->publiclyViewable()->has('images', '>=', 1),
            ])
            ->orderByDesc('public_galleries_count')
            ->orderBy('sort_order')
            ->having('public_galleries_count', '>', 0)
            ->paginate(self::PER_PAGE);

        $seo = $this->seo->forHub(
            templateKey: 'venues_hub',
            description: 'Explore 3D venue templates for virtual exhibitions — museums, warehouses, lofts, and galleries. See live exhibitions built with each venue on ' . config('seo.site_name', 'Exospace') . '.',
            canonicalPath: '/venues',
        )->with(['jsonLd' => [
            $this->schema->hubCollectionPage(
                '3D Venue Templates',
                CanonicalUrl::path('/venues'),
            ),
        ]]);

        $breadcrumbs = Breadcrumb::trail([
            ['Home', url('/')],
            ['Venues'],
        ]);

        return view('venues.index', [
            'venues' => $venues,
            'seoData' => $seo,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $venue = VenueTemplate::active()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $galleries = Gallery::publiclyViewable()
            ->with(['coverImage', 'user', 'venueTemplate'])
            ->where('venue_template_id', $venue->id)
            ->has('images', '>=', 1)
            ->orderByDesc('view_count')
            ->paginate(self::PER_PAGE);

        $baseUrl = CanonicalUrl::path('/venues/' . $venue->slug);
        $page = max(1, (int) $request->input('page', 1));
        $pagination = CanonicalUrl::paginationLinks($baseUrl, $page, $galleries->hasMorePages());

        // Quality rule: a venue with no live exhibitions is a thin page.
        $robots = $galleries->total() === 0 ? 'noindex,follow' : null;

        $title = ($venue->name ?: 'Venue') . ' — 3D Exhibitions';
        $description = $venue->description
            ? \Illuminate\Support\Str::limit($venue->description, 155)
            : 'Walk through 3D virtual exhibitions built with the ' . $venue->name . ' venue template on ' . config('seo.site_name', 'Exospace') . '.';

        $seo = (new SeoData(
            title: $title,
            description: $description,
            canonicalUrl: $page > 1 ? $baseUrl . '?page=' . $page : $baseUrl,
            robots: $robots,
            ogTitle: $title,
            ogDescription: $description,
            ogImage: $venue->thumbnail_path ? asset('storage/' . $venue->thumbnail_path) : asset((string) config('seo.og.default_image', 'img/og-default.png')),
            prevUrl: $pagination['prev'],
            nextUrl: $pagination['next'],
        ));

        // Iteration 3: CollectionPage graph with the venue's live exhibitions.
        if ($galleries->isNotEmpty()) {
            $seo = $seo->with(['jsonLd' => [
                $this->schema->hubCollectionPage(
                    ($venue->name ?: 'Venue') . ' — Live 3D Exhibitions',
                    $baseUrl,
                    $galleries->getCollection(),
                ),
            ]]);
        }

        $breadcrumbs = Breadcrumb::trail([
            ['Home', url('/')],
            ['Venues', route('venues.index')],
            [$venue->name],
        ]);

        return view('venues.show', [
            'venue' => $venue,
            'galleries' => $galleries,
            'seoData' => $seo,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }
}
