<?php

namespace App\Http\Controllers;

use App\Models\VenueTemplate;
use App\Services\SampleExhibitionService;
use App\Services\VenueConfigExporter;
use Illuminate\Http\Response;
use Illuminate\View\View;

class VenuePreviewController extends Controller
{
    public function __construct(
        private VenueConfigExporter $exporter,
        private SampleExhibitionService $samples,
    ) {}

    public function show(string $slug): Response
    {
        $venue = VenueTemplate::active()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $config = $this->exporter->forVenuePreview($venue);
        $images = $this->samples->forVenue($venue);

        $defaultSettings = $venue->default_settings ?? [];

        $galleryData = [
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

            'userPlan'   => $venue->plan_required ?: 'free',

            'customLogoUrl'     => null,
            'curtainLogoUrl'    => null,
            'curtainBgColor'    => null,
            'newsletterUrl'     => null,
            'eventsUrl'         => null,
            'hasUpcomingEvents' => false,
            'deepLinkArtworkId' => null,

            'arrival_enabled' => \App\Services\FeatureFlag::isEnabled('arrival_choreography'),

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
