<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\GalleryImage;
use App\Services\Seo\SchemaBuilder;
use App\Support\Seo\Breadcrumb;
use App\Support\Seo\CanonicalUrl;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public directory of artists with publicly-viewable works.
 *
 * Route: GET /artists
 *
 * This is the crawlable HUB for the artist layer (SEO OS Iteration 2):
 * every artist profile is at most one internal link away. Artists with
 * zero public works are excluded entirely (they would be thin pages).
 *
 * Pagination: self-canonical with the page param preserved; rel=prev/next
 * emitted via SeoData. Sort/filter params canonicalize to the clean URL.
 */
class ArtistDirectoryController extends Controller
{
    private const PER_PAGE = 24;

    public function __construct(
        private SeoManager $seo,
        private SchemaBuilder $schema,
    ) {}

    public function index(Request $request): View
    {
        $artists = Artist::query()
            ->whereHas('images.gallery', fn ($q) => $q->publiclyViewable())
            ->withCount([
                'images as public_works_count' => fn ($q) => $q->whereHas('gallery', fn ($g) => $g->publiclyViewable()),
            ])
            ->orderByDesc('public_works_count')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Cover image per artist card (first public work). NOTE: eager
        // loading with ->limit(1) would limit the TOTAL set across all
        // parents (classic Eloquent gotcha) — fetch first-per-artist in
        // PHP instead: one query, no N+1.
        $artistIds = $artists->getCollection()->pluck('id');
        $covers = GalleryImage::query()
            ->whereIn('artist_id', $artistIds)
            ->whereHas('gallery', fn ($g) => $g->publiclyViewable())
            ->orderByDesc('created_at')
            ->get(['id', 'artist_id', 'path', 'filename'])
            ->groupBy('artist_id')
            ->map(fn ($group) => $group->first());

        $baseUrl = CanonicalUrl::path('/artists');
        $page = max(1, (int) $request->input('page', 1));
        $pagination = CanonicalUrl::paginationLinks($baseUrl, $page, $artists->hasMorePages());

        $canonical = $page > 1 ? $baseUrl . '?page=' . $page : $baseUrl;

        $seo = $this->seo->forHub(
            templateKey: 'artists_hub',
            description: 'Browse artists exhibiting 3D virtual exhibitions on ' . config('seo.site_name', 'Exospace') . '. Discover painters, photographers, sculptors, and digital artists from around the world.',
            canonicalPath: '/artists',
        )->with([
            'canonicalUrl' => $canonical,
            'prevUrl' => $pagination['prev'],
            'nextUrl' => $pagination['next'],
        ]);

        // Iteration 3: CollectionPage graph on the first page only.
        if ($page === 1) {
            $seo = $seo->with(['jsonLd' => [
                $this->schema->hubCollectionPage(
                    'Artists Exhibiting in 3D',
                    $baseUrl,
                    $artists->getCollection(),
                ),
            ]]);
        }

        $breadcrumbs = Breadcrumb::trail([
            ['Home', url('/')],
            ['Artists'],
        ]);

        return view('artists.index', [
            'artists' => $artists,
            'covers' => $covers,
            'seoData' => $seo,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }
}
