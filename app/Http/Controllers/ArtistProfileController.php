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

class ArtistProfileController extends Controller
{
    public function __construct(
        private SeoManager $seo,
        private SchemaBuilder $schema,
        private InternalLinkingService $linking,
    ) {}

    public function show(Request $request, string $slug): View
    {
        $artist = Artist::where('slug', $slug)->with('seoProfile')->firstOrFail();

        $images = $artist->images()
            ->with(['gallery.venueTemplate', 'media'])
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

        $galleries = $galleries->values();

        $exhibitionCount = $galleries->count();
        $workCount = $images->count();

        // Quality rule: no public works → noindex.
        $robots = $workCount === 0 ? 'noindex,follow' : null;

        $seo = $this->seo->forArtist($artist, $workCount, $exhibitionCount)
            ->with(['robots' => $robots]);

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
            // Preload the portrait as the LCP image.
            'preloadImage' => $artist->portrait_url,
        ]);
    }
}
