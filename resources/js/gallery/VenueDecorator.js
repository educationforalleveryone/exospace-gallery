// ─────────────────────────────────────────────────────────────────────────────
// VenueDecorator — applies venue-specific overrides
//
// Two paths:
//   1. Data-driven: if window.GALLERY_DATA.venueConfig.visual_config is set
//      (which it always is, because VenueConfigExporter populates it), apply
//      the JSON config from the database.
//   2. Legacy fallback: hardcoded switch — only runs if the JSON is missing.
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
import { CONFIG, OPEN_AIR_VENUES, parseColor } from './config.js';
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

// ── Top-level dispatcher ────────────────────────────────────────────────────
export function applyVenueOverrides(slug) {
    const cfg = window.GALLERY_DATA.venueConfig;
    if (cfg && cfg.visual_config && Object.keys(cfg.visual_config).length) {
        this.applyVenueConfig(cfg);
        return;
    }
    // Legacy fallback (kept for backward compat with galleries that have no
    // venueConfig — e.g. older seeded venues without visual_config)
    legacyVenueSwitch.call(this, slug);
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
    this._venueSlug = cfg.slug || 'white-cube';

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

// ── Legacy hardcoded switch (fallback only) ─────────────────────────────────
function legacyVenueSwitch(slug) {
    switch (slug) {
        case 'white-cube':
        default:
            CONFIG.room.wallHeight = 4;
            this.scene.background  = new THREE.Color(0x0f0f0f);
            this.scene.fog         = new THREE.Fog(0x0f0f0f, 10, 30);
            this._venueSlug        = 'white-cube';
            break;
        case 'industrial-loft':
            CONFIG.room.wallHeight = 7;
            CONFIG.room.wallDepth  = 0.5;
            this.scene.background  = new THREE.Color(0x111008);
            this.scene.fog         = new THREE.Fog(0x111008, 8, 35);
            this._venueSlug        = 'industrial-loft';
            break;
        case 'dark-museum':
            CONFIG.room.wallHeight = 5;
            this.scene.background  = new THREE.Color(0x020202);
            this.scene.fog         = new THREE.Fog(0x020202, 5, 18);
            this._venueSlug        = 'dark-museum';
            break;
        case 'zen-gallery':
            CONFIG.room.wallHeight = 3.2;
            this.scene.background  = new THREE.Color(0x1a1710);
            this.scene.fog         = new THREE.Fog(0x1a1710, 12, 40);
            this._venueSlug        = 'zen-gallery';
            break;
        case 'luxury-penthouse':
            CONFIG.room.wallHeight = 4.5;
            this.scene.background  = new THREE.Color(0x08090d);
            this.scene.fog         = new THREE.Fog(0x08090d, 8, 25);
            this._venueSlug        = 'luxury-penthouse';
            break;
        case 'cyber-gallery':
            CONFIG.room.wallHeight = 6;
            this.scene.background  = new THREE.Color(0x020412);
            this.scene.fog         = new THREE.Fog(0x020412, 6, 22);
            this._venueSlug        = 'cyber-gallery';
            break;
        case 'sculpture-garden':
            CONFIG.room.wallHeight = 8;
            this.scene.background  = new THREE.Color(0x0d1a0d);
            this.scene.fog         = new THREE.Fog(0x0d1a0d, 10, 45);
            this._venueSlug        = 'sculpture-garden';
            break;
        case 'infinite-void':
            CONFIG.room.wallHeight = 20;
            this.scene.background  = new THREE.Color(0x000000);
            this.scene.fog         = null;
            this._venueSlug        = 'infinite-void';
            break;
    }
}

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
    const slug = this._venueSlug || 'white-cube';
    const wh   = CONFIG.room.wallHeight;

    // Iteration 0 (roadmap P0.3): all procedural distribution below draws
    // from a seeded generator — hash(venue_slug + ':' + gallery_id) — so the
    // same venue + gallery renders an IDENTICAL composition on every load.
    // Rebuilt scenes (Live Preview override reloads) recreate the rng from
    // the same seed, so rebuilds are stable too.
    this._venueRng = createVenueRng(venueSeedSource(slug));

    if (slug === 'industrial-loft') {
        addIndustrialLoftStructure.call(this, data);
    } else if (slug === 'dark-museum') {
        addDarkMuseumStructure.call(this, data); // includes collision registration
    } else if (slug === 'sculpture-garden') {
        addSculptureGardenStructure.call(this, data); // grass, hedges, trees, sky
    } else if (slug === 'infinite-void' || slug === 'crystal-cathedral' ||
               slug === 'nebula-drift' || slug === 'mirror-lake') {
        addVoidVenueStructure.call(this, data);
    }
    // white-cube + others: no structure — clean is the point
}

// ── INDUSTRIAL LOFT — steel beams + columns + floor grates ───────────────────
function addIndustrialLoftStructure(data) {
    const beamMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x2a2a2a })
        : new THREE.MeshStandardMaterial({ color: 0x1e1e1e, roughness: 0.6, metalness: 0.9 });

    const meta = this._layoutMeta || {};
    const length = meta.length || 20;
    const width  = meta.width  || 6;

    // PERF-D21 (3D audit F21): beams, columns and grates each merge into ONE
    // mesh — the old per-piece Meshes cost (beamCount + columns + beamCount)
    // draw calls for identical materials with static transforms.
    const beamCount = Math.max(3, Math.floor(length / 5));
    const beamStep  = length / (beamCount + 1);
    const beamGeo   = new THREE.BoxGeometry(width + 0.4, 0.25, 0.3);
    const colGeo    = new THREE.BoxGeometry(0.18, CONFIG.room.wallHeight, 0.18);
    const grateMat  = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x111111 })
        : new THREE.MeshStandardMaterial({ color: 0x0d0d0d, roughness: 1.0, metalness: 0.3 });
    const grateGeo  = new THREE.BoxGeometry(width + 0.3, 0.02, 0.15);

    const beamParts = [], colParts = [], grateParts = [];
    for (let i = 1; i <= beamCount; i++) {
        const x = -length / 2 + i * beamStep;
        beamParts.push({ geo: beamGeo, pos: [x, CONFIG.room.wallHeight - 0.12, 0] });
        grateParts.push({ geo: grateGeo, pos: [x, 0.01, 0] });
        // Vertical column supports at every other beam
        if (i % 2 === 1) {
            [-width / 2 + 0.09, width / 2 - 0.09].forEach(z => {
                colParts.push({ geo: colGeo, pos: [x, CONFIG.room.wallHeight / 2, z] });
            });
        }
    }

    this.scene.add(new THREE.Mesh(mergeParts(beamParts), beamMat));
    this.scene.add(new THREE.Mesh(mergeParts(colParts),  beamMat));
    this.scene.add(new THREE.Mesh(mergeParts(grateParts), grateMat));
    beamGeo.dispose(); colGeo.dispose(); grateGeo.dispose();
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
    const sunLight = new THREE.DirectionalLight(0xfff4e0, 0.8);
    sunLight.position.set(radius * 0.6, radius * 1.5, -radius * 0.4);
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

    // ── 4. Procedural trees (cylinder trunk + 2 cone foliage) ──────────────
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

    treePositions.forEach(pos => {
        const tree = new THREE.Group();
        const trunk = new THREE.Mesh(trunkGeo, trunkMat);
        trunk.position.y = 1.25;
        tree.add(trunk);
        const leaves1 = new THREE.Mesh(leafGeo, leafMat);
        leaves1.position.y = 3.5;
        tree.add(leaves1);
        const leaves2 = new THREE.Mesh(leafGeo, leafMat);
        leaves2.position.y = 4.5;
        leaves2.scale.setScalar(0.7);
        tree.add(leaves2);
        tree.position.set(pos.x, 0, pos.z);
        tree.scale.setScalar(pos.scale);
        this.scene.add(tree);
        // Trunk collides (so player can't walk through tree)
        this.registerObstacle(trunk, 0.1);
    });

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

    // ── 6. Central pedestal — stone column for a hero sculpture ────────────
    const pedestalMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x808080 })
        : new THREE.MeshStandardMaterial({ color: 0xa0a0a0, roughness: 0.7, metalness: 0.1 });
    const pedestalGeo = new THREE.CylinderGeometry(0.6, 0.7, 1.2, 16);
    const pedestal = new THREE.Mesh(pedestalGeo, pedestalMat);
    pedestal.position.set(0, 0.6, 0);
    this.scene.add(pedestal);
    this.registerObstacle(pedestal, 0.2);

    // ── 7. Set circular bounds (player can't walk past the hedge) ─────────
    this._circularBoundsRadius = radius - 0.5;
}

// ── VOID VENUES — Infinite Void + 3 new variants ────────────────────────────
// All four share the "no walls, no ceiling, abstract atmosphere" feel.
// Individual character comes from the bespoke decorations below.
//
// Iteration 2 "Phenomena": each venue's NEW identity body is gated on its
// config declaring structure_pass = 'phenomena' — removing that one key from
// the venue's JSON reverts the venue to its pre-pass render (the per-venue
// rollback the roadmap's Iteration 2 contract requires). Artwork placement
// (float vs easel) is resolved independently from placement_mode.
function addVoidVenueStructure(data) {
    const slug = this._venueSlug;
    const meta = this._layoutMeta || {};
    const radius = meta.radius || 15;

    // Common: circular bounds + subtle ambient
    this._circularBoundsRadius = radius - 0.5;

    if (slug === 'infinite-void') {
        // Original infinite void — pure black + soft ambient blue.
        // (Its Iteration 2 identity — float placement + floor-edge fade — is
        // applied via config keys in ArtworkPlacer/RoomBuilder, not here.)
        addInfiniteVoidParticles.call(this, radius);
    } else if (slug === 'crystal-cathedral') {
        addCrystalCathedralStructure.call(this, radius);
    } else if (slug === 'nebula-drift') {
        addNebulaDriftStructure.call(this, radius);
    } else if (slug === 'mirror-lake') {
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
function addCrystalCathedralStructure(radius) {
    const vc = this._venueVisualConfig || {};
    if (vc.structure_pass === 'phenomena') {
        addCrystalCathedralColonnade.call(this, radius);
    } else {
        addCrystalCathedralLegacyShards.call(this, radius);
    }
}

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
    const coherent = (this._venueVisualConfig || {}).structure_pass === 'phenomena';

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
