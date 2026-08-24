<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Support\Seo\Breadcrumb;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public artwork landing pages (SEO OS Iteration 2).
 *
 * Route: GET /gallery/{slug}/artwork/{image}
 *
 * STRATEGIC DECISION (documented in docs/SEO_AUDIT.md §H2):
 * Individual artwork pages ARE valuable for this product because each
 * artwork carries real, distinct metadata (title, artist, medium, year,
 * dimensions, edition, description, price flag, image). They are the
 * landing surface for "<artist name> <artwork title>" style queries and
 * they give the internal-linking graph its leaf nodes:
 *   artwork → artist, artwork → exhibition, artwork → sibling artworks.
 *
 * QUALITY GATE (anti-thin-page):
 * An artwork page is indexable ONLY when ALL of:
 *   - parent gallery is publiclyViewable (active, no PIN, in schedule)
 *   - it has an image file
 *   - it has a title (or original filename to derive one)
 *   - AND at least one of: description ≥ 80 chars, medium, year, artist.
 * Artworks failing the gate still render (shared deep links must work)
 * but carry noindex — no mass thin-page generation.
 */
class ArtworkController extends Controller
{
    private const SIBLINGS_SHOWN = 8;

    public function __construct(
        private SeoManager $seo,
    ) {}

    public function show(Request $request, string $slug, GalleryImage $image): View
    {
        $gallery = Gallery::query()
            ->where('slug', $slug)
            ->with(['images' => fn ($q) => $q->orderBy('position_order'), 'images.artist', 'images.media', 'user', 'venueTemplate'])
            ->firstOrFail();

        // The artwork must belong to this gallery (scoped URL).
        abort_unless($image->gallery_id === $gallery->id, 404);

        // Private/PIN-protected/scheduled galleries never get artwork pages.
        // Render a minimal view with noindex so shared links don't break,
        // but search engines see nothing indexable.
        if (!$gallery->is_active || $gallery->hasPinProtection() || $gallery->hasNotOpenedYet()) {
            $seo = $this->seo->forArtwork($image, $gallery)->with(['robots' => 'noindex,nofollow']);

            return view('artworks.show', [
                'artwork'   => $image,
                'gallery'   => $gallery,
                'seoData'   => $seo,
                'breadcrumbs' => Breadcrumb::trail([
                    [$gallery->title ?: 'Exhibition', $gallery->public_url],
                    [$image->title ?: $image->original_name ?: 'Artwork'],
                ]),
                'siblings'  => collect(),
                'gatePassed' => false,
            ]);
        }

        $gatePassed = $this->passesQualityGate($image);
        $robots = $gatePassed ? null : 'noindex,follow';

        $seo = $this->seo->forArtwork($image, $gallery)->with(['robots' => $robots]);

        // VisualArtwork + ImageObject schema — REAL data only. No fabricated
        // offers (only for_sale + real price), no invented dates.
        $artworkSchema = $this->buildArtworkSchema($image, $gallery, $seo->canonicalUrl);

        $seo = $seo->with(['jsonLd' => [$artworkSchema]]);

        $breadcrumbs = Breadcrumb::trail([
            ['Home', url('/')],
            ['Discover', route('discover')],
            [$gallery->title ?: 'Exhibition', $gallery->public_url],
            [$image->title ?: $image->original_name ?: 'Artwork'],
        ]);

        // Sibling works in the same exhibition (internal linking).
        $siblings = $gallery->images
            ->filter(fn ($img) => $img->id !== $image->id)
            ->take(self::SIBLINGS_SHOWN);

        return view('artworks.show', [
            'artwork'     => $image,
            'gallery'     => $gallery,
            'seoData'     => $seo,
            'breadcrumbs' => $breadcrumbs,
            'siblings'    => $siblings,
            'gatePassed'  => $gatePassed,
        ]);
    }

    /**
     * Anti-thin-page quality gate. See class docblock.
     */
    public static function passesQualityGate(GalleryImage $image): bool
    {
        // Must have a derivable title.
        if (!trim((string) ($image->title ?: $image->original_name))) {
            return false;
        }

        $minDescription = (int) config('seo.artwork_gate.min_description_chars', 80);
        $hasDepth = mb_strlen(trim((string) $image->description)) >= $minDescription
            || !empty($image->medium)
            || !empty($image->year)
            || !empty($image->artist_id);

        return $hasDepth;
    }

    /**
     * VisualArtwork JSON-LD from real columns only.
     *
     * @return array<string, mixed>
     */
    private function buildArtworkSchema(GalleryImage $image, Gallery $gallery, string $canonicalUrl): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'VisualArtwork',
            'name' => $image->title ?: $image->original_name ?: 'Untitled',
            'url' => $canonicalUrl,
            'image' => asset($image->path),
            'isAccessibleForFree' => true,
        ];

        if ($image->description) {
            $schema['description'] = \Illuminate\Support\Str::limit($image->description, 500);
        }

        if ($image->artist) {
            $schema['creator'] = [
                '@type' => 'Person',
                'name' => $image->artist->name,
                'url' => route('artist.profile', $image->artist->slug),
            ];
        }

        // Exhibition context (the gallery IS the exhibition of record).
        $schema['isPartOf'] = [
            '@type' => 'CollectionPage',
            'name' => $gallery->title ?: 'Untitled Exhibition',
            'url' => $gallery->public_url,
        ];

        if ($image->medium) {
            // artMedium expects the material(s), e.g. "Oil on canvas".
            $schema['artMedium'] = $image->medium;
        }

        if ($image->year) {
            // dateCreated expects a Date; a bare year is valid ISO 8601.
            $schema['dateCreated'] = (string) $image->year;
        }

        if ($image->dimensions) {
            // width/height are QuantitativeValues; dimensions strings like
            // "120 × 80 cm" map naturally to the `size` property.
            $schema['size'] = $image->dimensions;
        }

        if ($image->edition_size || $image->edition_number) {
            // Edition info maps to workExample with a real name only when
            // the curator recorded edition data.
            $edition = ['@type' => 'CreativeWorkSeries'];
            if ($image->formattedEdition()) {
                $edition['name'] = 'Edition ' . $image->formattedEdition();
            }
            $schema['workExample'] = $edition;
        }

        // Offers ONLY when the artwork is genuinely for sale with a price.
        if ($image->for_sale && $image->price) {
            $schema['offers'] = [
                '@type' => 'Offer',
                'price' => number_format((float) $image->price, 2, '.', ''),
                'priceCurrency' => $image->currency ?: 'USD',
                'availability' => 'https://schema.org/InStock',
                'url' => $canonicalUrl,
            ];
        }

        return $schema;
    }
}
