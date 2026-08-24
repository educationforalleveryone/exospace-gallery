<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Services\VenueConfigExporter;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GalleryViewController extends Controller
{
    public function __construct(
        private VenueConfigExporter $venueExporter,
    ) {}

    public function show(Request $request, string $slug): View|\Illuminate\Http\RedirectResponse
    {
        // Allow resolution by slug OR by custom domain (Studio plan).
        // The DetectCustomDomain middleware may have populated
        // request()->attributes->get('resolved_gallery') for CNAME requests.
        //
        // E-1 FIX (Iter-011): Added 'images.media' to the eager-load chain.
        // Previously only 'images.artist' was eager-loaded — every call to
        // $image->getSrcsetAttribute() or $image->conversionUrl() (which
        // both call $this->getFirstMedia('original')) issued a separate DB
        // query. On a 20-image gallery, that's 20+ extra queries per render.
        // Spatie's InteractsWithMedia caches media on the model instance
        // once loaded, so eager-loading 'media' here means all subsequent
        // getFirstMedia() calls in the view are free.
        $gallery = $request->attributes->get('resolved_gallery')
            ?? Gallery::where('slug', $slug)
                ->where('is_active', true)
                ->with(['images.artist', 'images.media', 'user', 'venueTemplate'])
                ->firstOrFail();

        // If the resolved-by-domain gallery's slug doesn't match the URL slug,
        // we still render it (this lets custom-domain visitors see the right
        // gallery even if they hit the bare domain).
        if ($gallery->slug !== $slug && !$request->attributes->has('resolved_gallery')) {
            // Sanity: a normal /gallery/{slug} request that resolved a different
            // slug shouldn't happen — fall back to firstOrFail's 404 logic.
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

        // PIN protection — redirect to PIN screen if not yet verified.
        // Skipped in embed mode (embeds are public-only by design).
        $isEmbed = $request->boolean('embed');
        if (!$isEmbed && $gallery->hasPinProtection() && !session("pin_verified_{$gallery->id}")) {
            return redirect()->route('gallery.pin', $gallery->slug);
        }

        // C-7 FIX (Iter-009): Defer the view-count increment to a job that
        // runs after the HTTP response is sent. Previously this was two
        // synchronous UPDATE statements on hot rows per page view, causing
        // InnoDB row-lock contention under viral spikes.
        //
        // dispatch()->afterResponse() runs the job in the same PHP process
        // AFTER the response is flushed to the client — the user sees zero
        // added latency, and the galleries row is no longer locked during
        // the request lifecycle.
        //
        // The view_count column is a denormalized cache; analytics_events
        // is the source of truth. A missed increment here is acceptable.
        if (!$isEmbed) {
            \App\Jobs\IncrementGalleryViews::dispatch(
                $gallery->id,
                $gallery->venueTemplate?->id,
            )->afterResponse();
        }

        // Build venue config via the exporter (data-driven path).
        // The 3D viewer consumes this JSON; if it's missing or has no
        // visual_config, the viewer falls back to the legacy JS switch.
        $venueConfig = $gallery->venueTemplate
            ? $this->venueExporter->forGallery($gallery)
            : null;

        // PERF-C15 (3D audit F15): this query used to run TWICE per page view
        // — once here for galleryData and once directly inside the Blade
        // curtain (@if($gallery->scheduleEvents()…exists())). Compute once,
        // pass to both consumers.
        $hasUpcomingEvents = $gallery->scheduleEvents()->active()->upcoming()->exists();

        $galleryData = [
            'id'          => $gallery->id,
            'title'       => $gallery->title,
            'description' => $gallery->description,
            'wall_texture'    => $gallery->wall_texture,
            'floor_material'  => $gallery->floor_material,
            'frame_style'     => $gallery->frame_style,
            'lighting_preset' => $gallery->lighting_preset,
            'room_layout'     => $gallery->room_layout ?? 'square',
            'venue_slug'      => $gallery->venueTemplate?->slug ?? 'white-cube',
            'venueConfig'     => $venueConfig,
            // PERF-E30 (3D audit): array_filter strips null fields — a
            // 100-artwork gallery otherwise ships ~500 dead bytes of nulls
            // per image (description, artist, price, medium, year...) inside
            // the inline GALLERY_DATA JSON. Every JS consumer already treats
            // missing keys and null identically (|| defaults, ?. chains).
            'images' => $gallery->images->map(fn($img) => array_filter([
                'id'             => $img->id,
                'url'            => asset($img->path),
                // PERF-A1 (3D audit F1): capability-appropriate texture variants.
                // The 3D viewer previously loaded the ORIGINAL file for every
                // artwork — the Spatie WebP conversions (thumb/small/medium/
                // large) that already exist on disk were never referenced.
                // These URLs are additive: 'url' above is unchanged and remains
                // the fallback when a conversion hasn't been generated yet
                // (conversionUrl() falls back to public_url, which is the
                // original). With 'images.media' eager-loaded above, these
                // calls read the memoized media relation — no extra queries.
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

            // (Task H41 / audit MX8) — deep-link target artwork. If the
            // URL has ?artwork=<id>, the 3D viewer's main.js will
            // auto-focus that artwork after the scene loads. This lets
            // artists share links to specific works from social media.
            'deepLinkArtworkId' => $request->integer('artwork'),
        ];

        return view('gallery.view', compact('gallery', 'galleryData', 'hasUpcomingEvents'));
    }
}
