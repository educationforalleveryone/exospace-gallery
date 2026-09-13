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
            ->whereDoesntHave('user', fn ($q) => $q->whereNotNull('banned_at'))
            ->with(['images' => fn ($q) => $q->orderBy('position_order'), 'images.artist', 'images.media', 'user', 'venueTemplate'])
            ->firstOrFail();

        // The artwork must belong to this gallery (scoped URL).
        abort_unless($image->gallery_id === $gallery->id, 404);

        if (! $gallery->is_active) {
            abort(404);
        }

        if ($gallery->hasNotOpenedYet() || $gallery->hasClosed()) {
            return redirect()->route('gallery.view', $gallery->slug);
        }

        if ($gallery->hasPinProtection() && ! session("pin_verified_{$gallery->id}")) {
            return redirect()->route('gallery.pin', $gallery->slug);
        }

        $pinGated = $gallery->hasPinProtection();

        $gatePassed = $this->passesQualityGate($image);
        $robots = $pinGated ? 'noindex,nofollow' : ($gatePassed ? null : 'noindex,follow');

        $seo = $this->seo->forArtwork($image, $gallery)->with(['robots' => $robots]);

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
