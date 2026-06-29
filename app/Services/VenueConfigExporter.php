<?php

namespace App\Services;

use App\Models\VenueTemplate;
use App\Models\Gallery;

/**
 * Exports venue template configuration as a JSON-safe array consumable
 * by the 3D viewer (resources/views/gallery/view.blade.php).
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this service, the viewer had a hardcoded `applyVenueOverrides(slug)`
 * switch with 8 cases. Each case knew the wall height, fog color, ambient
 * color, frame override, and structural decorations for one venue.
 * Adding or editing a venue required editing JavaScript.
 *
 * Now, the viewer reads `window.GALLERY_DATA.venueConfig` (produced by this
 * service). The JS switch is preserved as a fallback for backward
 * compatibility — if `venueConfig` is null or missing `visual_config`, the
 * switch runs as before. This means:
 *
 *   - Existing galleries keep working unchanged.
 *   - New / edited venues pick up their configuration automatically.
 *   - The hardcoded switch can be removed in a future cleanup once every
 *     venue has a complete `visual_config` JSON in the database.
 *
 * USAGE
 * -----
 * In GalleryViewController::show():
 *
 *     $venueConfig = $gallery->venueTemplate
 *         ? app(\App\Services\VenueConfigExporter::class)->forGallery($gallery)
 *         : null;
 *
 *     $galleryData['venueConfig'] = $venueConfig;
 *
 * In view.blade.php, replace the start of `applyVenueOverrides(slug)`:
 *
 *     applyVenueOverrides(slug) {
 *         const cfg = window.GALLERY_DATA.venueConfig;
 *         if (cfg && cfg.visual_config && Object.keys(cfg.visual_config).length) {
 *             this._applyVenueConfig(cfg);
 *             return;
 *         }
 *         // ... legacy hardcoded switch follows ...
 *     }
 *
 * And add a new method `_applyVenueConfig(cfg)` that reads each field with
 * sensible defaults:
 *
 *     _applyVenueConfig(cfg) {
 *         const v = cfg.visual_config || {};
 *         const m = cfg.material_config || {};
 *
 *         this.wallHeight = v.wall_height ?? 4;
 *         this.wallDepth  = v.wall_depth  ?? 0.3;
 *         this.scene.background = new THREE.Color(v.background_color ?? 0x0f0f0f);
 *         this.scene.fog = v.fog_color
 *             ? new THREE.Fog(new THREE.Color(v.fog_color), v.fog_near ?? 10, v.fog_far ?? 30)
 *             : null;
 *
 *         // ... and so on for ambient_intensity, spot_intensity, fill_intensity,
 *         // tone_mapping_exposure, frame_override, ceiling_type, etc.
 *     }
 *
 * This service is the single source of truth for the JSON shape — if you
 * need to add a new field, add it here first, then teach the viewer to
 * consume it.
 */
class VenueConfigExporter
{
    /**
     * Build the viewer config for a specific gallery + venue combination.
     *
     * The gallery-level wall_texture / floor_material / frame_style /
     * lighting_preset / room_layout (chosen by the curator in the admin
     * panel) override the venue's default_settings — this preserves the
     * existing behaviour where a curator can pick "Zen Gallery" but then
     * manually change the wall to "brick".
     *
     * @return array|null  null if the gallery has no venue template.
     */
    public function forGallery(Gallery $gallery): ?array
    {
        $venue = $gallery->venueTemplate;
        if (!$venue) {
            return null;
        }

        $config = $venue->toViewerConfig();

        // Merge gallery-level overrides on top of the venue's default_settings.
        // The gallery's explicit fields win.
        $config['effective_settings'] = array_merge(
            $venue->default_settings ?? [],
            array_filter([
                'wall_texture'    => $gallery->wall_texture,
                'floor_material'  => $gallery->floor_material,
                'frame_style'     => $gallery->frame_style,
                'lighting_preset' => $gallery->lighting_preset,
                'room_layout'     => $gallery->room_layout,
            ], fn ($v) => !is_null($v))
        );

        // Filter decorations by the visitor's plan so a Free visitor
        // doesn't see Studio-only props.
        $visitorPlan = $gallery->user->plan ?? 'free';
        $config['decorations'] = array_values(array_filter(
            $config['decorations'] ?? [],
            function ($dec) use ($visitorPlan) {
                $required = $dec['plan_required'] ?? 'free';
                return $this->planSees($visitorPlan, $required);
            }
        ));

        // Resolve decoration model paths to absolute URLs.
        foreach ($config['decorations'] as &$dec) {
            if (!empty($dec['model_path'])) {
                $dec['model_url'] = asset('storage/' . ltrim($dec['model_path'], '/'));
            }
        }
        unset($dec);

        return $config;
    }

    /**
     * Export a venue template standalone (no gallery context). Used by
     * the admin "preview" button.
     */
    public function forVenue(VenueTemplate $venue): array
    {
        return $venue->toViewerConfig();
    }

    /**
     * Free sees free. Pro sees free + pro. Studio sees all.
     */
    private function planSees(string $visitorPlan, string $requiredPlan): bool
    {
        return match ($requiredPlan) {
            'free'    => true,
            'pro'     => in_array($visitorPlan, ['pro', 'studio']),
            'studio'  => $visitorPlan === 'studio',
            default   => true,
        };
    }
}
