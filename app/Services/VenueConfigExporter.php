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
     *   (post_fx: the venue declares post-processing inside
     *   visual_config.post_fx — that merged object IS the authority the
     *   runtime reads. The old legacy-shaped sibling `post_fx` bucket this
     *   method used to export next to visual_config was read by NOTHING on
     *   the page-load path (live-preview patches travel over postMessage),
     *   so shipping it was dead bytes with a trap attached: any future
     *   consumer would have silently defeated the venue's declared
     *   post_fx — exactly the class of stale-override bug the
     *   2026-09-05 deployed-screenshot incident turned on. Removed; the
     *   curator's saved post_fx overrides still reach the panel via the
     *   gallery model itself (live-preview-panel.blade.php reads
     *   $overrides['post_fx'] directly), never through this payload.)
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
        //
        // Iteration 2 "Phenomena" (§10.7, landed early per §16.6 — this
        // iteration edits venue templates, so without this the new identity
        // would stay invisible up to 1 h + stale window, and a per-venue
        // config ROLLBACK would stay cached just as long): the key also
        // includes the VENUE TEMPLATE's updated_at. Previously only gallery
        // saves busted the cache — saving the venue template (the exact
        // action an admin takes to fix a venue) did nothing, the documented
        // "my fix isn't live" trap.
        //
        // PARITY FIX (Industrial Loft forensic audit): the key now also
        // includes the OWNER's plan. decorations[] are filtered by that plan
        // (buildConfig below), but the key never carried it — a plan upgrade
        // or downgrade re-keyed NOTHING, so the pre-change decoration set
        // kept serving for up to 1 h + 2 h stale even though a fresh
        // computation would have returned a different set. The preview and
        // the public view could disagree about props for hours after a
        // billing change with zero code difference between the paths.
        $venueTs = $gallery->venueTemplate?->updated_at?->timestamp ?? '0';
        $plan = $gallery->user->plan ?? 'free';
        $cacheKey = "venue_config:{$gallery->id}:{$gallery->updated_at?->timestamp}:v{$venueTs}:p{$plan}";

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

        // ── VENUE-OWNED ATMOSPHERE (post-deploy hotfix, 2026-09-05) ─────
        // background_color is NOT a curator-tunable. It is structural: the
        // venue's bodies (floor_edge_fade, fog ramp, void dome) DERIVE from
        // it, so an override doesn't recolor the venue — it recomposes it
        // into a different venue (the deployed "purple belt" incident:
        // gallery override 0x6D0DA0 painted a violet annulus through the
        // floor edge fade). Retired at every layer: the panel control is
        // removed, the controller strips it on save, and HERE the exporter
        // ignores it both for legacy rows (heals already-broken galleries
        // on deploy — no manual reset needed) and for any future writer.
        // The venue template's declared background is the only authority.
        $overrideVisual = array_filter($overrides['visual_config'] ?? [], fn ($v) => !is_null($v));
        unset($overrideVisual['background_color']);

        if (!empty($overrideVisual)) {
            $config['visual_config'] = array_merge(
                $config['visual_config'] ?? [],
                $overrideVisual
            );
        }

        // Belt-and-braces: even if a future merge path reintroduces the key
        // above, the venue's declared value always wins the final payload.
        $venueBackground = $venue->visual_config['background_color'] ?? null;
        if ($venueBackground !== null) {
            $config['visual_config']['background_color'] = $venueBackground;
        }

        if (!empty($overrides['material_config'])) {
            $config['material_config'] = array_merge(
                $config['material_config'] ?? [],
                array_filter($overrides['material_config'], fn ($v) => !is_null($v))
            );
        }

        // NOTE: gallery visual_overrides['post_fx'] is deliberately NOT
        // exported here. See the class-level merge-order note above — the
        // runtime's only boot-time post-fx authority is the merged
        // visual_config.post_fx; the panel applies curator post-fx edits as
        // live patches over postMessage.

        // Filter decorations by the plan tier this gallery is entitled to
        // render at, so a Free visitor doesn't see Studio-only props.
        //
        // PARITY FIX (Industrial Loft forensic audit — "preview promises more
        // than public delivers"): the filter used the OWNER's plan alone.
        // But a gallery living in a venue ABOVE its owner's plan is
        // GRANDFATHERED (assertVenueAccessibleForPlan only gates NEW saves;
        // PlanDowngradeService deliberately keeps live galleries walkable).
        // The venue walk-through preview renders that venue at its own
        // plan_required tier (forVenuePreview), so the customer previewing a
        // pro/studio venue saw its full prop set — and every real gallery of
        // a lower-plan owner silently stripped them in the public view. The
        // two paths now agree: a gallery renders decorations at the tier of
        // its VENUE ACCESS, which is max(owner plan, venue plan_required).
        // For same-or-below-plan pairings this is byte-identical to the old
        // behaviour; only grandfathered above-plan galleries change, and they
        // change TOWARD what the preview promised.
        $ownerPlan = $gallery->user->plan ?? 'free';
        $venuePlan = $venue->plan_required ?: 'free';
        $visitorPlan = $this->planRank($venuePlan) > $this->planRank($ownerPlan)
            ? $venuePlan      // grandfathered above-plan venue: render at venue tier
            : $ownerPlan;
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

        // Runtime post_fx overrides are NOT merged into the payload here —
        // the iframe receives them as live postMessage patches (the only
        // consumer of patch-shaped post_fx), keeping one post-fx authority
        // in the payload: visual_config.post_fx.
        //
        // background_color is likewise excluded (venue-owned atmosphere —
        // see buildConfig). A stale panel or a hand-crafted ?override= URL
        // cannot repaint the venue through the preview payload either.
        $runtimeVisual = array_filter($runtimeOverrides['visual_config'] ?? [], fn ($v) => !is_null($v));
        unset($runtimeVisual['background_color']);

        $config['visual_config']   = array_merge(
            $config['visual_config']   ?? [],
            $runtimeVisual
        );
        $config['material_config'] = array_merge(
            $config['material_config'] ?? [],
            array_filter($runtimeOverrides['material_config'] ?? [], fn ($v) => !is_null($v))
        );

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

    /**
     * Numeric plan rank for "which tier is this gallery entitled to render
     * at" comparisons (free < pro < studio). Unknown values rank 0.
     */
    private function planRank(string $plan): int
    {
        return match ($plan) {
            'pro'    => 1,
            'studio' => 2,
            default  => 0,
        };
    }
}
