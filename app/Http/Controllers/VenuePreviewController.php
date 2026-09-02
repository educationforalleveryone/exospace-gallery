<?php

namespace App\Http\Controllers;

use App\Models\VenueTemplate;
use App\Services\SampleExhibitionService;
use App\Services\VenueConfigExporter;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Iteration 1 "The Rehearsal" (roadmap P1.1) — public walkable venue preview.
 *
 * GET /venues/{slug}/preview  →  name: venues.preview
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this route, 0/11 venues could be experienced before commit — the
 * selection journey showed gradients and sentences, never the space. This
 * controller builds a SYNTHETIC GALLERY_DATA payload (same shape the public
 * gallery viewer consumes) from:
 *
 *   - the venue template's own configuration (VenueConfigExporter::forVenuePreview)
 *   - the sample exhibition set (SampleExhibitionService, config-driven)
 *
 * …and renders it through the SAME 3D runtime (resources/js/gallery/main.js)
 * a paying customer's gallery uses. What you preview is what you buy.
 *
 * SAFETY PROPERTIES (each pinned by VenuePreviewIterationTest)
 * -----------------------------------------------------------
 *   1. SAMPLE-ONLY  — no Gallery/Image/User row is read or written; the
 *      payload's artwork ids are namespaced `sample-*`.
 *   2. NOINDEX      — X-Robots-Tag header + meta robots, so previews never
 *      compete with /venues/{slug} or real exhibitions in search.
 *   3. NO SIGNUP    — previews are the funnel (roadmap DO NOT DO #10:
 *      never gate previews behind signup; rate-limit instead).
 *   4. RATE-LIMITED — throttle:20,1 per IP at the route layer.
 *   5. FLAG-GATED   — feature_flag:venue_previews middleware aborts 404
 *      when the flag is off (rollback = one env var; route stays harmless).
 *   6. PUBLISHED-ONLY — draft/inactive venues 404, same scoping as the
 *      public venue pages (Iteration 0's selection-integrity contract).
 *
 * ANALYTICS SILENCE
 * -----------------
 * The viewer's Analytics module only fires when window.EXOSPACE_TRACK_URL
 * is set; this page deliberately never sets it, so preview walks cannot
 * pollute gallery analytics or the view-count economy.
 */
class VenuePreviewController extends Controller
{
    public function __construct(
        private VenueConfigExporter $exporter,
        private SampleExhibitionService $samples,
    ) {}

    public function show(string $slug): Response
    {
        // Same scoping contract as PublicVenueController::show() — a draft
        // or deactivated venue is not walkable either.
        $venue = VenueTemplate::active()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $config = $this->exporter->forVenuePreview($venue);
        $images = $this->samples->forVenue($venue);

        $defaultSettings = $venue->default_settings ?? [];

        $galleryData = [
            // Stable string id → the seeded PRNG (Iteration 0, Rng.js) builds
            // the venue as `{slug}:preview` — the SAME deterministic
            // composition for every visitor on every load, which is exactly
            // what makes preview stills reproducible for marketing + QA.
            'id'          => 'preview',
            'title'       => $venue->name ?: 'Venue preview',
            'description' => $venue->description,

            // The preview is a sample exhibition, not a Gallery row.
            'isPreview'   => true,

            // Venue defaults (no gallery layer exists in a preview).
            'wall_texture'    => $defaultSettings['wall_texture']    ?? 'white',
            'floor_material'  => $defaultSettings['floor_material']  ?? 'concrete',
            'frame_style'     => $defaultSettings['frame_style']     ?? 'minimal',
            'lighting_preset' => $defaultSettings['lighting_preset'] ?? 'bright',
            'room_layout'     => $defaultSettings['room_layout']     ?? 'square',
            'venue_slug'      => $venue->slug,
            'venueConfig'     => $config,

            'images'     => $images,
            'imageCount' => count($images),

            // Ambient audio: the venue's own default, if it ships one.
            'audioUrl'   => $venue->default_audio_url,

            // Render at the tier that unlocks the venue (matches the
            // decoration filtering in forVenuePreview).
            'userPlan'   => $venue->plan_required ?: 'free',

            // Everything below is deliberately NULL/empty: a preview has no
            // custom branding, no newsletter, no events, no deep-links, and
            // — because EXOSPACE_TRACK_URL is never set on this page — no
            // analytics traffic either.
            'customLogoUrl'     => null,
            'curtainLogoUrl'    => null,
            'curtainBgColor'    => null,
            'newsletterUrl'     => null,
            'eventsUrl'         => null,
            'hasUpcomingEvents' => false,
            'deepLinkArtworkId' => null,

            // Iteration 4 "Arrival": previews walk the SAME runtime with the
            // SAME composed first frame a paying visitor will get — seeing
            // the arrival IS part of choosing a venue.
            'arrival_enabled' => \App\Services\FeatureFlag::isEnabled('arrival_choreography'),

            // Iteration 7 "Frontier" (roadmap P3.1 spike): personal try-on —
            // upload one LOCAL image and see it on this venue's wall, in the
            // browser only. Client-side by construction (TryOn.js performs
            // zero network I/O; nothing is persisted), preview-payload-only
            // by policy (customer galleries never receive this key), and
            // default-OFF (spike = opt-in rollout). Rollback: the flag alone.
            'tryOnEnabled' => \App\Services\FeatureFlag::isEnabled('venue_try_on'),
        ];

        return response()
            ->view('venues.preview', [
                'venue'             => $venue,
                'galleryData'       => $galleryData,
                'sampleNote'        => $this->samples->noteFor($venue),
                'sampleCredit'      => $this->samples->credit(),
            ])
            // NOINDEX (belt) — the meta robots tag in the blade is the braces.
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
