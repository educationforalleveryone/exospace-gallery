<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Services\Seo\InternalLinkingService;
use App\Services\Seo\SchemaBuilder;
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
        private SchemaBuilder $schema,
        private InternalLinkingService $linking,
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
                'alsoByArtist' => collect(),
                'gatePassed' => false,
            ]);
        }

        $gatePassed = $this->passesQualityGate($image);
        $robots = $gatePassed ? null : 'noindex,follow';

        $seo = $this->seo->forArtwork($image, $gallery)->with(['robots' => $robots]);

        // VisualArtwork schema via the central builder (Iteration 3) —
        // REAL data only, no fabricated offers or dates.
        $seo = $seo->with(['jsonLd' => [
            $this->schema->visualArtwork($image, $gallery),
        ]]);

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

        // Iteration 3: other works by the same artist in OTHER public
        // exhibitions — cross-gallery internal links from the leaf node.
        $alsoByArtist = $this->linking->relatedArtworks($image);

        return view('artworks.show', [
            'artwork'     => $image,
            'gallery'     => $gallery,
            'seoData'     => $seo,
            'breadcrumbs' => $breadcrumbs,
            'siblings'    => $siblings,
            'alsoByArtist' => $alsoByArtist,
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

}
