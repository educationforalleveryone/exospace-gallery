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
import { createVenueRng, venueSeedSource } from './Rng.js';
// Iteration 2 "Phenomena": declared tier-fallback effects + their pure
// decision core. TierEffects/TierResolve contain zero slug knowledge —
// venues opt in per config key (§11.3: degradation is DESIGNED, not emergent).
import {
    makeGlassMaterial,
    addPlanarReflection,
    addMoonLightStreak,
    addFloorEdgeFade,
} from './TierEffects.js';
import { resolveReflectionMode } from './TierResolve.js';
// Iteration 3 "Rooms": the generic structure-descriptor interpreter (§10.3).
// Zero slug knowledge — venues opt in per config key (structure_pass +
// structure). Zen / Penthouse / Cyber are the vocabulary's first consumers.
import { buildStructure } from './StructureBuilder.js';

// Iteration 6 "Consolidation" (P2.2 + P2.3): the opt-in curator layer.
import { resolveSpacing } from './PlacementCuration.js';
// Industrial Loft deepening: placement parity — the structure pass derives
// its artwork lanes from the SAME centred-run math the placer uses.
import { wallRunOffset } from './ArtworkPlacer.js';

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
    if (Array.isArray(cfg.lighting_fixtures) && cfg.lighting_fixtures.length) {
        this.addCustomLights(cfg.lighting_fixtures);
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
    this._venueRng = createVenueRng(venueSeedSource(slug));

    // Iteration 3: hangable surfaces are (re)built WITH the structure — a
    // Live-Preview rebuild must never accumulate stale surfaces.
    this._hangableSurfaces = [];

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
    //   'garden'    sky dome, sun, hedge ring, trees, path, pedestal
    //   'phenomena' void family — composable flags (void_dust / void_starfield /
    //               void_colonnade / void_shards / void_lake)
    // No pass declared ⇒ no structure. Admin-created venues get FULL identity
    // by declaring the same keys (§17 IT6 outcome).
    if (pass === 'rooms') {
        if (Array.isArray(vc.structure) && vc.structure.length > 0) {
            buildStructure(this, vc.structure);
        }
    } else if (pass === 'cube') {
        addWhiteCubeRespectPass.call(this, data);
    } else if (pass === 'loft') {
        addIndustrialLoftStructure.call(this, data);
    } else if (pass === 'museum') {
        addDarkMuseumStructure.call(this, data); // collision + hangable surfaces
    } else if (pass === 'garden') {
        addSculptureGardenStructure.call(this, data); // grass, hedges, trees, sky
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

// ── Post-placement structure hook (config-gated dispatcher) ─────────────────
// Called by RoomBuilder.buildGallery AFTER placeArtworks — the extension
// point for structure that must anchor to final artwork transforms. Venues
// opt in by structure_pass; no pass ⇒ no-op.
export function addVenuePostPlacementStructure() {
    const pass = (this._venueVisualConfig || {}).structure_pass;
    if (pass === 'museum') addDarkMuseumPictureLights.call(this);
}

// ── SCULPTURE GARDEN — full outdoor redesign ────────────────────────────────
// No walls, no ceiling. Circular grass plane + hedge boundary + sky dome +
// procedural trees + stone path + central pedestal. Artworks are placed on
// easels (built procedurally — see ArtworkPlacer.js for the easel placement).
function addSculptureGardenStructure(data) {
    const meta = this._layoutMeta || {};
    const radius = meta.radius || 15;

    // ── 1. Sky dome — gradient blue (top dark, horizon light) ──────────────
    const skyGeo = new THREE.SphereGeometry(radius * 3, 32, 16);
    const skyMat = new THREE.ShaderMaterial({
        side: THREE.BackSide,
        uniforms: {
            topColor:    { value: new THREE.Color(0x2a5a9a) },
            bottomColor: { value: new THREE.Color(0xc8d8e8) },
            offset:      { value: 0.4 },
            exponent:    { value: 0.6 },
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
    const sky = new THREE.Mesh(skyGeo, skyMat);
    this.scene.add(sky);

    // ── 2. Sun — directional light from above + visual sphere ──────────────
    // Iteration 3 (§4.10): the garden is the ONLY venue where the sky
    // establishes a sun, so it is the only venue that may enable sun shadows
    // — tier-gated (high tier only) and config-gated (visual_config.
    // sun_shadows — the per-venue rollback switch). Everywhere else shadows
    // stay off (CONFIG.performance.shadowsEnabled = false, DO-NOT-DO #4).
    const vcShadow = this._venueVisualConfig || {};
    const sunLight = new THREE.DirectionalLight(0xfff4e0, 0.8);
    sunLight.position.set(radius * 0.6, radius * 1.5, -radius * 0.4);
    if (vcShadow.sun_shadows === true && !this.isLowEnd && !this._isMobileTier) {
        if (!this.renderer.shadowMap.enabled) this.renderer.shadowMap.enabled = true;
        sunLight.castShadow = true;
        sunLight.shadow.mapSize.set(1024, 1024);
        const sc = sunLight.shadow.camera;
        sc.left = -radius * 1.1; sc.right = radius * 1.1;
        sc.top  =  radius * 1.1; sc.bottom = -radius * 1.1;
        sc.near = 1; sc.far = radius * 4;
        sunLight.shadow.bias = -0.0005;
    }
    this.scene.add(sunLight);

    const sunGeo = new THREE.SphereGeometry(2, 16, 16);
    const sunMat = new THREE.MeshBasicMaterial({ color: 0xfff4e0 });
    const sun = new THREE.Mesh(sunGeo, sunMat);
    sun.position.copy(sunLight.position);
    this.scene.add(sun);

    // ── 3. Hedge boundary (low wall of greenery around the circle) ─────────
    // Low enough (1.2m) that the player can see over, dense enough to feel
    // like a garden boundary. Registered as collision obstacles.
    const hedgeMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x2a4a1a })
        : new THREE.MeshStandardMaterial({ color: 0x2a4a1a, roughness: 1.0, metalness: 0.0 });

    const hedgeHeight = 1.2;
    const hedgeThickness = 0.5;
    const hedgeSegments = 24;
    const hedgeGeo = new THREE.BoxGeometry(2 * Math.PI * radius / hedgeSegments + 0.1, hedgeHeight, hedgeThickness);
    // PERF-D21 (3D audit F21): the 24 hedge segments merge into ONE mesh
    // (24 draw calls → 1). The per-segment registerObstacle calls are also
    // dropped — they were redundant: enforceRoomBounds clamps the player to
    // _circularBoundsRadius (radius − 1), well inside the hedge ring, so the
    // hedge AABBs were unreachable. Collision behaviour is unchanged.
    const hedgeParts = [];
    for (let i = 0; i < hedgeSegments; i++) {
        const angle = (i / hedgeSegments) * Math.PI * 2;
        hedgeParts.push({
            geo: hedgeGeo,
            pos: [Math.sin(angle) * radius, hedgeHeight / 2, Math.cos(angle) * radius],
            rot: [0, -angle + Math.PI / 2, 0],
        });
    }
    this.scene.add(new THREE.Mesh(mergeParts(hedgeParts), hedgeMat));
    hedgeGeo.dispose();

    // ── 4. Procedural trees — MERGED low-poly canopies (Iteration 3, §4.10).
    // Identical silhouettes and positions; the 12 previously separate meshes
    // (trunk + 2 cones × 4 trees) merge per part into THREE draw calls total.
    const treePositions = [
        { x: -radius * 0.7, z:  radius * 0.5, scale: 1.0 },
        { x:  radius * 0.6, z:  radius * 0.7, scale: 1.2 },
        { x: -radius * 0.5, z: -radius * 0.6, scale: 0.9 },
        { x:  radius * 0.8, z: -radius * 0.3, scale: 1.1 },
    ];
    const trunkMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x3a2418 })
        : new THREE.MeshStandardMaterial({ color: 0x3a2418, roughness: 0.9, metalness: 0.0 });
    const leafMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x2a4a1a })
        : new THREE.MeshStandardMaterial({ color: 0x2a4a1a, roughness: 1.0, metalness: 0.0 });
    const trunkGeo = new THREE.CylinderGeometry(0.2, 0.3, 2.5, 8);
    const leafGeo  = new THREE.ConeGeometry(1.5, 3.0, 8);

    const trunkParts = [], leaf1Parts = [], leaf2Parts = [];
    treePositions.forEach(pos => {
        trunkParts.push({ geo: trunkGeo, pos: [pos.x, 1.25 * pos.scale, pos.z], scale: pos.scale });
        leaf1Parts.push({ geo: leafGeo,  pos: [pos.x, 3.5 * pos.scale,  pos.z], scale: pos.scale });
        leaf2Parts.push({ geo: leafGeo,  pos: [pos.x, 4.5 * pos.scale,  pos.z], scale: pos.scale * 0.7 });
        // Trunk collision (player can't walk through the tree) — an invisible
        // proxy per tree (the merged-canopy AABB would span the whole grove).
        const trunkProxy = new THREE.Mesh(new THREE.BoxGeometry(0.5, 2.5, 0.5), trunkMat);
        trunkProxy.position.set(pos.x, 1.25 * pos.scale, pos.z);
        trunkProxy.visible = false;
        this.scene.add(trunkProxy);
        this.registerObstacle(trunkProxy, 0.1);
        trunkProxy.geometry.dispose();
    });
    this.scene.add(new THREE.Mesh(mergeParts(trunkParts), trunkMat));
    this.scene.add(new THREE.Mesh(mergeParts(leaf1Parts), leafMat));
    this.scene.add(new THREE.Mesh(mergeParts(leaf2Parts), leafMat));
    trunkGeo.dispose(); leafGeo.dispose();

    // ── 5. Stone path — circular segments of lighter-coloured ground ──────
    const pathMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x9a9080 })
        : new THREE.MeshStandardMaterial({ color: 0x9a9080, roughness: 0.95, metalness: 0.0 });
    const pathGeo = new THREE.RingGeometry(0, 1.5, 16);
    const pathSteps = 6;
    // PERF-D21: one merged path mesh instead of six
    // Iteration 0: stone rotation/scale are seeded (was Math.random) — the
    // garden path is now identical on every load for the same gallery.
    const rng = this._venueRng;
    const pathParts = [];
    for (let i = 0; i < pathSteps; i++) {
        const t = i / (pathSteps - 1);
        const r = radius * 0.3 + t * radius * 0.4;
        const angle = t * Math.PI * 1.5 - Math.PI / 2;
        pathParts.push({
            geo: pathGeo,
            pos: [Math.cos(angle) * r, 0.02, Math.sin(angle) * r],
            rot: [-Math.PI / 2, 0, rng.next() * Math.PI],
            scale: 0.8 + rng.next() * 0.4,
        });
    }
    this.scene.add(new THREE.Mesh(mergeParts(pathParts), pathMat));
    pathGeo.dispose();

    // ── 6. Central pedestal + the signature sculpture (Iteration 3, §4.10).
    // The pedestal stood EMPTY — the venue was missing its hero moment. One
    // abstract bronze knot (a single mesh, elegant-miniature register) gives
    // the garden its centre of gravity. No new animation (§10.3 rule).
    const pedestalMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x808080 })
        : new THREE.MeshStandardMaterial({ color: 0xa0a0a0, roughness: 0.7, metalness: 0.1 });
    const pedestalGeo = new THREE.CylinderGeometry(0.6, 0.7, 1.2, 16);
    const pedestal = new THREE.Mesh(pedestalGeo, pedestalMat);
    pedestal.position.set(0, 0.6, 0);
    pedestal.castShadow = !this.isLowEnd && !this._isMobileTier &&
        (this._venueVisualConfig || {}).sun_shadows === true;
    this.scene.add(pedestal);
    this.registerObstacle(pedestal, 0.2);

    const sculptureMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x8c6a3f })
        : new THREE.MeshStandardMaterial({ color: 0x8c6a3f, roughness: 0.35, metalness: 0.9 });
    const sculpture = new THREE.Mesh(new THREE.TorusKnotGeometry(0.32, 0.11, 96, 10), sculptureMat);
    sculpture.position.set(0, 1.2 + 0.46, 0);
    sculpture.rotation.set(0.55, 0.35, 0);
    sculpture.castShadow = pedestal.castShadow;
    this.scene.add(sculpture);

    // ── 7. Set circular bounds (player can't walk past the hedge) ─────────
    this._circularBoundsRadius = radius - 0.5;
}

// ── VOID VENUES — Infinite Void + 3 new variants ────────────────────────────
// All four share the "no walls, no ceiling, abstract atmosphere" feel.
// Individual character comes from the bespoke decorations below.
//
// Iteration 2 "Phenomena" → Iteration 6 "Consolidation": the four void
// venues' bespoke bodies are now COMPOSABLE INGREDIENTS selected by config
// flags (the slug ladder is gone — DoD rule #7). The 'phenomena'
// structure_pass remains the pass gate + rollback switch; each ingredient is
// declared per venue:
//   void_dust       floating dust points        (infinite-void)
//   void_starfield  starfield + nebula cloud    (nebula-drift)
//   void_colonnade  seeded glass colonnade      (crystal-cathedral, new body)
//   void_shards     legacy shard ring           (cathedral rollback body)
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
    if (vc.void_starfield === true) {
        addNebulaDriftStructure.call(this, radius);
    }
    if (vc.void_colonnade === true) {
        addCrystalCathedralColonnade.call(this, radius);
    } else if (vc.void_shards === true) {
        // Designed rollback body — the Iteration 2 legacy shard ring,
        // reachable by config only (swap void_colonnade/void_shards).
        addCrystalCathedralLegacyShards.call(this, radius);
    }
    if (vc.void_lake === true) {
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
// call, no lighting interaction (MeshBasic-class shader), fog-exempt.
function addVoidDepthGradient(radius) {
    // Radius budget: buildGallery derives camera.far from the circular
    // bounds as reach·2.5 + 10, and the floor fade ends at radius·2.2 — the
    // dome sits just outside the fade and just inside the far plane, so it
    // can never be clipped nor outdone by the background colour.
    const domeRadius = radius * 2.4 + 6;
    const geo = new THREE.SphereGeometry(domeRadius, 24, 12);
    const mat = new THREE.ShaderMaterial({
        side: THREE.BackSide,
        depthWrite: false,
        fog: false,
        uniforms: {
            uZenith:  { value: new THREE.Color(0x0a0e18) },
            uHorizon: { value: new THREE.Color(0x000000) },
        },
        vertexShader: /* glsl */`
            varying vec3 vDir;
            void main() {
                vDir = normalize(position);
                gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
            }
        `,
        fragmentShader: /* glsl */`
            uniform vec3 uZenith;
            uniform vec3 uHorizon;
            varying vec3 vDir;
            void main() {
                float t = smoothstep(-0.08, 0.75, vDir.y);
                gl_FragColor = vec4(mix(uHorizon, uZenith, t), 1.0);
            }
        `,
    });
    const dome = new THREE.Mesh(geo, mat);
    dome.renderOrder = -10; // behind everything
    dome.frustumCulled = false;
    this.scene.add(dome);
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
