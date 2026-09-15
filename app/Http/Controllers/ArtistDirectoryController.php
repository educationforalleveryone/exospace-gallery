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

        $artistIds = $artists->getCollection()->pluck('id');
        // Latest public work per artist: pick the max row id per artist group,
        // then fetch exactly those rows with their media — the page-1 artists
        // are the most prolific, so an unbounded fetch would touch the largest
        // image sets in the database to keep one cover each.
        $latestCoverIds = GalleryImage::query()
            ->whereIn('artist_id', $artistIds)
            ->whereHas('gallery', fn ($g) => $g->publiclyViewable())
            ->groupBy('artist_id')
            ->selectRaw('MAX(id) as id')
            ->pluck('id');
        $covers = GalleryImage::query()
            ->with('media')
            ->whereIn('id', $latestCoverIds)
            ->get(['id', 'artist_id', 'path', 'filename'])
            ->keyBy('artist_id');

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

        // CollectionPage graph on the first page only.
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
