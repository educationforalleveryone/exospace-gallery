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
 *
 * ITERATION-3 FIX (P0 access-control hole): this controller previously
 * rendered the FULL artwork — image, description, price, medium, edition —
 * for PIN-protected, unpublished, and not-yet-opened galleries, with only
 * a noindex meta tag as mitigation. noindex stops crawlers, not humans:
 * anyone holding an artwork URL bypassed the gallery PIN entirely, and
 * "unpublish" did not actually withdraw the artwork pages. The gating now
 * mirrors the gallery page's own visibility rules exactly:
 *
 *   gallery state                 gallery page          artwork page
 *   ─────────────────────────     ─────────────────     ─────────────────
 *   draft / unpublished           404                   404
 *   not yet open (scheduled)      coming-soon page      redirect → gallery
 *   closed                        closed page           redirect → gallery
 *   PIN, not verified this sess.  redirect → PIN        redirect → PIN
 *   PIN, verified this session    3D viewer             full page, noindex
 */
class ArtworkController extends Controller
{
    private const SIBLINGS_SHOWN = 8;

    public function __construct(
        private SeoManager $seo,
        private SchemaBuilder $schema,
        private InternalLinkingService $linking,
    ) {}

    public function show(Request $request, string $slug, GalleryImage $image): View|\Illuminate\Http\RedirectResponse
    {
        $gallery = Gallery::query()
            ->where('slug', $slug)
            ->with(['images' => fn ($q) => $q->orderBy('position_order'), 'images.artist', 'images.media', 'user', 'venueTemplate'])
            ->firstOrFail();

        // The artwork must belong to this gallery (scoped URL).
        abort_unless($image->gallery_id === $gallery->id, 404);

        // ── ITERATION-3 FIX: mirror the gallery page's visibility rules ──
        // (see class docblock table). A noindex tag is not access control.

        // Draft/unpublished: the gallery URL 404s, so the artwork URL 404s
        // too — unpublishing must actually withdraw the content.
        if (! $gallery->is_active) {
            abort(404);
        }

        // Time-gates: defer to the gallery's own coming-soon / closed
        // pages, which are exactly what a visitor at the exhibition URL
        // sees. Pre-opening artworks must not leak through deep links, and
        // a closed exhibition shows its archived state, not live content.
        if ($gallery->hasNotOpenedYet() || $gallery->hasClosed()) {
            return redirect()->route('gallery.view', $gallery->slug);
        }

        // PIN protection — the same session gate the gallery view uses.
        // Until the visitor enters the PIN, the artwork page redirects to
        // the PIN screen; after verification, the full page renders (with
        // noindex — PIN galleries are never publiclyViewable).
        if ($gallery->hasPinProtection() && ! session("pin_verified_{$gallery->id}")) {
            return redirect()->route('gallery.pin', $gallery->slug);
        }

        $pinGated = $gallery->hasPinProtection();

        $gatePassed = $this->passesQualityGate($image);
        // PIN galleries stay out of search engines entirely (they are not
        // publiclyViewable); everyone else gets the standard quality-gate
        // robots policy.
        $robots = $pinGated ? 'noindex,nofollow' : ($gatePassed ? null : 'noindex,follow');

        $seo = $this->seo->forArtwork($image, $gallery)->with(['robots' => $robots]);

        // VisualArtwork schema via the central builder (Iteration 3) —
        // REAL data only, no fabricated offers or dates. Structured data
        // is only emitted for non-PIN galleries: describing gated content
        // to machines re-leaks what the PIN gate protects.
        if (! $pinGated) {
            $seo = $seo->with(['jsonLd' => [
                $this->schema->visualArtwork($image, $gallery),
            ]]);
        }

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
            // Iteration 7: preload the LCP image (the artwork itself).
            'preloadImage' => $image->public_url,
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
