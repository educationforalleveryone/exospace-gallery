<?php

namespace App\Services;

use App\Models\VenueTemplate;
use App\Models\Gallery;
use Illuminate\Support\Facades\Cache;

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
 * THREE-LAYER MERGE
 * -----------------
 * The final config sent to the viewer is the result of merging three layers
 * in order of increasing precedence (last wins):
 *
 *   1. Venue template defaults (from the `venue_templates` row's JSON columns)
 *   2. Gallery-level explicit fields (wall_texture, floor_material,
 *      frame_style, lighting_preset, room_layout — chosen via the venue
 *      picker or the "Override materials" dropdowns on the edit page)
 *   3. Gallery visual_overrides (per-gallery tweaks from the Live Preview
 *      panel — wall height, fog, ambient intensity, PBR roughness, etc.)
 *
 * Layer 2 is kept for back-compat with the existing edit form's hidden
 * inputs. Layer 3 is new — it's the granular, per-gallery override that
 * makes the Live Preview feature possible without modifying venue templates.
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
 */
class VenueConfigExporter
{
    /**
     * Build the viewer config for a specific gallery + venue combination.
     *
     * The merge order is:
     *   venue->visual_config  ←  gallery->visual_overrides['visual_config']
     *   venue->material_config ← gallery->visual_overrides['material_config']
     *   venue has no post_fx column yet, so post_fx comes ONLY from overrides.
     *
     * Null values inside the override buckets are stripped before merge so
     * that "reset to default" (which writes null) doesn't clobber the venue
     * default with a null.
     *
     * @return array|null  null if the gallery has no venue template.
     */
    public function forGallery(Gallery $gallery): ?array
    {
        // P2-21: Cache the merged config per gallery. The cache key includes
        // the gallery's updated_at timestamp so any save (title, visual
        // overrides, venue template change) automatically busts the cache.
        // TTL is 1 hour with a 2-hour stale window (flexible = stampede-safe).
        $cacheKey = "venue_config:{$gallery->id}:{$gallery->updated_at?->timestamp}";

        return Cache::flexible($cacheKey, [now()->addHour(), now()->addHours(2)], function () use ($gallery) {
            return $this->buildConfig($gallery);
        });
    }

    private function buildConfig(Gallery $gallery): ?array
    {
        $venue = $gallery->venueTemplate;
        if (!$venue) {
            return null;
        }

        $config = $venue->toViewerConfig();

        // Layer 2 — gallery-level explicit fields (kept for back-compat with
        // the existing edit form's hidden inputs and the legacy JS switch).
        // The gallery's explicit fields win over the venue's default_settings.
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

        // Layer 3 — per-gallery visual_overrides from the Live Preview panel.
        // Merged on top of the venue's visual_config + material_config so the
        // viewer sees the curator's tweaks without us having to mutate the
        // venue template (which is shared across galleries).
        $overrides = $gallery->visualOverridesArray();

        if (!empty($overrides['visual_config'])) {
            $config['visual_config'] = array_merge(
                $config['visual_config'] ?? [],
                array_filter($overrides['visual_config'], fn ($v) => !is_null($v))
            );
        }

        if (!empty($overrides['material_config'])) {
            $config['material_config'] = array_merge(
                $config['material_config'] ?? [],
                array_filter($overrides['material_config'], fn ($v) => !is_null($v))
            );
        }

        if (!empty($overrides['post_fx'])) {
            $config['post_fx'] = array_filter($overrides['post_fx'], fn ($v) => !is_null($v));
        }

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
     * Export a venue for the PUBLIC walkable preview (Iteration 1 "The
     * Rehearsal", roadmap P1.1) — the first production consumer of
     * forVenue()'s machinery.
     *
     * PREVIEW HONESTY RULE
     * --------------------
     * A pre-signup visitor previewing a venue must see the venue rendered
     * AT THE TIER THAT UNLOCKS IT (the venue's own plan_required), not the
     * maximal studio-prop rendering. forVenue() returns ALL decorations
     * regardless of plan — fine for a super-admin tool, but a promise gap
     * on a public surface (a free-tier venue preview would show studio-only
     * props the customer would never get). This method filters decorations
     * with the same planSees() ladder as forGallery(), using the venue's
     * plan_required as the visitor plan:
     *
     *   free venue   → free decorations only
     *   pro venue    → free + pro decorations
     *   studio venue → all decorations
     *
     * There is deliberately NO gallery layer here: no wall_texture/floor
     * overrides, no visual_overrides, no cache keyed by gallery id — a
     * preview renders the venue's own defaults, exactly what a buyer of
     * that venue gets before they start customizing.
     */
    public function forVenuePreview(VenueTemplate $venue): array
    {
        $config = $venue->toViewerConfig();

        $config['effective_settings'] = $venue->default_settings ?? [];

        $visitorPlan = $venue->plan_required ?: 'free';

        $config['decorations'] = array_values(array_filter(
            $config['decorations'] ?? [],
            fn ($dec) => $this->planSees($visitorPlan, $dec['plan_required'] ?? 'free')
        ));

        foreach ($config['decorations'] as &$dec) {
            if (!empty($dec['model_path'])) {
                $dec['model_url'] = asset('storage/' . ltrim($dec['model_path'], '/'));
            }
        }
        unset($dec);

        return $config;
    }

    /**
     * Build a preview config for the admin Live Preview iframe.
     *
     * Same as forGallery() but also accepts a runtime override patch (from
     * the URL `?override=` param) so the iframe can render un-saved tweaks
     * without writing to the database first.
     *
     * @param array $runtimeOverrides  Same shape as gallery->visual_overrides.
     *                                 Merged on top of the gallery's stored
     *                                 overrides so unsaved slider tweaks win.
     */
    public function forGalleryPreview(Gallery $gallery, array $runtimeOverrides = []): ?array
    {
        $config = $this->forGallery($gallery);
        if (!$config) return null;

        $merged = [
            'visual_config'   => array_merge(
                $config['visual_config']   ?? [],
                array_filter($runtimeOverrides['visual_config']   ?? [], fn ($v) => !is_null($v))
            ),
            'material_config' => array_merge(
                $config['material_config'] ?? [],
                array_filter($runtimeOverrides['material_config'] ?? [], fn ($v) => !is_null($v))
            ),
            'post_fx'         => array_merge(
                $config['post_fx']         ?? [],
                array_filter($runtimeOverrides['post_fx']         ?? [], fn ($v) => !is_null($v))
            ),
        ];

        $config['visual_config']   = $merged['visual_config'];
        $config['material_config'] = $merged['material_config'];
        $config['post_fx']         = $merged['post_fx'];

        return $config;
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
