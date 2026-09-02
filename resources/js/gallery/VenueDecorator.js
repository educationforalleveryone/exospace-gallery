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
    } else if (v.fog_color === null) {
        this.scene.fog = null;
    }
    if (v.ambient_color)              this._venueAmbientColor     = parseColor(v.ambient_color);
    if (v.ambient_intensity != null)  this._venueAmbientIntensity = v.ambient_intensity;
    if (v.spot_intensity != null)     this._venueSpotIntensity    = v.spot_intensity;
    if (v.fill_intensity != null)     this._venueFillIntensity    = v.fill_intensity;
    if (v.tone_mapping_exposure != null) this.renderer.toneMappingExposure = v.tone_mapping_exposure;
    if (v.frame_override)             this._venueFrameOverride    = v.frame_override;
    if (v.ceiling_type)               this._venueCeilingType      = v.ceiling_type;

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
    if ('frame_override'    in patch) this._venueFrameOverride      = patch.frame_override     === null ? null      : patch.frame_override;
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
    //   'museum'    dividers (cap + hangable faces) and skirting
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
// Square + corridor only (the two layouts the grid math is designed for);
// rotunda/l-shape White Cubes keep their pre-pass purity (documented).
function addWhiteCubeRespectPass(data) {
    const meta = this._layoutMeta || {};
    const wh   = CONFIG.room.wallHeight;

    const revealMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xe9e7e2 })
        : new THREE.MeshStandardMaterial({ color: 0xe9e7e2, roughness: 0.9, metalness: 0.0 });
    const fixtureMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xfff2dd, emissive: 0xffedd0, emissiveIntensity: 1.3 })
        : new THREE.MeshStandardMaterial({ color: 0xfff2dd, emissive: 0xffedd0, emissiveIntensity: 1.3, roughness: 0.6 });

    if (meta.type === 'square') {
        const L = meta.wallLength;
        const baseGeo  = new THREE.BoxGeometry(L, 0.09, 0.045);
        const baseParts = [
            { geo: baseGeo, pos: [0, 0.045, -L / 2 + 0.022] },
            { geo: baseGeo, pos: [0, 0.045,  L / 2 - 0.022], rot: [0, Math.PI, 0] },
            { geo: baseGeo, pos: [-L / 2 + 0.022, 0.045, 0], rot: [0, Math.PI / 2, 0] },
            { geo: baseGeo, pos: [ L / 2 - 0.022, 0.045, 0], rot: [0, -Math.PI / 2, 0] },
        ];
        this.scene.add(new THREE.Mesh(mergeParts(baseParts), revealMat));
        baseGeo.dispose();

        const crownGeo  = new THREE.BoxGeometry(L, 0.05, 0.035);
        const crownParts = [
            { geo: crownGeo, pos: [0, wh - 0.025, -L / 2 + 0.018] },
            { geo: crownGeo, pos: [0, wh - 0.025,  L / 2 - 0.018], rot: [0, Math.PI, 0] },
            { geo: crownGeo, pos: [-L / 2 + 0.018, wh - 0.025, 0], rot: [0, Math.PI / 2, 0] },
            { geo: crownGeo, pos: [ L / 2 - 0.018, wh - 0.025, 0], rot: [0, -Math.PI / 2, 0] },
        ];
        this.scene.add(new THREE.Mesh(mergeParts(crownParts), revealMat));
        crownGeo.dispose();

        // Fixtures at the 2×2 fill-light grid (same math as RoomBuilder).
        const gridStart = -L / 2 + L / 3, step = L / 3;
        const fixGeo = new THREE.CylinderGeometry(0.3, 0.32, 0.05, 20);
        const fixParts = [];
        for (let i = 0; i < 2; i++) {
            for (let j = 0; j < 2; j++) {
                fixParts.push({ geo: fixGeo, pos: [gridStart + i * step, wh - 0.026, gridStart + j * step] });
            }
        }
        this.scene.add(new THREE.Mesh(mergeParts(fixParts), fixtureMat));
        fixGeo.dispose();
    } else if (meta.type === 'corridor') {
        const { length, width } = meta;
        const baseGeo  = new THREE.BoxGeometry(length, 0.09, 0.045);
        this.scene.add(new THREE.Mesh(mergeParts([
            { geo: baseGeo, pos: [0, 0.045, -width / 2 + 0.022] },
            { geo: baseGeo, pos: [0, 0.045,  width / 2 - 0.022], rot: [0, Math.PI, 0] },
        ]), revealMat));
        baseGeo.dispose();

        const crownGeo = new THREE.BoxGeometry(length, 0.05, 0.035);
        this.scene.add(new THREE.Mesh(mergeParts([
            { geo: crownGeo, pos: [0, wh - 0.025, -width / 2 + 0.018] },
            { geo: crownGeo, pos: [0, wh - 0.025,  width / 2 - 0.018], rot: [0, Math.PI, 0] },
        ]), revealMat));
        crownGeo.dispose();

        const fixGeo = new THREE.CylinderGeometry(0.3, 0.32, 0.05, 20);
        this.scene.add(new THREE.Mesh(mergeParts([
            { geo: fixGeo, pos: [-length / 4, wh - 0.026, 0] },
            { geo: fixGeo, pos: [ length / 4, wh - 0.026, 0] },
        ]), fixtureMat));
        fixGeo.dispose();
    }
}

// ── INDUSTRIAL LOFT — beams, placement-aware columns, perimeter coves ────────
// Iteration 3 rework (§4.3), three verified defects fixed:
//   1. LAYOUT-AWARE: the old code read _layoutMeta.length/width — fields only
//      the CORRIDOR builder sets (square/l-shape silently built a 20×6 room's
//      worth of beams mid-air). Reads the real layout now.
//   2. PLACEMENT-AWARE COLUMNS: columns never stand in front of an artwork.
//      Candidates that fall on an artwork lane (deterministic — the SAME lane
//      math ArtworkPlacer uses) shift into the gap between lanes.
//   3. Center-floor grates REPLACED by perimeter cove details — the floor no
//      longer reads as a grate field under the visitor's feet (§4.3).
//   4. Eye-level credibility: crates + rack + track-light heads so the story
//      survives at walking height, not only overhead (corridor + square).
function addIndustrialLoftStructure(data) {
    const beamMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x2a2a2a })
        : new THREE.MeshStandardMaterial({ color: 0x1e1e1e, roughness: 0.6, metalness: 0.9 });
    const coveMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x111111 })
        : new THREE.MeshStandardMaterial({ color: 0x0d0d0d, roughness: 1.0, metalness: 0.3 });

    const meta    = this._layoutMeta || {};
    const wh      = CONFIG.room.wallHeight;
    const spacing = CONFIG.room.artworkSpacing;
    const colGeo  = new THREE.BoxGeometry(0.18, wh, 0.18);

    // Placement-aware helper: shift a wall-line coordinate off the artwork
    // lanes (lanes use the SAME formula ArtworkPlacer applies for this
    // layout — structure and placement can never disagree).
    const avoidLanes = (cand, lanes) => {
        let x = cand;
        for (const k of lanes) {
            if (Math.abs(x - k) < 0.8) { x = k + spacing / 2; break; }
        }
        return x;
    };

    if (meta.type === 'corridor') {
        const length = meta.length, width = meta.width;
        const half   = Math.ceil((data.imageCount || 0) / 2);
        const lanes  = Array.from({ length: half }, (_, k) => -length / 2 + spacing * (1 + k));

        const beamCount = Math.max(3, Math.floor(length / 5));
        const beamStep  = length / (beamCount + 1);
        const beamGeo   = new THREE.BoxGeometry(width + 0.4, 0.25, 0.3);
        const beamParts = [], colParts = [];
        for (let i = 1; i <= beamCount; i++) {
            const x = -length / 2 + i * beamStep;
            beamParts.push({ geo: beamGeo, pos: [x, wh - 0.12, 0] });
            if (i % 2 === 1) {
                const cx = avoidLanes(x, lanes);
                [-width / 2 + 0.09, width / 2 - 0.09].forEach(z => {
                    colParts.push({ geo: colGeo, pos: [cx, wh / 2, z] });
                });
            }
        }
        this.scene.add(new THREE.Mesh(mergeParts(beamParts), beamMat));
        this.scene.add(new THREE.Mesh(mergeParts(colParts), beamMat));
        beamGeo.dispose();

        // Perimeter coves (replace the old center grates) + eye-level props.
        addLoftPerimeterCoves.call(this, [
            { cx: 0, cz: -width / 2 + 0.1, w: length - 0.8, d: 0.09 },
            { cx: 0, cz:  width / 2 - 0.1, w: length - 0.8, d: 0.09 },
            { cx: -length / 2 + 0.1, cz: 0, w: 0.09, d: width - 0.8 },
            { cx:  length / 2 - 0.1, cz: 0, w: 0.09, d: width - 0.8 },
        ], coveMat);
        addLoftEyeLevelProps.call(this, { length, width, beamY: wh - 0.12, alongX: true });
    } else if (meta.type === 'square') {
        const L = meta.wallLength;
        const perWall = Math.ceil((data.imageCount || 0) / 4);
        const lanes   = Array.from({ length: perWall }, (_, k) => -L / 2 + spacing * (1 + k));

        const beamCount = Math.max(3, Math.floor(L / 5));
        const beamStep  = L / (beamCount + 1);
        const beamGeo   = new THREE.BoxGeometry(0.3, 0.25, L + 0.4);
        const beamParts = [], colParts = [];
        for (let i = 1; i <= beamCount; i++) {
            const z = -L / 2 + i * beamStep;
            beamParts.push({ geo: beamGeo, pos: [0, wh - 0.12, z] });
            if (i % 2 === 1) {
                const cz = avoidLanes(z, lanes);
                [-L / 2 + 0.09, L / 2 - 0.09].forEach(x => {
                    colParts.push({ geo: colGeo, pos: [x, wh / 2, cz] });
                });
            }
        }
        this.scene.add(new THREE.Mesh(mergeParts(beamParts), beamMat));
        this.scene.add(new THREE.Mesh(mergeParts(colParts), beamMat));
        beamGeo.dispose();

        addLoftPerimeterCoves.call(this, [
            { cx: 0, cz: -L / 2 + 0.1, w: L - 0.8, d: 0.09 },
            { cx: 0, cz:  L / 2 - 0.1, w: L - 0.8, d: 0.09 },
            { cx: -L / 2 + 0.1, cz: 0, w: 0.09, d: L - 0.8 },
            { cx:  L / 2 - 0.1, cz: 0, w: 0.09, d: L - 0.8 },
        ], coveMat);
        addLoftEyeLevelProps.call(this, { length: L, width: L, beamY: wh - 0.12, alongX: true });
    } else if (meta.type === 'l-shape') {
        // Wing A only — the structure follows the highest artwork density;
        // documented in the iteration report (l-shape structural scope).
        const { wingW, lenA } = meta;
        const zStart = -lenA / 2 + spacing;
        const zLimit = lenA / 2 - wingW - spacing / 2;
        const lanes  = [];
        for (let z = zStart; z < zLimit; z += spacing * 2) lanes.push(z); // sideA alternation → every 2nd spacing

        const beamCount = Math.max(2, Math.floor(lenA / 5));
        const beamStep  = lenA / (beamCount + 1);
        const beamGeo   = new THREE.BoxGeometry(wingW + 0.4, 0.25, 0.3);
        const beamParts = [], colParts = [];
        for (let i = 1; i <= beamCount; i++) {
            const z = -lenA / 2 + i * beamStep;
            beamParts.push({ geo: beamGeo, pos: [wingW / 2, wh - 0.12, z] });
            if (i % 2 === 1) {
                const cz = avoidLanes(z, lanes);
                [0 + 0.09, wingW - 0.09].forEach(x => {
                    colParts.push({ geo: colGeo, pos: [x, wh / 2, cz] });
                });
            }
        }
        this.scene.add(new THREE.Mesh(mergeParts(beamParts), beamMat));
        this.scene.add(new THREE.Mesh(mergeParts(colParts), beamMat));
        beamGeo.dispose();

        addLoftPerimeterCoves.call(this, [
            { cx: wingW / 2, cz: -lenA / 2 + 0.1, w: wingW - 0.8, d: 0.09 },
            { cx: wingW / 2, cz:  lenA / 2 - 0.1, w: wingW - 0.8, d: 0.09 },
            { cx: 0.1,           cz: 0, w: 0.09, d: lenA - 0.8 },
            { cx: wingW - 0.1,   cz: 0, w: 0.09, d: lenA - 0.8 },
        ], coveMat);
    }
    colGeo.dispose();
}

// Perimeter cove strips — merged into ONE mesh (replaces the old center
// floor grates; §4.3 "replace center-floor grates with perimeter details").
function addLoftPerimeterCoves(strips, coveMat) {
    const geo   = new THREE.BoxGeometry(1, 0.07, 1);
    const parts = strips.map(s => ({
        geo,
        pos: [s.cx, 0.035, s.cz],
        scale: undefined,
    }));
    // Scale per strip: BoxGeometry(1,0.07,1) scaled to (w, 1, d).
    parts.forEach((p, i) => { p.scale = [strips[i].w, 1, strips[i].d]; });
    this.scene.add(new THREE.Mesh(mergeParts(parts), coveMat));
    geo.dispose();
}

// Eye-level industrial props — crates, rack, track-light heads.
// Positioned in the END zones past the last artwork lane (corridor places
// artworks on the long walls only, so the end zones are prop-safe).
function addLoftEyeLevelProps({ length, width, beamY, alongX }) {
    const crateMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x6a5230 })
        : new THREE.MeshStandardMaterial({ color: 0x6a5230, roughness: 0.9, metalness: 0.0 });
    const steelMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x2a2c30 })
        : new THREE.MeshStandardMaterial({ color: 0x2a2c30, roughness: 0.45, metalness: 0.85 });
    const lampMat  = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0xfff2dd, emissive: 0xffe9c8, emissiveIntensity: 1.6 })
        : new THREE.MeshStandardMaterial({ color: 0xfff2dd, emissive: 0xffe9c8, emissiveIntensity: 1.6, roughness: 0.5 });

    const endX = length / 2 - 1.4;

    // Crates (cluster, +end) — merged, registered as one obstacle.
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

    // Rack (steel shelving, −end) — merged, registered as one obstacle.
    const upGeo   = new THREE.BoxGeometry(0.06, 1.8, 0.06);
    const shelfGeo = new THREE.BoxGeometry(0.5, 0.04, 1.7);
    const rackParts = [
        { geo: upGeo, pos: [ -endX, 0.9, -0.85 ] },
        { geo: upGeo, pos: [ -endX, 0.9,  0.85 ] },
        { geo: shelfGeo, pos: [ -endX, 0.55, 0 ] },
        { geo: shelfGeo, pos: [ -endX, 1.15, 0 ] },
    ];
    const rack = new THREE.Mesh(mergeParts(rackParts), steelMat);
    upGeo.dispose(); shelfGeo.dispose();
    this.scene.add(rack);
    this.registerObstacle(rack, 0.2);

    // Track-light heads on the two outermost beams — visual fixtures under
    // the existing fill lights (no new dynamic lights — PERF-B18 discipline).
    const headGeo = new THREE.CylinderGeometry(0.07, 0.05, 0.2, 10);
    const headParts = [ -endX, -endX / 2, endX / 2, endX ].map(x => ({
        geo: headGeo,
        pos: [x * (alongX ? 1 : 0), beamY - 0.16, x * (alongX ? 0 : 1)],
    }));
    this.scene.add(new THREE.Mesh(mergeParts(headParts), lampMat));
    headGeo.dispose();
}

// ── DARK MUSEUM — dividers + skirting board (with collision) ────────────────
// FIX: divider walls are now registered as collision obstacles so the player
// can't walk through them.
function addDarkMuseumStructure(data) {
    const meta = this._layoutMeta || {};
    const wl   = Math.max(8, meta.wallLength || 14);
    const wh   = CONFIG.room.wallHeight;

    const divMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x080808 })
        : new THREE.MeshStandardMaterial({ color: 0x050505, roughness: 0.95, metalness: 0.0 });

    const dividerDepth  = 0.3;
    const dividerLength = wl * 0.28; // reach 28% into the room
    const dividerH      = wh;
    const zOffset       = wl * 0.18; // asymmetric bays

    [
        { x: -wl / 2 + dividerLength / 2, z:  zOffset },
        { x:  wl / 2 - dividerLength / 2, z: -zOffset },
    ].forEach(cfg => {
        const geo  = new THREE.BoxGeometry(dividerLength, dividerH, dividerDepth);
        const mesh = new THREE.Mesh(geo, divMat);
        mesh.position.set(cfg.x, dividerH / 2, cfg.z);
        mesh.castShadow    = false;
        mesh.receiveShadow = !this.isLowEnd;
        this.scene.add(mesh);

        // 🔑 FIX: register the divider as a collision obstacle
        this.registerObstacle(mesh, 0.4);

        // ── Iteration 3 (§4.4): top cap + hangable faces.
        // Cap: a slightly larger slab crowns each divider (painted-museum
        // detail; the dividers previously ended as raw box tops).
        const capGeo = new THREE.BoxGeometry(dividerLength + 0.12, 0.07, dividerDepth + 0.12);
        const capMat = this.isLowEnd
            ? new THREE.MeshLambertMaterial({ color: 0x141414 })
            : new THREE.MeshStandardMaterial({ color: 0x101010, roughness: 0.9, metalness: 0.1 });
        const cap = new THREE.Mesh(capGeo, capMat);
        cap.position.set(cfg.x, dividerH + 0.035, cfg.z);
        this.scene.add(cap);

        // Bay redistribution (generic mechanism, ArtworkPlacer consumes):
        // both long faces of every divider register as artwork-hang surfaces,
        // so the bays gain works instead of holding none while the outer
        // walls hold thirty.
        const eye = CONFIG.camera.height;
        this._hangableSurfaces = this._hangableSurfaces || [];
        this._hangableSurfaces.push(
            { x: cfg.x, z: cfg.z + dividerDepth / 2 + 0.02, nx: 0, nz:  1, width: dividerLength - 0.9, height: dividerH - 0.6 },
            { x: cfg.x, z: cfg.z - dividerDepth / 2 - 0.02, nx: 0, nz: -1, width: dividerLength - 0.9, height: dividerH - 0.6 },
        );
    });

    // Skirting / baseboard along all four walls
    const skirtMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x0a0a0a })
        : new THREE.MeshStandardMaterial({ color: 0x080808, roughness: 0.8, metalness: 0.4 });
    const skirtH   = 0.12;
    const skirtGeo = new THREE.BoxGeometry(wl, skirtH, 0.06);
    // PERF-D21: one merged skirting mesh instead of four
    const skirtParts = [
        { geo: skirtGeo, pos: [0,     skirtH / 2 + 0.01, -wl / 2], rot: [0, 0, 0] },
        { geo: skirtGeo, pos: [0,     skirtH / 2 + 0.01,  wl / 2], rot: [0, Math.PI, 0] },
        { geo: skirtGeo, pos: [-wl/2, skirtH / 2 + 0.01, 0     ], rot: [0, Math.PI/2, 0] },
        { geo: skirtGeo, pos: [ wl/2, skirtH / 2 + 0.01, 0     ], rot: [0, -Math.PI/2, 0] },
    ];
    this.scene.add(new THREE.Mesh(mergeParts(skirtParts), skirtMat));
    skirtGeo.dispose();
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

    // Common: circular bounds
    this._circularBoundsRadius = radius - 0.5;

    if (vc.void_dust === true) {
        addInfiniteVoidParticles.call(this, radius);
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

// INFINITE VOID — slow-floating dust particles, very subtle
function addInfiniteVoidParticles(radius) {
    const rng = this._venueRng;
    const particleCount = 200;
    const positions = new Float32Array(particleCount * 3);
    for (let i = 0; i < particleCount; i++) {
        positions[i * 3]     = (rng.next() - 0.5) * radius * 2;
        positions[i * 3 + 1] = rng.next() * 5;
        positions[i * 3 + 2] = (rng.next() - 0.5) * radius * 2;
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    const mat = new THREE.PointsMaterial({
        color: 0xaaccff,
        size: 0.05,
        transparent: true,
        opacity: 0.6,
        sizeAttenuation: true,
    });
    const points = new THREE.Points(geo, mat);
    points.userData.isParticle = true;
    points.userData.baseY = positions;
    this.scene.add(points);
    this._particleSystems = this._particleSystems || [];
    // PERF-C16: phase gives each system a distinct point in the bob cycle
    // (seeded — Iteration 0)
    this._particleSystems.push({ obj: points, type: 'drift', phase: rng.next() * Math.PI * 2 });
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
