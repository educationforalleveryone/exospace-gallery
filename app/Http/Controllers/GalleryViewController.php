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
        $gallery = $request->attributes->get('resolved_gallery')
            ?? Gallery::where('slug', $slug)
                ->where('is_active', true)
                ->with(['images', 'user', 'venueTemplate'])
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

        // Don't double-count views on embed loads (the host page already counts)
        if (!$isEmbed) {
            $gallery->increment('view_count');
        }

        // Increment venue template view count (cheap, no events)
        if ($gallery->venueTemplate) {
            $gallery->venueTemplate->incrementViewCount();
        }

        // Build venue config via the exporter (data-driven path).
        // The 3D viewer consumes this JSON; if it's missing or has no
        // visual_config, the viewer falls back to the legacy JS switch.
        $venueConfig = $gallery->venueTemplate
            ? $this->venueExporter->forGallery($gallery)
            : null;

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
            'venueConfig'     => $venueConfig,   // NEW — data-driven venue config
            'images' => $gallery->images->map(fn($img) => [
                'id'          => $img->id,
                'url'         => asset($img->path),
                'width'       => $img->width,
                'height'      => $img->height,
                'aspectRatio' => $img->width / max($img->height, 1),
                'orientation' => $img->orientation,
                'title'       => $img->title ?? $img->original_name,
                'description' => $img->description,
            ])->values(),
            'imageCount'     => $gallery->images->count(),
            'audioUrl'       => $gallery->audio_path ? asset('storage/' . $gallery->audio_path) : null,
            'userPlan'       => $gallery->user->plan ?? 'free',
            'customLogoUrl'  => ($gallery->custom_logo_path && $gallery->user->plan === 'studio')
                                    ? asset('storage/' . $gallery->custom_logo_path)
                                    : null,
        ];

        return view('gallery.view', compact('gallery', 'galleryData'));
    }
}
