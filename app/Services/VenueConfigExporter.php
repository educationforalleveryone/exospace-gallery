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
     * Payload schema version — baked into the forGallery() cache key. Bump
     * whenever the merge semantics change so already-cached merged configs
     * bust on deploy (deploy-time heal with no user action).
     *
     * s2 (Dark Museum deployed-screenshot incident, 2026-09-06): the
     * venue-owned authority set expanded from background_color alone to the
     * full atmosphere/architecture/rig list below. Cached payloads built
     * under s1 may still carry leaked keys — the version bump re-keys every
     * gallery so the next render recomputes under the new guard.
     *
     * s3 (post-hotfix residual incident, 2026-09-06): the user's second
     * deployed screenshot proved the stale layer also rode through the two
     * buckets s2 did NOT guard — material_config (only texture_tint was
     * owned, so a white-cube-era floor layer recomposed the museum's dark
     * stone into a bright polished plane that ambient 3.2 lifts into the
     * brightest surface in the room) and visual_config.post_fx (a nested
     * object key; array_merge replaces the venue's whole post-fx object, so
     * a stale {bloom:true} re-armed bloom and fell the blend back to the
     * stock grey glow). post_fx + placement are now venue-owned nested
     * keys, the material identity set is owned in full, and this bump
     * re-keys every gallery so the residual layer heals on deploy.
     *
     * s4 (environment-authority audit, 2026-09-07): the LAST unguarded
     * identity channel was the ENVIRONMENT (HDRI). It was never part of the
     * override merge — it rode in through a side door: the runtime resolved
     * the HDRI from the GALLERY's lighting_preset column (bright→studio.hdr,
     * moody→rural_evening.hdr, dramatic→night.hdr), so a stale gallery-era
     * preset could install a different sky in any venue, and the floor/frame
     * materials read the PRESET's envIntensity instead of the venue's
     * declared env_intensity (the Dark Museum cloud-sheen root cause —
     * see EXOSPACE_VENUES.md §"Environment"). s4 makes the environment a
     * venue-owned key (visual_config.environment), ships the owned-key
     * lists IN the payload so the runtime patch guard can never drift from
     * this file, and bumps the schema so every cached payload re-keys on
     * deploy.
     */
    public const SCHEMA = 's4';

    /**
     * VENUE-OWNED ATMOSPHERE, ARCHITECTURE AND RIG (visual_config).
     *
     * The venue template is the single authority for everything that
     * COMPOSES the venue rather than decorating it. A gallery-level value
     * for any of these keys does not tune the venue — it recomposes it into
     * a different one (the deployed "purple belt" incident via
     * background_color; the Dark Museum deployed-screenshot incident via
     * fog_color + the pre-polish dim rig + open_air + floor_reflection).
     *
     *   • atmosphere  — background/fog: the void dome, floor-edge fade and
     *     distance read all derive from them; a curator tint re-skins the
     *     venue ("Fog Tint stays" proved wrong: on a museum whose fog ramp
     *     IS the room, a violet fog paints a violet belt across the far
     *     wall and the room stops reading as a room).
     *   • architecture— wall/ceiling geometry + open_air + structure_pass
     *     + void_* flags: these decide whether the venue is a room at all
     *     (open_air:true silently strips every wall and ceiling).
     *   • rig         — ambient/spot/fill/exposure/hemisphere/env and the
     *     artwork legibility floor: the v2 museum's readability contract
     *     ("no artwork sits in the dark") is enforced by these numbers; a
     *     stale pre-polish rig defeats every venue-side remediation.
     *   • curation    — placement: the density/focal-wall/pairing object IS
     *     the venue's hang. A stale packed-density layer re-crams the walls
     *     and undoes the composed arrival (s3).
     *   • presentation— post_fx: a NESTED object under visual_config, which
     *     array_merge replaces WHOLESALE — a stale {bloom:true} from the
     *     pre-restraint era re-arms bloom and drops the venue's black-blend
     *     vignette back to the stock grey glow (the s3 residual incident's
     *     haloed lights and lifted blacks). Owned as one object: bloom on/
     *     off is the venue's restraint declaration, not a per-gallery knob.
     *
     * Stripped unconditionally at every layer (save-side normalizer,
     * buildConfig merge, preview runtime patches) — legacy rows HEAL on
     * deploy with no manual reset, exactly like background_color before.
     */
    public const VENUE_OWNED_VISUAL_KEYS = [
        // atmosphere (s4: environment = WHICH sky/HDRI the venue IS — the
        // venue's atmosphere identity, never a curator knob; the gallery's
        // lighting_preset column no longer reaches the environment at all)
        'background_color', 'fog_color', 'fog_near', 'fog_far', 'environment',
        // architecture + structure identity
        'open_air', 'layout_shape', 'wall_height', 'wall_depth',
        'ceiling_type', 'ceiling_color', 'ceiling_height',
        'structure_pass', 'placement_mode', 'floor_reflection',
        // lighting rig + legibility floor
        'ambient_color', 'ambient_intensity', 'spot_intensity',
        'fill_intensity', 'hemisphere_intensity', 'env_intensity',
        'tone_mapping_exposure',
        'artwork_light_base', 'artwork_light_pool_cap',
        // curation + presentation (s3 — nested objects, owned wholesale)
        'placement', 'post_fx',
    ];

    /**
     * Venue-owned material identity, owned IN FULL as of s3.
     *
     * texture_tint is not a look — it is the flag that lets the venue's
     * declared colours reach textured builds at all (its absence was the
     * v1.0.0 museum's White-Cube-white headline bug). A saved false
     * re-breaks every textured venue.
     *
     * s3 (post-hotfix residual incident): texture_tint alone was far too
     * narrow a guard. The user's second deployed screenshot showed the
     * museum with healed fog/walls/rig but a BRIGHT POLISHED FLOOR — a
     * white-cube-era floor layer (light colour + low roughness + metal)
     * riding through the unguarded material bucket, defeating the venue's
     * declared dark stone (0x3a3835 / 0.3 / 0.15) and, lifted by the rig's
     * own ambient, becoming the brightest plane in the room — the exact
     * hierarchy inversion the night wing was built to prevent. The venue
     * composes its materials the same way it composes its atmosphere: the
     * full declared set below is venue authority now. The panel's four
     * roughness/metalness sliders are retired with this change (same
     * grounds as the fog/exposure retirements — the panel keeps the
     * legitimate lanes: materials pickers, frames, audio).
     */
    public const VENUE_OWNED_MATERIAL_KEYS = [
        'texture_tint',
        'wall_color', 'wall_roughness', 'wall_metalness', 'wall_normal_strength',
        'floor_color', 'floor_roughness', 'floor_metalness',
        'floor_normal_strength', 'floor_tile_meters',
    ];

    /**
     * True when a visual_config key is venue-owned. The void_* effect flags
     * are declared per-venue and never curator-tunable, so they are matched
     * by prefix rather than enumerated (new flags inherit the guard).
     */
    public static function isVenueOwnedKey(string $key): bool
    {
        return in_array($key, self::VENUE_OWNED_VISUAL_KEYS, true)
            || str_starts_with($key, 'void_');
    }

    /**
     * The runtime mirror of the ownership sets, shipped INSIDE the payload
     * (s4): GalleryScene.applyLiveOverride drops patch keys listed here
     * before any handler sees them. Shipping the list — instead of
     * hardcoding a second copy in JS — means the two layers can never
     * drift: this file stays the single definition, and a future owned-key
     * expansion guards the runtime on the very next payload rebuild.
     *
     * The void_* prefix rule is enforced runtime-side (it is a stable
     * vocabulary convention, not a per-key list).
     */
    public static function ownedKeyPayload(): array
    {
        return [
            'venue_owned_visual'   => array_values(self::VENUE_OWNED_VISUAL_KEYS),
            'venue_owned_material' => array_values(self::VENUE_OWNED_MATERIAL_KEYS),
        ];
    }

    /**
     * VENUE-OWNED LIGHTING PRESET RESOLUTION (s4).
     *
     * The lighting preset name drives ONLY the renderer's generic fallbacks
     * now (proximity radius, undeclared rig fallbacks) — after s4 it NO
     * LONGER picks the environment for venue-managed galleries. To keep
     * those fallbacks venue-consistent, a gallery WITH a venue renders the
     * VENUE's default preset, not whatever preset the gallery row carried
     * from an earlier era (the preview/public divergence fix: the public
     * path used to pass the gallery column while venues.preview passed the
     * venue default — two presets, two skies, one venue).
     *
     * Venue-less (legacy) galleries keep their own column value.
     */
    public function presetForGallery(Gallery $gallery): string
    {
        $venuePreset = $gallery->venueTemplate?->default_settings['lighting_preset'] ?? null;

        return is_string($venuePreset) && $venuePreset !== ''
            ? $venuePreset
            : ($gallery->lighting_preset ?: 'bright');
    }

    /**
     * VENUE-CONSTRAINED LAYOUT RESOLUTION (s4).
     *
     * room_layout is a gallery-owned exhibition choice, but only within the
     * layouts the venue declares it supports (VenueTemplate::supportsLayout
     * existed since the column shipped and NOTHING called it on the render
     * path — a gallery holding a corridor value from a previous venue could
     * force a corridor shell into a venue that only declares square,
     * silently breaking its structure passes and sizing). A value the
     * venue does not support falls back to the venue's default layout;
     * venue-less galleries keep their column value.
     */
    public function layoutForGallery(Gallery $gallery): string
    {
        $layout  = $gallery->room_layout ?: 'square';
        $venue   = $gallery->venueTemplate;

        if (!$venue) {
            return $layout;
        }

        return $venue->supportsLayout($layout)
            ? $layout
            : ($venue->default_settings['room_layout'] ?? 'square');
    }
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
        // s5-audit: the venue is resolved through a FRESH relation query on
        // every call — a caller-held Gallery instance can carry an eager-
        // loaded venueTemplate from an earlier render in the same process
        // (queues, long-lived controllers, integration tests), and that
        // stale instance would silently re-serve the old cache key.
        $venue = $gallery->venueTemplate()->first();
        $venueTs = $venue?->updated_at?->timestamp ?? '0';
        // s5-audit: content signature — updated_at has second precision, so
        // two saves within the same wall-clock second (save → snapshot
        // restore-rollback is exactly that pattern) produced the SAME key
        // and the pre-save payload kept serving for the whole flexible-TTL
        // window. The signature changes whenever any venue-owned content the
        // payload depends on changes, and stays stable for content-identical
        // saves (so gratuitous touches do not stampede the cache).
        $venueSig = $this->venueSignature($venue);
        $plan = $gallery->user()->value('plan') ?? 'free';
        // :s{SCHEMA} — bumped when merge semantics change, so already-cached
        // payloads bust on deploy (an authority-set expansion must not wait
        // out the 1 h + 2 h stale window to take effect).
        $cacheKey = "venue_config:{$gallery->id}:{$gallery->updated_at?->timestamp}:v{$venueTs}:{$venueSig}:p{$plan}:" . self::SCHEMA;

        return Cache::flexible($cacheKey, [now()->addHour(), now()->addHours(2)], function () use ($gallery) {
            return $this->buildConfig($gallery);
        });
    }

    /**
     * s5-audit: content fingerprint of every venue-owned column the merged
     * payload depends on. Cheap (json_encode of a few KB, sha1) and computed
     * per forGallery call — the call itself is cached, so this only runs on
     * the cache-lookup path.
     */
    private function venueSignature(?VenueTemplate $venue): string
    {
        if (!$venue) {
            return 'nov';
        }

        return substr(sha1((string) json_encode([
            $venue->visual_config,
            $venue->material_config,
            $venue->decorations,
            $venue->lighting_fixtures,
            $venue->default_settings,
            $venue->supported_layouts,
            $venue->hdri_path,
            $venue->plan_required,
            $venue->is_draft,
            $venue->is_active,
            $venue->archived_at?->timestamp,
        ])), 0, 16);
    }

    private function buildConfig(Gallery $gallery): ?array
    {
        // s5-audit: fresh relation query (see forGallery) — the closure can
        // run under a caller-held Gallery instance whose eager-loaded
        // venueTemplate predates the very save this key was minted for.
        $venue = $gallery->venueTemplate()->first();
        if (!$venue) {
            return null;
        }

        $config = $venue->toViewerConfig();

        // s4: ship the ownership sets so the runtime patch guard mirrors
        // this file exactly (see ownedKeyPayload()).
        $config += self::ownedKeyPayload();

        // Layer 2 — gallery-level explicit exhibition fields. These are the
        // LEGITIMATE lanes (surface family, frames, layout-within-support).
        // s4: lighting_preset and room_layout resolve through the venue
        // authority (presetForGallery / layoutForGallery) so the payload's
        // view of them can never disagree with what the controllers ship at
        // the GALLERY_DATA top level (single resolution, one place).
        $config['effective_settings'] = array_merge(
            $venue->default_settings ?? [],
            array_filter([
                'wall_texture'    => $gallery->wall_texture,
                'floor_material'  => $gallery->floor_material,
                'frame_style'     => $gallery->frame_style,
                'lighting_preset' => $this->presetForGallery($gallery),
                'room_layout'     => $this->layoutForGallery($gallery),
            ], fn ($v) => !is_null($v))
        );

        // Layer 3 — per-gallery visual_overrides from the Live Preview panel.
        // Merged on top of the venue's visual_config + material_config so the
        // viewer sees the curator's tweaks without us having to mutate the
        // venue template (which is shared across galleries).
        $overrides = $gallery->visualOverridesArray();

        // ── VENUE-OWNED ATMOSPHERE / ARCHITECTURE / RIG ─────────────────
        // (extends the 2026-09-05 background_color hotfix to the full
        // composed-venue set — see the class consts for the incident history
        // behind each group. Dark Museum deployed-screenshot incident,
        // 2026-09-06: a legacy override layer carrying violet fog + the
        // pre-polish dim rig + open_air + floor_reflection recomposed the
        // night wing into a purple void WITH museum furniture, because only
        // background_color was guarded.)
        //
        // The exporter ignores them for legacy rows (heals already-broken
        // galleries on deploy — no manual reset needed) and for any future
        // writer. The venue template's declaration is the only authority.
        $overrideVisual = array_filter($overrides['visual_config'] ?? [], fn ($v) => !is_null($v));
        foreach (array_keys($overrideVisual) as $key) {
            if (self::isVenueOwnedKey((string) $key)) {
                unset($overrideVisual[$key]);
            }
        }

        if (!empty($overrideVisual)) {
            $config['visual_config'] = array_merge(
                $config['visual_config'] ?? [],
                $overrideVisual
            );
        }

        // Belt-and-braces: even if a future merge path reintroduces an owned
        // key, the venue's declared value always wins the final payload.
        $venueVisual = $venue->visual_config ?? [];
        foreach (self::VENUE_OWNED_VISUAL_KEYS as $owned) {
            if (array_key_exists($owned, $venueVisual) && $venueVisual[$owned] !== null) {
                $config['visual_config'][$owned] = $venueVisual[$owned];
            }
        }
        foreach (array_keys($config['visual_config'] ?? []) as $key) {
            if (str_starts_with((string) $key, 'void_') && !array_key_exists($key, $venueVisual)) {
                unset($config['visual_config'][$key]); // a venue that never declared a void effect can never grow one from an override
            }
        }

        if (!empty($overrides['material_config'])) {
            $overrideMaterial = array_filter($overrides['material_config'], fn ($v) => !is_null($v));
            foreach (self::VENUE_OWNED_MATERIAL_KEYS as $owned) {
                unset($overrideMaterial[$owned]);
            }
            if (!empty($overrideMaterial)) {
                $config['material_config'] = array_merge(
                    $config['material_config'] ?? [],
                    $overrideMaterial
                );
            }
        }
        $venueMaterial = $venue->material_config ?? [];
        foreach (self::VENUE_OWNED_MATERIAL_KEYS as $owned) {
            if (array_key_exists($owned, $venueMaterial) && $venueMaterial[$owned] !== null) {
                $config['material_config'][$owned] = $venueMaterial[$owned];
            }
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

        // s4: same self-describing ownership contract as forGallery().
        $config += self::ownedKeyPayload();

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
        // The venue-owned set (atmosphere/architecture/rig/curation/
        // presentation/environment — see buildConfig) is likewise excluded:
        // a stale panel or a hand-crafted ?override= URL cannot repaint the
        // venue through the preview payload either. isVenueOwnedKey already
        // covers visual_config.environment (joined the owned set in s4).
        $runtimeVisual = array_filter($runtimeOverrides['visual_config'] ?? [], fn ($v) => !is_null($v));
        foreach (array_keys($runtimeVisual) as $key) {
            if (self::isVenueOwnedKey((string) $key)) {
                unset($runtimeVisual[$key]);
            }
        }

        // s3: the runtime MATERIAL patch gets the same guard. Before this,
        // a hand-crafted preview override could still chrome-plate the
        // venue's floor through material_config even after buildConfig was
        // fully guarded.
        $runtimeMaterial = array_filter($runtimeOverrides['material_config'] ?? [], fn ($v) => !is_null($v));
        foreach (self::VENUE_OWNED_MATERIAL_KEYS as $owned) {
            unset($runtimeMaterial[$owned]);
        }

        $config['visual_config']   = array_merge(
            $config['visual_config']   ?? [],
            $runtimeVisual
        );
        $config['material_config'] = array_merge(
            $config['material_config'] ?? [],
            $runtimeMaterial
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
