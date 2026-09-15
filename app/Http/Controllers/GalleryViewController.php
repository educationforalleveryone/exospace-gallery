<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Services\Seo\InternalLinkingService;
use App\Services\Seo\SchemaBuilder;
use App\Services\VenueConfigExporter;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GalleryViewController extends Controller
{
    public function __construct(
        private VenueConfigExporter $venueExporter,
        private SeoManager $seo,
        private SchemaBuilder $schema,
        private InternalLinkingService $linking,
    ) {}

    public function show(Request $request, string $slug): View|\Illuminate\Http\RedirectResponse
    {
        $gallery = $request->attributes->get('resolved_gallery')
            ?? Gallery::publiclyAccessible()
                ->where('slug', $slug)
                ->with(['images.artist', 'images.media', 'user', 'venueTemplate', 'seoProfile'])
                ->firstOrFail();

        if ($gallery->slug !== $slug && !$request->attributes->has('resolved_gallery')) {
            abort(404);
        }

        // Time-gate: not open yet
        if ($gallery->hasNotOpenedYet()) {
            return view('gallery.coming-soon', compact('gallery'));
        }

        // Time-gate: exhibition has closed
        if ($gallery->hasClosed()) {
            return view('gallery.closed', compact('gallery'));
        }

        $isEmbed = $request->boolean('embed');
        if ($gallery->hasPinProtection() && !session("pin_verified_{$gallery->id}")) {
            return redirect()->route('gallery.pin', $gallery->slug);
        }

        if (!$isEmbed) {
            \App\Jobs\IncrementGalleryViews::dispatch(
                $gallery->id,
                $gallery->venueTemplate?->id,
            )->afterResponse();
        }

        $venueConfig = $gallery->venueTemplate
            ? $this->venueExporter->forGallery($gallery)
            : null;

        $hasUpcomingEvents = $gallery->scheduleEvents()->active()->upcoming()->exists();

        $galleryData = [
            'id'          => $gallery->id,
            'title'       => $gallery->title,
            'description' => $gallery->description,
            'wall_texture'    => $gallery->wall_texture,
            'floor_material'  => $gallery->floor_material,
            'frame_style'     => $gallery->frame_style,
            'lighting_preset' => $this->venueExporter->presetForGallery($gallery),
            'room_layout'     => $this->venueExporter->layoutForGallery($gallery),
            'venue_slug'      => $gallery->venueTemplate?->slug,
            'venueConfig'     => $venueConfig,
            'images' => $gallery->images->map(fn($img) => array_filter([
                'id'             => $img->id,
                'url'            => asset($img->path),
                'textures'       => [
                    'thumb'  => $img->conversionUrl('thumb'),
                    'small'  => $img->conversionUrl('small'),
                    'medium' => $img->conversionUrl('medium'),
                    'large'  => $img->conversionUrl('large'),
                ],
                'width'          => $img->width,
                'height'         => $img->height,
                'aspectRatio'    => $img->width / max($img->height, 1),
                'orientation'    => $img->orientation,
                'title'          => $img->title ?? $img->original_name,
                'description'    => $img->description,
                // NEW (Round 4) — per-artwork metadata for focus mode
                'artist'         => $img->artist ? [
                    'id'     => $img->artist->id,
                    'name'   => $img->artist->name,
                    'slug'   => $img->artist->slug,
                    'url'    => route('artist.profile', $img->artist->slug),
                ] : null,
                'price'          => $img->price ? (float) $img->price : null,
                'currency'       => $img->currency,
                'formattedPrice' => $img->formattedPrice(),
                'forSale'        => (bool) $img->for_sale,
                'medium'         => $img->medium,
                'year'           => $img->year,
                'dimensions'     => $img->dimensions,
                'edition'        => $img->formattedEdition(),
                'externalUrl'    => $img->external_url,
            ], fn ($v) => $v !== null))->values(),
            'imageCount'     => $gallery->images->count(),
            'audioUrl'       => $gallery->audio_path ? asset('storage/' . $gallery->audio_path) : null,
            'userPlan'       => $gallery->user->plan ?? 'free',
            'customLogoUrl'  => ($gallery->custom_logo_path && $gallery->user->plan === 'studio')
                                    ? asset('storage/' . $gallery->custom_logo_path)
                                    : null,
            // NEW (Round 4) — branded curtain (Studio only)
            'curtainLogoUrl' => ($gallery->curtain_logo_path && $gallery->user->plan === 'studio')
                                    ? asset('storage/' . $gallery->curtain_logo_path)
                                    : null,
            'curtainBgColor' => ($gallery->curtain_bg_color && $gallery->user->plan === 'studio')
                                    ? $gallery->curtain_bg_color
                                    : null,
            // NEW (Round 4) — newsletter signup endpoint
            'newsletterUrl'  => route('gallery.newsletter', $gallery->slug),
            // NEW (Round 4) — events page link
            'eventsUrl'      => route('gallery.events.index', $gallery->slug),
            'hasUpcomingEvents' => $hasUpcomingEvents,

            'deepLinkArtworkId' => $request->integer('artwork'),

            'arrival_enabled' => \App\Services\FeatureFlag::isEnabled('arrival_choreography'),
        ];

        $gallerySeo = $this->seo->forGallery($gallery);
        $robots = $gallerySeo->robots;
        if ($isEmbed) {
            $robots = 'noindex,nofollow';
        } elseif ($gallery->images->isEmpty()) {
            $robots = 'noindex,follow';
        }
        $gallerySeo = $gallerySeo->with(['robots' => $robots]);

        $artworkParam = $request->integer('artwork');
        if ($artworkParam && !$isEmbed) {
            $linked = $gallery->images->firstWhere('id', $artworkParam);
            if ($linked && \App\Http\Controllers\ArtworkController::passesQualityGate($linked)) {
                $gallerySeo = $gallerySeo->with([
                    'canonicalUrl' => url("/gallery/{$gallery->slug}/artwork/{$linked->id}"),
                ]);
            }
        }

        $graphs = [];
        if (!$isEmbed) {
            $graphs[] = ($gallery->opens_at || $gallery->closes_at)
                ? $this->schema->exhibitionEvent($gallery)
                : $this->schema->collectionPage($gallery);

            if ($gallery->images->isNotEmpty()) {
                $graphs[] = $this->schema->artworkItemList(
                    $gallery,
                    $gallery->images->take(60),
                    $gallery->images->count(),
                );
            }
            $gallerySeo = $gallerySeo->with(['jsonLd' => $graphs]);
        }

        // ── SEO OS: related exhibitions (internal linking).
        $relatedGalleries = (!$isEmbed && $gallery->images->isNotEmpty())
            ? $this->linking->relatedGalleries($gallery)
            : collect();

        return view('gallery.view', compact(
            'gallery', 'galleryData', 'hasUpcomingEvents', 'gallerySeo', 'relatedGalleries'
        ));
    }
}
