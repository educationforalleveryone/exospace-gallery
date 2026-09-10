// ─────────────────────────────────────────────────────────────────────────────
// VenueDecorator — applies venue-specific overrides
//
// ONE path (since Iteration 6 "Consolidation", P2.2):
//   window.GALLERY_DATA.venueConfig.visual_config IS the venue. The DB is
//   the sole source of venue identity (§10.2) — the interpreter reads
//   config keys and never venue names. The old hardcoded fallback switch
//   (legacyVenueSwitch) is DELETED: a venue without visual_config renders
//   as a plain default room with a console warning, never as some other
//   venue's identity.
//
// SCULPTURE GARDEN REDESIGN:
//   Old version: tall walls + dark green background + 2 missing GLBs.
//   New version: circular grass plane + sky dome + procedural hedges + trees +
//   stone path + artworks on easels. No walls, no missing GLBs.
//
// DARK MUSEUM FIX:
//   Divider walls are now registered as collision obstacles via registerObstacle
//   (see Collisions.js), so the player can't walk through them.
//
// NEW VOID VENUES:
//   Crystal Cathedral, Nebula Drift, Mirror Lake — all use the circular ground
//   plane + sky/particle decorations.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { CONFIG, parseColor } from './config.js';
import { loadGlb } from './AssetLoader.js';
import { mergeParts } from './GeometryUtils.js';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { createVenueRng, venueSeedSource } from './Rng.js';
import { buildGardenPlan } from './GardenLayout.js';
// Mirror Lake v3.0.0 "The Still Shore": the pure waterfront plan (shoreline,
// landing, shore walk, pier + pavilion, over-water artwork arc, far shore).
import { buildLakePlan } from './LakeLayout.js';
// Garden v4.0.0 "The Sculpture Park": the ASSET-DRIVEN environment layer —
// role-tagged plan anchors consume owner-supplied GLBs (trees, planting,
// boulders, benches) with graceful per-role fallback and instanced batching.
import {
    resolveGardenAssetRequests,
    loadGardenAssets,
    buildGardenAssetInstances,
    groupAnchorsByRole,
} from './GardenAssets.js';
// Iteration 2 "Phenomena": declared tier-fallback effects + their pure
// decision core. TierEffects/TierResolve contain zero slug knowledge —
// venues opt in per config key (§11.3: degradation is DESIGNED, not emergent).
import {
    makeGlassMaterial,
    addPlanarReflection,
    addMoonLightStreak,
    addFloorEdgeFade,
    addWaterReflection,
} from './TierEffects.js';
import { resolveReflectionMode } from './TierResolve.js';
// Iteration 3 "Rooms": the generic structure-descriptor interpreter (§10.3).
// Zero slug knowledge — venues opt in per config key (structure_pass +
// structure). Zen / Penthouse / Cyber are the vocabulary's first consumers.
import { buildStructure, resolveAnchor } from './StructureBuilder.js';

// Iteration 6 "Consolidation" (P2.2 + P2.3): the opt-in curator layer.
import { resolveSpacing } from './PlacementCuration.js';
// Industrial Loft deepening: placement parity — the structure pass derives
// its artwork lanes from the SAME centred-run math the placer uses.
// Zen "Quiet Procession" (framed bays): the bay architecture consumes the
// same pure run plans — one math for structure and hang, never two.
import { wallRunOffset, squareRunPlan, lshapeRowPlan } from './ArtworkPlacer.js';

// ── Top-level dispatcher ────────────────────────────────────────────────────
export function applyVenueOverrides(slug) {
    const cfg = window.GALLERY_DATA.venueConfig;
    if (cfg && cfg.visual_config && Object.keys(cfg.visual_config).length) {
        this.applyVenueConfig(cfg);
        return;
    }
    // ── Iteration 6: the hardcoded per-venue fallback is GONE. A venue
    // without visual_config renders as a plain default room (CONFIG.room
    // values) — it must never silently inherit SOME OTHER venue's
    // identity. The slug remains available as seed data only.
    console.warn('[exospace] venue has no visual_config — rendering default room. ' +
                 'Declare the venue identity in its template JSON (§10.2).');
    this._venueSlug = slug || 'venue';
}

// ── Data-driven config application ───────────────────────────────────────────
export function applyVenueConfig(cfg) {
    const v = cfg.visual_config || {};
    const m = cfg.material_config || {};

    if (v.wall_height)                CONFIG.room.wallHeight = v.wall_height;
    if (v.wall_depth)                 CONFIG.room.wallDepth  = v.wall_depth;
    if (v.background_color)           this.scene.background  = parseColor(v.background_color);
    if (v.fog_color) {
        this.scene.fog = new THREE.Fog(
            parseColor(v.fog_color),
            v.fog_near ?? 10,
            v.fog_far  ?? 30
        );
        // Renderer.applyLowEndSettings/applyMobileSettings read this: a
        // VENUE-DECLARED fog is identity, not a quality knob — tier changes
        // must never recompose it (degradation-parity guard).
        this._venueFogDeclared = true;
    } else if (v.fog_color === null) {
        this.scene.fog = null;
        this._venueFogDeclared = true;
    }
    if (v.ambient_color)              this._venueAmbientColor     = parseColor(v.ambient_color);
    if (v.ambient_intensity != null)  this._venueAmbientIntensity = v.ambient_intensity;
    if (v.spot_intensity != null)     this._venueSpotIntensity    = v.spot_intensity;
    if (v.fill_intensity != null)     this._venueFillIntensity    = v.fill_intensity;
    if (v.tone_mapping_exposure != null) this.renderer.toneMappingExposure = v.tone_mapping_exposure;
    if (v.frame_override)             this._venueFrameStyleOverride    = v.frame_override;
    if (v.ceiling_type)               this._venueCeilingType      = v.ceiling_type;

    // ── post_fx : venue-declared post-processing identity ────────────────
    // { bloom, bloom_strength, bloom_threshold, bloom_radius,
    //   vignette, vignette_darkness, vignette_offset } — all optional.
    // A restrained venue (white cube) declares bloom:false; the composer
    // honours the declaration on construction AND through every later
    // quality-level switch. Declared here, interpreted in PostProcessing —
    // zero venue knowledge anywhere (§10.2).
    if (v.post_fx && typeof v.post_fx === 'object') {
        this._venuePostFx = v.post_fx;
        if (this._postFx) this._postFx.applyVenueConfig(this._venuePostFx);
    }

    // ── Iteration 2 "Phenomena" declared-identity keys (§10.2: the DB is the
    // sole source of venue identity; JS interprets, never knows) ──────────
    // placement_mode    : 'float' → artworks hover (ArtworkPlacer dispatch)
    // env_intensity     : venue-level scene.environment strength — lets a
    //                     venue silence the accidental horizon glow of its
    //                     lighting preset's HDRI (Nebula/night.hdr defect)
    // structure_pass    : which generation of structure code renders — the
    //                     per-venue ROLLBACK SWITCH (remove the key → the
    //                     venue reverts to its pre-pass render)
    // glass_material / floor_reflection / floor_edge_fade : read where used
    //                     (Cathedral colonnade / Mirror Lake / RoomBuilder)
    this._venueVisualConfig = v;
    if (v.placement_mode)          this._venuePlacementMode = v.placement_mode;
    if (v.env_intensity != null)   this._venueEnvIntensity  = v.env_intensity;
    // Standing-glow fraction for the pooled artwork lights (Lighting.js).
    // 0..1; absent/invalid ⇒ the historical 0.15 default (untouched venues).
    if (v.artwork_light_base != null) this._venueArtworkLightBase = v.artwork_light_base;
    // Optional pool raise so every artwork of a typical hang carries its
    // standing glow at once (Lighting._ensureLightPool; tier floors apply).
    if (v.artwork_light_pool_cap != null) this._venueArtworkLightPoolCap = v.artwork_light_pool_cap;
    // Hemisphere fill strength (0..~0.5). The shared HemisphereLight used to
    // be a hard-coded 0.15 white wash in EVERY venue — in a controlled-
    // darkness venue that constant grey top-down light fights the venue's
    // own hierarchy. Config-declared (visual_config.hemisphere_intensity);
    // absent ⇒ the historical 0.15 (untouched venues render unchanged).
    if (v.hemisphere_intensity != null) this._venueHemisphereIntensity = v.hemisphere_intensity;
    // ── Cyber Gallery iteration: movement-reactive artwork declaration ──
    // visual_config.artwork_reactive = { enabled, dead_zone, ref_speed,
    // attack, release, max_intensity, bezel_color } — the venue's signature
    // interaction, interpreted by ArtworkReactive.js (TierResolve pattern:
    // pure decision core, designed tier degradation). Absent ⇒ null ⇒ the
    // venue takes the historic static-canvas path byte-identically. Read in
    // applyVenueConfig (boot + Live-Preview rebuilds); consumed by
    // RoomBuilder.buildGallery → initArtworkReactive BEFORE placeArtworks.
    if (v.artwork_reactive !== undefined) this._venueArtworkReactive = v.artwork_reactive;

    this._venueMaterialConfig = m;
    this._venueSlug = cfg.slug || 'venue';

    // ── Iteration 6 "Consolidation" keys (P2.2 + P2.3) ──────────────
    // layout_shape  : 'circular' forces the circular shell (replaces the
    //                 CIRCULAR_VENUES slug set; read in RoomBuilder + here)
    // open_air      : true ⇒ no ceiling (replaces the OPEN_AIR_VENUES set)
    // ceiling_color : '0x…' ceiling tint (replaces the per-slug chains)
    // ceiling_beams / ceiling_neon : declared shell details (replaces the
    //                 industrial-loft / cyber-gallery slug branches)
    // placement     : opt-in curation (§6.3–§6.5) — density / pairing / focal
    if (v.layout_shape) this._venueLayoutShape = v.layout_shape;
    if (v.placement && typeof v.placement === 'object') {
        this._venuePlacement = v.placement;
        // §6.3 density: the resolved spacing feeds room sizing AND hang
        // offsets (both read CONFIG.room.artworkSpacing — they can never
        // disagree). Absent/unknown preset ⇒ unchanged spacing.
        const spacing = resolveSpacing(v.placement, CONFIG.room.artworkSpacing);
        if (spacing !== CONFIG.room.artworkSpacing) {
            CONFIG.room.artworkSpacing = spacing;
        }
    }

    if (Array.isArray(cfg.decorations) && cfg.decorations.length) {
        this.loadDecorations(cfg.decorations);
    }
    // ── ANCHORED FIXTURES (Luxury Penthouse identity pass) ────────────
    // A fixture may declare `anchor: { from, offset: [side, up, forward],
    // turn }` instead of an absolute `position` — the same grammar the
    // structure descriptors speak. Anchored fixtures can only resolve
    // AFTER the room builds (the anchors are derived from _layoutMeta /
    // _glazing), so they are stashed here and resolved in
    // addVenueStructure; absolute fixtures keep the historic boot path
    // byte-identically (a fixture without `anchor` never takes the new
    // code path — §11.3 rule 2).
    const fixtures = Array.isArray(cfg.lighting_fixtures) ? cfg.lighting_fixtures : [];
    this._venueAnchoredFixtures = fixtures.filter(f => f && f.anchor);
    const absoluteFixtures = fixtures.filter(f => !f || !f.anchor);
    if (absoluteFixtures.length) {
        this.addCustomLights(absoluteFixtures);
    }
    if (cfg.hdri_url) this._customHdriUrl = cfg.hdri_url;
}

// ── Live Preview patcher ────────────────────────────────────────────────────
//
// applyVisualPatch(patch) is the Live-Preview counterpart to applyVenueConfig.
// Where applyVenueConfig runs ONCE at scene boot to apply the full venue
// config, applyVisualPatch runs REPEATEDLY (on every slider tweak) to update
// individual venue-state fields without rebuilding the room.
//
// It only updates the internal _venue* state fields that Lighting.js,
// Materials.js, and other modules read on the next frame. The actual scene
// mutations (fog color, background color, light intensities, material
// roughness) are handled by GalleryScene.applyLiveOverride() — which calls
// this function first to sync state, then does the scene-level work.
//
// This split exists so venue-state updates don't get duplicated: this
// function owns the "what's the current venue intent" state, and the
// scene-level mutator owns "apply that intent to THREE.Scene objects".
//
// Null values in the patch revert to the venue template's default (read
// from window.GALLERY_DATA.venueConfig).
export function applyVisualPatch(patch) {
    if (!patch || typeof patch !== 'object') return;

    const venueCfg = window.GALLERY_DATA?.venueConfig?.visual_config || {};

    if ('ambient_color'      in patch) this._venueAmbientColor      = patch.ambient_color      === null ? null      : parseColor(patch.ambient_color);
    if ('ambient_intensity'  in patch) this._venueAmbientIntensity  = patch.ambient_intensity  === null ? null      : patch.ambient_intensity;
    if ('spot_intensity'     in patch) this._venueSpotIntensity     = patch.spot_intensity     === null ? null      : patch.spot_intensity;
    if ('fill_intensity'     in patch) this._venueFillIntensity     = patch.fill_intensity     === null ? null      : patch.fill_intensity;
    if ('tone_mapping_exposure' in patch && this.renderer) {
        this.renderer.toneMappingExposure = patch.tone_mapping_exposure === null
            ? (venueCfg.tone_mapping_exposure ?? 0.5)
            : patch.tone_mapping_exposure;
    }
    if ('frame_override'    in patch) this._venueFrameStyleOverride      = patch.frame_override     === null ? null      : patch.frame_override;
    if ('ceiling_type'      in patch) this._venueCeilingType        = patch.ceiling_type       === null ? null      : patch.ceiling_type;

    // Material config patches (wall/floor PBR overrides) — stored on the
    // _venueMaterialConfig object so Materials.js reads them on the next
    // material rebuild. For LIVE updates (no rebuild), GalleryScene.
    // applyLiveOverride() pokes the material props directly.
    if (patch._materialPatch && typeof patch._materialPatch === 'object') {
        this._venueMaterialConfig = {
            ...(this._venueMaterialConfig || {}),
            ...patch._materialPatch,
        };
    }
}

// ── Iteration 6 "Consolidation" (P2.2): legacyVenueSwitch is DELETED.
// The hardcoded per-venue fallback no longer exists anywhere — the DB's
// visual_config is the ONLY source of venue identity (§10.2).
// ── Load 3D decoration props (GLB) asynchronously ─────────────────────────────
// PERF-A13 (3D audit F13): decorations now load in PARALLEL via
// Promise.allSettled. The old `for…of await` sequence downloaded each GLB
// one after another — on venues with several props (benches, pedestals,
// plants) the last prop waited for every previous download + parse before
// its request even started. Failures are still per-prop (allSettled), and
// obstacle registration order is irrelevant to the collision system.
export async function loadDecorations(decorations) {
    const place = async (dec) => {
        try {
            const url = dec.model_url || dec.model_path;
            if (!url) return;
            const obj = await loadGlb.call(this, url);
            if (dec.position) obj.position.set(dec.position[0], dec.position[1], dec.position[2]);
            if (dec.rotation) obj.rotation.set(dec.rotation[0], dec.rotation[1], dec.rotation[2]);
            if (typeof dec.scale === 'number')      obj.scale.setScalar(dec.scale);
            else if (Array.isArray(dec.scale))       obj.scale.set(dec.scale[0], dec.scale[1], dec.scale[2]);

            obj.traverse(child => {
                if (child.isMesh) {
                    child.castShadow    = !this.isLowEnd;
                    child.receiveShadow = !this.isLowEnd;
                    // Register physical props as collision obstacles
                    if (dec.solid !== false) this.registerObstacle(child);
                }
            });
            this.scene.add(obj);
        } catch (err) {
            console.warn('Decoration load failed:', dec.model_path || dec.model_url, err);
        }
    };

    await Promise.allSettled(decorations.map(place));
}

// NOTE (PERF-A14 / 3D audit F14): addCustomLights() used to be duplicated
// here AND in Lighting.js — identical function bodies. GalleryScene now
// imports the Lighting.js copy only; this file's duplicate is deleted to
// keep a single source of truth (smaller bundle, no drift risk).

// ── Venue structure (in-room details: beams, dividers, hedges, particles) ────
// This is the venue-specific decoration that's NOT a GLB — it's procedural
// geometry we build in code. Each venue can have bespoke code here.
export function addVenueStructure(data) {
    const vc = this._venueVisualConfig || {};
    const pass = vc.structure_pass;
    const slug = this._venueSlug || 'venue';

    // Iteration 0 (roadmap P0.3): all procedural distribution below draws
    // from a seeded generator — hash(venue_slug + ':' + gallery_id) — so the
    // same venue + gallery renders an IDENTICAL composition on every load.
    // Rebuilt scenes (Live Preview override reloads) recreate the rng from
    // the same seed, so rebuilds are stable too.
    // Sculpture Garden iteration: createRoomCircular may have ALREADY created
    // this rng (the garden's landscape plan draws first so terrain, walks,
    // courts and vegetation share one deterministic stream). Guard — never
    // reseed mid-build.
    if (!this._venueRng) this._venueRng = createVenueRng(venueSeedSource(slug));

    // Iteration 3: hangable surfaces are (re)built WITH the structure — a
    // Live-Preview rebuild must never accumulate stale surfaces.
    this._hangableSurfaces = [];

    // ── ANCHORED FIXTURES resolution (runs here because the anchors only
    // exist after the room built; see applyVenueConfig for the split).
    // Live-Preview rebuilds re-run addVenueStructure, so re-resolving is
    // idempotent per build — the same guarantee the structure itself has.
    if (Array.isArray(this._venueAnchoredFixtures) && this._venueAnchoredFixtures.length) {
        const resolved = [];
        for (const f of this._venueAnchoredFixtures) {
            const a = resolveAnchor(this, f.anchor?.from);
            if (!a) continue;                  // anchor unavailable on this layout — skip, never guess
            const o = f.anchor.offset || [0, 0, 0];
            const fx = a.fwd[0], fz = a.fwd[2];
            const sx = fz, sz = -fx;           // side = up × fwd (horizontal)
            const baseY = f.anchor.up === 'ceiling' ? (a.height || 4) : 0;
            resolved.push({
                ...f,
                anchor: undefined,
                position: [
                    a.pos[0] + sx * o[0] + fx * o[2],
                    baseY + o[1],
                    a.pos[2] + sz * o[0] + fz * o[2],
                ],
            });
        }
        if (resolved.length) this.addCustomLights(resolved);
    }

    // ── Iteration 6 "Consolidation" (P2.2): the slug if/else chain is GONE.
    // structure_pass is the SINGLE interpreter selector and stays the
    // per-venue rollback switch (remove/rename the key → that venue's
    // interpreter stops → per-venue config revert restores the pre-pass
    // body; §17 IT6 rollback = per-venue migration order):
    //   'rooms'     descriptor vocabulary (StructureBuilder §10.3) —
    //               Zen / Penthouse / Cyber + any admin-created venue
    //   'cube'      White Cube respect pass (base reveal / crown / fixtures;
    //               internally square+corridor only, as designed in IT3)
    //   'loft'      industrial beams, placement-aware columns, coves, props
    //   'museum'    the night wing: shadow-gap reveal, stone baseboard,
    //               salon cabinets (brass cap + hangable faces) + post-
    //               placement picture lights (every artwork, every layout)
    //   'bays'      framed-bay architecture (Zen "Quiet Procession" + any
    //               venue declaring the idea): fins/headers/recesses/steps/
    //               clerestory/rafters aligned to the hang's own run plan
    //   'garden'    sky dome, sun, hedge ring, trees, path, pedestal
    //   'phenomena' void family — composable flags (void_dust / void_starfield /
    //               void_colonnade / void_shards / void_lake)
    // No pass declared ⇒ no structure. Admin-created venues get FULL identity
    // by declaring the same keys (§17 IT6 outcome).
    if (pass === 'rooms') {
        if (Array.isArray(vc.structure) && vc.structure.length > 0) {
            buildStructure(this, vc.structure);
        }
        // The Media Wall (penthouse v3.1.0, generic for any rooms venue):
        // runs AFTER buildStructure because it self-locates on the bezel
        // descriptor's mesh (structure:<bezel>). Absent key → absent screen;
        // the console/bezel furniture still renders as plain descriptors.
        if (vc.media_wall && typeof vc.media_wall === 'object') {
            buildMediaWall(this, vc.media_wall);
        }
    } else if (pass === 'cube') {
        addWhiteCubeRespectPass.call(this, data);
    } else if (pass === 'loft') {
        addIndustrialLoftStructure.call(this, data);
    } else if (pass === 'museum') {
        addDarkMuseumStructure.call(this, data); // collision + hangable surfaces
    } else if (pass === 'bays') {
        addBaysStructure.call(this, data); // framed-bay architecture — fins,
                                           // headers, recesses, clerestory,
                                           // steps, rafters (collision-registered;
                                           // hang alignment via the shared run plan)
    } else if (pass === 'garden') {
        addSculptureGardenStructure.call(this, data); // grass, hedges, trees, sky
    } else if (pass === 'lake') {
        // Mirror Lake v3.0.0 "The Still Shore" — the waterfront body.
        // (The v1.0.0 void-lake rollback stays reachable at the SAME level:
        // structure_pass 'phenomena' + void_lake renders the phenomena
        // composer's lake body, untouched.)
        addMirrorLakeShore.call(this, (this._layoutMeta || {}).radius, data);
    } else if (pass === 'phenomena') {
        addVoidVenueStructure.call(this, data);
    }
}

// ── WHITE CUBE — the respect pass (Iteration 3, roadmap §4.1) ───────────────
// Small, deliberate craft details so large rooms stop reading as untextured
// boxes: a base reveal (skirting), a crown line, and VISIBLE ceiling fixtures
// at the exact positions of the fill-light grid RoomBuilder builds (the
// lights were real but invisible — the ceiling had no light source to see).
//
// WHITE CUBE POLISH iteration — three forensic defects fixed here:
//   1. BURIED TRIM: the skirting/crown strips were offset from the wall
//      CENTRE plane by less than half the wall depth, so the strips sat
//      entirely INSIDE the wall boxes (inner face at wallDepth/2 = 0.15,
//      strip half-depth 0.0225) — occluded geometry, zero visual effect in
//      every layout. Offsets are now measured from the INNER FACE and the
//      strips protrude ~2 cm into the room, as designed.
//   2. LAYOUT PARITY: the venue advertises square/corridor/l-shape/rotunda,
//      but the pass only covered square + corridor — an l-shape or rotunda
//      White Cube silently lost its entire identity detail set. The pass now
//      covers all four layouts (l-shape mirrors createRoomLShape's wall
//      segment list; rotunda uses ring bands on the cylinder wall).
//   3. FIXTURE PARITY: fixture discs sit at the exact positions of the
//      ceiling fill lights each layout's builder adds (square 2×2 grid,
//      corridor ±L/4, l-shape wing lights, rotunda centre) — the light
//      sources in the room finally have a visible origin.
// Deterministic: pure geometry from _layoutMeta — no RNG consumed.
function addWhiteCubeRespectPass(data) {
    const meta = this._layoutMeta || {};
    const wh   = CONFIG.room.wallHeight;
    const wd   = CONFIG.room.wallDepth || 0.3;
    const face = wd / 2;              // wall centre plane → inner face
    const baseP = 0.02, crownP = 0.015; // protrusion into the room (metres)

    const revealMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xe9e7e2 })
        : new THREE.MeshStandardMaterial({ color: 0xe9e7e2, roughness: 0.9, metalness: 0.0 });
    const fixtureMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xfff2dd, emissive: 0xffedd0, emissiveIntensity: 1.3 })
        : new THREE.MeshStandardMaterial({ color: 0xfff2dd, emissive: 0xffedd0, emissiveIntensity: 1.3, roughness: 0.6 });

    // Strip emitter — one line of trim along a wall segment. [cx, cz, ry, len,
    // nx, nz] positions the wall's CENTRE plane and its inward normal; the
    // strip's back is embedded in the wall and its front stands `p` proud of
    // the inner face: offset = face + p − depth/2 (never floats, never hides).
    const stripParts = (segments, y, h, depth, p) => {
        const geo = new THREE.BoxGeometry(1, h, depth);
        const off = face + p - depth / 2;
        return segments.map(([cx, cz, ry, len, nx, nz]) => ({
            geo,
            pos: [cx + nx * off, y, cz + nz * off],
            rot: [0, ry, 0],
            scale: [len, 1, 1],
        }));
    };
    const addStrips = (segments, y, h, depth, p) => {
        const parts = stripParts(segments, y, h, depth, p);
        this.scene.add(new THREE.Mesh(mergeParts(parts), revealMat));
        parts.forEach(pt => pt.geo.dispose());
    };
    const addFixtures = (points) => {
        const geo = new THREE.CylinderGeometry(0.3, 0.32, 0.05, 20);
        this.scene.add(new THREE.Mesh(mergeParts(
            points.map(p => ({ geo, pos: [p[0], wh - 0.026, p[1]] }))
        ), fixtureMat));
        geo.dispose();
    };

    if (meta.type === 'square') {
        const L = meta.wallLength;
        const S = Math.PI / 2;
        // [cx, cz, ry, len, inwardNormalX, inwardNormalZ]
        const segments = [
            [0, -L / 2, 0,     L, 0,  1],
            [0,  L / 2, Math.PI, L, 0, -1],
            [-L / 2, 0, S,     L, 1,  0],
            [ L / 2, 0, -S,    L, -1, 0],
        ];
        addStrips(segments, 0.045, 0.09, 0.045, baseP);
        addStrips(segments, wh - 0.025, 0.05, 0.035, crownP);
        // Fixtures at the 2×2 fill-light grid (same math as RoomBuilder).
        const gridStart = -L / 2 + L / 3, step = L / 3;
        const pts = [];
        for (let i = 0; i < 2; i++)
            for (let j = 0; j < 2; j++)
                pts.push([gridStart + i * step, gridStart + j * step]);
        addFixtures(pts);
    } else if (meta.type === 'corridor') {
        const { length, width } = meta;
        const S = Math.PI / 2;
        const segments = [
            [0, -width / 2, 0,     length, 0,  1],
            [0,  width / 2, Math.PI, length, 0, -1],
            [-length / 2, 0, S,  width, 1,  0],
            [ length / 2, 0, -S, width, -1, 0],
        ];
        addStrips(segments, 0.045, 0.09, 0.045, baseP);
        addStrips(segments, wh - 0.025, 0.05, 0.035, crownP);
        addFixtures([[-length / 4, 0], [length / 4, 0]]);
    } else if (meta.type === 'l-shape') {
        // Same segment list createRoomLShape builds, each with its inward
        // normal — trim and fixtures exist on every wall of both wings.
        const { wingW, lenA, lenB, jZ } = meta;
        const S = Math.PI / 2, P = Math.PI;
        const upperH    = jZ - (-lenA / 2);
        const upperMidZ = -lenA / 2 + upperH / 2;
        const bCZ       = lenA / 2 - wingW / 2;
        const segments = [
            [0,                0,        S, lenA,   1,  0],
            [wingW / 2,       -lenA / 2, 0, wingW,  0,  1],
            [wingW,      upperMidZ,      S, upperH, -1,  0],
            [wingW + lenB / 2, jZ,       0, lenB,   0, -1],
            [wingW + lenB,     bCZ,      S, wingW, -1,  0],
            [wingW + lenB / 2, lenA / 2, P, lenB,   0, -1],
            [wingW / 2,        lenA / 2, P, wingW,  0, -1],
        ];
        addStrips(segments, 0.045, 0.09, 0.045, baseP);
        addStrips(segments, wh - 0.025, 0.05, 0.035, crownP);
        // Wing fill lights (same positions as createRoomLShape's mkLight).
        addFixtures([
            [wingW / 2,     -lenA / 4],
            [wingW / 2,      lenA / 4],
            [wingW + lenB / 2, bCZ],
        ]);
    } else if (meta.type === 'rotunda') {
        // Ring bands just inside the cylinder wall (radius r, BackSide):
        // a band at r - protrusion reads as trim standing proud of the wall.
        const r = meta.radius;
        // Dedicated BackSide material — mutating the shared revealMat's side
        // would flip the box strips' culling too (shared-material mutation
        // side effects are an audit red flag).
        const ringMat = this.isLowEnd
            ? new THREE.MeshLambertMaterial({ color: 0xe9e7e2, side: THREE.BackSide })
            : new THREE.MeshStandardMaterial({ color: 0xe9e7e2, roughness: 0.9, metalness: 0.0, side: THREE.BackSide });
        const mkRing = (y, h, p) => {
            const geo = new THREE.CylinderGeometry(r - p, r - p, h, 48, 1, true);
            const mesh = new THREE.Mesh(geo, ringMat);
            mesh.position.y = y;
            this.scene.add(mesh);
        };
        mkRing(0.045, 0.09, baseP);
        mkRing(wh - 0.025, 0.05, crownP);
        // Central fixture — the rotunda's single ceiling fill light.
        addFixtures([[0, 0]]);
    }
}

// ── INDUSTRIAL LOFT — the venue's structural identity pass ───────────────────
// Deepening rework (Industrial Loft forensic audit — screenshot-verified).
// The v1.0.0 pass carried five verified defects, all fixed at the root:
//
//   1. CORRIDOR BEAM AXIS SWAP: the corridor branch built its "cross" beams
//      with BoxGeometry(width+0.4, 0.25, 0.3) — X and Z swapped (copied from
//      the l-shape branch, where that orientation is correct). The corridor's
//      long axis is X, so every beam ran PARALLEL to the aisle instead of
//      across it — 6.4 m bars inside a 16–40 m room, overlapping their
//      neighbours, touching neither wall. Joists now span the SHORT axis and
//      step evenly down the long axis, ends buried in the walls.
//   2. BURIED TRIM (same bug class as the White Cube polish audit): coves
//      and columns measured offsets from the wall CENTRE plane with constant
//      0.09/0.09 — inside this venue's 0.5 m walls both rendered fully
//      INVISIBLE. Every offset now measures from the wall's inner face via
//      one shared protrusion formula (off = face + protrusion − depth/2),
//      the same one ArtworkPlacer.wallInset uses for the hang.
//   3. LANE MATH DRIFT: avoidLanes() replicated the OLD corner-offset hang
//      formula while ArtworkPlacer had moved to centred runs
//      (wallRunOffset) — columns "avoided" positions no artwork occupied
//      and could land directly in front of one. Lanes are now produced by
//      the SAME wallRunOffset the placer uses (l-shape uses the placer's
//      alternating-row walk); structure and placement cannot disagree.
//   4. DOUBLE BEAM SYSTEMS: the venue declares ceiling_beams (RoomBuilder
//      runners) AND structure_pass 'loft' (this pass). Both built beams at
//      near-identical heights with no designed relationship. Now it is a
//      deliberate industrial hierarchy: RoomBuilder's runners are the
//      PRIMARY girders; this pass hangs SECONDARY joists just BELOW them —
//      a real loft ceiling grid, not two competing systems.
//   5. FLOATING FIXTURES + SPAWN COLLISION: track-light heads were placed
//      at ±endX, ±endX/2 — coordinates no beam occupies (mid-air fixtures),
//      and the steel rack stood at the corridor's exact spawn point
//      (camera −length/2+1.5, z=0; rack uprights x=−length/2+1.4, z=±0.85).
//      Heads now mount under actual joists; the rack stands against the
//      side of the aisle; the spawn lane and arrival apron stay clear.
//
// NEW IDENTITY PIECES (all deterministic — zero rng draws):
//   • Pendant fixtures at the EXACT (x, z) of each layout's ceiling fill
//     lights (fixture parity — the White Cube respect-pass pattern): every
//     light source finally has a visible origin.
//   • Clerestory window band high on the walls — steel-mullioned factory
//     panes glowing cool night-light above the artwork band. Reads as
//     "converted warehouse" from the spawn angle; never competes with the
//     hang (bottom edge ≈ 4.7 m on the 7 m walls; artwork tops ≤ 2.7 m).
//   • l-shape parity: the wing previously received bare beams only; it now
//     gets the full joist/column/cove/window/pendant treatment.
//
// Low-end tier: every piece has a flat Lambert body of the same silhouette —
// degradation removes shading, never the venue's structural language.
function addIndustrialLoftStructure(data) {
    const meta = this._layoutMeta || {};
    const wh   = CONFIG.room.wallHeight;
    const wd   = CONFIG.room.wallDepth || 0.3;
    const face = wd / 2;                    // wall centre plane → inner face
    const spacing = CONFIG.room.artworkSpacing;

    // Materials — steel primary, lamp + pane emissives, crate wood.
    const steelMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x2a2a2a })
        : new THREE.MeshStandardMaterial({ color: 0x1e1e1e, roughness: 0.55, metalness: 0.85 });
    const darkMat  = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x111111 })
        : new THREE.MeshStandardMaterial({ color: 0x0d0d0d, roughness: 1.0, metalness: 0.3 });
    const lampMat  = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xfff2dd, emissive: 0xffe9c8, emissiveIntensity: 1.4 })
        : new THREE.MeshStandardMaterial({ color: 0xfff2dd, emissive: 0xffe9c8, emissiveIntensity: 1.4, roughness: 0.5 });
    const paneMat  = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x2c3644, emissive: 0x8ea6c4, emissiveIntensity: 0.55 })
        : new THREE.MeshStandardMaterial({ color: 0x232c38, emissive: 0x8ea6c4, emissiveIntensity: 0.55, roughness: 0.35, metalness: 0.2 });
    const shadeMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x191919 })
        : new THREE.MeshStandardMaterial({ color: 0x141414, roughness: 0.5, metalness: 0.7 });
    const crateMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x6a5230 })
        : new THREE.MeshStandardMaterial({ color: 0x6a5230, roughness: 0.9, metalness: 0.0 });

    // ── Wall-face vocabulary (shared with ArtworkPlacer.wallInset) ───────
    // A wall segment is declared [cx, cz, ry, len] where ry is the yaw whose
    // inward normal is (sin ry, cos ry) — the same convention RoomBuilder's
    // wall boxes use (ry 0 → wall on the −z side, face toward +z).
    // Trim protrusion: the piece's centre sits at face + p − depth/2 from
    // the wall centre plane so its room-side surface stands exactly `p`
    // proud of the inner face (never floats, never hides — White Cube rule).
    const trimOffset = (depth, p) => face + p - depth / 2;
    const segNormal  = (ry) => [Math.sin(ry), Math.cos(ry)];
    const segTangent = (ry) => [Math.cos(ry), -Math.sin(ry)];

    // ── Artwork lanes (placement parity, artwork-aware) ──────────────────
    // Lanes carry BOTH the exact position wallRunOffset will hang at AND the
    // actual half-width of the piece that will occupy them (makeArtworkGroup
    // math: height 2.0, width 2.0 × aspect, clamped at 3.0). Stanchion
    // slots clear per-piece — a column may stand beside a portrait but
    // never beside a 3 m panorama.
    const artHalfWidths = (data.images || []).map(img => {
        const aspect = img.aspectRatio || (img.width && img.height ? img.width / img.height : 1) || 1;
        let w = 2.0 * aspect;
        if (w > 3.0) w = 3.0;
        return w / 2;
    });
    const laneObjectsFor = (runCount, wallLength, firstImageIdx) => {
        const lanes = [];
        for (let p = 0; p < runCount; p++) {
            // Placer parity: the hang position equals start (corner + spacing)
            // + wallRunOffset − spacing, which reduces to wallRunOffset − L/2.
            lanes.push({
                pos: wallRunOffset(runCount, p, spacing, wallLength) - wallLength / 2,
                half: artHalfWidths[firstImageIdx + p] ?? 1.0,
            });
        }
        return lanes;
    };
    // CLEARANCE FIX (screenshot-verified): the v1 clearance (0.8 m) was
    // smaller than a wide artwork's half-width (3.0 m clamp → 1.5 m), so a
    // "cleared" column still poked through the edge of a landscape piece.
    // A stanchion slot must clear every piece it stands beside, plus the
    // column's half-width and a viewing margin.
    const COL_HALF = 0.08;
    const COL_MARGIN = 0.15;
    const clearOfLanes = (cand, lanes) =>
        lanes.every(l => Math.abs(cand - l.pos) >= l.half + COL_HALF + COL_MARGIN);

    // Stanchion slots — RHYTHM FIX (probe-verified): the joist/column grid
    // stepped at ~artwork spacing, so column candidates landed EXACTLY on
    // artwork lanes and every wall ended up stanchion-less. Industrial
    // logic reversed, exhibition-correct: the slots are chosen from the
    // GAPS of the hang (lane mid-points + the end zones), thinned to a
    // ≥ 2.6 m rhythm, and the joist grid is EXTENDED to meet each slot so
    // every stanchion visibly supports a real joist. Columns yield to the
    // hang, the ceiling structure follows the columns — like a real
    // build-out of an existing shell.
    const slotCandidatesFor = (lanes, extent) => {
        const cands = [-extent / 2 + 1.3];
        for (let i = 0; i < lanes.length - 1; i++) {
            cands.push((lanes[i].pos + lanes[i + 1].pos) / 2);
        }
        cands.push(extent / 2 - 1.3);
        return cands;
    };
    const slotsFor = (lanes, extent, minGap = 2.6) => {
        const kept = [];
        for (const c of slotCandidatesFor(lanes, extent)) {
            if (clearOfLanes(c, lanes) && kept.every(k => Math.abs(k - c) >= minGap)) kept.push(c);
        }
        return kept;
    };
    // Is a slot already (near) on the even grid that addJoists will build?
    const slotsAreNear = (t, stepFrom, gridStep) => {
        const k = Math.round((t - stepFrom) / gridStep);
        return Math.abs(t - (stepFrom + k * gridStep)) <= 0.35;
    };

    // ── Piece builders ───────────────────────────────────────────────────
    // Secondary joists UNDER the RoomBuilder runner girders (runner bottom
    // face at wh − 0.19; 5 mm reveal keeps the contact line from z-fighting).
    const joistY = wh - 0.19 - 0.005 - 0.13;   // centre of a 0.26-tall joist
    // runAxis 'z': joists span Z (BoxGeometry(0.12, 0.26, span)) and the set
    //              steps along X; bayX = the joist line's Z centre.
    // runAxis 'x': joists span X (BoxGeometry(span, 0.26, 0.12)) and the set
    //              steps along Z; bayX = the joist line's X centre.
    // Either an even step grid ({stepFrom, stepTo, count}) or explicit
    // positions ({at}) — the stanchion build-out extends the grid, so the
    // caller may merge both forms in one call. Returns ALL joist positions.
    const addJoists = ({ runAxis, span, bayX, stepFrom, stepTo, count = 0, at = [] }) => {
        const geo = runAxis === 'z'
            ? new THREE.BoxGeometry(0.12, 0.26, span)
            : new THREE.BoxGeometry(span, 0.26, 0.12);
        const positions = [];
        if (count > 0) {
            const step = (stepTo - stepFrom) / (count + 1);
            for (let i = 1; i <= count; i++) positions.push(stepFrom + i * step);
        }
        for (const t of at) positions.push(t);
        if (positions.length === 0) { geo.dispose(); return []; }
        // runAxis is the joist's LONG axis: 'z' joists span Z (geometry z =
        // span) and the SET spreads along X (t → x); 'x' joists span X and
        // spread along Z (t → z). bayX is the long-axis centre of the line.
        const parts = positions.map(t => (runAxis === 'z'
            ? { geo, pos: [t, joistY, bayX || 0] }
            : { geo, pos: [bayX || 0, joistY, t] }));
        this.scene.add(new THREE.Mesh(mergeParts(parts), steelMat));
        geo.dispose();
        return positions;
    };

    // Clerestory window band — panes + mullions per wall run. Protrudes 3 cm
    // into the room, measured from the inner face.
    const addWindowBand = (segments, yCentre, bandH) => {
        const paneGeo = new THREE.BoxGeometry(1, bandH, 0.02);
        const mulGeo  = new THREE.BoxGeometry(0.055, bandH + 0.06, 0.05);
        const paneOff = trimOffset(0.02, 0.03);
        const mulOff  = trimOffset(0.05, 0.035);
        const paneParts = [], mulParts = [];
        for (const [cx, cz, ry, len] of segments) {
            const [nx, nz] = segNormal(ry);
            const [tx, tz] = segTangent(ry);
            const ox = cx + nx * paneOff, oz = cz + nz * paneOff;
            const mx = cx + nx * mulOff,  mz = cz + nz * mulOff;
            const panes = Math.max(2, Math.round(len / 2.4));
            const paneLen = (len - 0.3) / panes;
            for (let i = 0; i < panes; i++) {
                const t = -len / 2 + 0.15 + paneLen * (i + 0.5);
                paneParts.push({ geo: paneGeo, pos: [ox + tx * t, yCentre, oz + tz * t], rot: [0, ry, 0], scale: [paneLen * 0.92, 1, 1] });
            }
            for (let i = 0; i <= panes; i++) {
                const t = -len / 2 + (len / panes) * i;
                mulParts.push({ geo: mulGeo, pos: [mx + tx * t, yCentre, mz + tz * t], rot: [0, ry, 0] });
            }
        }
        if (paneParts.length) this.scene.add(new THREE.Mesh(mergeParts(paneParts), paneMat));
        if (mulParts.length)  this.scene.add(new THREE.Mesh(mergeParts(mulParts), steelMat));
        paneGeo.dispose(); mulGeo.dispose();
    };

    // Perimeter floor coves — dark steel expansion channels hugging the wall
    // faces INSIDE the room (the v1.0.0 strips sat inside the wall boxes).
    const addCoves = (segments) => {
        const geo = new THREE.BoxGeometry(1, 0.07, 1);
        const d = 0.09;
        const off = trimOffset(d, 0.085);
        const parts = segments.map(([cx, cz, ry, len]) => {
            const [nx, nz] = segNormal(ry);
            const alongX = Math.abs(Math.sin(ry)) < 0.5; // ry 0 / π → wall runs along X
            return {
                geo,
                pos: [cx + nx * off, 0.035, cz + nz * off],
                rot: [0, ry, 0],
                scale: alongX ? [len, 1, d] : [d, 1, len],
            };
        });
        this.scene.add(new THREE.Mesh(mergeParts(parts), darkMat));
        geo.dispose();
    };

    // Columns — support the joist ends near the walls. Centre measured from
    // the wall centre plane so the column embeds 2 cm and protrudes 14 cm:
    // reads as a steel stanchion, never floats, never hides.
    const colSize = 0.16;
    const colGeo  = new THREE.BoxGeometry(colSize, wh - 0.45, colSize);
    const colCentre = (wallHalf) => wallHalf - face - colSize / 2 + 0.02;
    const addColumns = (positions) => {
        if (!positions.length) return;
        const parts = positions.map(([x, z]) => ({ geo: colGeo, pos: [x, (wh - 0.45) / 2, z] }));
        this.scene.add(new THREE.Mesh(mergeParts(parts), steelMat));
    };

    // Pendant fixtures at the fill-light positions (fixture parity — every
    // ceiling light gets a visible origin: rod + shade + emissive disc).
    const addPendants = (points) => {
        const rodGeo   = new THREE.CylinderGeometry(0.02, 0.02, 0.55, 6);
        const shadeGeo = new THREE.CylinderGeometry(0.05, 0.24, 0.22, 14);
        const discGeo  = new THREE.CylinderGeometry(0.17, 0.17, 0.02, 12);
        const rodParts = [], shadeParts = [], discParts = [];
        for (const [x, z] of points) {
            rodParts.push({ geo: rodGeo,   pos: [x, wh - 0.275, z] });
            shadeParts.push({ geo: shadeGeo, pos: [x, wh - 0.66, z] });
            discParts.push({ geo: discGeo,  pos: [x, wh - 0.78, z] });
        }
        this.scene.add(new THREE.Mesh(mergeParts(rodParts), steelMat));
        this.scene.add(new THREE.Mesh(mergeParts(shadeParts), shadeMat));
        this.scene.add(new THREE.Mesh(mergeParts(discParts), lampMat));
        rodGeo.dispose(); shadeGeo.dispose(); discGeo.dispose();
    };

    // ── Layout branches ──────────────────────────────────────────────────
    if (meta.type === 'corridor') {
        const length = meta.length, width = meta.width;
        const half = Math.ceil((data.imageCount || 0) / 2);
        const runA = Math.min(half, data.imageCount || 0);
        const runB = Math.max(0, (data.imageCount || 0) - half);
        const lanesA = laneObjectsFor(runA, length, 0);
        const lanesB = laneObjectsFor(runB, length, runA);

        // Stanchion slots from the hang's own gaps, per wall (each side's
        // run centres independently). The joist grid gains a joist at every
        // chosen slot so each stanchion supports a real member; walls keep
        // their centre spawn lane and end zones clear.
        const slotsA = slotsFor(lanesA, length);
        const slotsB = slotsFor(lanesB, length);
        const joistCount = Math.max(3, Math.round(length / 4));
        const gridStep = length / (joistCount + 1);
        // One merged grid: even bays + every stanchion slot (deduped) — each
        // column visibly supports a real joist.
        const joistXs = addJoists({
            runAxis: 'z', span: width + 0.4, bayX: 0,
            stepFrom: -length / 2, stepTo: length / 2, count: joistCount,
            at: [...new Set([...slotsA, ...slotsB])].filter(x =>
                !slotsAreNear(x, -length / 2, gridStep)),
        });
        const cols = [];
        const zCol = colCentre(width / 2);
        for (const x of slotsA) cols.push([x, -zCol]); // wall A side
        for (const x of slotsB) cols.push([x,  zCol]); // wall B side
        addColumns(cols);

        // Perimeter coves + clerestory band on the long walls + pendants at
        // the corridor fill-light positions (x = ±length/4).
        addCoves([
            [0, -width / 2, 0,          length - 0.8],
            [0,  width / 2, Math.PI,    length - 0.8],
            [-length / 2, 0, Math.PI / 2, width - 0.8],
            [ length / 2, 0, -Math.PI / 2, width - 0.8],
        ]);
        addWindowBand([
            [0, -width / 2, 0,       length - 1.6],
            [0,  width / 2, Math.PI, length - 1.6],
        ], wh - 1.75, 1.05);
        addPendants([[-length / 4, 0], [length / 4, 0]]);

        // Eye-level props + joist-mounted track heads (corridor hangs on the
        // LONG walls, so the end zones past the last lane are prop-safe).
        addLoftEyeLevelProps.call(this, {
            length, width, joistY,
            joistXs: joistXs,
            crateMat, steelMat, lampMat,
        });
    } else if (meta.type === 'square') {
        const L = meta.wallLength;
        const perWall  = Math.ceil((data.imageCount || 0) / 4);
        const outer    = data.imageCount || 0;
        const runLens  = [0, 1, 2, 3].map(i => Math.max(0, Math.min(perWall, outer - i * perWall)));
        // Columns on the ±x edges interact with the left/right walls' runs
        // (walls[2] hangs images [rc0+rc1 .. +rc2), walls[3] the remainder).
        const lanesZ   = laneObjectsFor(runLens[2], L, runLens[0] + runLens[1])
            .concat(laneObjectsFor(runLens[3], L, runLens[0] + runLens[1] + runLens[2]));
        const lanesX   = laneObjectsFor(runLens[0], L, 0)
            .concat(laneObjectsFor(runLens[1], L, runLens[0]));

        // Stanchion slots from the left/right walls' hang gaps; joist grid
        // extended to meet them (same build-out logic as the corridor).
        const slotsZ = slotsFor(lanesZ, L);
        const joistCount = Math.max(3, Math.round(L / 4.5));
        const gridStep = L / (joistCount + 1);
        const joistZs = addJoists({
            runAxis: 'z', span: L + 0.4, bayX: 0,
            stepFrom: -L / 2, stepTo: L / 2, count: joistCount,
            at: slotsZ.filter(z => !slotsAreNear(z, -L / 2, gridStep)),
        });
        const cols = [];
        const cx = colCentre(L / 2);
        for (const z of slotsZ) cols.push([cx, z], [-cx, z]);
        addColumns(cols);

        addCoves([
            [0, -L / 2, 0,            L - 0.8],
            [0,  L / 2, Math.PI,      L - 0.8],
            [-L / 2, 0, Math.PI / 2,  L - 0.8],
            [ L / 2, 0, -Math.PI / 2, L - 0.8],
        ]);
        addWindowBand([
            [0, -L / 2, 0,      L - 1.6],
            [0,  L / 2, Math.PI, L - 1.6],
            [-L / 2, 0, Math.PI / 2,  L - 1.6],
            [ L / 2, 0, -Math.PI / 2, L - 1.6],
        ], wh - 1.75, 1.05);

        // Fixture parity: the square builder's 2×2 fill grid sits at
        // (−L/2 + L/3 + i·L/3, −L/2 + L/3 + j·L/3) = (±L/6, ±L/6).
        addPendants([
            [-L / 6, -L / 6], [L / 6, -L / 6],
            [-L / 6,  L / 6], [L / 6,  L / 6],
        ]);

        addLoftEyeLevelProps.call(this, {
            length: L, width: L, joistY,
            squareJoistZs: joistZs,
            crateMat, steelMat, lampMat,
        });
    } else if (meta.type === 'l-shape') {
        // Wing A gets the full treatment (v1.0.0 was joists only); wing B
        // stays calm — the hang's density lives in wing A.
        const { wingW, lenA, zStart, zLimit } = meta;

        // Lanes from the placer's ACTUAL alternating-row walk: image i sits
        // at z = zStart + floor(i/2)·spacing, sides alternating — one lane
        // object per image, carrying its own half-width.
        const lanes = [];
        for (let i = 0; i < (data.imageCount || 0); i++) {
            const z = zStart + Math.floor(i / 2) * spacing;
            if (z > zLimit) break;
            lanes.push({ pos: z, half: artHalfWidths[i] ?? 1.0 });
        }

        // Stanchion slots from wing A's hang gaps; joist grid extended to
        // meet them (same build-out logic as the corridor).
        const slotsA = slotsFor(lanes, lenA);
        const joistCount = Math.max(2, Math.round(lenA / 4.5));
        const gridStep = lenA / (joistCount + 1);
        addJoists({
            runAxis: 'x', span: wingW + 0.4, bayX: wingW / 2,
            stepFrom: -lenA / 2, stepTo: lenA / 2, count: joistCount,
            at: slotsA.filter(z => !slotsAreNear(z, -lenA / 2, gridStep)),
        });
        const cols = [];
        for (const z of slotsA) cols.push([face + colSize / 2 - 0.02, z], [wingW - face - colSize / 2 + 0.02, z]);
        addColumns(cols);

        addCoves([
            [wingW / 2, -lenA / 2, 0,          wingW - 0.8],
            [wingW / 2,  lenA / 2, Math.PI,    wingW - 0.8],
            [0,      0, Math.PI / 2,  lenA - 0.8],
            [wingW,  0, -Math.PI / 2, lenA - 0.8],
        ]);
        addWindowBand([
            [0,     0, Math.PI / 2,  lenA - 1.6],
            [wingW, 0, -Math.PI / 2, lenA - 1.6],
        ], wh - 1.75, 1.05);

        // Fixture parity: wing A fills at (aCX, ±lenA/4); wing B fill at
        // (bCX, bCZ) — the same (x, z) createRoomLShape's mkLight used.
        addPendants([
            [wingW / 2, -lenA / 4],
            [wingW / 2,  lenA / 4],
            [wingW + meta.lenB / 2, meta.jZ],
        ]);
    }
    colGeo.dispose();
}

// Eye-level industrial props — crates + rack + joist-mounted track heads.
// Spawn-safe by construction: the rack stands against the SIDE of the aisle
// (the v1.0.0 rack sat on the corridor's exact spawn point), both clusters
// keep the centre lane clear, and the track heads mount under REAL joist
// positions instead of floating at arbitrary end-zone coordinates.
function addLoftEyeLevelProps({ length, width, joistY, joistXs, squareJoistZs, crateMat, steelMat, lampMat }) {
    const endX = length / 2 - 1.4;

    // Crates (cluster, +end, offset off the centre lane) — merged, one obstacle.
    const crateGeo = new THREE.BoxGeometry(1, 1, 1);
    const crateParts = [
        { geo: crateGeo, pos: [ endX, 0.3, -width / 4 ], rot: [0, 0.12, 0], scale: [0.78, 0.6, 0.78] },
        { geo: crateGeo, pos: [ endX - 0.05, 0.85, -width / 4 ], rot: [0, 0.3, 0], scale: [0.62, 0.5, 0.62] },
        { geo: crateGeo, pos: [ endX - 0.9, 0.24, -width / 4 + 0.4 ], rot: [0, -0.2, 0], scale: [0.66, 0.48, 0.66] },
    ];
    const crates = new THREE.Mesh(mergeParts(crateParts), crateMat);
    crateGeo.dispose();
    this.scene.add(crates);
    this.registerObstacle(crates, 0.2);

    // Rack (steel shelving, −end, SIDE of the aisle) — merged, one obstacle.
    // The rack body spans z ∈ [width/4 − 0.85, width/4 + 0.85]; padded it
    // never reaches the spawn lane (z = 0) for any supported corridor width.
    const upGeo   = new THREE.BoxGeometry(0.06, 1.8, 0.06);
    const shelfGeo = new THREE.BoxGeometry(0.5, 0.04, 1.7);
    const rackZ = width / 4;
    const rackParts = [
        { geo: upGeo, pos: [ -endX, 0.9, rackZ - 0.85 ] },
        { geo: upGeo, pos: [ -endX, 0.9, rackZ + 0.85 ] },
        { geo: shelfGeo, pos: [ -endX, 0.55, rackZ ] },
        { geo: shelfGeo, pos: [ -endX, 1.15, rackZ ] },
    ];
    const rack = new THREE.Mesh(mergeParts(rackParts), steelMat);
    upGeo.dispose(); shelfGeo.dispose();
    this.scene.add(rack);
    this.registerObstacle(rack, 0.2);

    // Track-light heads — mounted under actual joists (corridor: joists step
    // along X, heads ride the two flanking the aisle centre; square: joists
    // step along Z, one head rides the centre joist). Heads hang flush under
    // the joist underside, aimed down the aisle axis.
    const headGeo = new THREE.CylinderGeometry(0.07, 0.05, 0.2, 10);
    const headParts = [];
    if (Array.isArray(joistXs) && joistXs.length) {
        const mid = Math.floor(joistXs.length / 2);
        [mid - 1, mid + 1].forEach(i => {
            if (i >= 0 && i < joistXs.length) {
                headParts.push({ geo: headGeo, pos: [joistXs[i], joistY - 0.13 - 0.1, 0] });
            }
        });
    } else if (Array.isArray(squareJoistZs) && squareJoistZs.length) {
        const mid = Math.floor(squareJoistZs.length / 2);
        headParts.push({ geo: headGeo, pos: [0, joistY - 0.13 - 0.1, squareJoistZs[mid]] });
    }
    if (headParts.length) {
        this.scene.add(new THREE.Mesh(mergeParts(headParts), lampMat));
    }
    headGeo.dispose();
}

// ── DARK MUSEUM — the venue's structural identity pass ──────────────────────
// Deepening rework (Dark Museum forensic audit — screenshot-verified). The
// v1.0.0 pass was "two black slabs + skirting", and the skirting itself was
// DEAD GEOMETRY:
//
//   1. BURIED SKIRTING (the documented White Cube defect class, unfixed
//      here): the four strips were positioned at the wall CENTRE plane
//      (±wl/2) with a 0.06 m depth — entirely inside the 0.3 m wall boxes
//      (inner face at 0.15). Occluded on every square room; zero visual
//      effect since the venue shipped.
//   2. ROTUNDA LAYOUT PARITY (the White Cube audit's defect #2, unfixed
//      here): the venue advertises square + rotunda, but the pass read
//      meta.wallLength — absent on rotunda — and fell back to a hardcoded
//      14 m square. Result in a circular room: four straight skirting
//      strips floating mid-floor (their endpoints reach r ≈ 9.9 while a
//      15-piece rotunda's wall is at r ≈ 9.6) and two slab dividers pinned
//      to no wall at all.
//   3. MONOLITH MERGE: full-height (5 m) 0x050505 dividers under a 0x080808
//      ceiling read as one continuous blackness — no silhouette, no cap,
//      no material response. Darkness concealed the fact that the "detail"
//      pieces were indistinguishable from the shell.
//
// THE V2 IDENTITY — "the night wing": architecture RECEDES, artwork glows.
//   • Wall/ceiling shadow-gap reveal: a near-black strip where wall meets
//     ceiling (the classic museum detail that makes the ceiling plane read
//     as floating) — offset from the INNER FACE, always visible.
//   • Stone baseboard: visible (face-offset) dark skirting with a slight
//     metallic graze so artwork pools rake across it.
//   • Salon cabinets (dividers v2): lowered to 3.1 m on the 5 m walls so
//     the visitor sees OVER them into the dark — depth + orientation, the
//     museum trick a black full-height slab can never do. Brass cap trim,
//     stone plinth, both faces still hangable + collision-registered.
//   • Picture-light fixtures: built POST-PLACEMENT (see
//     addDarkMuseumPictureLights) — one brass-backed warm tube above EVERY
//     artwork, anchored to the artwork's real transform in every layout.
//   • Rotunda: ring baseboard + centre downlight; no square-room geometry.
//
// Deterministic: pure geometry from _layoutMeta + config — no RNG draws.
// Low-end tier: flat Lambert bodies of the same silhouettes — degradation
// removes shading, never the venue's structural language.
function addDarkMuseumStructure(data) {
    const meta = this._layoutMeta || {};
    const wh   = CONFIG.room.wallHeight;
    const wd   = CONFIG.room.wallDepth || 0.3;
    const face = wd / 2;                    // wall centre plane → inner face
    const trimOffset = (depth, p) => face + p - depth / 2; // White Cube rule

    // ── Materials: charcoal plaster recedes, brass + stone carry the light.
    const brassMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x8a6d3b })
        : new THREE.MeshStandardMaterial({ color: 0x8a6d3b, roughness: 0.35, metalness: 0.9 });
    const stoneMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x16130f })
        : new THREE.MeshStandardMaterial({ color: 0x141210, roughness: 0.55, metalness: 0.35 });
    const cabinetMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x1c1c1c })
        : new THREE.MeshStandardMaterial({ color: 0x1c1c1c, roughness: 0.92, metalness: 0.0 });
    const gapMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x060606 })
        : new THREE.MeshStandardMaterial({ color: 0x060606, roughness: 1.0, metalness: 0.0 });

    // ── Wall-face vocabulary (same convention as the White Cube pass) ────
    // [cx, cz, ry, len, nx, nz]: centre plane, yaw, span, inward normal.
    const squareSegments = (L) => {
        const S = Math.PI / 2;
        return [
            [0, -L / 2, 0,     L, 0,  1],
            [0,  L / 2, Math.PI, L, 0, -1],
            [-L / 2, 0, S,     L, 1,  0],
            [ L / 2, 0, -S,    L, -1, 0],
        ];
    };

    // Baseboard — 12 cm dark stone standing 2 cm proud of the inner face.
    const addBaseboards = (segments) => {
        const geo = new THREE.BoxGeometry(1, 0.12, 0.045);
        const off = trimOffset(0.045, 0.02);
        const parts = segments.map(([cx, cz, ry, len, nx, nz]) => ({
            geo,
            pos: [cx + nx * off, 0.07, cz + nz * off],
            rot: [0, ry, 0],
            scale: [len, 1, 1],
        }));
        this.scene.add(new THREE.Mesh(mergeParts(parts), stoneMat));
        geo.dispose();
    };

    // Shadow-gap reveal — 5 cm near-black strip just under the ceiling.
    // Reads as a recessed junction the ceiling plane floats above.
    const addShadowGap = (segments) => {
        const geo = new THREE.BoxGeometry(1, 0.05, 0.03);
        const off = trimOffset(0.03, 0.012);
        const parts = segments.map(([cx, cz, ry, len, nx, nz]) => ({
            geo,
            pos: [cx + nx * off, wh - 0.045, cz + nz * off],
            rot: [0, ry, 0],
            scale: [len, 1, 1],
        }));
        this.scene.add(new THREE.Mesh(mergeParts(parts), gapMat));
        geo.dispose();
    };

    // Downlight discs at the ceiling fill-grid positions (fixture parity —
    // the same points RoomBuilder drops its PointLights on).
    const addDownlights = (points) => {
        const ringGeo = new THREE.CylinderGeometry(0.15, 0.17, 0.035, 16);
        const discGeo = new THREE.CylinderGeometry(0.1, 0.1, 0.012, 14);
        const ringParts = points.map(p => ({ geo: ringGeo, pos: [p[0], wh - 0.02, p[1]] }));
        const warmMat = this.isLowEnd
            ? new THREE.MeshLambertMaterial({ color: 0xffe3b0, emissive: 0xffdca0, emissiveIntensity: 0.9 })
            : new THREE.MeshStandardMaterial({ color: 0xffe3b0, emissive: 0xffdca0, emissiveIntensity: 0.9, roughness: 0.4 });
        this.scene.add(new THREE.Mesh(mergeParts(ringParts), brassMat));
        this.scene.add(new THREE.Mesh(mergeParts(
            points.map(p => ({ geo: discGeo, pos: [p[0], wh - 0.045, p[1]] })),
            warmMat
        )));
        ringGeo.dispose(); discGeo.dispose();
    };

    const squareFillGrid = (L) => {
        const start = -L / 2 + L / 3, step = L / 3;
        const pts = [];
        for (let i = 0; i < 2; i++)
            for (let j = 0; j < 2; j++)
                pts.push([start + i * step, start + j * step]);
        return pts;
    };

    if (meta.type === 'rotunda') {
        // ── Rotunda parity: ring baseboard + ring shadow gap + centre
        // downlight. No straight skirting, no dividers — the v1 pass built
        // a 14 m square's worth of geometry inside a circle.
        const r = meta.radius || 10;
        const baseBand = new THREE.Mesh(
            new THREE.CylinderGeometry(r - 0.02, r - 0.02, 0.12, 48, 1, true),
            stoneMat.clone()
        );
        baseBand.material.side = THREE.BackSide;   // dedicated material — never
        // mutate the shared stoneMat's side (shared-material mutation is an
        // audit red flag; see the White Cube ring-band note).
        baseBand.position.y = 0.07;
        this.scene.add(baseBand);

        const gapBand = new THREE.Mesh(
            new THREE.CylinderGeometry(r - 0.012, r - 0.012, 0.05, 48, 1, true),
            gapMat.clone()
        );
        gapBand.material.side = THREE.BackSide;
        gapBand.position.y = wh - 0.045;
        this.scene.add(gapBand);

        addDownlights([[0, 0]]);
    } else {
        // ── Square: the full trim set on all four walls.
        const L  = Math.max(8, meta.wallLength || 14);
        const segs = squareSegments(L);
        addBaseboards(segs);
        addShadowGap(segs);
        addDownlights(squareFillGrid(L));

        // ── Salon cabinets (dividers v2) — lowered monoliths you see over.
        const CAB_H        = Math.min(3.1, wh - 0.6);
        const dividerDepth  = 0.3;
        const dividerLength = L * 0.28;      // reach 28% into the room
        const zOffset       = L * 0.18;      // asymmetric bays (kept from v1)

        [
            { x: -L / 2 + dividerLength / 2, z:  zOffset },
            { x:  L / 2 - dividerLength / 2, z: -zOffset },
        ].forEach(cfg => {
            const geo  = new THREE.BoxGeometry(dividerLength, CAB_H, dividerDepth);
            const mesh = new THREE.Mesh(geo, cabinetMat);
            mesh.position.set(cfg.x, CAB_H / 2, cfg.z);
            mesh.castShadow    = false;
            mesh.receiveShadow = !this.isLowEnd;
            this.scene.add(mesh);
            this.registerObstacle(mesh, 0.4);

            // Brass cap trim — the cabinet's lit silhouette against the dark.
            const capGeo = new THREE.BoxGeometry(dividerLength + 0.06, 0.045, dividerDepth + 0.06);
            const cap = new THREE.Mesh(capGeo, brassMat);
            cap.position.set(cfg.x, CAB_H + 0.0225, cfg.z);
            this.scene.add(cap);

            // Stone plinth — the baseboard language continues across the bay.
            const plinthGeo = new THREE.BoxGeometry(dividerLength + 0.05, 0.12, dividerDepth + 0.05);
            const plinth = new THREE.Mesh(plinthGeo, stoneMat);
            plinth.position.set(cfg.x, 0.06, cfg.z);
            this.scene.add(plinth);

            // Bay redistribution (generic mechanism, ArtworkPlacer consumes):
            // both faces register as hang surfaces. The registration sits
            // 0.03 off the face so a bay piece's total standoff (0.03 surface
            // + 0.02 planner gap = 5 cm) matches the outer walls' wallInset
            // clearance — the v1 faces left the frame back EXACTLY on the
            // surface, a different shadow line than the outer walls.
            const eye = CONFIG.camera.height;
            this._hangableSurfaces = this._hangableSurfaces || [];
            this._hangableSurfaces.push(
                { x: cfg.x, z: cfg.z + dividerDepth / 2 + 0.03, nx: 0, nz:  1, width: dividerLength - 0.9, height: CAB_H - 0.7 },
                { x: cfg.x, z: cfg.z - dividerDepth / 2 - 0.03, nx: 0, nz: -1, width: dividerLength - 0.9, height: CAB_H - 0.7 },
            );
        });
    }
}

// ── DARK MUSEUM — post-placement picture lights ─────────────────────────────
// One brass-backed warm tube above EVERY artwork, anchored to the artwork's
// REAL transform (world position + facing quaternion) rather than a
// recomputed lane: the fixture can never drift from the piece it lights, in
// any layout (square outer walls, salon-cabinet bays, rotunda ring). This is
// the museum's signature ritual — "the artwork is professionally lit" — and
// the mechanism that lets darkness carve hierarchy without stranding pieces.
//
// Runs from buildGallery's post-placement hook (see RoomBuilder): the
// fixtures must see the final artwork transforms, which do not exist during
// addVenueStructure. Config-gated on structure_pass 'museum' — zero slug
// knowledge (§10.2). Merged into ONE brass mesh + ONE tube mesh per scene:
// +2 draw calls total, independent of artwork count. Deterministic: pure
// function of the hang (itself deterministic). No RNG draws.
export function addDarkMuseumPictureLights() {
    const arts = this.artworks || [];
    if (!arts.length) return;
    const wh = CONFIG.room.wallHeight;

    const brassMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x8a6d3b })
        : new THREE.MeshStandardMaterial({ color: 0x8a6d3b, roughness: 0.35, metalness: 0.9 });
    const tubeMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xfff0d8, emissive: 0xffe3b0, emissiveIntensity: 1.5 })
        : new THREE.MeshStandardMaterial({ color: 0xfff0d8, emissive: 0xffe3b0, emissiveIntensity: 1.5, roughness: 0.4 });

    const plateGeo = new THREE.BoxGeometry(1, 0.1, 0.035);
    const tubeGeo  = new THREE.BoxGeometry(1, 0.055, 0.055);
    const plateParts = [], tubeParts = [];

    for (const art of arts) {
        art.updateMatrixWorld(true);
        const p = new THREE.Vector3();
        art.getWorldPosition(p);
        // Artwork geometry: height 2.0 (clamped by the 3.0 width rule) —
        // makeArtworkGroup math, read back from the canvas mesh so a focal
        // hero's scale boost keeps its fixture tracking the real top edge.
        const canvas = art.userData?._canvasMesh;
        const h = canvas ? canvas.geometry.parameters.height * (art.scale.y || 1) : 2.0;
        const w = canvas ? canvas.geometry.parameters.width * (art.scale.x || 1) : 2.0;
        const normal = new THREE.Vector3(0, 0, 1).applyQuaternion(art.quaternion);

        // Backing plate hugs the wall above the frame; the tube hangs just
        // proud of it, washing light down the canvas.
        const platePos = p.clone().add(normal.clone().multiplyScalar(0.045));
        platePos.y = p.y + (h / 2) + 0.34;
        if (platePos.y > wh - 0.12) platePos.y = wh - 0.12; // never pierce the ceiling
        const tubePos = p.clone().add(normal.clone().multiplyScalar(0.15));
        tubePos.y = platePos.y - 0.16;

        const yaw = Math.atan2(normal.x, normal.z);
        plateParts.push({ geo: plateGeo, pos: [platePos.x, platePos.y, platePos.z], rot: [0, yaw, 0], scale: [w + 0.24, 1, 1] });
        tubeParts.push({ geo: tubeGeo, pos: [tubePos.x, tubePos.y, tubePos.z], rot: [0, yaw, 0], scale: [w + 0.06, 1, 1] });
    }

    // Named handles: the QA gate pins the fixture-per-artwork invariant on
    // the plate mesh specifically (the pass's other brass pieces — cabinet
    // caps, downlight rings — share the material but not the contract).
    const plateMesh = new THREE.Mesh(mergeParts(plateParts), brassMat);
    plateMesh.name = 'museum-picture-light-plates';
    const tubeMesh = new THREE.Mesh(mergeParts(tubeParts), tubeMat);
    tubeMesh.name = 'museum-picture-light-tubes';
    this.scene.add(plateMesh);
    this.scene.add(tubeMesh);
    plateGeo.dispose(); tubeGeo.dispose();
}

// ── FRAMED BAYS — the generic bay architecture pass (structure_pass 'bays') ──
// (Japanese Zen Gallery v2 "The Quiet Procession" — and any venue declaring
// the same spatial idea. CODE PROVIDES CAPABILITIES; the template declares.)
//
// ONE IDEA: artwork presented in a rhythm of FRAMED BAYS. Deep timber fins
// divide the wall into bays, a recessed plaster panel backs each bay, a
// timber header closes it at the top, a continuous paper clerestory band
// glows above the headers, and a low timber step (the engawa datum) runs
// the wall at the floor. The blank wall between bays is the point — the
// pause between the works (ma) — so the venue hangs at a generous rhythm.
//
// THE SIGNATURE GUARANTEE — architecture serves the hang: fin positions
// derive from the SAME pure run-plan math the artwork placer consumes
// (squareRunPlan / lshapeRowPlan in ArtworkPlacer.js — the loft precedent,
// generalised). Every artwork sits centred in its own bay on every supported
// layout, at any count. NO RNG: the composition is a pure function of
// (layout, count, spacing) — deterministic by construction.
//
// Config (visual_config.bays, all optional — the venue decides):
//   fin_width  fin_depth  fin_top  header_height  recess_lift
//   step_height  step_depth  clerestory_gap  clerestory_height
//
// Rollback: structure_pass !== 'bays' → the pass never runs (the venue
// reverts to a plain room, live — config is the only switch). Wall/floor
// identity still comes from material_config; this pass contributes only the
// bay family (timber / recess plaster / paper / step).
//
// Low-end tier: Lambert flat-colour bodies of the SAME silhouettes —
// degradation removes shading, never the structural language (the museum
// rule). The clerestory keeps its emissive glow on every tier: the paper
// light IS this family's lighting identity.
//
// Layout parity: square (4 walls, glazing-aware — mirrors the placer),
// corridor (both long walls), l-shape (both faces of both wings). Rotunda /
// circular: linear bay rhythm does not apply — the pass skips (a venue must
// not advertise rotunda in supported_layouts alongside 'bays').
function addBaysStructure(data) {
    const meta = this._layoutMeta || {};
    const vc   = this._venueVisualConfig || {};
    const S    = CONFIG.room.artworkSpacing;
    const wd   = CONFIG.room.wallDepth || 0.3;
    const wh   = CONFIG.room.wallHeight;
    const face = wd / 2;                    // wall centre plane → inner face
    const count = this.artworkImages.length;
    if (!count || count < 1) return;

    // ── Bay proportions (venue-decidable, tasteful defaults) ────────────
    // Defaults clear the focal-hero frame (top ≈ 2.87 m at the 1.15 scale
    // boost) with room for the header, the paper band and the beam line.
    const B = Object.assign({
        fin_width: 0.16, fin_depth: 0.14, fin_top: 3.12, header_height: 0.20,
        recess_lift: 0.012, step_height: 0.08, step_depth: 0.36,
        clerestory_gap: 0.05, clerestory_height: 0.24,
    }, (vc.bays && typeof vc.bays === 'object') ? vc.bays : {});
    B.fin_depth     = Math.max(0.04, B.fin_depth);
    B.fin_width     = Math.max(0.06, B.fin_width);
    B.header_height = Math.max(0.08, B.header_height);
    B.step_depth    = Math.max(0.12, B.step_depth);
    B.step_height   = Math.max(0.03, B.step_height);
    B.clerestory_gap = Math.max(0.02, B.clerestory_gap);
    B.fin_top       = Math.min(B.fin_top, wh - 0.25);
    // The paper band must stay under the ceiling (and clear of any beam
    // line ~wh−0.14): shrink, then drop, rather than pierce.
    B.clerestory_height = Math.min(
        B.clerestory_height,
        wh - B.fin_top - B.clerestory_gap - 0.2
    );

    // ── Materials — the bay family ──────────────────────────────────────
    const timberMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x33291d })
        : new THREE.MeshStandardMaterial({ color: 0x33291d, roughness: 0.78, metalness: 0.02 });
    const recessMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xded4c0 })
        : new THREE.MeshStandardMaterial({ color: 0xded4c0, roughness: 0.95, metalness: 0.0 });
    const paperMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xf3ecd8, emissive: 0xffe8c2, emissiveIntensity: 0.45, transparent: true, opacity: 0.85, side: THREE.DoubleSide })
        : new THREE.MeshStandardMaterial({ color: 0xf3ecd8, emissive: 0xffe8c2, emissiveIntensity: 0.45, transparent: true, opacity: 0.85, side: THREE.DoubleSide, roughness: 0.95, metalness: 0.0 });
    const stepMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x8b6f47 })
        : new THREE.MeshStandardMaterial({ color: 0x8b6f47, roughness: 0.7, metalness: 0.02 });

    // ── Part accumulators (merged per material → one draw call each) ────
    const timberParts = [], recessParts = [], paperParts = [], stepParts = [], beamParts = [];
    const collideBoxes = [];   // { pos, rot, size } → invisible proxies at emit

    const unitGeo = new THREE.BoxGeometry(1, 1, 1);

    // Wall vocabulary: anchor point on the wall CENTRE plane, inward normal
    // n, run tangent t (chosen so the run's positive direction matches the
    // placer's walk), yaw from the normal (boxes are tangent-symmetric).
    // pos(q, t, y) = anchor + t·q + n·t_depth.
    const emitBays = (wall, qFirst, run) => {
        if (run <= 0) return;
        const depth = face + B.fin_depth / 2;
        const P = (q, t, y) => [
            wall.ax + wall.tx * q + wall.nx * t,
            y,
            wall.az + wall.tz * q + wall.nz * t,
        ];
        const ry = Math.atan2(wall.nx, wall.nz);

        // Fins — run+1 blades framing every work (artwork r sits centred
        // between fins r and r+1 by construction).
        for (let j = 0; j <= run; j++) {
            const q = qFirst + j * S;
            timberParts.push({
                geo: unitGeo, pos: P(q, depth, B.fin_top / 2), rot: [0, ry, 0],
                scale: [B.fin_width, B.fin_top, B.fin_depth],
            });
            collideBoxes.push({
                pos: P(q, depth, B.fin_top / 2), rot: [0, ry, 0],
                size: [B.fin_width, B.fin_top, B.fin_depth],
            });
        }

        const bayW        = S - B.fin_width;
        const headerBottom = B.fin_top - B.header_height;
        for (let j = 0; j < run; j++) {
            const qMid = qFirst + S / 2 + j * S;
            // Header — closes the bay at the top.
            timberParts.push({
                geo: unitGeo, pos: P(qMid, depth, B.fin_top - B.header_height / 2),
                rot: [0, ry, 0], scale: [bayW, B.header_height, B.fin_depth],
            });
            // Recess panel — the bay's own plaster tone, a whisper proud of
            // the wall face (the artwork hangs 30+ mm clear of it).
            recessParts.push({
                geo: unitGeo, pos: P(qMid, face + B.recess_lift, headerBottom / 2),
                rot: [0, ry, 0], scale: [bayW, headerBottom, 0.012],
            });
        }

        // Clerestory band — one paper strip across the whole run, above the
        // headers: the bay rhythm's soft light source (visual only; the
        // actual wash comes from the venue rig — no new dynamic lights).
        const span    = run * S + B.fin_width;
        const qCenter = qFirst + (run * S) / 2;
        if (B.clerestory_height >= 0.08) {
            paperParts.push({
                geo: unitGeo,
                pos: P(qCenter, face + B.recess_lift, B.fin_top + B.clerestory_gap + B.clerestory_height / 2),
                rot: [0, ry, 0], scale: [span, B.clerestory_height, 0.012],
            });
        }

        // Display step — the engawa datum under the bay line (one colliding
        // plinth per wall run).
        stepParts.push({
            geo: unitGeo, pos: P(qCenter, face + B.step_depth / 2, B.step_height / 2),
            rot: [0, ry, 0], scale: [span, B.step_height, B.step_depth],
        });
        collideBoxes.push({
            pos: P(qCenter, face + B.step_depth / 2, B.step_height / 2), rot: [0, ry, 0],
            size: [span, B.step_height, B.step_depth],
        });
    };

    // Fin offsets of a centred run (square/corridor beams read these).
    const finOffsets = (r) => Array.from({ length: r + 1 }, (_, j) => (-r * S) / 2 + j * S);
    const intersect = (a, b) => {
        const out = [];
        for (const x of a) for (const y of b) if (Math.abs(x - y) < 1e-6) { out.push(x); break; }
        return out;
    };

    if (meta.type === 'square') {
        // Mirror the placer's wall list (glazing-aware) and run split —
        // ONE math (squareRunPlan), so bays always wrap the real hang.
        const glazingWallId = this._glazing ? this._glazing.wallId : null;
        const walls = [
            { id: 'front', ax: 0,           az: -meta.wallLength / 2, nx: 0,  nz: 1,  tx: 1,  tz: 0 },
            { id: 'back',  ax: 0,           az:  meta.wallLength / 2, nx: 0,  nz: -1, tx: -1, tz: 0 },
            { id: 'left',  ax: -meta.wallLength / 2, az: 0,           nx: 1,  nz: 0,  tx: 0,  tz: -1 },
            { id: 'right', ax:  meta.wallLength / 2, az: 0,           nx: -1, nz: 0,  tx: 0,  tz: 1 },
        ].filter(w => w.id !== glazingWallId);
        const { runCounts } = squareRunPlan(count, count, S, walls.length);
        walls.forEach((wall, i) => emitBays(wall, -(runCounts[i] * S) / 2, runCounts[i]));

        // Ceiling rafters — the bay rhythm thins overhead: rafters land only
        // on ALIGNED fin lines (set intersection — a beam can never sit
        // beside a fin) and SUBSAMPLE them (≤ 4 lines per axis) so a
        // capacity hang keeps a calm ceiling grid instead of a dark lattice
        // (the wide-30 capture: 17 rafters read as a cage — the procession
        // lives on the walls; overhead it whispers). Corridor/l-shape keep
        // a flat ceiling.
        const L  = meta.wallLength;
        const beamY = wh - 0.06;
        const sub = (arr, maxLines) => {
            const stride = Math.max(1, Math.ceil(arr.length / maxLines));
            return arr.filter((_, i) => i % stride === 0);
        };
        const beamZ = sub(intersect(finOffsets(runCounts[0]), finOffsets(runCounts[1])), 4);
        const beamX = sub(intersect(finOffsets(runCounts[2]), finOffsets(runCounts[3])), 4);
        for (const q of beamZ) {
            beamParts.push({ geo: unitGeo, pos: [q, beamY, 0], rot: [0, 0, 0], scale: [0.1, 0.12, L - 0.2] });
        }
        for (const q of beamX) {
            beamParts.push({ geo: unitGeo, pos: [0, beamY, q], rot: [0, 0, 0], scale: [L - 0.2, 0.12, 0.1] });
        }
    } else if (meta.type === 'corridor') {
        const { width: W } = meta;
        const half = Math.ceil(count / 2);           // placer's split, verbatim
        const runs = [Math.min(half, count), Math.max(0, count - half)];
        const walls = [
            { ax: 0, az: -W / 2, nx: 0, nz: 1,  tx: 1,  tz: 0 },
            { ax: 0, az:  W / 2, nx: 0, nz: -1, tx: -1, tz: 0 },
        ];
        walls.forEach((wall, i) => emitBays(wall, -(runs[i] * S) / 2, runs[i]));
    } else if (meta.type === 'l-shape') {
        const { wingW, lenA, jZ, zStart, zLimit } = meta;
        const plan = lshapeRowPlan(count, zStart, zLimit, S);
        const xStart = wingW + S;                    // placer's xStart, verbatim
        // Wing A — outer wall face (x≈0) and inner wall face (x=wingW),
        // rows stepping +z from zStart; wing B — both faces of the band,
        // rows stepping +x from xStart. Fins frame each row, plus the run
        // ends — same emit path as the square walls.
        emitBays({ ax: 0,      az: 0,        nx: 1,  nz: 0, tx: 0, tz: 1 }, zStart - S / 2, plan.rowsA);
        emitBays({ ax: wingW,  az: 0,        nx: -1, nz: 0, tx: 0, tz: 1 }, zStart - S / 2, plan.rowsA1);
        emitBays({ ax: 0,      az: jZ,       nx: 0,  nz: 1, tx: 1, tz: 0 }, xStart - S / 2, plan.rowsB);
        emitBays({ ax: 0,      az: lenA / 2, nx: 0,  nz: -1, tx: 1, tz: 0 }, xStart - S / 2, plan.rowsB1);
    } else {
        console.warn('[VenueDecorator] bays pass: unsupported layout "' + (meta.type || 'unknown') + '" — no bays built.');
    }

    // ── Emit — one merged mesh per material (5 draw calls, count-agnostic)
    const addMerged = (parts, mat, name, shadows) => {
        if (!parts.length) return;
        const mesh = new THREE.Mesh(mergeParts(parts), mat);
        mesh.name = name;
        mesh.castShadow    = !!shadows && !this.isLowEnd;
        mesh.receiveShadow = !!shadows && !this.isLowEnd;
        this.scene.add(mesh);
    };
    addMerged(timberParts, timberMat, 'bays-timber', true);
    addMerged(recessParts, recessMat, 'bays-recess', true);
    addMerged(paperParts, paperMat, 'bays-paper', false);   // glowing band casts nothing
    addMerged(stepParts, stepMat, 'bays-step', true);
    addMerged(beamParts, timberMat, 'bays-beams', true);
    unitGeo.dispose();

    // ── Collision — invisible proxies per fin + per step (per-part AABBs;
    // a merged mesh's own box would span the whole wall). Fins pad 0.25
    // (physical blades), steps pad 0.12 (a datum, not a barricade — viewing
    // distance stays comfortable).
    const proxyMat = new THREE.MeshBasicMaterial({ visible: false });
    for (const c of collideBoxes) {
        const proxy = new THREE.Mesh(
            new THREE.BoxGeometry(c.size[0], c.size[1], c.size[2]), proxyMat
        );
        proxy.position.set(c.pos[0], c.pos[1], c.pos[2]);
        proxy.rotation.set(c.rot[0], c.rot[1], c.rot[2]);
        const isStep = c.size[1] <= 0.12;
        this.registerObstacle(proxy, isStep ? 0.12 : 0.25);
        proxy.geometry.dispose();
    }
    proxyMat.dispose();
}

// ── Post-placement structure hook (config-gated dispatcher) ─────────────────
// Called by RoomBuilder.buildGallery AFTER placeArtworks — the extension
// point for structure that must anchor to final artwork transforms. Venues
// opt in by structure_pass; no pass ⇒ no-op.
export function addVenuePostPlacementStructure() {
    const vc = this._venueVisualConfig || {};
    const pass = vc.structure_pass;
    if (pass === 'museum') addDarkMuseumPictureLights.call(this);
    // Deep Field presentation (2026-09-08 audit pass, generic): a declared
    // placement.light_pools drops a pool of cool light on the floor beneath
    // every floating artwork. Any float venue may declare it — the effect is
    // presentation vocabulary, not venue identity, so the key is generic and
    // the venue-owned placement object carries it.
    if (vc.placement?.light_pools === true) addFloatLightPools.call(this);
}

// ── FLOAT LIGHT POOLS — grounding for suspended works ───────────────────────
// A dark cosmic floor gives nothing back to a floating canvas: the works
// hover over a void and the hang reads as unanchored (the v1.0.0 Nebula
// Drift's presentation gap). One soft additive pool of cool light beneath
// each artwork:
//   • grounds the float — the work is suspended OVER its light, the way a
//     gallery washes a wall from above;
//   • composes the floor — from the spawn the pools read as the exhibition's
//     constellation map (the visitor can see where the show is before
//     walking it);
//   • costs ONE InstancedMesh draw for the whole hang.
// Neutral cool white (0xaebbe0-family), subtle opacity — artwork lighting
// itself stays warm and pooled (§12); these are atmosphere, not key light.
// Deterministic: pool size variation continues the venue's seeded rng after
// placement has consumed its draws.
function addFloatLightPools() {
    const artworks = this.artworks || [];
    if (!artworks.length || this._floatLightPoolsAdded) return;
    this._floatLightPoolsAdded = true;

    const rng = this._venueRng;
    const isLowEnd = !!this.isLowEnd;

    const geo = new THREE.PlaneGeometry(1, 1);
    const mat = new THREE.MeshBasicMaterial({
        map: makeRadialPoolTexture(),
        transparent: true,
        opacity: isLowEnd ? 0.16 : 0.22,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        // Scene fog applies — far pools melt into the floor's depth haze
        // exactly like the far artworks do (fog is the depth cue here).
    });
    const pools = new THREE.InstancedMesh(geo, mat, artworks.length);
    const m = new THREE.Matrix4();
    const q = new THREE.Quaternion().setFromEuler(new THREE.Euler(-Math.PI / 2, 0, 0));
    const p = new THREE.Vector3();
    const s = new THREE.Vector3();
    artworks.forEach((art, i) => {
        const poolR = 2.6 + rng.next() * 0.8; // seeded size rhythm
        p.set(art.position.x, 0.02, art.position.z);
        s.set(poolR, poolR, 1);
        m.compose(p, q, s);
        pools.setMatrixAt(i, m);
    });
    pools.instanceMatrix.needsUpdate = true;
    pools.frustumCulled = false;
    pools.renderOrder = 1; // after the floor, with the other transparents
    this.scene.add(pools);
}

// Radial pool sprite — a soft-edged disc, generated once and shared by the
// instanced mesh (module-level cache: one 256² texture per session).
let _poolTextureCache = null;
function makeRadialPoolTexture() {
    if (_poolTextureCache) return _poolTextureCache;
    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    const grad = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
    grad.addColorStop(0, 'rgba(190,200,235,0.62)');
    grad.addColorStop(0.45, 'rgba(170,185,225,0.26)');
    grad.addColorStop(1, 'rgba(160,175,220,0)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, size, size);
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    _poolTextureCache = tex;
    return tex;
}

// ── SCULPTURE GARDEN v4.0.0 — "The Sculpture Park" (asset-driven) ───────────
// A designed landscape, not a decorated plane. Every element here is placed
// by the pure GardenLayout plan (terrain → walks → courts → vegetation, in
// that order) — this body only RENDERS the plan: it displaces the terrain,
// paves the walks as continuous gravel (ribbons + court/plaza discs), builds
// the hero travertine court, the distant rolling landscape, the sky that
// ties them together — and hands the LIVING layer (trees, planting,
// boulders, benches) to the owner-supplied GLB library via GardenAssets.js.
//
// v4 changes vs v3 (the user verdict on the shipped result):
//   • primitive trees/shrubs (icosahedra/cones)  → owner GLB assets, instanced
//   • cylinder stepping stones + box hedge ring  → continuous gravel walks +
//     horizon treeline anchors (a fence became landscape)
//   • tripod easels (yard-sale read)             → museum panel stands
//   • tall trophy pedestal                       → low travertine drum
//   • flat astroturf fallback                    → tonal lawn detail map
//
// Design contract (brief):
//   • Landscape first. Sculpture second. Decoration last.        (§3)
//   • Terrain is gentle and controlled — a lawn, not a wilderness. (§4)
//   • Walks connect arrival → court → court; they are circulation. (§5)
//   • Vegetation frames and screens; it never competes.      (§9/§10)
//   • The horizon must not reveal the illusion.                  (§15)
//   • Deterministic: every draw comes from the venue's seeded rng. (§19)
//   • The garden stays performant: continuous geometry + instancing — the
//     whole static landscape renders in ~8 draw calls, and a FULLY PLANTED
//     asset layer adds only ~1 draw call per (role × asset mesh).    (§25)
function addSculptureGardenStructure(data) {
    const meta = this._layoutMeta || {};
    const radius = meta.radius || 15;
    const vc = this._venueVisualConfig || {};
    const gardenCfg = vc.garden || {};
    const highFx = !this.isLowEnd && !this._isMobileTier;

    // ── 0. The landscape plan (built in RoomBuilder BEFORE the structure so
    // placement, structure and terrain share one plan). The fallback covers
    // pathological build orders — it draws from the same seeded stream and
    // is therefore still deterministic.
    let plan = this._gardenPlan;
    if (!plan) {
        this._venueRng = this._venueRng || createVenueRng(venueSeedSource(this._venueSlug || 'venue'));
        plan = this._gardenPlan = buildGardenPlan({
            radius,
            count: data.imageCount || 1,
            rng: this._venueRng,
            config: gardenCfg,
        });
    }
    const height = plan.terrain.height;

    // ── 1. Terrain — the flat grass disc becomes a gentle lawn ──────────
    // The plan's closed-form height field displaces the floor mesh in place
    // (same material, same UVs — the grass texture stretches negligibly at
    // ±0.21 m amplitude). Courts and the spawn plaza are flattened BY the
    // field itself, so panels and visitors always stand level.
    const floor = this._circularFloor;
    if (floor) {
        const lawnGeo = new THREE.RingGeometry(0.02, radius, 128, 48);
        const lp = lawnGeo.attributes.position;
        for (let i = 0; i < lp.count; i++) {
            // floor.rotation.x = -π/2 maps local (x, y, z) → world (x, z, -y):
            // world height lives in local +z, world z is local -y.
            lp.setZ(i, height(lp.getX(i), -lp.getY(i)));
        }
        lawnGeo.computeVertexNormals();
        floor.geometry.dispose();
        floor.geometry = lawnGeo;

        // Lawn material — the harness/fallback path (no grass PBR set) is a
        // FLAT colour, which read as astroturf. This deterministic canvas
        // detail map adds large soft tonal patches + fine mottle that
        // multiply over the declared lawn colour (production builds with
        // real grass textures keep their map untouched).
        if (!floor.material.map && typeof document !== 'undefined') {
            floor.material.map = makeLawnDetailTexture();
            floor.material.map.repeat.set(9, 9);
            floor.material.needsUpdate = true;
            // The fallback lawn reads flat-bright under the daylight stack —
            // deepen it toward the declared muted green (production builds
            // with real grass textures never take this path).
            if (floor.material.color) floor.material.color.multiplyScalar(0.85);
        }
    }

    // ── 2. The distant landscape — rolling meadow beyond the bound ──────
    // The skirt shares the lawn's height field (continuity across the
    // bound line) and grows into hills that carry the eye to a soft, hazed
    // horizon. The horizon TREELINE (plan's horizon groves → tree assets)
    // stands on this skirt — the garden closes with landscape, not a fence.
    const skirtGeo = new THREE.RingGeometry(radius - 0.05, radius * 2.4, 128, 36);
    const sp = skirtGeo.attributes.position;
    for (let i = 0; i < sp.count; i++) {
        sp.setZ(i, height(sp.getX(i), -sp.getY(i)));
    }
    skirtGeo.computeVertexNormals();
    const skirt = new THREE.Mesh(skirtGeo, floor.material);
    skirt.rotation.x = -Math.PI / 2;
    skirt.position.y = 0;
    skirt.receiveShadow = highFx;
    this.scene.add(skirt);

    // ── 3. Sky dome — clear-afternoon gradient (kept from v3, retuned) ──
    // A deeper, less saturated zenith and a warmer haze horizon; the fog is
    // matched to the horizon tone so the skirt dissolves INTO the sky.
    const skyGeo = new THREE.SphereGeometry(radius * 2.9, 32, 16);
    const skyMat = new THREE.ShaderMaterial({
        side: THREE.BackSide,
        depthWrite: false,
        uniforms: {
            topColor:    { value: new THREE.Color(0x3e74b3) },
            bottomColor: { value: new THREE.Color(0xe7ead9) },
            offset:      { value: 0.35 },
            exponent:    { value: 0.5 },
        },
        vertexShader: `
            varying vec3 vWorldPosition;
            void main() {
                vec4 worldPosition = modelMatrix * vec4(position, 1.0);
                vWorldPosition = worldPosition.xyz;
                gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
            }
        `,
        fragmentShader: `
            uniform vec3 topColor;
            uniform vec3 bottomColor;
            uniform float offset;
            uniform float exponent;
            varying vec3 vWorldPosition;
            void main() {
                float h = normalize(vWorldPosition + offset).y;
                gl_FragColor = vec4(mix(bottomColor, topColor, max(pow(max(h, 0.0), exponent), 0.0)), 1.0);
            }
        `,
    });
    this.scene.add(new THREE.Mesh(skyGeo, skyMat));

    // Aerial perspective: fog matched to the horizon colour. Near plane sits
    // BEYOND every artwork (courts ≤ R − 2.2) — atmosphere adds depth to the
    // landscape without ever touching the art.
    this.scene.fog = new THREE.Fog(0xdfe2d1, radius * 1.55, radius * 2.75);

    // ── 4. Sun — warm raking light, shadow-casting (tier + config gated) ─
    // The garden is the ONLY venue whose sky establishes a sun, so it is the
    // only venue that may enable sun shadows — tier-gated (high tier only)
    // and config-gated (visual_config.sun_shadows — the rollback switch).
    const sunLight = new THREE.DirectionalLight(0xffeecb, 1.3);
    // WSW afternoon sun: long, gentle shadows model the sculpture courts.
    sunLight.position.set(-radius * 0.8, radius * 0.9, radius * 0.12);
    if (vc.sun_shadows === true && highFx) {
        if (!this.renderer.shadowMap.enabled) this.renderer.shadowMap.enabled = true;
        sunLight.castShadow = true;
        sunLight.shadow.mapSize.set(1536, 1536);
        const sc = sunLight.shadow.camera;
        sc.left = -radius * 1.15; sc.right = radius * 1.15;
        sc.top  =  radius * 1.15; sc.bottom = -radius * 1.15;
        sc.near = 1; sc.far = radius * 4;
        sunLight.shadow.bias = -0.0006;
    }
    this.scene.add(sunLight);

    const sun = new THREE.Mesh(
        new THREE.SphereGeometry(1.7, 16, 16),
        new THREE.MeshBasicMaterial({ color: 0xfff6dd, fog: false })
    );
    sun.position.copy(sunLight.position).multiplyScalar(2.1);
    this.scene.add(sun);

    // ── 5. Sky environment — the garden IS its own sky ──────────────────
    // The venue declares environment 'none' (no HDRI download, no interior
    // studio reflections — the s4 authority chain skips the fetch), and the
    // dome above is rendered once into a PMREM so the BRONZE of the hero
    // sculpture and the lawn's sheen reflect the actual sky of THIS garden.
    // Asset-free, deterministic, one-time cost. Config-gated
    // (garden.sky_environment — the rollback switch).
    if (!this.isLowEnd && gardenCfg.sky_environment === true) {
        try {
            const pmrem = new THREE.PMREMGenerator(this.renderer);
            const envScene = new THREE.Scene();
            envScene.add(new THREE.Mesh(skyGeo.clone(), skyMat.clone()));
            const rt = pmrem.fromScene(envScene, 0.04);
            this.scene.environment = rt.texture;
            this.scene.environmentIntensity = this._venueEnvIntensity ?? 0.45;
            pmrem.dispose();
        } catch (e) {
            console.warn('[garden] sky environment skipped:', e);
        }
    }

    // ── 6. Walks — continuous gravel, the landscape-architect finish ────
    // v2 laid decorative stone DISCS (the "hexagonal stepping stones" the
    // brief rejected); v3 sampled cylinder stones along the polylines —
    // both read as game geometry. v4 paves each planned walk as a CONTINUOUS
    // terrain-following gravel ribbon with a crisp dark soil underlay edge —
    // the restrained gravel path a real sculpture park specifies. The
    // promenade and ring walk meet the hero GRAVEL COURT and the SPAWN
    // PLAZA discs; all gravel merges into ONE mesh, the underlay into a
    // second — two draw calls for the entire circulation.
    const gravelMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xb3a98f, map: makeGravelTexture() })
        : new THREE.MeshStandardMaterial({
            color: 0xcfc5ab, map: makeGravelTexture(), roughness: 0.96, metalness: 0.0,
        });
    if (gravelMat.map) {
        gravelMat.map.wrapS = gravelMat.map.wrapT = THREE.RepeatWrapping;
        const aniso = Math.min(8, this.renderer.capabilities.getMaxAnisotropy?.() || 1);
        gravelMat.map.anisotropy = aniso;
    }
    const soilMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x56503e })
        : new THREE.MeshStandardMaterial({ color: 0x56503e, roughness: 1.0, metalness: 0.0 });

    const liftByKind = { promenade: 0.035, loop: 0.028, spur: 0.028 };
    const gravelParts = [];
    for (const p of plan.paths) {
        gravelParts.push(buildGravelRibbon(p.samples, p.width, height, liftByKind[p.kind] ?? 0.03));
    }
    // Hero court + spawn plaza discs (same gravel vocabulary)
    gravelParts.push(buildGravelDisc(0, 0, 3.0, height, 0.02));
    gravelParts.push(buildGravelDisc(plan.spawn.x, plan.spawn.z, 2.2, height, 0.02));
    const gravelGeo = mergeGeometries(gravelParts.filter(Boolean));
    gravelParts.forEach(g => g && g.dispose());
    if (gravelGeo) {
        const gravel = new THREE.Mesh(gravelGeo, gravelMat);
        gravel.receiveShadow = highFx;
        this.scene.add(gravel);
    }
    const soilParts = [];
    for (const p of plan.paths) {
        soilParts.push(buildGravelRibbon(p.samples, p.width + 0.26, height, 0.008));
    }
    const soilGeo = mergeGeometries(soilParts.filter(Boolean));
    soilParts.forEach(g => g && g.dispose());
    if (soilGeo) {
        const soil = new THREE.Mesh(soilGeo, soilMat);
        soil.receiveShadow = highFx;
        this.scene.add(soil);
    }

    // ── 7. The hero court — travertine drum + bronze knot (kept, restaged) ─
    // The garden's centre of gravity and the promenade's full stop. The v3
    // pedestal was tall and narrow (a plinth for a trophy); the v4 drum is
    // LOW and WIDE — a contemporary sculpture-park plinth the visitor
    // circles on the gravel court. The knot keeps its sky-lit bronze.
    const pedestalMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xbdb5a2 })
        : new THREE.MeshStandardMaterial({ color: 0xbdb5a2, roughness: 0.8, metalness: 0.02 });
    const pedestalGeo = new THREE.CylinderGeometry(0.98, 1.08, 0.55, 40);
    const pedestal = new THREE.Mesh(pedestalGeo, pedestalMat);
    pedestal.position.set(0, height(0, 0) + 0.275, 0);
    pedestal.castShadow = highFx && vc.sun_shadows === true;
    pedestal.receiveShadow = highFx;
    this.scene.add(pedestal);
    this.registerObstacle(pedestal, 0.25);

    const sculptureMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x8c6a3f })
        : new THREE.MeshStandardMaterial({
            color: 0x8c6a3f, roughness: 0.32, metalness: 0.88,
            envMapIntensity: 1.1,   // the knot answers to THIS garden's sky
        });
    const sculpture = new THREE.Mesh(new THREE.TorusKnotGeometry(0.36, 0.12, 128, 16), sculptureMat);
    sculpture.position.set(0, height(0, 0) + 0.55 + 0.48, 0);
    sculpture.rotation.set(0.55, 0.35, 0);
    sculpture.castShadow = pedestal.castShadow;
    this.scene.add(sculpture);

    // ── 8. The asset layer — trees, planting, boulders, benches ─────────
    // Everything living comes from the owner's GLB library (§3-§5 of the
    // brief): the plan's role-tagged anchors consume named assets from
    // visual_config.garden.assets. Missing files SKIP their layer with a
    // single diagnostic — no placeholders, no crash; the base environment
    // above is designed to stand alone. Instancing keeps a fully planted
    // garden at ~1 draw call per role mesh. A generation token guards
    // against stale async loads after a rebuild.
    const anchors = [
        ...plan.trees.map(t => ({ x: t.x, z: t.z, role: t.role, yaw: t.rot, scale: t.scale })),
        ...plan.shrubs.map(s => ({ x: s.x, z: s.z, role: s.role, yaw: s.rot, scale: s.scale })),
        ...plan.boulders.map(b => ({ x: b.x, z: b.z, role: 'boulder', yaw: b.rot, scale: b.scale })),
        ...plan.benches.map(b => ({ x: b.x, z: b.z, role: 'bench', yaw: b.yaw, scale: 1 })),
    ];
    const gen = (this._gardenAssetGen = (this._gardenAssetGen || 0) + 1);
    this._gardenAssetsSettled = false;   // deterministic async-content gate (QA harness polls this)
    loadGardenAssets(resolveGardenAssetRequests(gardenCfg), this).then(({ entries }) => {
        if (gen !== this._gardenAssetGen || this._disposed) return;   // stale build
        buildGardenAssetInstances(this, entries, groupAnchorsByRole(anchors), height, {
            tierLow: this.isLowEnd,
            anchorCap: 16,   // low tier trims trailing (horizon) anchors first
            sink: 0.05,
        });
        this._gardenAssetsSettled = true;
    }).catch(() => { this._gardenAssetsSettled = true; /* never blocks boot */ });

    // ── 9. Ground follow — the visitor walks the lawn, not a plane ─────
    // Movement pins the camera to 1.6 m every frame; this tick (consumed by
    // the animate loop AFTER movement) settles the eye onto the TERRAIN's
    // 1.6 m — gently, frame-rate independently. Locomotion, not an effect:
    // reduced-motion users keep it (it is how walking downhill feels).
    this._gardenTick = function gardenGroundFollow() {
        if (this.arrivalActive || this.isInspecting) return;
        const now = performance.now();
        const dt = Math.min(0.1, (now - (this._gardenLastT || now)) / 1000);
        this._gardenLastT = now;
        const cam = this.camera.position;
        const target = height(cam.x, cam.z) + CONFIG.camera.height;
        const nextY = cam.y + (target - cam.y) * (1 - Math.exp(-9 * dt));
        if (Math.abs(nextY - cam.y) > 1e-4) cam.y = nextY;
    };

    // ── 10. Camera far floor — the sky must survive the off-centre spawn ─
    // The generic room-far (2.5·reach + 10) sized for the FLOOR; the spawn
    // plaza stands 0.64R south of the dome's centre, so the dome's forward
    // surface sits ~3.5R from the lens — beyond that far plane the dome
    // clipped into a hard-edged pale disc (low-tier QA catch). Floor the
    // far plane at 3.7R on every tier.
    const farFloor = radius * 3.7;
    if (this.camera.far < farFloor) {
        this.camera.far = farFloor;
        this.camera.updateProjectionMatrix();
    }

    // ── 11. Set circular bounds (player stays inside the landscape) ─────
    this._circularBoundsRadius = radius - 0.5;
}

// ── Garden build helpers (v4) ────────────────────────────────────────────────
// Deterministic mulberry32 — canvas textures must be IDENTICAL across loads
// and builds (the Rng.js contract, local copy so this stays build-time only).
function _gardenTexRng(seedStr) {
    let h = 1779033703 ^ seedStr.length;
    for (let i = 0; i < seedStr.length; i++) {
        h = Math.imul(h ^ seedStr.charCodeAt(i), 3432918353);
        h = (h << 13) | (h >>> 19);
    }
    let a = h >>> 0;
    return function () {
        a |= 0; a = (a + 0x6D2B79F5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

// Lawn detail: large soft tonal patches + fine mottle, NEAR-WHITE so the
// declared lawn colour drives the hue (the texture only varies it).
let _lawnTex = null;
function makeLawnDetailTexture() {
    if (_lawnTex) return _lawnTex;
    const c = document.createElement('canvas');
    c.width = c.height = 256;
    const ctx = c.getContext('2d');
    const rng = _gardenTexRng('garden-lawn-v4');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, 256, 256);
    const tones = ['246,249,238', '236,243,222', '226,236,206', '242,240,226', '250,250,244'];
    for (let i = 0; i < 30; i++) {
        const x = rng() * 256, y = rng() * 256;
        const r = 18 + rng() * 46;
        const g = ctx.createRadialGradient(x, y, 0, x, y, r);
        const tone = tones[Math.floor(rng() * tones.length)];
        g.addColorStop(0, `rgba(${tone},${0.16 + rng() * 0.16})`);
        g.addColorStop(1, `rgba(${tone},0)`);
        ctx.fillStyle = g;
        ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill();
    }
    for (let i = 0; i < 1100; i++) {
        const v = rng() < 0.5 ? 70 : 255;
        ctx.fillStyle = `rgba(${v},${v},${v},${0.04 + rng() * 0.06})`;
        ctx.fillRect(rng() * 256, rng() * 256, 1 + rng() * 1.6, 1 + rng() * 1.6);
    }
    const tex = new THREE.CanvasTexture(c);
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    tex.colorSpace = THREE.SRGBColorSpace;
    _lawnTex = tex;
    return tex;
}

// Gravel: warm limestone speckle — deterministic, tileable (noise wraps by
// drawing pebbles across edges with modulo).
let _gravelTex = null;
function makeGravelTexture() {
    if (_gravelTex) return _gravelTex;
    const c = document.createElement('canvas');
    c.width = c.height = 256;
    const ctx = c.getContext('2d');
    const rng = _gardenTexRng('garden-gravel-v4');
    ctx.fillStyle = '#a89e8a';
    ctx.fillRect(0, 0, 256, 256);
    const pebble = ['#8e8574', '#b8ae9a', '#cfc7b2', '#9a917e', '#d8d1bd', '#7d7462'];
    for (let i = 0; i < 2400; i++) {
        const x = rng() * 256, y = rng() * 256;
        const r = 0.8 + rng() * 1.9;
        ctx.fillStyle = pebble[Math.floor(rng() * pebble.length)];
        ctx.globalAlpha = 0.55 + rng() * 0.45;
        ctx.beginPath();
        ctx.ellipse(x, y, r, r * (0.7 + rng() * 0.5), rng() * Math.PI, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.globalAlpha = 1;
    const tex = new THREE.CanvasTexture(c);
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    tex.colorSpace = THREE.SRGBColorSpace;
    _gravelTex = tex;
    return tex;
}

// Continuous gravel ribbon along a sampled polyline — the geometry behind
// the v4 walks (replaces v2's stone discs / v3's cylinder stones).
// Returns a BufferGeometry in WORLD XZ with +Y normals; uv v = metres/0.9.
function buildGravelRibbon(samples, width, height, lift) {
    const n = samples.length;
    if (n < 2) return null;
    const pos = new Float32Array(n * 2 * 3);
    const uv = new Float32Array(n * 2 * 2);
    const idx = [];
    const half = width / 2;
    let v = 0;
    for (let i = 0; i < n; i++) {
        const p = samples[i];
        const prev = samples[Math.max(0, i - 1)];
        const next = samples[Math.min(n - 1, i + 1)];
        let dx = next[0] - prev[0], dz = next[1] - prev[1];
        const len = Math.hypot(dx, dz) || 1;
        dx /= len; dz /= len;
        // left of direction (cross(up, dir)) in XZ
        const nx = -dz, nz = dx;
        if (i > 0) v += Math.hypot(p[0] - prev[0], p[1] - prev[1]);
        const y = height(p[0], p[1]) + lift;
        const o = i * 6;
        pos[o]     = p[0] + nx * half; pos[o + 1] = y; pos[o + 2] = p[1] + nz * half;
        pos[o + 3] = p[0] - nx * half; pos[o + 4] = y; pos[o + 5] = p[1] - nz * half;
        const uo = i * 4;
        uv[uo] = 0; uv[uo + 1] = v / 0.9;
        uv[uo + 2] = 1; uv[uo + 3] = v / 0.9;
        if (i < n - 1) {
            const a = i * 2;
            idx.push(a, a + 1, a + 2, a + 1, a + 3, a + 2);
        }
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    geo.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
    geo.setIndex(idx);
    geo.computeVertexNormals();
    return geo;
}

// Gravel disc (hero court / spawn plaza) — world XZ, terrain-following,
// uv scaled to the SAME 0.9 m tile as the ribbons so the gravel reads as
// one paved vocabulary.
function buildGravelDisc(x, z, r, height, lift) {
    const geo = new THREE.CircleGeometry(r, 40);
    geo.rotateX(-Math.PI / 2);
    const p = geo.attributes.position;
    const uv = geo.attributes.uv;
    for (let i = 0; i < p.count; i++) {
        const wx = p.getX(i) + x, wz = p.getZ(i) + z;
        p.setY(i, height(wx, wz) + lift);
        uv.setXY(i, (p.getX(i) + r) / (1.8 * r), (p.getZ(i) + r) / (1.8 * r));
    }
    geo.translate(x, 0, z);
    geo.computeVertexNormals();
    return geo;
}

// ── VOID VENUES — Infinite Void + 3 new variants ────────────────────────────
// All four share the "no walls, no ceiling, abstract atmosphere" feel.
// Individual character comes from the bespoke decorations below.
//
// Iteration 2 "Phenomena" → Iteration 6 "Consolidation" → CATHEDRAL AUDIT
// (2026-09-07): the four void venues' bespoke bodies are COMPOSABLE
// INGREDIENTS selected by config flags (the slug ladder is gone — DoD rule
// #7). The 'phenomena' structure_pass remains the pass gate + rollback
// switch; each ingredient is declared per venue:
//   void_dust       floating dust points        (infinite-void)
//   void_deepfield  layered deep-field nebula   (nebula-drift, 2026-09-08 audit
//                                               body: tilted galactic band of
//                                               seeded nebula-mass sprites, two-
//                                               strata neutral starfield, colossal
//                                               dark silhouettes, stardrift
//                                               current, meridian ring)
//   void_starfield  starfield + nebula cloud    (nebula-drift ROLLBACK body — the
//                                               v1.0.0 look, config-revert only)
//   void_arcade     luminous arcade architecture(crystal-cathedral, audit body:
//                                               faceted piers + pointed arches +
//                                               art bays + clerestory + rib vault
//                                               + oculus)
//   void_colonnade  IT2 glass-tube ring         (cathedral ROLLBACK body #1)
//   void_shards     legacy shard ring           (cathedral ROLLBACK body #2)
//   void_lake       moon + reflection + mist    (mirror-lake)
// Undeclared ⇒ nothing renders. Admin-created venues compose their own void
// from the same vocabulary — full identity without code (§17 IT6 outcome).
function addVoidVenueStructure(data) {
    const vc = this._venueVisualConfig || {};
    const meta = this._layoutMeta || {};
    const radius = meta.radius || 15;

    // Common: circular bounds. RoomBuilder.createRoomCircular owns the value
    // (radius − 0.5, the documented walkway edge); the old re-set here made
    // this line look like the authority while Collisions subtracted ANOTHER
    // 0.5 at enforcement time — a double inset nobody documented. Single
    // source now; the enforced bound is exactly radius − 0.5.
    if (this._circularBoundsRadius == null) {
        this._circularBoundsRadius = radius - 0.5;
    }

    if (vc.void_dust === true) {
        addVoidDustField.call(this, radius);
    }
    // A barely-perceptible zenith gradient — the one depth cue that makes
    // pure black read as DISTANCE instead of enclosure. Declared per venue
    // (void_depth_gradient); skipped on low-end, where the flat black
    // background already carries the identity.
    if (vc.void_depth_gradient === true && !this.isLowEnd) {
        addVoidDepthGradient.call(this, radius);
    }
    if (vc.void_deepfield === true) {
        // NEBULA DRIFT audit body (2026-09-08): the layered deep-field sky.
        // Takes precedence over the legacy starfield — the rollback chain is
        // void_deepfield → void_starfield → (nothing).
        addNebulaDeepfield.call(this, radius);
    } else if (vc.void_starfield === true) {
        addNebulaDriftStructure.call(this, radius);
    }
    if (vc.void_arcade === true) {
        // The audit body: crystal as ARCHITECTURE (piers, arches, vault,
        // oculus) — the IT2 colonnade below stays reachable as the rollback.
        addCrystalCathedralArcade.call(this, radius);
    } else if (vc.void_colonnade === true) {
        addCrystalCathedralColonnade.call(this, radius);
    } else if (vc.void_shards === true) {
        // Designed rollback body — the Iteration 2 legacy shard ring,
        // reachable by config only (swap void_colonnade/void_shards).
        addCrystalCathedralLegacyShards.call(this, radius);
    }
    if (vc.void_lake === true) {
        // v1.0.0 "Phomena" lake body — the rollback target for Mirror Lake
        // (structure_pass 'phenomena' + void_lake). The v3.0.0 flagship
        // body is the top-level 'lake' pass branch above.
        addMirrorLakeStructure.call(this, radius);
    }
}

// ── VOID DUST — per-particle drift, all heights (Infinity-void identity) ────
// The old body was a 200-point SQUARE slab pinned to y ∈ [0, 5] whose only
// motion was a whole-cloud vertical bob (the captured baseY array was never
// read). On screen it read as a cheap starfield band, not dust.
//
// This body:
//   • distributes motes in a CYLINDER around the exhibition (r ≤ radius·1.15,
//     y from knee height to ~3× eye level — dust surrounds the visitor in
//     every direction instead of forming a horizon band);
//   • drifts PER PARTICLE in the vertex shader (uTime uniform): each mote
//     breathes on its own seeded phase — vertical wander + a slow lateral
//     curl. Zero per-frame CPU, zero per-frame allocations, one draw call;
//   • stays static for reduced-motion visitors and on low-end (the animate
//     loop never advances uTime there, and low-end gets the plain
//     PointsMaterial body — same composition, no GLSL).
// Deterministic: every attribute comes from the venue's seeded rng.
function addVoidDustField(radius) {
    const rng = this._venueRng;
    const isLowEnd = !!this.isLowEnd;
    // 700 motes is still one draw call and ~8 KB of attributes — cheap
    // everywhere; the low-end body keeps 300 (Lambert-class device budget).
    const COUNT = isLowEnd ? 300 : 700;
    const ySpan = Math.min(12, radius * 0.45 + 2);

    const positions = new Float32Array(COUNT * 3);
    const phases    = new Float32Array(COUNT);
    const sizes     = new Float32Array(COUNT);

    for (let i = 0; i < COUNT; i++) {
        // Cylinder distribution (uniform disc × height band)
        const a = rng.next() * Math.PI * 2;
        const r = Math.sqrt(rng.next()) * radius * 1.15;
        positions[i * 3]     = Math.cos(a) * r;
        positions[i * 3 + 1] = 0.1 + rng.next() * ySpan;
        positions[i * 3 + 2] = Math.sin(a) * r;
        phases[i] = rng.next() * Math.PI * 2;
        sizes[i]  = 0.6 + rng.next() * 0.9; // relative size — attenuated in shader
    }

    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geo.setAttribute('aPhase',   new THREE.BufferAttribute(phases, 1));
    geo.setAttribute('aSize',    new THREE.BufferAttribute(sizes, 1));

    let mat;
    if (isLowEnd) {
        // Low-end: plain unlit points, static (no GLSL on this tier).
        mat = new THREE.PointsMaterial({
            color: 0xaabbcc,
            size: 0.045,
            transparent: true,
            opacity: 0.5,
            sizeAttenuation: true,
            depthWrite: false,
        });
    } else {
        mat = new THREE.ShaderMaterial({
            transparent: true,
            depthWrite: false,
            fog: false, // the dust IS atmosphere — scene fog must not eat it
            uniforms: {
                uTime:     { value: 0 },
                uColor:    { value: new THREE.Color(0x9fb2c8) },
                uOpacity:  { value: 0.42 },
                uBaseSize: { value: 0.05 },
            },
            vertexShader: /* glsl */`
                attribute float aPhase;
                attribute float aSize;
                uniform float uTime;
                uniform float uBaseSize;
                varying float vFade;
                void main() {
                    vec3 p = position;
                    // Per-mote drift: vertical breath + slow lateral curl.
                    // Amplitudes stay centimetre-scale — presence, not snow.
                    p.y += sin(uTime * 0.22 + aPhase) * 0.35;
                    p.x += sin(uTime * 0.11 + aPhase * 1.7) * 0.28;
                    p.z += cos(uTime * 0.09 + aPhase * 2.3) * 0.28;
                    vec4 mv = modelViewMatrix * vec4(p, 1.0);
                    // Distance fade: motes melt into the dark far away, and
                    // never pop against the camera at close range.
                    float d = -mv.z;
                    vFade = smoothstep(0.6, 2.0, d) * (1.0 - smoothstep(14.0, 26.0, d));
                    gl_PointSize = aSize * uBaseSize * (240.0 / max(d, 0.001));
                    gl_Position = projectionMatrix * mv;
                }
            `,
            fragmentShader: /* glsl */`
                uniform vec3 uColor;
                uniform float uOpacity;
                varying float vFade;
                void main() {
                    // Soft round mote (no square points)
                    vec2 uv = gl_PointCoord - 0.5;
                    float a = 1.0 - smoothstep(0.18, 0.5, length(uv));
                    gl_FragColor = vec4(uColor, uOpacity * a * vFade);
                }
            `,
        });
    }

    const points = new THREE.Points(geo, mat);
    points.frustumCulled = false; // per-mote drift must never pop at the bbox edge
    this.scene.add(points);
    this._particleSystems = this._particleSystems || [];
    this._particleSystems.push({
        obj: points,
        type: 'void-drift',
        phase: rng.next() * Math.PI * 2, // reserved: keeps rng call order stable vs the old body
    });
}

// ── VOID DEPTH GRADIENT — the whisper of "up" that makes black infinite ─────
// A huge inverted sphere with a two-stop gradient: a near-black blue at the
// zenith dissolving to pure black at/below the horizon. On its own it is
// almost invisible; next to pure-#000 screen edges it gives the eye a sense
// of VAST SPACE ABOVE instead of a painted ceiling of nothing. One draw
// call, no lighting interaction, fog-exempt.
//
// CATHEDRAL AUDIT (2026-09-07): the gradient moved from a custom
// ShaderMaterial to a CanvasTexture on MeshBasicMaterial. Visual parity is
// EXACT — the canvas bakes the same smoothstep(-0.08, 0.75, dir.y) curve
// against the sphere's v coordinate (dir.y = 1 − 2v) — but the basic-
// material path composites safely through the transmission/reflection
// passes on software rasterizers (SwiftShader died compositing
// shader-material dome + transmission glass), costs no custom program, and
// still reads on Lambert-class devices. Infinite Void shares this body and
// renders the identical gradient.
function addVoidDepthGradient(radius) {
    // Radius budget: buildGallery derives camera.far from the circular
    // bounds as reach·2.5 + 10, and the floor fade ends at radius·2.2 — the
    // dome sits just outside the fade and just inside the far plane, so it
    // can never be clipped nor outdone by the background colour.
    const domeRadius = radius * 2.4 + 6;
    const geo = new THREE.SphereGeometry(domeRadius, 24, 12);

    // Bake the smoothstep gradient (256 px of vertical resolution is far
    // beyond what a two-stop near-black fade can ever reveal).
    // COLOUR-SPACE PARITY: the old shader mixed the LINEAR uniform values
    // and let the OutputPass encode to sRGB. The texture is tagged sRGB
    // (decoded on sample), so the bytes must be the sRGB ENCODING of the
    // same linear mix — identical framebuffer values, identical render.
    const ZENITH  = new THREE.Color(0x0a0e18);   // linear working space
    const HORIZON = new THREE.Color(0x000000);
    const canvas = document.createElement('canvas');
    canvas.width = 1; canvas.height = 256;
    const ctx2d = canvas.getContext('2d');
    const img = ctx2d.createImageData(1, 256);
    const mixed = new THREE.Color();
    for (let y = 0; y < 256; y++) {
        const v = y / 255;              // 0 = top row (zenith pole), 1 = bottom
        const dirY = 1 - 2 * v;         // sphere v → dir.y parity
        // GLSL smoothstep parity: t = clamp((x−e0)/(e1−e0))² (3−2t̂)
        const x = Math.min(1, Math.max(0, (dirY - -0.08) / (0.75 - -0.08)));
        const t = x * x * (3 - 2 * x);
        mixed.copy(HORIZON).lerp(ZENITH, t).convertLinearToSRGB();
        img.data[y * 4]     = Math.round(mixed.r * 255);
        img.data[y * 4 + 1] = Math.round(mixed.g * 255);
        img.data[y * 4 + 2] = Math.round(mixed.b * 255);
        img.data[y * 4 + 3] = 255;
    }
    ctx2d.putImageData(img, 0, 0);
    const gradientTex = new THREE.CanvasTexture(canvas);
    gradientTex.colorSpace = THREE.SRGBColorSpace;
    gradientTex.magFilter = THREE.LinearFilter;
    gradientTex.minFilter = THREE.LinearFilter;
    gradientTex.generateMipmaps = false;

    const mat = new THREE.MeshBasicMaterial({
        side: THREE.BackSide,
        depthWrite: false,
        fog: false,
        map: gradientTex,
    });
    const dome = new THREE.Mesh(geo, mat);
    dome.renderOrder = -10; // behind everything
    dome.frustumCulled = false;
    this.scene.add(dome);
}

// CRYSTAL CATHEDRAL — "The Luminous Arcade" (architecture audit, 2026-09-07)
// ────────────────────────────────────────────────────────────────────────────
// The IT2 colonnade below rendered twelve thin smooth-shaded glass TUBES plus
// four pastel-rainbow point lights: crystal as decoration, a fence of rods —
// nothing that deserved the word "cathedral". This body replaces decoration
// with STRUCTURE (§7: crystal as architecture). One idea, executed
// coherently — a Gothic SECTION without a single religious symbol:
//
//   [y 18.8]  OCULUS — luminous disc inside a crystal boss ring, the vault's
//             crown. The one full-bright surface (bloom threshold 0.82
//             catches it and the clerestory seam — nothing else).
//   [y 12.6→18.8] RIB VAULT — one faceted rib springing per pier, curving
//             inward to the boss ring. Radial, structural, THE look-up shot.
//   [y 13.0→15.4] CLERESTORY — a crystal panel per bay under a continuous
//             luminous seam: light ENTERS high, as the section demands.
//   [y 7.6→12.8] POINTED ARCHES — equilateral two-centred arcs between the
//             piers (hexagonal torus profile = faceted, cut-stone read).
//   [y 0→13]  PIERS — tapered OCTAGONAL prisms (8 flat-shaded facets,
//             0.5→0.72 m, alternating 13.0/12.2 m mass rhythm like real
//             Gothic pier alternation) on a ring OUTSIDE the walk bound.
//   [y 0→4.6] ART-BAY WALL — an opaque polished-slate band ring with a
//             pilaster + lintel per bay: the framed bays artworks hover
//             before. Architecture outside, controlled presentation inside
//             (§18's exact resolution for artwork-vs-translucency).
//
// SCALE / RHYTHM (§10, §13): the bay count adapts to the exhibition radius —
// round(2πR / 6 m) clamped to [10, 20] — so bays stay ~6 m wide from 5
// artworks (R ≈ 10) to 40 (R ≈ 17): deliberate rhythm, never a sparse fence.
// All ring geometry is INSTANCED (14 draw calls for the whole composition,
// ~12k triangles); nothing is seeded-random on purpose — architectural
// rhythm is arithmetic, not noise (the only rng consumer remains the float
// hang in ArtworkPlacer, deterministic per gallery as before).
//
// MATERIALS (§11, §19): a four-tier hierarchy, every tier resolved:
//   1. primary crystal (piers/arches/clerestory/vault/boss) — tier-resolved
//      glass, flat-shaded so facets read even on Lambert (low-end);
//   2. art-bay stone (wall/coping/pilasters/headers) — OPAQUE, reads the
//      venue's material_config wall_* declaration (DB is the authority);
//   3. floor — the venue's slate (material_config floor_*), planar Reflector
//      on high tier via the declared floor_reflection = 'planar';
//   4. luminous accents (oculus/seam/medallion/shafts) — unlit MeshBasic,
//      the only emissive-class surfaces in the venue.
// Colour restraint (§20): ice-white light on a deep blue-black void; the
// blue lives in the atmosphere, NEVER in the material. The pastel-rainbow
// point lights of the old body are gone — the rig is ambient + hemisphere +
// the oculus key SpotLight + the pooled artwork lights.
//
// PERF (§26): 14 draws / ~12k tris / +1 SpotLight (PERF-B18 budget intact:
// 4 rainbow PointLights removed, 1 SpotLight added). Transmission = 1 extra
// pass on high tier; Reflector = 1 extra 1024² render on high tier (both
// already-shipped shared effects, Mirror Lake precedent). Low-end: Lambert
// glass, gloss floor, same geometry — the cathedral reads on every tier.
function addCrystalCathedralArcade(radius) {
    const vc   = this._venueVisualConfig || {};
    const mc   = this._venueMaterialConfig || {};
    const tint = parseColor(vc.colonnade_tint) || new THREE.Color(0xe6f0fb);

    // ── Adaptive bay plan ─────────────────────────────────────────────────
    // Pier ring radius sits 0.8 m outside the floor radius; the walk bound
    // is radius − 0.5 (RoomBuilder) and the art ring radius − 1.5 — nothing
    // here is reachable, so nothing registers a collision obstacle.
    const pierR      = radius + 0.8;
    const bayTarget  = 6.0;                                   // metres of arcade per bay
    const bayCount   = Math.max(10, Math.min(24, Math.round((Math.PI * 2 * pierR) / bayTarget)));
    const chord      = 2 * pierR * Math.sin(Math.PI / bayCount); // pier-centre span
    const PIER_H     = 13.0;
    const PIER_H_ALT = 12.2;                                  // deliberate ABAB mass rhythm
    // Adaptive springing: the pointed crown rises 0.866·chord above the
    // springing line and must stay under the 13.0 pier crown at EVERY
    // exhibition radius — including the 24-bay clamp, where bay spacing
    // exceeds the 6 m target (chord → 7.2 m at R = 22). 7.4 m is the
    // composed default; the clamp term only engages on the largest shows.
    const SPRING_Y   = Math.min(7.4, 12.9 - chord * 0.866);
    const STONE      = parseColor(mc.wall_color) || new THREE.Color(0x131a26);

    // Crystal, tier-resolved, FACETED (flatShading — the §13 mandate).
    const crystalMat = makeGlassMaterial(this, { tint, opacity: 0.55, flatShading: true, roughness: 0.14, thickness: 1.1 });
    // Art-bay stone: opaque, from the venue's own material declaration.
    const stoneMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: STONE, side: THREE.BackSide })
        : new THREE.MeshStandardMaterial({
            color: STONE,
            roughness: mc.wall_roughness ?? 0.3,
            metalness: mc.wall_metalness ?? 0.06,
            side: THREE.BackSide,
        });
    // Dressed-stone trim (pilasters/headers/coping) — DEPLOY REVIEW FIX
    // (2026-09-08): the framing was the SAME material and colour as the wall
    // field, and nothing in the rig reaches the wall band, so the "framed
    // bays of stone" the copy promises rendered as one black void. Real
    // arcades differentiate DRESSED stone from field stone; the trim tone is
    // derived deterministically from the DECLARED wall colour (lerp toward
    // the crystal's ice-white — no second config source, no DB key), quiet
    // enough to keep the field dark and the artwork contrast intact.
    const TRIM = STONE.clone().lerp(new THREE.Color(0xdfe9f5), 0.45);
    const stoneTrimMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: TRIM })
        : new THREE.MeshStandardMaterial({
            color: TRIM,
            roughness: Math.min((mc.wall_roughness ?? 0.3) + 0.15, 0.6), // dressed stone is matte
            metalness: mc.wall_metalness ?? 0.06,
        });

    const tmpM  = new THREE.Matrix4();
    const tmpQ  = new THREE.Quaternion();
    const tmpE  = new THREE.Euler();
    const tmpP  = new THREE.Vector3();
    const tmpS  = new THREE.Vector3(1, 1, 1);

    const placeInstance = (mesh, i, azimuth, x, y, z, rotY, rotZ = 0, scaleY = 1) => {
        tmpE.set(0, rotY, rotZ);
        tmpQ.setFromEuler(tmpE);
        tmpP.set(x, y, z);
        tmpS.set(1, scaleY, 1);
        tmpM.compose(tmpP, tmpQ, tmpS);
        mesh.setMatrixAt(i, tmpM);
        // Instance-aware bounds are unnecessary at this scale — the whole
        // composition is ~14 draws; never let unit-geometry bounds cull a
        // ring whose instances live metres away from the origin.
        mesh.frustumCulled = false;
    };

    // ── 1. Piers — tapered octagonal prisms, ABAB height rhythm ───────────
    const pierGeo = new THREE.CylinderGeometry(0.5, 0.72, 1, 8, 1); // unit height; scaled per instance
    const piers = new THREE.InstancedMesh(pierGeo, crystalMat, bayCount);
    for (let i = 0; i < bayCount; i++) {
        const a = (i / bayCount) * Math.PI * 2;
        const h = (i % 2 === 0) ? PIER_H : PIER_H_ALT;
        placeInstance(piers, i, a, Math.sin(a) * pierR, h / 2, Math.cos(a) * pierR, a, 0, h);
    }
    piers.instanceMatrix.needsUpdate = true;
    this.scene.add(piers);

    // ── 2. Pointed arches — equilateral two-centred arcs between piers ────
    // Each arch = two 60° torus arcs of radius = chord. arcsR is centred on
    // pier i (A): geometry 0° → pier i+1 (B), geometry 60° → the shared
    // apex. arcsL is centred on pier i+1 (B), pre-rotated by 120°: geometry
    // 0° → the shared apex, geometry 60° → pier i (A). The crown sits just
    // under the pier tops at every radius (adaptive SPRING_Y above).
    // FRAME: the torus lies in its local XY plane, so local +X must point
    // along the chord A→B and local +Y up: rotY = atan2(−dz, dx) maps
    // +X→(cos,0,−sin) onto the chord direction (three.js Ry convention).
    // The instance quaternion is Ry(yaw)·Rz(sweep) — the sweep rotates the
    // arc WITHIN its own plane before the yaw orients the plane.
    const arcGeo = new THREE.TorusGeometry(chord, 0.16, 6, 14, Math.PI / 3);
    const arcsL  = new THREE.InstancedMesh(arcGeo, crystalMat, bayCount); // centred on B: apex→A
    const arcsR  = new THREE.InstancedMesh(arcGeo, crystalMat, bayCount); // centred on A: B→apex
    for (let i = 0; i < bayCount; i++) {
        const a  = (i / bayCount) * Math.PI * 2;
        const a2 = ((i + 1) / bayCount) * Math.PI * 2;
        const ax = Math.sin(a) * pierR,  az = Math.cos(a) * pierR;
        const bx = Math.sin(a2) * pierR, bz = Math.cos(a2) * pierR;
        const yaw = Math.atan2(-(bz - az), bx - ax); // local +X along the chord A→B
        placeInstance(arcsL, i, a, bx, SPRING_Y, bz, yaw, 2 * Math.PI / 3);
        placeInstance(arcsR, i, a, ax, SPRING_Y, az, yaw, 0);
    }
    arcsL.instanceMatrix.needsUpdate = true;
    arcsR.instanceMatrix.needsUpdate = true;
    this.scene.add(arcsL);
    this.scene.add(arcsR);

    // ── 3. Art-bay wall — the opaque presentation band ─────────────────────
    const wallR  = radius + 0.15;
    const wallH  = 4.6;
    const wall   = new THREE.Mesh(new THREE.CylinderGeometry(wallR, wallR, wallH, 96, 1, true), stoneMat);
    wall.position.y = wallH / 2;
    this.scene.add(wall);
    const coping = new THREE.Mesh(new THREE.CylinderGeometry(wallR + 0.04, wallR + 0.04, 0.16, 96, 1, true), stoneTrimMat);
    coping.position.y = wallH + 0.08;
    this.scene.add(coping);

    // Pilaster + lintel per bay (instanced) — the "framed bay" read the
    // copy promises; aligned to the SAME azimuths as the piers.
    const pilGeo = new THREE.BoxGeometry(0.14, wallH, 0.06);
    const pilasters = new THREE.InstancedMesh(pilGeo, stoneTrimMat, bayCount);
    const headerW = (Math.PI * 2 * (wallR + 0.02)) / bayCount - 0.5;
    const hdrGeo  = new THREE.BoxGeometry(1, 0.22, 0.07); // x scaled per bay
    const headers = new THREE.InstancedMesh(hdrGeo, stoneTrimMat, bayCount);
    for (let i = 0; i < bayCount; i++) {
        const a = (i / bayCount) * Math.PI * 2;
        placeInstance(pilasters, i, a, Math.sin(a) * (wallR - 0.02), wallH / 2, Math.cos(a) * (wallR - 0.02), a);
        tmpE.set(0, a, 0);
        tmpQ.setFromEuler(tmpE);
        tmpP.set(Math.sin(a) * (wallR - 0.02), wallH - 0.25, Math.cos(a) * (wallR - 0.02));
        tmpS.set(headerW, 1, 1);
        tmpM.compose(tmpP, tmpQ, tmpS);
        headers.setMatrixAt(i, tmpM);
    }
    pilasters.instanceMatrix.needsUpdate = true;
    headers.instanceMatrix.needsUpdate = true;
    this.scene.add(pilasters);
    this.scene.add(headers);

    // ── 4. Clerestory — a crystal panel per bay + the luminous seam ───────
    const clR  = radius + 0.95;
    const clH  = 2.4;
    const clY  = 14.2; // band 13.0 → 15.4 — above the arch crown, below the vault
    const clGeo = new THREE.PlaneGeometry(chord * 0.82, clH);
    const clerestory = new THREE.InstancedMesh(clGeo, crystalMat, bayCount);
    for (let i = 0; i < bayCount; i++) {
        const a = (i / bayCount) * Math.PI * 2;
        placeInstance(clerestory, i, a, Math.sin(a) * clR, clY, Math.cos(a) * clR, a);
    }
    clerestory.instanceMatrix.needsUpdate = true;
    this.scene.add(clerestory);

    const seam = new THREE.Mesh(
        new THREE.CylinderGeometry(radius + 1.02, radius + 1.02, 0.14, 96, 1, true),
        new THREE.MeshBasicMaterial({
            color: 0xeaf2ff,
            transparent: true,
            opacity: 0.85,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
            side: THREE.DoubleSide,
        })
    );
    seam.position.y = clY + clH / 2 + 0.15;
    this.scene.add(seam);

    // ── 5. Rib vault — one rib per pier, converging on the boss ring ──────
    const bossR = 2.3;
    const bossY = 18.8;
    const springR = pierR * 0.92;          // ribs spring just inside the pier axes
    const vaultCurve = new THREE.QuadraticBezierCurve3(
        new THREE.Vector3(springR, 12.6, 0),
        new THREE.Vector3((springR + bossR) / 2, 17.2, 0),
        new THREE.Vector3(bossR, bossY, 0)
    );
    const ribGeo = new THREE.TubeGeometry(vaultCurve, 16, 0.11, 6, false);
    const ribs = new THREE.InstancedMesh(ribGeo, crystalMat, bayCount);
    for (let i = 0; i < bayCount; i++) {
        const a = (i / bayCount) * Math.PI * 2;
        // The rib geometry is drawn in the XY plane (x = radius, y = up);
        // rotY = a − π/2 maps its radial +X onto the pier azimuth a (whose
        // position convention is (sin a, ·, cos a)).
        placeInstance(ribs, i, a, 0, 0, 0, a - Math.PI / 2);
    }
    ribs.instanceMatrix.needsUpdate = true;
    this.scene.add(ribs);

    const boss = new THREE.Mesh(new THREE.TorusGeometry(bossR, 0.16, 6, 48), crystalMat);
    boss.rotation.x = Math.PI / 2;
    boss.position.y = bossY;
    this.scene.add(boss);

    // ── 6. Oculus — the luminous crown + the single key light ─────────────
    const oculus = new THREE.Mesh(
        new THREE.CircleGeometry(1.9, 32),
        new THREE.MeshBasicMaterial({ color: 0xf2f7ff })
    );
    oculus.rotation.x = Math.PI / 2; // faces DOWN — the vault's source of light
    oculus.position.y = bossY;
    this.scene.add(oculus);

    const keyLight = new THREE.SpotLight(0xeaf2ff, 2.4, 46, 0.55, 0.45, 1.2);
    keyLight.position.set(0, bossY + 1.4, 0);
    keyLight.target.position.set(0, 0, 0);
    keyLight.castShadow = false;
    this.scene.add(keyLight);
    this.scene.add(keyLight.target);

    // Six light planes falling from the oculus (the §14 "light shafts" —
    // anchored to the oculus, above the sightlines, additive at 0.06: the
    // only effect in the venue, and it is architectural, not decorative).
    const shaftGeo = new THREE.PlaneGeometry(1.5, 4.5);
    const shaftMat = new THREE.MeshBasicMaterial({
        color: 0xdfeaff,
        transparent: true,
        opacity: 0.06,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        side: THREE.DoubleSide,
        fog: false,
    });
    const SHAFTS = 6;
    const shafts = new THREE.InstancedMesh(shaftGeo, shaftMat, SHAFTS);
    for (let i = 0; i < SHAFTS; i++) {
        const a = (i / SHAFTS) * Math.PI * 2 + Math.PI / SHAFTS; // offset from the pier axes
        tmpE.set(-0.28, a, 0, 'YXZ'); // lean the top edge toward the axis
        tmpQ.setFromEuler(tmpE);
        tmpP.set(Math.sin(a) * 2.55, 16.05, Math.cos(a) * 2.55);
        tmpS.set(1, 1, 1);
        tmpM.compose(tmpP, tmpQ, tmpS);
        shafts.setMatrixAt(i, tmpM);
    }
    shafts.instanceMatrix.needsUpdate = true;
    this.scene.add(shafts);

    // ── 7. Floor medallion — where the light lands (the crossing) ─────────
    const medallion = new THREE.Mesh(
        new THREE.RingGeometry(2.35, 2.75, 48),
        new THREE.MeshBasicMaterial({ color: 0x9fb8d9, transparent: true, opacity: 0.35 })
    );
    medallion.rotation.x = -Math.PI / 2;
    medallion.position.y = 0.02; // above the Reflector plane (0.001) and the floor
    this.scene.add(medallion);

    // ── 8. Floor reflection — declared 'planar', tiered (TierResolve) ─────
    // Same machinery Mirror Lake ships, but DEPLOY REVIEW FIX (2026-09-08):
    // blend = 'multiply'. The stock overlay blend with this bright a tint
    // brightened reflection midtones and left near-white pixels at full
    // luminance, so reflected artworks re-entered the bloom pass and the
    // deployed screenshot read as a DUPLICATED WORLD (user-reported). The
    // multiply blend scales every reflected luminance DOWN by the tint —
    // reflected whites cap at the tint (≈0.35, far under the 0.82 bloom
    // threshold) — which is polished-dark-stone behaviour: the hang ghosts
    // faintly across the floor instead of playing again underneath it.
    const reflectionMode = resolveReflectionMode({
        isLowEnd: !!this.isLowEnd,
        isMobileTier: !!this._isMobileTier,
        declared: vc.floor_reflection === 'planar',
    });
    if (reflectionMode === 'planar') {
        addPlanarReflection(this, radius, { color: 0x5a6a85, resolution: 1024, blend: 'multiply' });
    } else if (reflectionMode === 'gloss') {
        // Designed gloss mood (no moon here — the venue's own rig carries
        // the specular response; soften metalness so a PBR floor without an
        // HDRI never reads dead black — the Mirror Lake lesson, reused).
        const floor = this._circularFloor;
        if (floor && floor.material && !this.isLowEnd) {
            floor.material.metalness = 0.5;
            floor.material.roughness = Math.min(floor.material.roughness ?? 0.22, 0.12);
            floor.material.needsUpdate = true;
        }
    }
}

// CRYSTAL CATHEDRAL — composed vertical light architecture (Iteration 2)
// ────────────────────────────────────────────────────────────────────────────
// NEW BODY (structure_pass = 'phenomena'): a seeded COLONNADE of 12 tall
// glass pillars ringing the exhibition — "cathedral" as verticality and
// light, not religion (§4.6: one idea — light through glass, at scale).
// The 12 random octahedra ("shader test scene", verified audit verdict) are
// REPLACED, not augmented: §5.6 — one signature per venue.
//
// Placement: the colonnade stands just OUTSIDE the walkable bounds
// (r ≈ radius + 0.35, bounds clamp at radius − 0.5), exactly like the
// garden's hedge boundary — it is the venue's wall of light, so no pillar
// can ever collide with a visitor or clip an artwork ring (artworks hover
// at r ≤ radius − 1 in float mode). The old shard ring placed geometry at
// radius × 0.5–0.8 — inside the artwork field — which is one reason it
// read as clutter.
//
// Tier gate (§11.3 row 2): glass material resolved by TierResolve — true
// transmission on high tier (HDRI guaranteed), designed cheap glass on
// mobile, flat transparent on low-end. NULL GLASS IS UNREACHABLE.
function addCrystalCathedralColonnade(radius) {
    const rng = this._venueRng;
    const vc  = this._venueVisualConfig || {};

    // One shared material for the whole colonnade (tier-resolved); one
    // shared geometry — 12 pillars, 12 draw calls.
    const tint = parseColor(vc.colonnade_tint) || new THREE.Color(0xdfeaff);
    const glassMat = makeGlassMaterial(this, { tint, opacity: 0.4 });

    const COUNT      = 12;
    const HEIGHT_MIN = 9;
    const HEIGHT_MAX = 15;

    for (let i = 0; i < COUNT; i++) {
        const angle = (i / COUNT) * Math.PI * 2;
        // Seeded depth wander within the outer band — organic, never aligned
        // like a fence, never inside the artwork field.
        const r = radius + 0.35 + (rng.next() - 0.5) * 0.5;
        // Seeded height: the skyline rhythm that makes it read as
        // architecture instead of a ring of posts.
        const h = HEIGHT_MIN + rng.next() * (HEIGHT_MAX - HEIGHT_MIN);
        const pillarRadius = 0.3 + rng.next() * 0.18;

        const pillar = new THREE.Mesh(
            new THREE.CylinderGeometry(pillarRadius * 0.8, pillarRadius, h, 8, 1, true),
            glassMat
        );
        pillar.position.set(Math.sin(angle) * r, h / 2, Math.cos(angle) * r);
        this.scene.add(pillar);

        // Coloured point light inside every 3rd pillar — the SAME 4-light
        // budget the shard ring used (PERF-B18 preserved exactly).
        if (i % 3 === 0) {
            const colors = [0xffaaaa, 0xaaffaa, 0xaaaaff, 0xffffaa, 0xffaaff, 0xaaffff];
            const c = colors[i % colors.length];
            const light = new THREE.PointLight(c, 0.5, 8);
            light.position.copy(pillar.position);
            this.scene.add(light);

            // ONE controlled light shaft per lit pillar: a tall additive
            // plane rising the full pillar height. 4 shafts total — part of
            // the venue's ONE signature (light through glass), not extra
            // polish (DO-NOT-DO #5 respected).
            const shaftGeo = new THREE.PlaneGeometry(1.1, h * 0.92);
            const shaftMat = new THREE.MeshBasicMaterial({
                color: c,
                transparent: true,
                opacity: 0.07,
                blending: THREE.AdditiveBlending,
                depthWrite: false,
                side: THREE.DoubleSide,
            });
            const shaft = new THREE.Mesh(shaftGeo, shaftMat);
            shaft.position.set(pillar.position.x, (h * 0.92) / 2, pillar.position.z);
            shaft.rotation.y = angle;
            this.scene.add(shaft);
        }
    }
}

// LEGACY BODY (pre-pass) — kept verbatim as the per-venue rollback target.
// Runs only when the venue's config does NOT declare structure_pass.
function addCrystalCathedralLegacyShards(radius) {
    const shardMat = new THREE.MeshPhysicalMaterial({
        color: 0xffffff,
        roughness: 0.05,
        metalness: 0.0,
        transmission: 0.95,
        thickness: 0.5,
        ior: 1.5,
        transparent: true,
        opacity: 0.6,
        side: THREE.DoubleSide,
    });

    // 12 floating glass shards at varying heights
    // Iteration 0: shard ring/height/rotation/scale are seeded (was
    // Math.random) — the venue a customer chose from a still is the venue
    // every visitor sees.
    const rng = this._venueRng;
    const shardGeo = new THREE.OctahedronGeometry(1.0, 0);
    for (let i = 0; i < 12; i++) {
        const shard = new THREE.Mesh(shardGeo, shardMat);
        const angle = (i / 12) * Math.PI * 2;
        const r = radius * 0.5 + rng.next() * radius * 0.3;
        shard.position.set(Math.cos(angle) * r, 2 + rng.next() * 4, Math.sin(angle) * r);
        shard.rotation.set(rng.next() * Math.PI, rng.next() * Math.PI, rng.next() * Math.PI);
        shard.scale.setScalar(0.8 + rng.next() * 1.5);
        this.scene.add(shard);

        // Coloured point light inside every 3rd shard.
        // PERF-B18 (3D audit F18): all 12 shards used to carry an
        // always-on coloured PointLight. Combined with artwork + fill lights
        // that pushed Crystal Cathedral past 20 dynamic lights per fragment
        // (every fragment pays for every light). Four lights spread through
        // the ring + the shards' own transmission shading read virtually
        // identically at a fraction of the cost. Bloom (high-end) still
        // catches the shards.
        if (i % 3 === 0) {
            const colors = [0xffaaaa, 0xaaffaa, 0xaaaaff, 0xffffaa, 0xffaaff, 0xaaffff];
            const c = colors[i % colors.length];
            const light = new THREE.PointLight(c, 0.5, 6);
            light.position.copy(shard.position);
            this.scene.add(light);
        }

        // Register for slow rotation animation in animate()
        this._particleSystems = this._particleSystems || [];
        this._particleSystems.push({ obj: shard, type: 'rotate-slow', phase: rng.next() * Math.PI * 2 });
    }

    // Floor: crystal — high metalness, mirror-like
    // (Floor is set in RoomBuilder via floorMaterial="marble" + high metalness
    // override in the venue's material_config.)
}

// NEBULA DRIFT — "THE DEEP FIELD" (audit body, 2026-09-08)
// ────────────────────────────────────────────────────────────────────────────
// The v1.0.0 body (kept below verbatim as the rollback) rendered the venue
// as "Infinite Void + purple": 800 all-violet stars on a far shell, ONE flat
// additive particle box drifting through the artwork zone, and a purple
// PointLight that DOUBLED the seeded 'nebula-center' fixture at the same
// position. Nothing anchored the space; the name test answered "purple fog
// and stars" — the exact failure state the brief forbids.
//
// This body's one idea: **a gallery suspended inside a living deep field.**
// The visitor should feel that something immense is moving around them while
// every artwork stays immediately readable. Identity comes from COMPOSITION
// (layers, band, silhouettes, pools) — never from bloom or saturation:
//
//   FAR   the galactic band — one immense ARCH across the upper sky (a
//         steeply tilted great circle, sprite azimuths biased around the
//         arch crown) in two shells: huge/faint, closer/denser; the arch
//         precesses around the vertical at two tiny rates (same sense →
//         coherent band, layered shear); a dense stratum of band-weighted
//         stars rides the same frame;
//   FAR   colossal dark monolith silhouettes occluding the band glow —
//         the scale-ambiguity cue (§8): enormous, unmeasurable, still;
//   MID   an all-sky two-strata NEUTRAL starfield (white → blue-white,
//         rare warm/cyan/rose tints — never all-violet again);
//   NEAR  the stardrift current — per-mote shader drift (the void_dust
//         GLSL precedent) streaming along ONE seeded direction, each mote
//         dissolving in and out over its journey (no wrap-pop, no snow);
//   NEAR  a pool of cool light on the floor beneath every floating artwork
//         (post-placement hook — the exhibition read as a constellation);
//   ABOVE one meridian ring — the arrival threshold anchor that frames the
//         spawn view and hands the eye an "up".
//
// COLOUR HIERARCHY (§11): declared deep-indigo atmosphere (background/fog)
// → dominant indigo-violet + secondary cool-blue nebular masses (visual_config.nebula,
// venue-owned) → ONE rare rose accent sprite (the band's core). Stars are
// neutral. The rig is neutral moon-slate; the ONLY warm light remains the
// pooled artwork lighting (§12 honesty — the v1.0.0 purple ambient tinted
// every lit canvas).
//
// DRIFT (§9): the shells precess (≈ 0.2°/s, opposite senses → parallax),
// the current streams (≈ 0.15 m/s), the hang floats. No bobbing whole-cloud
// sine, no screensaver. Reduced-motion + low-end: all motion rests, the
// composition alone carries the identity (§35).
//
// PERF (§21): ≈ 18 draws / < 20k tris for the whole body (10 sprite draws,
// 3 star draws, 1 instanced monolith draw, 1 current draw, ring + halo) —
// the sky is quads and points, the depth is composition, not geometry. With
// environment 'none' the night.hdr download disappears entirely.
// Deterministic: every placement and texture blob comes from the venue's
// seeded rng in a fixed call order; drift is a pure function of time.
function addNebulaDeepfield(radius) {
    const vc  = this._venueVisualConfig || {};
    const rng = this._venueRng;
    const pal = (vc.nebula && typeof vc.nebula === 'object') ? vc.nebula : {};
    const DOMINANT  = parseColor(pal.dominant)  || new THREE.Color(0x5a4ae0);
    const SECONDARY = parseColor(pal.secondary) || new THREE.Color(0x2e6ac8);
    const ACCENT    = parseColor(pal.accent)    || new THREE.Color(0xd85a9e);

    const isLowEnd   = !!this.isLowEnd;
    const isMobile   = !!this._isMobileTier;
    const starBudget = isLowEnd ? 0.55 : (isMobile ? 0.75 : 1.0);

    this._particleSystems = this._particleSystems || [];

    // ── 1. The galactic band — one immense arch across the upper sky ──────
    // A galactic band is a great circle: it must ARCH overhead and descend
    // toward the horizon. The v2.0.0 band sat 22–27° from horizontal, so
    // every mass hugged the horizon plane — the overhead sky stayed empty
    // (the cam-up evidence frame proved it) and the deployed venue read as
    // "Infinite Void in blue". Tilt is now ≈ 55–62° and sprite azimuths are
    // TRIANGULAR-BIASED around the arch crown (see bandSprite), so the
    // masses compose one immense glowing arch over the exhibition (§10: a
    // large-scale formation with silhouette and scale, never wallpaper).
    //
    // Precession: the drift-rotate handler increments rotation.y, so driving
    // it on the TILT group yaws the whole arch around the visitor at
    // constant world heights — the sky wheels like a slow celestial
    // structure (§9: the atmosphere moves, the exhibition rests). The
    // v2.0.0 inner spin rotated masses THROUGH the floor plane, where the
    // opaque floor fade clipped them; the arch never sinks now, and the
    // two shells run at slightly different rates in the SAME sense so the
    // band stays one coherent structure whose layers shear by ≈ 8°/3 min.
    const bandTilt = 0.96 + rng.next() * 0.12;  // ≈ 55–62° from horizontal
    const bandYaw  = rng.next() * Math.PI * 2;  // band azimuth, seeded per venue

    // World azimuth of the arch crown (band-local azimuth π — the tilted
    // ring's highest point). Monolith silhouettes are seeded against it so
    // the band glow passes directly behind them (computed through the same
    // Euler transform the scene graph applies — exact parity).
    const archCrownAz = (() => {
        const v = new THREE.Vector3(0, 0, -1).applyEuler(new THREE.Euler(bandTilt, bandYaw, 0));
        return Math.atan2(v.x, v.z);
    })();

    const makeShell = (defs, dist, speed) => {
        const tilt = new THREE.Group();
        tilt.rotation.set(bandTilt, bandYaw, 0);
        for (const d of defs) {
            // Textures are cached per (colour × resolution) and shared — the
            // masses differ through SCALE, SEEDED SCREEN ROTATION and layer
            // opacity, so 5 canvases serve the whole band (~1.8 MB instead
            // of ~10 MB on the GPU).
            const tex = getMassTexture(d.color, d.size > dist * 0.6 ? 512 : 256);
            const mat = new THREE.SpriteMaterial({
                map: tex,
                rotation: (rng.next() - 0.5) * Math.PI * 2, // no two masses read identical
                transparent: true,
                // Layer control. (The v1 first pass multiplied THIS by the
                // texture's own 0.05–0.14 blob alpha → the whole band
                // rendered at ~2% and read as bare black. The texture now
                // carries real body — see makeNebulaMassTexture — and the
                // material opacity is the only dimmer. The v2.0.0 opacities
                // were STILL sub-threshold at production distances — the
                // deployed frame's band read as a faint haze while only the
                // saturated rose accent punched through. Lifted 20–35%
                // across the board: readable at a glance, still far from
                // screensaver territory.)
                opacity: d.opacity,
                blending: THREE.AdditiveBlending,
                depthWrite: false,
                fog: false, // the band IS the sky — scene fog must not eat it
            });
            const s = new THREE.Sprite(mat);
            s.position.set(d.x, d.y, d.z);
            s.scale.setScalar(d.size);
            s.renderOrder = -9;
            tilt.add(s);
        }
        this.scene.add(tilt);
        // The tilt group itself precesses: the arch wheels around the
        // visitor; its world heights never change, so nothing ever sinks
        // into the floor plane.
        this._particleSystems.push({ obj: tilt, type: 'drift-rotate', speed });
        return tilt;
    };

    // Lazy texture cache — created in fixed call order, so the seeded rng
    // stream stays deterministic per build.
    const massTexCache = new Map();
    const getMassTexture = (color, size) => {
        const key = `${color.getHexString()}:${size}`;
        if (!massTexCache.has(key)) massTexCache.set(key, makeNebulaMassTexture(rng, color, size));
        return massTexCache.get(key);
    };

    // Sprite placement on the band plane: azimuth TRIANGULAR-BIASED around
    // the arch crown (see below), band-local height seeded (the band is a
    // BAND, not a sphere). Sizes are fractions of the venue radius so the
    // composition scales from 5-work salons to 40-work halls.
    // v2.2.0: also returns the band-local azimuth `a` — the luminosity
    // profile (crownWeight) needs each mass's angular distance from the
    // crown, and the masses' own opacity must be a pure function of it.
    const bandSprite = (shellR, sizeF, hF, span) => {
        // Triangular distribution centred on the crown (band-local azimuth
        // π): dense at the top of the arch, thinning toward its ends. The
        // v2.0.0 uniform ring put half of every mass on the far side of the
        // sky (and below the horizon plane) — the arch concentrates the
        // composition instead. `span` caps how far from the crown a mass may
        // sit: features stay ≤ 1.45 (centres never meaningfully sink below
        // the horizon plane), haze runs wider so the arch grows soft skirts.
        const a = Math.PI + (rng.next() + rng.next() - 1) * span;
        const h = (rng.next() - 0.5) * 2 * hF * shellR;
        return {
            x: Math.sin(a) * shellR, y: h, z: Math.cos(a) * shellR,
            size: shellR * sizeF * (0.8 + rng.next() * 0.4),
            a,
        };
    };

    // v2.2.0 K1 — THE ARCH HAS A LUMINOSITY PROFILE. The v2.1.0 frame's
    // residual failure: the band read as one soft corner GLOW PATCH while
    // the rest of the sky stayed black — every mass rendered at the same
    // mid opacity, so nothing in the sky had hierarchy and the eye found
    // no “core”. Real galactic structure is brightest at its centre and
    // thins toward the limbs. The crown region (band-local azimuth ≈ π)
    // now renders up to ×1.15 the mass base opacity; the arch ends never
    // fall below ×0.7 (they must still read as THE SAME structure, not
    // detach into scattered fog). Pure function of the seeded azimuth —
    // determinism contract intact.
    const crownWeight = (a, span) => {
        const t = Math.min(1, Math.abs(Math.PI - a) / Math.max(0.001, span));
        return 0.7 + 0.45 * (0.5 + 0.5 * Math.cos(t * Math.PI));
    };

    // The band backbone — the arch's soft body between the feature masses;
    // alternating palette for tonal variety.
    const bandHaze = (shellR, count, sizeF, hF, opMin, opMax, colA, colB, rngSrc, span) => {
        const defs = [];
        for (let j = 0; j < count; j++) {
            const a  = Math.PI + (rngSrc.next() + rngSrc.next() - 1) * span;
            const h  = (rngSrc.next() - 0.5) * 2 * hF * shellR;
            defs.push({
                x: Math.sin(a) * shellR, y: h, z: Math.cos(a) * shellR,
                size: shellR * sizeF * (0.85 + rngSrc.next() * 0.3),
                color: (j % 2 === 0) ? colA : colB,
                opacity: opMin + rngSrc.next() * (opMax - opMin),
            });
        }
        return defs;
    };

    // v2.2.0 K1/K2 — feature opacities lifted ~30% over v2.1.0 (which was
    // still sub-perceptual at spawn distance) and the backbone haze lifted
    // +2 puffs and +0.1 opacity so the arch reads as ONE continuous river
    // of luminosity rather than scattered blobs on black. The crown-weight
    // profile multiplies each feature's base — hierarchy without hue drift.
    //
    // K1b — DEEP MASSES. Additive blending can only ADD light, so tonal
    // structure cannot come from darkening — it comes from CONTRAST. The
    // v2.1.0 crown read as one uniform blue glow ball: bright sprites
    // stacked into a soft field with nothing dimmer between them. These
    // half-luminance indigo bodies set BETWEEN the bright features read as
    // the darker nebular material real deep-field photographs show — the
    // mottled depth that separates "nebula" from "blue fog" (§1 density
    // variation), at zero extra light in the sky.
    const farR = radius * 3.1;
    const DEEP = DOMINANT.clone().multiplyScalar(0.45);
    const feature = (shellR, sizeF, hF, span, base, color) => {
        const d = bandSprite(shellR, sizeF, hF, span);
        return { ...d, color, opacity: Math.min(0.92, base * crownWeight(d.a, span)) };
    };
    const deepMass = (shellR, sizeF, hF, span, base) => {
        const d = bandSprite(shellR, sizeF, hF, span);
        return { ...d, color: DEEP, opacity: base }; // no crown weight — deep bodies stay dim everywhere
    };
    const farTilt = makeShell(
        [
            feature(farR, 1.9, 0.2,  1.3,  0.8,  DOMINANT),
            deepMass(farR, 1.35, 0.22, 1.5, 0.5),
            feature(farR, 1.6, 0.2,  1.38, 0.72, DOMINANT),
            feature(farR, 1.5, 0.22, 1.42, 0.66, SECONDARY),
            deepMass(farR, 1.2,  0.24, 1.6, 0.45),
            feature(farR, 1.4, 0.22, 1.45, 0.6,  SECONDARY),
            // The band backbone: seeded haze (triangular around the crown —
            // the arch's soft body between the feature masses, with wide
            // skirts so the arch ends dissolve toward the horizon instead of
            // stopping). Low-end skips it — large additive sprites are
            // fill-rate that tier does not have; the feature masses carry
            // the identity alone.
            // v2.2.0: skirts widened to 2.8 rad — the arch's haze reaches
            // further around the horizon so a level view on the far side
            // from the crown still catches the band's glow (the crown
            // azimuth is seeded; the STRUCTURE must not be invisible from
            // any arrival direction).
            ...(isLowEnd ? [] : bandHaze(farR, 16, 1.15, 0.24, 0.3, 0.42, DOMINANT, SECONDARY, rng, 2.8)),
        ],
        farR, 0.0034,
    );

    const midR = radius * 2.15;
    makeShell(
        [
            feature(midR, 1.05, 0.2,  1.3,  0.85, DOMINANT),
            deepMass(midR, 0.95, 0.22, 1.55, 0.42),
            feature(midR, 0.9,  0.2,  1.36, 0.76, DOMINANT),
            feature(midR, 0.8,  0.22, 1.4,  0.68, SECONDARY),
            deepMass(midR, 0.8,  0.24, 1.6,  0.38),
            feature(midR, 0.7,  0.22, 1.42, 0.62, SECONDARY),
            feature(midR, 0.6,  0.24, 1.45, 0.56, DOMINANT),
            // THE rare warm accent — the band's core, one sprite in the
            // venue, seeded INTO the arch near its crown (the v2.0.0 accent
            // drew a uniform azimuth and could orphan itself on the bare
            // side of the sky, detached from the structure it belongs to).
            // v2.2.0: opacity 0.58 → 0.7 — the neighbouring features got
            // brighter around it (K1); the accent must stay the ONE warm
            // note the eye finds in the core, not a casualty of it.
            { ...bandSprite(midR, 0.42, 0.12, 0.55), color: ACCENT,    opacity: 0.7  },
            ...(isLowEnd ? [] : bandHaze(midR, 12, 0.62, 0.2, 0.28, 0.4, DOMINANT, SECONDARY, rng, 2.7)),
        ],
        midR, 0.0042, // slightly faster → layered parallax against the far shell
    );

    // v2.2.0 K3 — THE NEAR VEIL. The v2.1.0 sky had depth on PAPER (two
    // shells) but read flat: both shells sit far outside the exhibition, so
    // nothing in the atmosphere answered the visitor's own motion. Three or
    // four huge, very soft masses drift INSIDE the venue's sky (R×1.55, high
    // above the hang) at the fastest precession rate — the nearest layer
    // shows the largest apparent travel, so walking now shears the sky in
    // true parallax: far arch behind, mid band behind that, the veil passing
    // overhead. Opacity stays whisper-low (0.09–0.14): the veil is felt
    // before it is seen — atmosphere you stand IN, never wallpaper (§12).
    // Low-end skips it (fill rate), like the haze backbone.
    if (!isLowEnd) {
        const veilR = radius * 1.55;
        const veilDefs = [];
        for (let i = 0; i < 4; i++) {
            const a = Math.PI + (rng.next() + rng.next() - 1) * 1.1;   // crown-biased
            const h = (0.35 + rng.next() * 0.5) * veilR;               // high band-local → overhead after tilt
            veilDefs.push({
                x: Math.sin(a) * veilR, y: h, z: Math.cos(a) * veilR,
                size: radius * (0.5 + rng.next() * 0.28),
                color: (i % 2 === 0) ? DOMINANT : SECONDARY,
                opacity: 0.09 + rng.next() * 0.05,
            });
        }
        makeShell(veilDefs, veilR, 0.0052);
    }

    // ── 2. Stars — two strata, NEUTRAL (the v1.0.0 sky was 100% violet) ───
    // Palette: ~78% white-blue, ~12% faint warm, ~6% cyan, ~4% rose — a sky,
    // not a screensaver. Distribution seeded; the band stratum (below) rides
    // the band frame so the Milky Way and the masses belong together.
    const makeStars = (count, rMin, rMax, size, opacity, yClamp) => {
        const n = Math.max(8, Math.round(count * starBudget));
        const pos = new Float32Array(n * 3);
        const col = new Float32Array(n * 3);
        const c = new THREE.Color();
        for (let i = 0; i < n; i++) {
            const theta = rng.next() * Math.PI * 2;
            const phi   = Math.acos(rng.next() * 2 - 1);
            const r     = rMin + rng.next() * (rMax - rMin);
            let y = r * Math.cos(phi);
            // Keep the all-sky strata out of the floor plane (the floor, the
            // pools and the hang own the first metres of the composition).
            if (yClamp != null && y < yClamp && y > -(yClamp)) {
                y = Math.sign(y || 1) * yClamp;
            }
            pos[i * 3]     = r * Math.sin(phi) * Math.cos(theta);
            pos[i * 3 + 1] = y;
            pos[i * 3 + 2] = r * Math.sin(phi) * Math.sin(theta);
            const t = rng.next();
            if (t < 0.78)      c.setHSL(0.58 + rng.next() * 0.08, 0.04 + rng.next() * 0.18, 0.72 + rng.next() * 0.24); // white-blue
            else if (t < 0.90) c.setHSL(0.07 + rng.next() * 0.06, 0.28 + rng.next() * 0.22, 0.78 + rng.next() * 0.14); // faint warm
            else if (t < 0.96) c.setHSL(0.52, 0.35, 0.8);                                                              // cyan tint
            else               c.setHSL(0.93, 0.30, 0.78);                                                             // rose tint
            col[i * 3] = c.r; col[i * 3 + 1] = c.g; col[i * 3 + 2] = c.b;
        }
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
        geo.setAttribute('color',    new THREE.BufferAttribute(col, 3));
        const mat = new THREE.PointsMaterial({
            size, vertexColors: true, transparent: true, opacity,
            sizeAttenuation: true, depthWrite: false,
            map: makeStarTexture(),   // round soft points — square gl_Points read as debris
            fog: false, // sky, not scene depth (the §4.7 exemption, kept)
        });
        const pts = new THREE.Points(geo, mat);
        pts.frustumCulled = false;
        pts.renderOrder = -8;
        return pts;
    };

    this.scene.add(makeStars(700, radius * 4.6, radius * 6.4, 0.34, 0.8, radius * 0.12)); // far fine field
    if (!isLowEnd) {
        this.scene.add(makeStars(130, radius * 2.6, radius * 3.5, 0.62, 0.95, radius * 0.15)); // near bright anchors
    }

    // Band stars — a denser stratum flattened along the band plane, nested
    // in the far shell's frame so the Milky Way and the masses precess as
    // ONE sky (no relative drift between the strata). Azimuths ride the same
    // arch bias — the v2.0.0 stratum ringed the full circle while the masses
    // arched, so the star band and the glow band disagreed about where the
    // galaxy is.
    {
        const n = Math.max(8, Math.round(260 * starBudget));
        const pos = new Float32Array(n * 3);
        const col = new Float32Array(n * 3);
        const c = new THREE.Color();
        for (let i = 0; i < n; i++) {
            const a = Math.PI + (rng.next() + rng.next() - 1) * 2.2;
            const r = farR * (0.9 + rng.next() * 0.35);
            const h = (rng.next() + rng.next() - 1) * farR * 0.24; // soft band thickness
            pos[i * 3]     = Math.sin(a) * r;
            pos[i * 3 + 1] = h;
            pos[i * 3 + 2] = Math.cos(a) * r;
            c.setHSL(0.58 + rng.next() * 0.08, 0.05 + rng.next() * 0.15, 0.68 + rng.next() * 0.28);
            col[i * 3] = c.r; col[i * 3 + 1] = c.g; col[i * 3 + 2] = c.b;
        }
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
        geo.setAttribute('color',    new THREE.BufferAttribute(col, 3));
        const mat = new THREE.PointsMaterial({
            size: 0.3, vertexColors: true, transparent: true, opacity: 0.75,
            sizeAttenuation: true, depthWrite: false, fog: false,
            map: makeStarTexture(),   // round soft points
        });
        const pts = new THREE.Points(geo, mat);
        pts.frustumCulled = false;
        pts.renderOrder = -8;
        farTilt.add(pts); // rides the band's precession — one sky, one motion
    }

    // ── 3. Monolith silhouettes — scale ambiguity (§8) ────────────────────
    // 3–4 enormous dark shards standing off beyond the field, catching just
    // enough of the cold key to hold an edge against the band glow. Opaque +
    // fog-exempt: they occlude the additive sky and read as silhouettes at
    // unmeasurable distances. The first two are seeded against the ARCH
    // CROWN so the glow passes directly behind them — the v2.0.0 monoliths
    // drew uniform azimuths in a sky whose glow sat wherever it pleased,
    // and on the deployed frame they silhouetted against nothing. They do
    // NOT precess — the colossal things are still; the sky wheels around
    // them (§9: the drift belongs to the atmosphere, not to architecture).
    {
        const count = isLowEnd ? 3 : 4;
        const geo   = new THREE.OctahedronGeometry(1, 0);
        // v2.2.0 K4: 0x0d0a22 → 0x141032. The v2.1.0 monoliths were invisible
        // in every captured pose — a silhouette needs something to silhouette
        // AGAINST, and at 0x0d0a22 the cold key could not model even their
        // facing facets. The lifted base still reads as a dark colossal form
        // (the band glow passes behind it), but its faceted planes now catch
        // the key light and the form exists instead of theorising.
        const mat   = isLowEnd
            ? new THREE.MeshLambertMaterial({ color: 0x141032, fog: false })
            : new THREE.MeshStandardMaterial({
                color: 0x141032, roughness: 0.9, metalness: 0.0,
                flatShading: true, fog: false,
            });
        const inst = new THREE.InstancedMesh(geo, mat, count);
        const m = new THREE.Matrix4();
        const q = new THREE.Quaternion();
        const e = new THREE.Euler();
        const p = new THREE.Vector3();
        const s = new THREE.Vector3();
        for (let i = 0; i < count; i++) {
            // i 0/1: arch-tied (seeded offsets around the crown azimuth);
            // i ≥ 2: wide spread. The seeded rng stream order is fixed, so
            // every build is identical for the same seed.
            const a = i < 2
                ? archCrownAz + (rng.next() - 0.5) * 1.8
                : rng.next() * Math.PI * 2;
            const d = radius * (2.9 + rng.next() * 1.3);
            const h = d * ((i < 2 ? 0.44 : 0.38) + rng.next() * 0.22);
            e.set((rng.next() - 0.5) * 0.16, rng.next() * Math.PI * 2, (rng.next() - 0.5) * 0.16);
            q.setFromEuler(e);
            p.set(Math.sin(a) * d, d * (0.14 + rng.next() * 0.3), Math.cos(a) * d);
            s.set(h * (0.11 + rng.next() * 0.05), h, h * (0.11 + rng.next() * 0.05));
            m.compose(p, q, s);
            inst.setMatrixAt(i, m);
        }
        inst.instanceMatrix.needsUpdate = true;
        inst.frustumCulled = false;
        this.scene.add(inst);
    }

    // ── 4. The stardrift current — §9's "drift", made literal ─────────────
    // Per-mote shader drift along ONE seeded direction. Each mote fades in,
    // crosses the exhibition volume at walking-pace-slow speed, and fades
    // out before re-entering — a current, not snow (the void_dust GLSL
    // precedent: zero per-frame CPU, zero allocations, one draw call).
    {
        const COUNT = isLowEnd ? 260 : (isMobile ? 360 : 480);
        const ySpan = Math.min(9, radius * 0.42 + 2);
        const dirA  = rng.next() * Math.PI * 2; // the current's heading, seeded per venue
        const pos = new Float32Array(COUNT * 3);
        const ph  = new Float32Array(COUNT);
        const sz  = new Float32Array(COUNT);
        const sp  = new Float32Array(COUNT);
        for (let i = 0; i < COUNT; i++) {
            const a = rng.next() * Math.PI * 2;
            const r = Math.sqrt(rng.next()) * radius * 1.15;
            pos[i * 3]     = Math.cos(a) * r;
            pos[i * 3 + 1] = 0.15 + rng.next() * ySpan;
            pos[i * 3 + 2] = Math.sin(a) * r;
            ph[i] = rng.next();
            sz[i] = 0.5 + rng.next() * 0.9;
            sp[i] = 0.6 + rng.next() * 0.8;
        }
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
        geo.setAttribute('aPhase',   new THREE.BufferAttribute(ph, 1));
        geo.setAttribute('aSize',    new THREE.BufferAttribute(sz, 1));
        geo.setAttribute('aSpeed',   new THREE.BufferAttribute(sp, 1));

        let mat;
        if (isLowEnd) {
            mat = new THREE.PointsMaterial({
                color: 0xbac8f0, size: 0.05, transparent: true, opacity: 0.42,
                sizeAttenuation: true, depthWrite: false,
            });
        } else {
            mat = new THREE.ShaderMaterial({
                transparent: true,
                depthWrite: false,
                fog: false, // the current IS atmosphere
                uniforms: {
                    uTime:   { value: 0 },
                    uDir:    { value: new THREE.Vector2(Math.sin(dirA), Math.cos(dirA)) },
                    uSpan:   { value: radius * 2.2 },
                    uColor:  { value: new THREE.Color(0xbac8f0) },
                    uOpacity:{ value: 0.4 },
                    uBaseSize: { value: 0.05 },
                },
                vertexShader: /* glsl */`
                    attribute float aPhase;
                    attribute float aSize;
                    attribute float aSpeed;
                    uniform float uTime;
                    uniform vec2  uDir;
                    uniform float uSpan;
                    uniform float uBaseSize;
                    varying float vFade;
                    void main() {
                        vec3 p = position;
                        // The journey: each mote progresses through [0,1) over
                        // ~2 minutes, crossing the span once, fading in/out at
                        // both ends so the wrap never pops.
                        float prog = fract(uTime * 0.008 * aSpeed + aPhase);
                        float traveled = (prog - 0.5) * uSpan;
                        p.x += uDir.x * traveled;
                        p.z += uDir.y * traveled;
                        // Centimetre-scale breath so no mote ever looks pinned.
                        p.y += sin(uTime * 0.2 + aPhase * 40.0) * 0.25;
                        vec4 mv = modelViewMatrix * vec4(p, 1.0);
                        float d = -mv.z;
                        vFade = smoothstep(0.6, 2.0, d) * (1.0 - smoothstep(16.0, 30.0, d));
                        vFade *= sin(3.14159265 * prog); // dissolve at both ends
                        gl_PointSize = aSize * uBaseSize * (240.0 / max(d, 0.001));
                        gl_Position = projectionMatrix * mv;
                    }
                `,
                fragmentShader: /* glsl */`
                    uniform vec3  uColor;
                    uniform float uOpacity;
                    varying float vFade;
                    void main() {
                        vec2 uv = gl_PointCoord - 0.5;
                        float a = 1.0 - smoothstep(0.18, 0.5, length(uv));
                        gl_FragColor = vec4(uColor, uOpacity * a * vFade);
                    }
                `,
            });
        }
        const pts = new THREE.Points(geo, mat);
        pts.frustumCulled = false;
        this.scene.add(pts);
        // GalleryScene advances uTime for the 'void-drift' type — the current
        // reuses it verbatim (one uniform write per frame, no allocations).
        this._particleSystems.push({ obj: pts, type: 'void-drift', phase: 0 });
    }

    // ── 5. The meridian ring — the arrival threshold anchor (§33) ────────
    // One luminous ring overhead, tilted off-axis just enough to feel found
    // rather than installed. The v2.0.0 ring shipped as a uniform torus at
    // 0.62 opacity — simultaneously the LOUDEST element of the deployed
    // frame and the least nebular: a debug wireframe where the atmosphere
    // should breathe. The geometry stays; the surface becomes LIGHT:
    //   • per-vertex LUMINOSITY — seeded bright and dim arcs (smooth cosine
    //     bumps along the ring path): most of the ring sits just under the
    //     declared bloom threshold, 2–3 bright arcs cross it so the existing
    //     post-fx draws their halos for free (no new glow assets), and one
    //     dim reach partially disappears into the dark;
    //   • ONE additive halo torus behind it — soft base falloff, no neon;
    //   • an almost-imperceptible yaw drift (bright arcs travel the ring
    //     over ~17 minutes — the heart of the drift, §9).
    // Cool white-blue throughout — the rose accent stays reserved for the
    // band core (one accent, once).
    {
        const ringR = radius * 0.62 + 2;
        const ringGeo = new THREE.TorusGeometry(ringR, 0.055, 8, 160);
        {
            // Seeded luminosity arcs — the rng draws happen in fixed order
            // before the per-vertex loop (which is pure), so every build is
            // identical for the same seed.
            const arcs = [];
            // v2.2.0 K6 — the v2.1.0 ring was the LOUDEST object in the sky
            // and still read as a bare luminous hoop: base 0.6 kept the whole
            // circle clearly visible (a wireframe), and the wide bright arcs
            // stacked on top. The demotion: the circle itself drops to a
            // whisper (base 0.3 — found, not installed), and the arcs become
            // NARROW BEADS of light — hot (they still cross the bloom
            // threshold, so the existing post-fx breathes over them) but
            // tight, reading as nodes of travelling light on a faint thread.
            const brightCount = 2 + Math.round(rng.next()); // 2–3 bright arcs
            for (let i = 0; i < brightCount; i++) {
                arcs.push({ c: rng.next(), w: 0.035 + rng.next() * 0.035, a: 1.1 + rng.next() * 0.25 });
            }
            arcs.push({ c: rng.next(), w: 0.1 + rng.next() * 0.08, a: -0.2 - rng.next() * 0.08 }); // the dim reach
            const base = 0.3 + rng.next() * 0.05;
            const pos = ringGeo.attributes.position;
            const lum = new Float32Array(pos.count * 3);
            for (let i = 0; i < pos.count; i++) {
                // TorusGeometry lies in the XY plane: the angle around the
                // ring comes straight off (x, y). Per-vertex lookup keeps
                // this independent of the internal vertex ordering.
                const u = Math.atan2(pos.getY(i), pos.getX(i)) / (Math.PI * 2) + 0.5;
                let L = base;
                for (const arc of arcs) {
                    let d = Math.abs(u - arc.c);
                    d = Math.min(d, 1 - d); // wrap
                    L += arc.a * Math.max(0, 1 - (d / arc.w) * (d / arc.w));
                }
                L = Math.min(1.8, L);
                lum[i * 3] = L; lum[i * 3 + 1] = L; lum[i * 3 + 2] = L;
            }
            ringGeo.setAttribute('color', new THREE.BufferAttribute(lum, 3));
        }
        const ring = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({
            color: 0xcdd6f2, vertexColors: true,
            transparent: true, opacity: 0.55, // v2.2.0 K6: 0.85 → 0.55 — thread, not hoop
            blending: THREE.AdditiveBlending, depthWrite: false,
            fog: false, // luminous class (the cathedral seam precedent)
        }));
        const rig = new THREE.Group(); // ring + halo yaw together (§9 drift)
        ring.position.set(0, ringR * 1.32 + 1.6, 0);
        ring.rotation.set(0.21 + (rng.next() - 0.5) * 0.06, rng.next() * Math.PI * 2, 0.06);
        ring.frustumCulled = false;
        ring.renderOrder = -7;
        rig.add(ring);

        // The soft base halo — one extra draw, no glow texture (the wide
        // tube at 0.05 additive reads as falloff; the vertex arcs stay the
        // only structure).
        // v2.2.0 K7: the halo gathers ATMOSPHERE around the thread — tinted
        // toward the nebula family (the ring lives in that sky) and doubled
        // in presence so the whisper-base ring still reads as an object.
        const halo = new THREE.Mesh(
            new THREE.TorusGeometry(ringR, 0.055 * 4.5, 8, 96),
            new THREE.MeshBasicMaterial({
                color: 0x4a4a9c, transparent: true, opacity: 0.1,
                blending: THREE.AdditiveBlending, depthWrite: false, fog: false,
            }),
        );
        halo.position.copy(ring.position);
        halo.rotation.copy(ring.rotation);
        halo.frustumCulled = false;
        halo.renderOrder = -7;
        rig.add(halo);

        this.scene.add(rig);
        this._particleSystems.push({ obj: rig, type: 'drift-rotate', speed: 0.006 });
    }
}

// ── Nebula mass texture — seeded procedural blob canvas ─────────────────────
// A soft cluster of radial-gradient blobs in one hue family. 512² for the
// huge far masses, 256² for the mid shell; no mip generation surprises (the
// default mip chain handles minification). Deterministic: every blob draws
// from the venue's seeded rng in call order.
function makeNebulaMassTexture(rng, color, size = 512) {
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    const base = (color && isColorLike(color)) ? color : new THREE.Color(0x5a4ae0);
    const css = (c, a) => `rgba(${Math.round(c.r * 255)},${Math.round(c.g * 255)},${Math.round(c.b * 255)},${a.toFixed(3)})`;
    // A broad BASE WASH first — every mass carries a soft body so no sprite
    // ever reads as a cluster of separate blobs. (The v1 first pass drew only
    // 0.05–0.14-alpha blobs; multiplied by the material's layer opacity the
    // whole band rendered at ~2% and the sky stayed black on every tier.
    // The v2.0.0 wash 0.34/0.20 was still too shy at production distances —
    // lifted to 0.50/0.28; the material opacity stays the only dimmer.)
    const wash = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size * 0.5);
    wash.addColorStop(0, css(base.clone().offsetHSL(0, -0.04, 0.05), 0.5));
    wash.addColorStop(0.55, css(base, 0.28));
    wash.addColorStop(1, css(base, 0));
    ctx.fillStyle = wash;
    ctx.fillRect(0, 0, size, size);
    // Outer wisps BEYOND the wash radius — they break the perfect circular
    // sprite silhouette (the deployed accent read as a lumpy billboard;
    // organic contours need overshoot, not a clean disc edge).
    const wisps = 5 + Math.round(rng.next() * 3); // 5–7
    for (let i = 0; i < wisps; i++) {
        const a   = rng.next() * Math.PI * 2;
        const rr  = (0.38 + rng.next() * 0.22) * size * 0.5;
        const cx  = size / 2 + Math.cos(a) * rr;
        const cy  = size / 2 + Math.sin(a) * rr;
        const rad = (0.16 + rng.next() * 0.16) * size;
        const c   = base.clone().offsetHSL((rng.next() - 0.5) * 0.03, 0, 0.02);
        const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, rad);
        grad.addColorStop(0, css(c, 0.05 + rng.next() * 0.05));
        grad.addColorStop(1, css(c, 0));
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, size, size);
    }
    // Structure on top: a seeded cluster of brighter lobes and dark dust
    // lanes (the lanes punch holes so the mass reads as FORM, not fog).
    // Fewer, larger and softer than v2.0.0 — structure without lumps.
    const blobs = Math.round(size / 18); // 28 @512, 14 @256
    for (let i = 0; i < blobs; i++) {
        const a  = rng.next() * Math.PI * 2;
        const rr = Math.pow(rng.next(), 0.62) * 0.4; // concentrate toward the centre
        const cx = (0.5 + Math.cos(a) * rr) * size;
        const cy = (0.5 + Math.sin(a) * rr) * size;
        const rad = (0.08 + rng.next() * 0.18) * size;
        const lane = rng.next() < 0.2; // dark dust lane
        const c = base.clone().offsetHSL(
            (rng.next() - 0.5) * 0.03,
            lane ? -0.1 : (rng.next() - 0.5) * 0.08,
            lane ? -0.16 : (rng.next() - 0.5) * 0.09,
        );
        const alpha = lane ? 0.26 : 0.1 + rng.next() * 0.16;
        const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, rad);
        grad.addColorStop(0, css(c, alpha));
        grad.addColorStop(1, css(c, 0));
        ctx.fillStyle = lane ? 'destination-out' : grad;
        if (lane) {
            // Punch the lane out with its own gradient mask.
            ctx.globalCompositeOperation = 'destination-out';
            ctx.fillStyle = grad;
        }
        ctx.fillRect(0, 0, size, size);
        ctx.globalCompositeOperation = 'source-over';
    }
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    return tex;
}

// ── Star + pool sprite textures — one shared canvas per session ────────────
// THREE.PointsMaterial without a map renders SQUARE gl_Points — at star
// sizes they read as pixel debris. One 64² radial-gradient canvas gives every
// stratum a soft round falloff (the mote shader does the same in GLSL).
let _starTextureCache = null;
function makeStarTexture() {
    if (_starTextureCache) return _starTextureCache;
    const size = 64;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    const grad = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
    grad.addColorStop(0, 'rgba(255,255,255,1)');
    grad.addColorStop(0.3, 'rgba(255,255,255,0.85)');
    grad.addColorStop(0.65, 'rgba(255,255,255,0.18)');
    grad.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, size, size);
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    _starTextureCache = tex;
    return tex;
}

function isColorLike(v) {
    return v && typeof v === 'object' && typeof v.r === 'number';
}


// NEBULA DRIFT — cosmic depth, made coherent (Iteration 2 refinement)
// ────────────────────────────────────────────────────────────────────────────
// Verified defects fixed here (§4.7):
//   1. fog/starfield depth conflict — stars were generated at radius × 4–6
//      (40–90 m) while venue fog ended at fog_far (40 m). Fogged to 100%,
//      the entire starfield fought the same fog that gives the floor and
//      artworks their depth. With structure_pass declared, the STARFIELD is
//      fog-exempt (PointsMaterial.fog = false) — it is the sky, not scene
//      depth; the nebula cloud and floor keep the fog that reads as depth.
//   2. accidental night-HDRI horizon glow — the 'dramatic' preset's
//      night.hdr put a rural horizon in the reflections of a cosmic void.
//      The venue now declares env_intensity in config (AssetLoader honours
//      it), silencing the glow at its source — a config key, not a slug
//      branch.
//   3. easels under "drift" fiction — resolved by the venue's placement_mode
//      = 'float' (ArtworkPlacer), not in this file.
function addNebulaDriftStructure(radius) {
    // Fog exemption (Iteration 2 §4.7): the starfield is the sky, not scene
    // depth. Inside the 'phenomena' interpreter this is ALWAYS coherent —
    // the interpreter only runs when the venue declares the pass; the
    // legacy fogged-star body stays reachable via per-venue config revert.
    const coherent = true;

    // 1. Starfield — distant points in all directions
    // Iteration 0: starfield distribution + palette are seeded (was
    // Math.random)
    const rng = this._venueRng;
    const starCount = 800;
    const starPositions = new Float32Array(starCount * 3);
    const starColors    = new Float32Array(starCount * 3);
    for (let i = 0; i < starCount; i++) {
        // Spherical distribution far away
        const theta = rng.next() * Math.PI * 2;
        const phi   = Math.acos(rng.next() * 2 - 1);
        const r     = radius * 4 + rng.next() * radius * 2;
        starPositions[i * 3]     = r * Math.sin(phi) * Math.cos(theta);
        starPositions[i * 3 + 1] = r * Math.cos(phi);
        starPositions[i * 3 + 2] = r * Math.sin(phi) * Math.sin(theta);

        // Purple/blue/pink star colours
        const hue = 0.7 + rng.next() * 0.15; // 0.7-0.85
        const sat = 0.4 + rng.next() * 0.4;
        const lit = 0.5 + rng.next() * 0.4;
        const c = new THREE.Color().setHSL(hue, sat, lit);
        starColors[i * 3]     = c.r;
        starColors[i * 3 + 1] = c.g;
        starColors[i * 3 + 2] = c.b;
    }
    const starGeo = new THREE.BufferGeometry();
    starGeo.setAttribute('position', new THREE.BufferAttribute(starPositions, 3));
    starGeo.setAttribute('color',    new THREE.BufferAttribute(starColors, 3));
    const starMat = new THREE.PointsMaterial({
        size: 0.4,
        vertexColors: true,
        transparent: true,
        opacity: 0.9,
        sizeAttenuation: true,
        // Iteration 2: the sky is exempt from scene fog when the venue
        // declares the coherence pass (legacy path keeps fogged stars —
        // per-venue rollback intact).
        fog: !coherent,
    });
    this.scene.add(new THREE.Points(starGeo, starMat));

    // 2. Drifting nebula cloud — closer, coloured particles
    const nebCount = 400;
    const nebPositions = new Float32Array(nebCount * 3);
    for (let i = 0; i < nebCount; i++) {
        nebPositions[i * 3]     = (rng.next() - 0.5) * radius * 2;
        nebPositions[i * 3 + 1] = rng.next() * 6 - 1;
        nebPositions[i * 3 + 2] = (rng.next() - 0.5) * radius * 2;
    }
    const nebGeo = new THREE.BufferGeometry();
    nebGeo.setAttribute('position', new THREE.BufferAttribute(nebPositions, 3));
    const nebMat = new THREE.PointsMaterial({
        color: 0x8844ff,
        size: 0.15,
        transparent: true,
        opacity: 0.5,
        sizeAttenuation: true,
        blending: THREE.AdditiveBlending,
    });
    const nebPoints = new THREE.Points(nebGeo, nebMat);
    this.scene.add(nebPoints);

    this._particleSystems = this._particleSystems || [];
    this._particleSystems.push({ obj: nebPoints, type: 'drift', phase: rng.next() * Math.PI * 2 });

    // 3. Soft purple backlight
    const backLight = new THREE.PointLight(0x8844ff, 0.5, radius * 2);
    backLight.position.set(0, 5, 0);
    this.scene.add(backLight);
}

// MIRROR LAKE — the reflecting flagship (Iteration 2)
// ────────────────────────────────────────────────────────────────────────────
// The audit's single largest promise/delivery gap: named "Mirror Lake", the
// floor was `roughness: 0` — PBR materials do not reflect scene objects, so
// the lake reflected NOTHING (the old code comment even admitted it).
//
// Declared resolution (visual_config.floor_reflection = 'planar'), tiered by
// TierResolve (§11.3 row 1 — degradation designed, never emergent):
//
//   high tier  → 'planar' : a real THREE.Reflector replaces the floor disc.
//                Artworks hover in float mode, so the lake reflects the ART
//                and the moon. This is the Studio flagship's kept promise.
//   mobile     → 'gloss'  : designed dark-gloss mood — the venue's own
//                near-zero-roughness floor catches the moonlight's specular
//                streak (metalness softened so a PBR floor without an HDRI
//                environment doesn't go dead black), a light-streak plane
//                runs toward the moon, and the mist rises/densifies. An
//                intentional composition, not a missing feature.
//   low-end    → 'gloss'  : same mood on Lambert (the additive streak plane
//                reads without PBR).
//   undeclared → 'none'   : pre-pass behaviour (moon + mist, plain glossy
//                floor) — the per-venue rollback target.
//
// NAME DECISION GATE (§4.11): resolved — the reflector ships and survives
// review, so the venue KEEPS the name "Mirror Lake". Revisit only if the
// high-tier reflection is ever removed.
function addMirrorLakeStructure(radius) {
    const vc = this._venueVisualConfig || {};
    const mode = resolveReflectionMode({
        isLowEnd: !!this.isLowEnd,
        isMobileTier: !!this._isMobileTier,
        declared: vc.floor_reflection === 'planar',
    });

    // Moonlight + moon visual — the composition anchor, shared by every mode.
    const moon = new THREE.DirectionalLight(0xb0c8ff, 0.6);
    moon.position.set(radius * 0.8, radius * 1.5, -radius * 0.5);
    this.scene.add(moon);

    const moonMesh = new THREE.Mesh(
        new THREE.SphereGeometry(1.5, 16, 16),
        new THREE.MeshBasicMaterial({ color: 0xe0e8ff })
    );
    moonMesh.position.copy(moon.position);
    this.scene.add(moonMesh);

    // Reflection — declared + tiered (see table above).
    if (mode === 'planar') {
        // Real planar reflection. The built floor disc is hidden (kept for
        // session-level restore), the Reflector renders the scene's mirror.
        addPlanarReflection(this, radius, { color: 0xaab4c8, resolution: 1024 });
    } else if (mode === 'gloss') {
        // Dark-gloss mood, step 1: keep the venue floor but make it READ as
        // night water on the mobile tier — metalness softened from the
        // template's 1.0 (a mirror-metal with no environment renders dead
        // black) so the moonlight's specular streak lives on the surface.
        const floor = this._circularFloor;
        if (floor && floor.material && !this.isLowEnd) {
            floor.material.metalness = 0.65;
            floor.material.roughness = 0.1;
            floor.material.needsUpdate = true;
        }
        // Step 2: the moon's light-streak on the water.
        addMoonLightStreak(this, radius, moon.position.clone());
    }

    // Drifting mist particles (seeded — Iteration 0). In the gloss mood the
    // mist rises and densifies: the fallback's intentional signature.
    const gloss   = mode === 'gloss';
    const mistCount = gloss ? 220 : 150;
    const mistYBase = gloss ? 0.4 : 0.1;
    const mistYSpan = gloss ? 2.6 : 2.0;
    const rng = this._venueRng;
    const positions = new Float32Array(mistCount * 3);
    for (let i = 0; i < mistCount; i++) {
        positions[i * 3]     = (rng.next() - 0.5) * radius * 2;
        positions[i * 3 + 1] = mistYBase + rng.next() * mistYSpan;
        positions[i * 3 + 2] = (rng.next() - 0.5) * radius * 2;
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    const mat = new THREE.PointsMaterial({
        color: 0xc0d0ff,
        size: 0.4,
        transparent: true,
        opacity: 0.3,
        sizeAttenuation: true,
        blending: THREE.AdditiveBlending,
    });
    const mist = new THREE.Points(geo, mat);
    this.scene.add(mist);
    this._particleSystems = this._particleSystems || [];
    this._particleSystems.push({ obj: mist, type: 'drift', phase: rng.next() * Math.PI * 2 });
}

// ── MIRROR LAKE v3.0.0 — "The Still Shore" ──────────────────────────────────
// The flagship redesign. The v1 body above was a void-family room: one dark
// disc the visitor SPAWNED ON (the "lake" was the floor), a chrome-perfect
// Reflector, square mist sprites, a moon fogged to invisibility. The forensic
// audit (worklog, Mirror Lake Task 1) recorded the full defect set; the brief
// asked for a venue whose name, water, reflection, architecture and artwork
// presentation all say the same thing.
//
// The design — THE STILL SHORE — renders the pure LakeLayout plan:
//
//   THE LANDING    cut-stone arrival plaza on the southern shore (declared
//                  spawn): the first sightline crosses the water to the art
//                  arc, the far-shore treeline and the moon.
//   THE LAKE       ~60% of the field: a real water plane at y=−0.24 with a
//                  wet-stone edge, a dark depth tint, a two-train ripple and
//                  a living sheen (TierEffects.addWaterReflection) — still
//                  water, never a mirror tile (brief §5-§7).
//   THE ART WALK   a stone walk follows the shoreline; the artworks FLOAT
//                  over the water along an arc (the void heritage, kept),
//                  facing the walk — every piece doubled by its reflection.
//   THE PIER       a low timber pier runs east over the water to…
//   THE PAVILION   …a stilted viewing pavilion, the Destination, one warm
//                  lantern glow reflected in the lake.
//   THE HORIZON    the far shore rises north in the haze with a treeline
//                  (owner GLB assets via the garden's manifest system); the
//                  sky is a procedural night dome — the venue IS its own sky
//                  (PMREM environment, no HDRI leak — the v1 accidental
//                  rural_evening environment is gone).
//
// Determinism: the plan (and every extra draw below — stars, noise table,
// mist) comes from the venue's seeded rng in documented order.
//
// Tiers: high → water Reflector + PMREM + full composition; mobile → gloss
// water plane + streak (the designed fallback, retinted for water); low →
// Lambert water + streak, Lambert everywhere, static mist. Identity survives
// every tier — the shoreline, the pier, the pavilion and the art arc are
// geometry, not effects.
//
// Rollback: structure_pass 'phenomena' + void_lake renders the v1 body above,
// untouched (the dispatcher's else branch).

// Night-stone paving — cool dark flagstone, deterministic (tileable).
let _lakeStoneTex = null;
function makeLakeStoneTexture() {
    if (_lakeStoneTex) return _lakeStoneTex;
    const c = document.createElement('canvas');
    c.width = c.height = 256;
    const g = c.getContext('2d');
    const rng = _gardenTexRng('lake-stone-v3');
    g.fillStyle = '#8e9298';
    g.fillRect(0, 0, 256, 256);
    // irregular flagstones on a jittered grid, dark joints
    const cell = 64;
    for (let gy = 0; gy < 4; gy++) {
        for (let gx = 0; gx < 4; gx++) {
            const x0 = gx * cell + rng() * 6 - 3;
            const y0 = gy * cell + rng() * 6 - 3;
            const w = cell - 5 - rng() * 5;
            const h = cell - 5 - rng() * 5;
            const tone = 96 + Math.floor(rng() * 46);
            g.fillStyle = `rgb(${tone},${tone + 3},${tone + 8})`;
            g.fillRect(x0, y0, w, h);
            // per-stone mottle
            for (let i = 0; i < 26; i++) {
                const v = tone + (rng() - 0.5) * 34;
                g.fillStyle = `rgba(${v},${v + 3},${v + 8},${0.16 + rng() * 0.2})`;
                g.fillRect(x0 + rng() * w, y0 + rng() * h, 1 + rng() * 2.4, 1 + rng() * 2.4);
            }
        }
    }
    const tex = new THREE.CanvasTexture(c);
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    tex.colorSpace = THREE.SRGBColorSpace;
    _lakeStoneTex = tex;
    return tex;
}

// Wet-dark timber — pier decking + pavilion, warm heartwood under night light.
let _lakeTimberTex = null;
function makeLakeTimberTexture() {
    if (_lakeTimberTex) return _lakeTimberTex;
    const c = document.createElement('canvas');
    c.width = c.height = 256;
    const g = c.getContext('2d');
    const rng = _gardenTexRng('lake-timber-v3');
    g.fillStyle = '#6b543c';
    g.fillRect(0, 0, 256, 256);
    // planks along u (128px pitch), grain streaks + joint shadows
    for (let p = 0; p < 2; p++) {
        const y0 = p * 128;
        const tone = 92 + Math.floor(rng() * 26);
        g.fillStyle = `rgb(${Math.round(tone * 1.24)},${Math.round(tone * 0.98)},${Math.round(tone * 0.7)})`;
        g.fillRect(0, y0, 256, 124);
        for (let i = 0; i < 90; i++) {
            const v = tone + (rng() - 0.5) * 30;
            g.strokeStyle = `rgba(${Math.round(v * 1.2)},${Math.round(v * 0.96)},${Math.round(v * 0.68)},${0.10 + rng() * 0.16})`;
            g.lineWidth = 0.8 + rng() * 1.4;
            g.beginPath();
            const yy = y0 + 4 + rng() * 118;
            g.moveTo(0, yy);
            g.bezierCurveTo(64, yy + (rng() - 0.5) * 5, 190, yy + (rng() - 0.5) * 5, 256, yy);
            g.stroke();
        }
        g.fillStyle = 'rgba(14,11,8,0.85)';
        g.fillRect(0, y0 + 124, 256, 4);
    }
    const tex = new THREE.CanvasTexture(c);
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    tex.colorSpace = THREE.SRGBColorSpace;
    _lakeTimberTex = tex;
    return tex;
}

// Soft round sprite — the mist reads as vapour, never as the v1 squares.
let _mistSpriteTex = null;
function makeMistSpriteTexture() {
    if (_mistSpriteTex) return _mistSpriteTex;
    const c = document.createElement('canvas');
    c.width = c.height = 128;
    const g = c.getContext('2d');
    const grad = g.createRadialGradient(64, 64, 4, 64, 64, 62);
    grad.addColorStop(0, 'rgba(214,224,240,0.55)');
    grad.addColorStop(0.5, 'rgba(196,208,228,0.22)');
    grad.addColorStop(1, 'rgba(188,200,222,0)');
    g.fillStyle = grad;
    g.fillRect(0, 0, 128, 128);
    const tex = new THREE.CanvasTexture(c);
    tex.colorSpace = THREE.SRGBColorSpace;
    _mistSpriteTex = tex;
    return tex;
}

// Star sprite — a tight luminous core with a faint falloff. Without a map,
// PointsMaterial renders SQUARE points (the same defect class as the v1
// mist squares — caught in the first lake render QA).
let _starSpriteTex = null;
function makeStarSpriteTexture() {
    if (_starSpriteTex) return _starSpriteTex;
    const c = document.createElement('canvas');
    c.width = c.height = 32;
    const g = c.getContext('2d');
    const grad = g.createRadialGradient(16, 16, 1, 16, 16, 15);
    grad.addColorStop(0, 'rgba(238,242,252,1)');
    grad.addColorStop(0.35, 'rgba(214,224,244,0.55)');
    grad.addColorStop(1, 'rgba(200,212,236,0)');
    g.fillStyle = grad;
    g.fillRect(0, 0, 32, 32);
    const tex = new THREE.CanvasTexture(c);
    tex.colorSpace = THREE.SRGBColorSpace;
    _starSpriteTex = tex;
    return tex;
}

// Moon halo — one soft ring of scattered light.
let _moonHaloTex = null;
function makeMoonHaloTexture() {
    if (_moonHaloTex) return _moonHaloTex;
    const c = document.createElement('canvas');
    c.width = c.height = 256;
    const g = c.getContext('2d');
    const grad = g.createRadialGradient(128, 128, 30, 128, 128, 126);
    grad.addColorStop(0, 'rgba(226,236,252,0.5)');
    grad.addColorStop(0.35, 'rgba(206,220,246,0.16)');
    grad.addColorStop(1, 'rgba(196,212,240,0)');
    g.fillStyle = grad;
    g.fillRect(0, 0, 256, 256);
    const tex = new THREE.CanvasTexture(c);
    tex.colorSpace = THREE.SRGBColorSpace;
    _moonHaloTex = tex;
    return tex;
}

function addMirrorLakeShore(radius, data) {
    const vc = this._venueVisualConfig || {};
    const lakeCfg = vc.lake || {};
    const highFx = !this.isLowEnd && !this._isMobileTier;
    const low = !!this.isLowEnd;

    // ── 0. The plan (built in RoomBuilder before the structure — placement,
    // terrain, water and architecture share ONE plan). The fallback covers
    // pathological build orders; it draws from the same seeded stream.
    let plan = this._lakePlan;
    if (!plan) {
        this._venueRng = this._venueRng || createVenueRng(venueSeedSource(this._venueSlug || 'venue'));
        plan = this._lakePlan = buildLakePlan({
            radius,
            count: data?.imageCount || 1,
            rng: this._venueRng,
            config: lakeCfg,
        });
    }
    const R = plan.radius;
    const height = plan.terrain.height;
    const waterLevel = plan.waterLevel;

    // ── 1. Terrain — the flat disc becomes land + lakebed ───────────────
    // The plan's height field displaces the floor mesh in place (land y=0,
    // the bed dropping to −0.9 north of the waterline — the visible wet band
    // is the slope crossing the water plane).
    const floor = this._circularFloor;
    if (floor) {
        const landGeo = new THREE.RingGeometry(0.02, R, 128, 40);
        const lp = landGeo.attributes.position;
        for (let i = 0; i < lp.count; i++) {
            lp.setZ(i, height(lp.getX(i), -lp.getY(i)));
        }
        landGeo.computeVertexNormals();
        floor.geometry.dispose();
        floor.geometry = landGeo;
        // Night-earth detail: soft tonal patches multiplying the declared
        // floor_colour (same mechanism as the garden's lawn detail). No
        // darkening multiplier — the night light budget is spent honestly;
        // a mulched albedo reads as murk, not moonlight.
        if (!floor.material.map && typeof document !== 'undefined') {
            floor.material.map = makeLawnDetailTexture();
            floor.material.map.repeat.set(10, 10);
            floor.material.needsUpdate = true;
        }
        if (floor.material.roughness !== undefined) floor.material.roughness = 1;
        if (floor.material.metalness !== undefined) floor.material.metalness = 0;
    }

    // ── 2. The far shore + southern swell — the horizon ─────────────────
    // One skirt ring beyond the bound: north (and along both water arcs) it
    // rises from BELOW the water plane into a dark silhouette ridge that
    // carries the treeline; south it swells gently and sinks into the haze.
    // A seeded 16-stop noise table gives the ridge its natural cadence.
    const noiseT = [];
    for (let i = 0; i < 16; i++) noiseT.push(this._venueRng.next());
    const nAt = (th) => {
        const u = (((th / (Math.PI * 2)) % 1) + 1) % 1 * 16;
        const i = Math.floor(u), f = u - i;
        return noiseT[i % 16] * (1 - f) + noiseT[(i + 1) % 16] * f;
    };
    const clamp01 = (v) => Math.min(1, Math.max(0, v));
    const sstep = (e0, e1, x) => {
        const t = clamp01((x - e0) / ((e1 - e0) || 1e-6));
        return t * t * (3 - 2 * t);
    };
    const skirtHeight = (x, z) => {
        const r = Math.hypot(x, z);
        const t = clamp01((r - (R - 0.05)) / 11.55);
        const th = Math.atan2(x, z);
        const bandF = 1 - sstep(0.10, 0.42, Math.cos(th)); // 0 south, 1 water arcs
        const n = nAt(th);
        const landY = Math.sin(t * Math.PI) * (0.5 + 0.9 * n) - t * t * 1.4;
        const bandY = -0.55 + (1.9 + 1.3 * n) * sstep(0, 1, Math.min(1, t * 1.25));
        return landY * (1 - bandF) + bandY * bandF;
    };
    const skirtGeo = new THREE.RingGeometry(R - 0.05, R + 11.5, 128, 30);
    const sp = skirtGeo.attributes.position;
    for (let i = 0; i < sp.count; i++) {
        sp.setZ(i, skirtHeight(sp.getX(i), -sp.getY(i)));
    }
    skirtGeo.computeVertexNormals();
    const skirt = new THREE.Mesh(skirtGeo, floor ? floor.material : new THREE.MeshLambertMaterial({ color: 0x161a14 }));
    skirt.rotation.x = -Math.PI / 2;
    this.scene.add(skirt);

    // ── 3. The water — still, deep-tinted, alive (brief §5-§7) ──────────
    // high: the water Reflector (depth tint + two-train ripple + sheen +
    // manual fog). mobile: dark gloss PBR plane (the designed fallback —
    // moonlight specular + PMREM sheen). low: Lambert water + streak.
    const fogColor = parseColor(vc.fog_color) || new THREE.Color(0x0f1726);
    const fogNear = Number(vc.fog_near) || 20;
    const fogFar = Number(vc.fog_far) || 64;
    // The declared reflection gate (floor_reflection): 'planar' → the water
    // Reflector on high tier, the designed gloss mood on mobile/low — the
    // SAME TierResolve decision the v1 body and the Cathedral consume. The
    // ?reflect=0 QA strip flips the declared value, never a code branch.
    const reflMode = resolveReflectionMode({
        isLowEnd: low,
        isMobileTier: !!this._isMobileTier,
        declared: vc.floor_reflection === 'planar',
    });
    let waterMat = null;
    if (reflMode === 'planar') {
        const reflector = addWaterReflection(this, {
            radius: R + 3,
            level: waterLevel,
            color: 0x5a6a78,          // deep blue-grey: the water returns a fraction
            resolution: 1024,
            fogColor: fogColor.getHex(),
            fogNear,
            fogFar,
        });
        // uTime rides the generic particle loop ('void-drift'): reduced-
        // motion + low tier freeze it automatically.
        this._particleSystems = this._particleSystems || [];
        this._particleSystems.push({ obj: reflector, type: 'void-drift' });
    } else {
        // The DESIGNED no-reflector water (mobile gloss / low Lambert): still
        // water has a faint blue-green BODY tone from scattered skylight —
        // pure black reads as a hole in the composition, not as a lake. The
        // moon streak + the sky-PMREM glint carry the highlight language.
        waterMat = low
            ? new THREE.MeshLambertMaterial({ color: 0x121c26 })
            : new THREE.MeshStandardMaterial({
                color: 0x16222e, roughness: 0.06, metalness: 0.55,
                envMapIntensity: 1.3,
            });
        const water = new THREE.Mesh(new THREE.CircleGeometry(R + 3, 72), waterMat);
        water.rotation.x = -Math.PI / 2;
        water.position.y = waterLevel;
        this.scene.add(water);
    }

    // ── 4. Sky — the venue IS its own night (no HDRI leak) ──────────────
    // Deep blue-black dome, a faint horizon band, ~340 seeded stars, the
    // moon + halo. The dome (+ moon) is PMREM'd once so the water, the
    // steel and the artworks reflect THIS sky (garden precedent).
    const domeR = (R + 14) * 1.15;
    const skyGeo = new THREE.SphereGeometry(domeR, 40, 22);
    const skyMat = new THREE.ShaderMaterial({
        side: THREE.BackSide,
        depthWrite: false,
        fog: false,
        uniforms: {
            topColor:    { value: new THREE.Color(0x05070f) },
            midColor:    { value: new THREE.Color(0x0e1a30) },
            bottomColor: { value: new THREE.Color(0x24365a) },
        },
        vertexShader: `
            varying vec3 vWorldPosition;
            void main() {
                vec4 worldPosition = modelMatrix * vec4(position, 1.0);
                vWorldPosition = worldPosition.xyz;
                gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
            }
        `,
        fragmentShader: `
            uniform vec3 topColor;
            uniform vec3 midColor;
            uniform vec3 bottomColor;
            varying vec3 vWorldPosition;
            void main() {
                float h = normalize(vWorldPosition).y;
                vec3 c = h > 0.18
                    ? mix(midColor, topColor, smoothstep(0.18, 0.72, h))
                    : mix(bottomColor, midColor, smoothstep(-0.04, 0.18, h));
                gl_FragColor = vec4(c, 1.0);
            }
        `,
    });
    const sky = new THREE.Mesh(skyGeo, skyMat);
    this.scene.add(sky);

    // Stars — faint; the lake, not the cosmos, is the identity.
    const STAR_COUNT = low ? 160 : 340;
    const starPos = new Float32Array(STAR_COUNT * 3);
    for (let i = 0; i < STAR_COUNT; i++) {
        const a = this._venueRng.next() * Math.PI * 2;
        const y = 0.06 + this._venueRng.next() * 0.9;    // upper hemisphere band
        const rr = Math.sqrt(Math.max(0, 1 - y * y)) * domeR * 0.97;
        starPos[i * 3] = Math.cos(a) * rr;
        starPos[i * 3 + 1] = y * domeR * 0.97;
        starPos[i * 3 + 2] = Math.sin(a) * rr;
    }
    const starGeo = new THREE.BufferGeometry();
    starGeo.setAttribute('position', new THREE.BufferAttribute(starPos, 3));
    const stars = new THREE.Points(starGeo, new THREE.PointsMaterial({
        map: makeStarSpriteTexture(), color: 0xcfd8ee, size: 0.55, sizeAttenuation: true,
        transparent: true, opacity: 0.9, depthWrite: false, fog: false,
    }));
    this.scene.add(stars);

    // Moon — the composition anchor. ENE by plan: its light lies across the
    // water, right of the arrival sightline, over the pavilion.
    const moonEl = plan.moon.elevation;
    const moonAz = plan.moon.azimuth;
    const moonDir = new THREE.Vector3(
        Math.sin(moonAz) * Math.cos(moonEl),
        Math.sin(moonEl),
        Math.cos(moonAz) * Math.cos(moonEl),
    );
    const moonPos = moonDir.clone().multiplyScalar(domeR * 0.86);

    const moonLight = new THREE.DirectionalLight(0xbdd0f2, 1.5);
    moonLight.position.copy(moonDir).multiplyScalar(60);
    this.scene.add(moonLight);

    const moon = new THREE.Mesh(
        new THREE.SphereGeometry(1.45, 20, 20),
        new THREE.MeshBasicMaterial({ color: 0xe3ebfa, fog: false }),
    );
    moon.position.copy(moonPos);
    this.scene.add(moon);

    const halo = new THREE.Mesh(
        new THREE.PlaneGeometry(11, 11),
        new THREE.MeshBasicMaterial({
            map: makeMoonHaloTexture(), transparent: true, opacity: 0.5,
            depthWrite: false, fog: false, blending: THREE.AdditiveBlending,
        }),
    );
    halo.position.copy(moonPos);
    halo.lookAt(0, 0, 0);
    this.scene.add(halo);

    // Mobile / low tiers: the designed dark-gloss mood — the moon's light
    // streak lying on the water toward the moon azimuth (the v1 fallback,
    // retinted for the still shore).
    if (!highFx) {
        addMoonLightStreak(this, R, moonDir, { color: 0xa8bcd8, opacity: 0.2 });
    }

    // Sky environment — PMREM of dome + moon; no HDRI download, no wrong
    // world reflected in the water (the v1 rural_evening leak is dead).
    // Config-gated (lake.sky_environment — the rollback switch).
    if (!low && lakeCfg.sky_environment === true) {
        try {
            const pmrem = new THREE.PMREMGenerator(this.renderer);
            const envScene = new THREE.Scene();
            envScene.add(new THREE.Mesh(skyGeo.clone(), skyMat.clone()));
            const envMoon = new THREE.Mesh(
                new THREE.SphereGeometry(1.45, 16, 16),
                new THREE.MeshBasicMaterial({ color: 0xe3ebfa }),
            );
            envMoon.position.copy(moonPos);
            envScene.add(envMoon);
            const rt = pmrem.fromScene(envScene, 0.04);
            this.scene.environment = rt.texture;
            this.scene.environmentIntensity = this._venueEnvIntensity ?? 0.14;
            pmrem.dispose();
        } catch (e) {
            console.warn('[lake] sky environment skipped:', e);
        }
    }

    // ── 5. The Landing + shore walk + pier apron — cut stone ────────────
    const stoneMat = low
        ? new THREE.MeshLambertMaterial({ color: 0x3d4149 })
        : new THREE.MeshStandardMaterial({
            color: 0x41454d, roughness: 0.92, metalness: 0.02,
            map: makeLakeStoneTexture(),
        });
    const addStone = (geo, lift) => {
        const m = new THREE.Mesh(geo, stoneMat);
        m.receiveShadow = highFx;
        this.scene.add(m);
        return m;
    };
    addStone(buildGravelDisc(plan.spawn.x, plan.spawn.z, plan.plaza.r, height, 0.016));
    const walkGeo = buildGravelRibbon(plan.walk.samples, plan.walk.width, height, 0.015);
    if (walkGeo) addStone(walkGeo);
    addStone(buildGravelDisc(plan.pier.x, plan.shoreZ(plan.pier.x) + 0.4, 1.5, height, 0.014));

    // ── 6. The pier + pavilion — the Destination ────────────────────────
    const timberMat = low
        ? new THREE.MeshLambertMaterial({ color: 0x3d2f21 })
        : new THREE.MeshStandardMaterial({
            color: 0x57432e, roughness: 0.82, metalness: 0.02,
            map: makeLakeTimberTexture(),
        });
    const steelMat = low
        ? new THREE.MeshLambertMaterial({ color: 0x232527 })
        : new THREE.MeshStandardMaterial({ color: 0x2a2c2f, roughness: 0.45, metalness: 0.7 });

    const pier = plan.pier;
    const pav = plan.pavilion;
    const pierGroup = new THREE.Group();
    {
        const deckZ0 = pav.z - pav.size / 2 - 0.2;
        const deckZ1 = pier.footZ + 0.8;               // bites into the bank
        const len = deckZ1 - deckZ0;
        const midZ = (deckZ0 + deckZ1) / 2;
        const parts = [];
        const deck = new THREE.BoxGeometry(pier.width, 0.1, len);
        parts.push(deck);
        // posts to the bed + rail stanchions
        const nBays = Math.max(2, Math.round(len / 2.4));
        for (let i = 0; i <= nBays; i++) {
            const z = deckZ1 - (len * i) / nBays;
            for (const sx of [-1, 1]) {
                const post = new THREE.CylinderGeometry(0.075, 0.085, 1.25, 8);
                post.translate(sx * (pier.width / 2 - 0.14), -0.55, z);
                parts.push(post);
                const rst = new THREE.BoxGeometry(0.05, 0.62, 0.05);
                rst.translate(sx * (pier.width / 2 - 0.05), 0.36, z);
                parts.push(rst);
            }
        }
        const pierMesh = new THREE.Mesh(mergeGeometries(parts, false), timberMat);
        pierMesh.castShadow = false;
        pierMesh.receiveShadow = highFx;
        pierGroup.add(pierMesh);
        // rails — dark steel, two runs
        const railParts = [];
        for (const sx of [-1, 1]) {
            const rail = new THREE.BoxGeometry(0.045, 0.045, len - 0.2);
            rail.translate(sx * (pier.width / 2 - 0.05), 0.64, midZ);
            railParts.push(rail);
        }
        const railMesh = new THREE.Mesh(mergeGeometries(railParts, false), steelMat);
        pierGroup.add(railMesh);
        pierGroup.position.set(pier.x, 0, 0);
        this.scene.add(pierGroup);
    }

    // The pavilion — stilted deck, four posts, low roof, one warm lantern.
    const pavGroup = new THREE.Group();
    {
        const s = pav.size;
        const parts = [];
        const deck = new THREE.BoxGeometry(s, 0.14, s);
        deck.translate(0, pav.deckY - 0.07, 0);
        parts.push(deck);
        for (const cx of [-1, 1]) for (const cz of [-1, 1]) {
            const post = new THREE.BoxGeometry(0.09, 2.85, 0.09);
            post.translate(cx * (s / 2 - 0.22), pav.deckY + 0.07 + 1.425, cz * (s / 2 - 0.22));
            parts.push(post);
        }
        // an interior bench facing the view
        const seat = new THREE.BoxGeometry(1.7, 0.055, 0.42);
        seat.translate(-s / 2 + 0.75, pav.deckY + 0.52, 0);
        parts.push(seat);
        for (const bz of [-0.14, 0.14]) {
            const skid = new THREE.BoxGeometry(1.6, 0.05, 0.07);
            skid.translate(-s / 2 + 0.75, pav.deckY + 0.26, bz);
            parts.push(skid);
        }
        const body = new THREE.Mesh(mergeGeometries(parts, false), timberMat);
        body.receiveShadow = highFx;
        pavGroup.add(body);

        const roof = new THREE.Mesh(new THREE.BoxGeometry(s + 0.8, 0.1, s + 0.8), timberMat);
        roof.position.y = pav.deckY + 2.9;
        pavGroup.add(roof);
        const fascia = new THREE.Mesh(new THREE.BoxGeometry(s + 0.9, 0.06, s + 0.9), steelMat);
        fascia.position.y = pav.deckY + 2.83;
        pavGroup.add(fascia);
        // the lantern — one warm emissive line under the south roof edge,
        // visible (and reflected) across the whole bay
        const stripMat = new THREE.MeshBasicMaterial({ color: 0xffd9a6 });
        stripMat.toneMapped = true;
        const strip = new THREE.Mesh(new THREE.BoxGeometry(s - 0.3, 0.045, 0.045), stripMat);
        strip.position.set(0, pav.deckY + 2.76, s / 2 - 0.12);
        pavGroup.add(strip);

        const lamp = new THREE.PointLight(0xffd2a0, 1.7, 8.5, 2);
        lamp.position.set(0, pav.deckY + 2.4, 0.6);
        pavGroup.add(lamp);

        pavGroup.position.set(pav.x, 0, pav.z);
        pavGroup.rotation.y = pav.yaw;
        this.scene.add(pavGroup);
        // corner posts are physical
        for (const cx of [-1, 1]) for (const cz of [-1, 1]) {
            const proxy = new THREE.Mesh(
                new THREE.BoxGeometry(0.18, 2.8, 0.18),
                new THREE.MeshBasicMaterial({ visible: false }),
            );
            proxy.position.set(
                pav.x + (cx * (pav.size / 2 - 0.22)) * Math.cos(pav.yaw) + (cz * (pav.size / 2 - 0.22)) * Math.sin(pav.yaw),
                1.4,
                pav.z - (cx * (pav.size / 2 - 0.22)) * Math.sin(pav.yaw) + (cz * (pav.size / 2 - 0.22)) * Math.cos(pav.yaw),
            );
            proxy.visible = false;
            this.scene.add(proxy);
            this.registerObstacle(proxy, 0.1);
        }
    }

    // ── 7. Mist — low vapour over the WATER (soft sprites; never squares) ─
    const mistSprite = makeMistSpriteTexture();
    const makeMistCloud = (count, size, opacity, yBase, ySpan) => {
        const pos = new Float32Array(count * 3);
        let placed = 0, guard = 0;
        while (placed < count && guard++ < count * 30) {
            const a = this._venueRng.next() * Math.PI * 2;
            const r = Math.sqrt(this._venueRng.next()) * (R - 1.5);
            const x = Math.cos(a) * r, z = Math.sin(a) * r;
            if (!plan.terrain.isWater(x, z, 1.6)) continue;    // over open water only
            pos[placed * 3] = x;
            pos[placed * 3 + 1] = yBase + this._venueRng.next() * ySpan;
            pos[placed * 3 + 2] = z;
            placed++;
        }
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(pos.slice(0, placed * 3), 3));
        const mat = new THREE.PointsMaterial({
            map: mistSprite, color: 0x9fb0c8, size, transparent: true,
            opacity, sizeAttenuation: true, depthWrite: false,
        });
        const pts = new THREE.Points(geo, mat);
        this.scene.add(pts);
        this._particleSystems = this._particleSystems || [];
        this._particleSystems.push({ obj: pts, type: 'drift', phase: this._venueRng.next() * Math.PI * 2 });
        return pts;
    };
    makeMistCloud(low ? 10 : 22, 7.5, 0.10, -0.05, 0.55);   // broad banks
    makeMistCloud(low ? 14 : 30, 3.2, 0.12, 0.05, 0.5);     // near-surface haze

    // ── 8. The living layer — owner GLB assets via the manifest system ──
    // Same contract as the garden: missing files skip their layer with one
    // diagnostic; the base environment above stands alone. Far-shore trees
    // stand ON the skirt (band height fn), near planting on the land.
    const anchors = [
        ...plan.vegetation.map(a => ({ x: a.x, z: a.z, role: a.role, yaw: a.yaw, scale: a.scale })),
        ...plan.benches.map(b => ({ x: b.x, z: b.z, role: 'bench', yaw: b.yaw, scale: 1 })),
    ];
    const anchorHeight = (x, z) => (Math.hypot(x, z) > R - 0.06 ? skirtHeight(x, z) : height(x, z));
    const gen = (this._lakeAssetGen = (this._lakeAssetGen || 0) + 1);
    this._lakeAssetsSettled = false;   // deterministic async-content gate (QA harness polls this)
    loadGardenAssets(resolveGardenAssetRequests(lakeCfg), this).then(({ entries }) => {
        if (gen !== this._lakeAssetGen || this._disposed) return;   // stale build
        buildGardenAssetInstances(this, entries, groupAnchorsByRole(anchors), anchorHeight, {
            tierLow: low,
            anchorCap: 18,   // low tier trims trailing (horizon) anchors first
            sink: 0.05,
        });
        this._lakeAssetsSettled = true;
    }).catch(() => { this._lakeAssetsSettled = true; /* never blocks boot */ });

    // ── 9. Shore clamp — the visitor walks the LAND, not the lake ──────
    // Movement enforces the circular field bound; this tick (after movement,
    // the garden-tick slot) closes the shoreline: water is standable-from,
    // never standable-on — except the pier corridor and the pavilion deck,
    // which ARE the over-water walk. Reduced-motion keeps it (locomotion,
    // not an effect — the garden ground-follow precedent).
    const halfPier = pier.width / 2 + 0.3;
    const halfPav = pav.size / 2 + 0.25;
    this._lakeTick = function lakeShoreClamp() {
        if (this.arrivalActive || this.isInspecting) return;
        const pos = this.camera.position;
        const onPier = Math.abs(pos.x - pier.x) < halfPier
            && pos.z < pier.footZ + 0.5 && pos.z > pav.z - halfPav;
        const inPavilion = Math.abs(pos.x - pav.x) < halfPav && Math.abs(pos.z - pav.z) < halfPav;
        if (onPier || inPavilion) return;
        const edge = plan.shoreZ(pos.x) - 0.15;    // toes at the waterline
        if (pos.z < edge) pos.z = edge;
    };

    // ── 10. Camera far floor — the sky must survive the south spawn ─────
    // The generic room-far (2.5·reach + 10) sizes for the floor; the dome
    // stands (R+14)·1.15 out and the spawn is 0.72R south of centre.
    const farFloor = Math.ceil(domeR * 2.2);
    if (this.camera.far < farFloor) this.camera.far = farFloor;
}

// ── THE MEDIA WALL (v3.1.0 "The Media Wall", penthouse — generic mechanism) ──
// visual_config.media_wall = {
//     bezel:  'media-bezel',           // descriptor id whose mesh hosts the screen
//     screen: { w: 1.5, h: 0.84 },     // display size in metres
//     accent: '0xd8a35a',              // UI accent (the room's bronze line)
// }
//
// WHY the screen is viewer-drawn and not a descriptor: the display shows the
// GALLERY'S identity — wordmark, featured artwork, work count — and a shared
// venue-template row cannot know the gallery it will serve. The viewer reads
// window.GALLERY_DATA at build time and bakes one CanvasTexture.
//
// Design rules honoured here:
//  • Self-locating: the display plane becomes a CHILD of the bezel mesh, so
//    anchors/turn/rot stay the migration's business and Live-Preview rebuilds
//    (scene.clear() → full re-run) cannot leak or orphan the plane.
//  • Facing: the plane sits on the bezel's local +Z face and is yaw-flipped
//    once if that face points away from the venue's occupied centre — the
//    screen always reads from the room, whatever the anchor convention.
//  • Emissive truth: a display is a light source — MeshBasicMaterial (unlit)
//    with tone mapping, so the panel glows in the dusk interior exactly like
//    hardware; the venue's bloom pass picks it up at its own threshold.
//  • Static by design: no per-frame ticker, no breathing, no slideshow —
//    zero animation cost on every tier and nothing for reduced-motion users
//    to opt out of. One 768×432 bake, redrawn at most once (thumbnail load).
//  • Honest fallbacks: no bezel mesh → silent no-op; no GALLERY_DATA →
//    generic wordmark; thumbnail 404/timeout → typographic idle screen.
function buildMediaWall(ctx, spec) {
    const bezelId = typeof spec.bezel === 'string' && spec.bezel ? spec.bezel : 'media-bezel';
    const bezel = ctx.scene.getObjectByName(`structure:${bezelId}`);
    if (!bezel?.isMesh) {
        // Config without furniture (admin pruned the descriptors) — skip,
        // never conjure a floating screen.
        console.warn(`[exospace] media_wall: bezel mesh 'structure:${bezelId}' not found — screen skipped.`);
        return;
    }

    const sw = Number(spec.screen?.w) || 1.5;
    const sh = Number(spec.screen?.h) || 0.84;
    const accent = parseColor(spec.accent || '0xd8a35a') || new THREE.Color(0xd8a35a);

    // ── The bake ─────────────────────────────────────────────────────────
    const W = 768, H = 432;
    const canvas = document.createElement('canvas');
    canvas.width = W; canvas.height = H;
    const g = canvas.getContext('2d');

    const drawIdle = (art) => {
        // Glass: near-black vertical sheen + corner vignette.
        const bg = g.createLinearGradient(0, 0, 0, H);
        bg.addColorStop(0, '#141110');
        bg.addColorStop(0.55, '#0c0a09');
        bg.addColorStop(1, '#080706');
        g.fillStyle = bg;
        g.fillRect(0, 0, W, H);
        const vig = g.createRadialGradient(W / 2, H / 2, H * 0.35, W / 2, H / 2, W * 0.72);
        vig.addColorStop(0, 'rgba(0,0,0,0)');
        vig.addColorStop(1, 'rgba(0,0,0,0.55)');
        g.fillStyle = vig;
        g.fillRect(0, 0, W, H);

        const hex = '#' + accent.getHexString();
        const cream = '#f2e9da';
        const dim = '#8a7a64';

        // Hairline bronze frame inset.
        g.strokeStyle = hex;
        g.globalAlpha = 0.55;
        g.lineWidth = 2;
        g.strokeRect(14.5, 14.5, W - 29, H - 29);
        g.globalAlpha = 1;

        const title = String(window.GALLERY_DATA?.title || 'EXOSPACE').trim() || 'EXOSPACE';
        const images = Array.isArray(window.GALLERY_DATA?.images) ? window.GALLERY_DATA.images : [];
        const count = Number(window.GALLERY_DATA?.imageCount ?? images.length);

        // Eyebrow.
        g.fillStyle = hex;
        g.font = '600 21px "Helvetica Neue", Arial, sans-serif';
        g.textAlign = 'left';
        g.textBaseline = 'alphabetic';
        g.save();
        g.translate(58, 96);
        const eyebrow = 'NOW SHOWING';
        for (let i = 0; i < eyebrow.length; i++) {
            g.fillText(eyebrow[i], i * 17, 0);
        }
        g.restore();

        // Wordmark (serif — the room's printed-catalogue voice).
        g.fillStyle = cream;
        g.font = `${title.length > 18 ? 58 : 76}px Georgia, "Times New Roman", serif`;
        g.fillText(title.length > 26 ? title.slice(0, 25) + '…' : title, 54, 188);

        // Rule + meta line.
        g.strokeStyle = dim;
        g.globalAlpha = 0.8;
        g.lineWidth = 1.5;
        g.beginPath();
        g.moveTo(56, 226);
        g.lineTo(art ? 448 : W - 56, 226);
        g.stroke();
        g.globalAlpha = 1;
        g.fillStyle = dim;
        g.font = '500 23px "Helvetica Neue", Arial, sans-serif';
        const meta = `${count} WORK${count === 1 ? '' : 'S'} · OPEN DAILY`;
        g.fillText(meta, 56, 268);

        // Footer mark.
        g.fillStyle = dim;
        g.font = '600 19px "Helvetica Neue", Arial, sans-serif';
        g.textAlign = 'right';
        g.fillText('E X O S P A C E', W - 58, H - 52);
        g.textAlign = 'left';

        // Featured artwork panel (right third) — cover-fit, bronze keyline.
        if (art?.img) {
            const pw = 232, ph = 300, px = W - pw - 62, py = (H - ph) / 2;
            g.save();
            g.beginPath();
            g.rect(px, py, pw, ph);
            g.clip();
            const iw = art.img.width || art.img.naturalWidth || 1;
            const ih = art.img.height || art.img.naturalHeight || 1;
            const s = Math.max(pw / iw, ph / ih);
            const dw = iw * s, dh = ih * s;
            g.drawImage(art.img, px + (pw - dw) / 2, py + (ph - dh) / 2, dw, dh);
            g.restore();
            g.strokeStyle = hex;
            g.globalAlpha = 0.7;
            g.lineWidth = 2;
            g.strokeRect(px + 0.5, py + 0.5, pw - 1, ph - 1);
            g.globalAlpha = 1;
            if (art.title) {
                g.fillStyle = cream;
                g.font = '500 19px "Helvetica Neue", Arial, sans-serif';
                const t = art.title.length > 30 ? art.title.slice(0, 29) + '…' : art.title;
                g.fillText(t, px, py + ph + 28);
            }
        }
    };

    drawIdle(null);

    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.anisotropy = 4;

    // ── The display plane ────────────────────────────────────────────────
    const mat = new THREE.MeshBasicMaterial({ map: tex, toneMapped: true });
    const screen = new THREE.Mesh(new THREE.PlaneGeometry(sw, sh), mat);
    const bezelDepth = bezel.geometry?.parameters?.depth ?? 0.08;
    screen.position.set(0, 0, bezelDepth / 2 + 0.004);
    bezel.add(screen);

    // Facing: flip once if the local +Z face points away from the venue's
    // occupied centre (scene bounds, not the origin — void venues lie).
    bezel.updateWorldMatrix(true, false);
    const faceNormal = new THREE.Vector3(0, 0, 1)
        .applyQuaternion(bezel.getWorldQuaternion(new THREE.Quaternion()));
    const centre = new THREE.Box3().setFromObject(ctx.scene).getCenter(new THREE.Vector3());
    const bezelPos = bezel.getWorldPosition(new THREE.Vector3());
    if (faceNormal.dot(centre.sub(bezelPos)) < 0) {
        screen.rotation.y = Math.PI;
    }

    // ── Featured artwork (async, at most one redraw) ─────────────────────
    // The AssetLoader owns the artwork textures; rather than couple to its
    // cache state we reuse the browser cache via a plain Image on the SAME
    // origin (urls.* are app-hosted conversions). Failure keeps the idle art.
    const first = (window.GALLERY_DATA?.images || [])
        .find(i => i?.urls?.large || i?.urls?.medium || i?.urls?.original);
    const src = first?.urls?.large || first?.urls?.medium || first?.urls?.original;
    if (src) {
        const img = new Image();
        img.decoding = 'async';
        img.onload = () => {
            drawIdle({ img, title: first.title || '' });
            tex.needsUpdate = true;
        };
        img.onerror = () => { /* typographic idle screen stands */ };
        img.src = src;
    }
}
