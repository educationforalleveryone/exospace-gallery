import * as THREE from 'three';
import gsap from 'gsap';
import { CONFIG, parseColor } from './config.js';
import { loadGlb } from './AssetLoader.js';
import { mergeParts } from './GeometryUtils.js';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { createVenueRng, venueSeedSource } from './Rng.js';
import { buildGardenPlan } from './GardenLayout.js';
import { buildLakePlan } from './LakeLayout.js';
import {
    resolveGardenAssetRequests,
    loadGardenAssets,
    buildGardenAssetInstances,
    groupAnchorsByRole,
} from './GardenAssets.js';
import {
    makeGlassMaterial,
    addPlanarReflection,
    addMoonLightStreak,
    addFloorEdgeFade,
    addWaterReflection,
} from './TierEffects.js';
import { resolveReflectionMode } from './TierResolve.js';
import { buildStructure, resolveAnchor } from './StructureBuilder.js';

// The opt-in curator layer.
import { resolveSpacing } from './PlacementCuration.js';
import { wallRunOffset, squareRunPlan, lshapeRowPlan } from './ArtworkPlacer.js';

export function applyVenueOverrides(slug) {
    const cfg = window.GALLERY_DATA.venueConfig;
    if (cfg && cfg.visual_config && Object.keys(cfg.visual_config).length) {
        this.applyVenueConfig(cfg);
        return;
    }
    console.warn('[exospace] venue has no visual_config — rendering default room. ' +
                 'Declare the venue identity in its template JSON (§10.2).');
    this._venueSlug = slug || 'venue';
}

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

    if (v.post_fx && typeof v.post_fx === 'object') {
        this._venuePostFx = v.post_fx;
        if (this._postFx) this._postFx.applyVenueConfig(this._venuePostFx);
    }

    this._venueVisualConfig = v;
    if (v.placement_mode)          this._venuePlacementMode = v.placement_mode;
    if (v.env_intensity != null)   this._venueEnvIntensity  = v.env_intensity;
    if (v.artwork_light_base != null) this._venueArtworkLightBase = v.artwork_light_base;
    if (v.artwork_light_pool_cap != null) this._venueArtworkLightPoolCap = v.artwork_light_pool_cap;
    if (v.hemisphere_intensity != null) this._venueHemisphereIntensity = v.hemisphere_intensity;
    if (v.artwork_reactive !== undefined) this._venueArtworkReactive = v.artwork_reactive;

    this._venueMaterialConfig = m;
    this._venueSlug = cfg.slug || 'venue';

    if (v.layout_shape) this._venueLayoutShape = v.layout_shape;
    if (v.placement && typeof v.placement === 'object') {
        this._venuePlacement = v.placement;
        const spacing = resolveSpacing(v.placement, CONFIG.room.artworkSpacing);
        if (spacing !== CONFIG.room.artworkSpacing) {
            CONFIG.room.artworkSpacing = spacing;
        }
    }

    if (Array.isArray(cfg.decorations) && cfg.decorations.length) {
        this.loadDecorations(cfg.decorations);
    }
    const fixtures = Array.isArray(cfg.lighting_fixtures) ? cfg.lighting_fixtures : [];
    this._venueAnchoredFixtures = fixtures.filter(f => f && f.anchor);
    const absoluteFixtures = fixtures.filter(f => !f || !f.anchor);
    if (absoluteFixtures.length) {
        this.addCustomLights(absoluteFixtures);
    }
    if (cfg.hdri_url) this._customHdriUrl = cfg.hdri_url;
}

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

    if (patch._materialPatch && typeof patch._materialPatch === 'object') {
        this._venueMaterialConfig = {
            ...(this._venueMaterialConfig || {}),
            ...patch._materialPatch,
        };
    }
}

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

export function addVenueStructure(data) {
    const vc = this._venueVisualConfig || {};
    const pass = vc.structure_pass;
    const slug = this._venueSlug || 'venue';

    if (!this._venueRng) this._venueRng = createVenueRng(venueSeedSource(slug));

    this._hangableSurfaces = [];

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

    if (pass === 'rooms') {
        if (Array.isArray(vc.structure) && vc.structure.length > 0) {
            buildStructure(this, vc.structure);
        }
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
    } else if (pass === 'garden') {
        addSculptureGardenStructure.call(this, data); // grass, hedges, trees, sky
    } else if (pass === 'lake') {
        addMirrorLakeShore.call(this, (this._layoutMeta || {}).radius, data);
    } else if (pass === 'phenomena') {
        addVoidVenueStructure.call(this, data);
    }
}

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
        const r = meta.radius;
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

    const trimOffset = (depth, p) => face + p - depth / 2;
    const segNormal  = (ry) => [Math.sin(ry), Math.cos(ry)];
    const segTangent = (ry) => [Math.cos(ry), -Math.sin(ry)];

    const artHalfWidths = (data.images || []).map(img => {
        const aspect = img.aspectRatio || (img.width && img.height ? img.width / img.height : 1) || 1;
        let w = 2.0 * aspect;
        if (w > 3.0) w = 3.0;
        return w / 2;
    });
    const laneObjectsFor = (runCount, wallLength, firstImageIdx) => {
        const lanes = [];
        for (let p = 0; p < runCount; p++) {
            lanes.push({
                pos: wallRunOffset(runCount, p, spacing, wallLength) - wallLength / 2,
                half: artHalfWidths[firstImageIdx + p] ?? 1.0,
            });
        }
        return lanes;
    };
    const COL_HALF = 0.08;
    const COL_MARGIN = 0.15;
    const clearOfLanes = (cand, lanes) =>
        lanes.every(l => Math.abs(cand - l.pos) >= l.half + COL_HALF + COL_MARGIN);

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

    const joistY = wh - 0.19 - 0.005 - 0.13;   // centre of a 0.26-tall joist
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
        const parts = positions.map(t => (runAxis === 'z'
            ? { geo, pos: [t, joistY, bayX || 0] }
            : { geo, pos: [bayX || 0, joistY, t] }));
        this.scene.add(new THREE.Mesh(mergeParts(parts), steelMat));
        geo.dispose();
        return positions;
    };

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

    const colSize = 0.16;
    const colGeo  = new THREE.BoxGeometry(colSize, wh - 0.45, colSize);
    const colCentre = (wallHalf) => wallHalf - face - colSize / 2 + 0.02;
    const addColumns = (positions) => {
        if (!positions.length) return;
        const parts = positions.map(([x, z]) => ({ geo: colGeo, pos: [x, (wh - 0.45) / 2, z] }));
        this.scene.add(new THREE.Mesh(mergeParts(parts), steelMat));
    };

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

    if (meta.type === 'corridor') {
        const length = meta.length, width = meta.width;
        const half = Math.ceil((data.imageCount || 0) / 2);
        const runA = Math.min(half, data.imageCount || 0);
        const runB = Math.max(0, (data.imageCount || 0) - half);
        const lanesA = laneObjectsFor(runA, length, 0);
        const lanesB = laneObjectsFor(runB, length, runA);

        const slotsA = slotsFor(lanesA, length);
        const slotsB = slotsFor(lanesB, length);
        const joistCount = Math.max(3, Math.round(length / 4));
        const gridStep = length / (joistCount + 1);
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
        const lanesZ   = laneObjectsFor(runLens[2], L, runLens[0] + runLens[1])
            .concat(laneObjectsFor(runLens[3], L, runLens[0] + runLens[1] + runLens[2]));
        const lanesX   = laneObjectsFor(runLens[0], L, 0)
            .concat(laneObjectsFor(runLens[1], L, runLens[0]));

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
        const { wingW, lenA, zStart, zLimit } = meta;

        const lanes = [];
        for (let i = 0; i < (data.imageCount || 0); i++) {
            const z = zStart + Math.floor(i / 2) * spacing;
            if (z > zLimit) break;
            lanes.push({ pos: z, half: artHalfWidths[i] ?? 1.0 });
        }

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

        addPendants([
            [wingW / 2, -lenA / 4],
            [wingW / 2,  lenA / 4],
            [wingW + meta.lenB / 2, meta.jZ],
        ]);
    }
    colGeo.dispose();
}

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
        const r = meta.radius || 10;
        const baseBand = new THREE.Mesh(
            new THREE.CylinderGeometry(r - 0.02, r - 0.02, 0.12, 48, 1, true),
            stoneMat.clone()
        );
        baseBand.material.side = THREE.BackSide;   // dedicated material — never
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

            const eye = CONFIG.camera.height;
            this._hangableSurfaces = this._hangableSurfaces || [];
            this._hangableSurfaces.push(
                { x: cfg.x, z: cfg.z + dividerDepth / 2 + 0.03, nx: 0, nz:  1, width: dividerLength - 0.9, height: CAB_H - 0.7 },
                { x: cfg.x, z: cfg.z - dividerDepth / 2 - 0.03, nx: 0, nz: -1, width: dividerLength - 0.9, height: CAB_H - 0.7 },
            );
        });
    }
}

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
        const canvas = art.userData?._canvasMesh;
        const h = canvas ? canvas.geometry.parameters.height * (art.scale.y || 1) : 2.0;
        const w = canvas ? canvas.geometry.parameters.width * (art.scale.x || 1) : 2.0;
        const normal = new THREE.Vector3(0, 0, 1).applyQuaternion(art.quaternion);

        const platePos = p.clone().add(normal.clone().multiplyScalar(0.045));
        platePos.y = p.y + (h / 2) + 0.34;
        if (platePos.y > wh - 0.12) platePos.y = wh - 0.12; // never pierce the ceiling
        const tubePos = p.clone().add(normal.clone().multiplyScalar(0.15));
        tubePos.y = platePos.y - 0.16;

        const yaw = Math.atan2(normal.x, normal.z);
        plateParts.push({ geo: plateGeo, pos: [platePos.x, platePos.y, platePos.z], rot: [0, yaw, 0], scale: [w + 0.24, 1, 1] });
        tubeParts.push({ geo: tubeGeo, pos: [tubePos.x, tubePos.y, tubePos.z], rot: [0, yaw, 0], scale: [w + 0.06, 1, 1] });
    }

    const plateMesh = new THREE.Mesh(mergeParts(plateParts), brassMat);
    plateMesh.name = 'museum-picture-light-plates';
    const tubeMesh = new THREE.Mesh(mergeParts(tubeParts), tubeMat);
    tubeMesh.name = 'museum-picture-light-tubes';
    this.scene.add(plateMesh);
    this.scene.add(tubeMesh);
    plateGeo.dispose(); tubeGeo.dispose();
}

function addBaysStructure(data) {
    const meta = this._layoutMeta || {};
    const vc   = this._venueVisualConfig || {};
    const S    = CONFIG.room.artworkSpacing;
    const wd   = CONFIG.room.wallDepth || 0.3;
    const wh   = CONFIG.room.wallHeight;
    const face = wd / 2;                    // wall centre plane → inner face
    const count = this.artworkImages.length;
    if (!count || count < 1) return;

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
    B.clerestory_height = Math.min(
        B.clerestory_height,
        wh - B.fin_top - B.clerestory_gap - 0.2
    );

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

    const emitBays = (wall, qFirst, run) => {
        if (run <= 0) return;
        const depth = face + B.fin_depth / 2;
        const P = (q, t, y) => [
            wall.ax + wall.tx * q + wall.nx * t,
            y,
            wall.az + wall.tz * q + wall.nz * t,
        ];
        const ry = Math.atan2(wall.nx, wall.nz);

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
            recessParts.push({
                geo: unitGeo, pos: P(qMid, face + B.recess_lift, headerBottom / 2),
                rot: [0, ry, 0], scale: [bayW, headerBottom, 0.012],
            });
        }

        const span    = run * S + B.fin_width;
        const qCenter = qFirst + (run * S) / 2;
        if (B.clerestory_height >= 0.08) {
            paperParts.push({
                geo: unitGeo,
                pos: P(qCenter, face + B.recess_lift, B.fin_top + B.clerestory_gap + B.clerestory_height / 2),
                rot: [0, ry, 0], scale: [span, B.clerestory_height, 0.012],
            });
        }

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
        const glazingWallId = this._glazing ? this._glazing.wallId : null;
        const walls = [
            { id: 'front', ax: 0,           az: -meta.wallLength / 2, nx: 0,  nz: 1,  tx: 1,  tz: 0 },
            { id: 'back',  ax: 0,           az:  meta.wallLength / 2, nx: 0,  nz: -1, tx: -1, tz: 0 },
            { id: 'left',  ax: -meta.wallLength / 2, az: 0,           nx: 1,  nz: 0,  tx: 0,  tz: -1 },
            { id: 'right', ax:  meta.wallLength / 2, az: 0,           nx: -1, nz: 0,  tx: 0,  tz: 1 },
        ].filter(w => w.id !== glazingWallId);
        const { runCounts } = squareRunPlan(count, count, S, walls.length);
        walls.forEach((wall, i) => emitBays(wall, -(runCounts[i] * S) / 2, runCounts[i]));

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

export function addVenuePostPlacementStructure() {
    const vc = this._venueVisualConfig || {};
    const pass = vc.structure_pass;
    if (pass === 'museum') addDarkMuseumPictureLights.call(this);
    if (vc.placement?.light_pools === true) addFloatLightPools.call(this);
}

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

function addSculptureGardenStructure(data) {
    const meta = this._layoutMeta || {};
    const radius = meta.radius || 15;
    const vc = this._venueVisualConfig || {};
    const gardenCfg = vc.garden || {};
    const highFx = !this.isLowEnd && !this._isMobileTier;

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

    const floor = this._circularFloor;
    if (floor) {
        const lawnGeo = new THREE.RingGeometry(0.02, radius, 128, 48);
        const lp = lawnGeo.attributes.position;
        for (let i = 0; i < lp.count; i++) {
            lp.setZ(i, height(lp.getX(i), -lp.getY(i)));
        }
        lawnGeo.computeVertexNormals();
        floor.geometry.dispose();
        floor.geometry = lawnGeo;

        if (!floor.material.map && typeof document !== 'undefined') {
            floor.material.map = makeLawnDetailTexture();
            floor.material.map.repeat.set(9, 9);
            floor.material.needsUpdate = true;
            if (floor.material.color) floor.material.color.multiplyScalar(0.85);
        }
    }

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

    this.scene.fog = new THREE.Fog(0xdfe2d1, radius * 1.55, radius * 2.75);

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

    this._gardenTick = function gardenGroundFollow() {
        if (this.arrivalActive || this.isInspecting || this._cameraScripted) return;
        const now = performance.now();
        const dt = Math.min(0.1, (now - (this._gardenLastT || now)) / 1000);
        this._gardenLastT = now;
        const cam = this.camera.position;
        const target = height(cam.x, cam.z) + CONFIG.camera.height;
        const nextY = cam.y + (target - cam.y) * (1 - Math.exp(-9 * dt));
        if (Math.abs(nextY - cam.y) > 1e-4) cam.y = nextY;
    };

    const farFloor = radius * 3.7;
    if (this.camera.far < farFloor) {
        this.camera.far = farFloor;
        this.camera.updateProjectionMatrix();
    }

    // ── 11. Set circular bounds (player stays inside the landscape) ─────
    this._circularBoundsRadius = radius - 0.5;
}

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

function addVoidVenueStructure(data) {
    const vc = this._venueVisualConfig || {};
    const meta = this._layoutMeta || {};
    const radius = meta.radius || 15;

    if (this._circularBoundsRadius == null) {
        this._circularBoundsRadius = radius - 0.5;
    }

    if (vc.void_dust === true) {
        addVoidDustField.call(this, radius);
    }
    if (vc.void_depth_gradient === true && !this.isLowEnd) {
        addVoidDepthGradient.call(this, radius);
    }
    if (vc.void_deepfield === true) {
        addNebulaDeepfield.call(this, radius);
    } else if (vc.void_starfield === true) {
        addNebulaDriftStructure.call(this, radius);
    }
    if (vc.void_arcade === true) {
        addCrystalCathedralArcade.call(this, radius);
    } else if (vc.void_colonnade === true) {
        addCrystalCathedralColonnade.call(this, radius);
    } else if (vc.void_shards === true) {
        addCrystalCathedralLegacyShards.call(this, radius);
    }
    if (vc.void_lake === true) {
        addMirrorLakeStructure.call(this, radius);
    }
}

function addVoidDustField(radius) {
    const rng = this._venueRng;
    const isLowEnd = !!this.isLowEnd;
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

function addVoidDepthGradient(radius) {
    const domeRadius = radius * 2.4 + 6;
    const geo = new THREE.SphereGeometry(domeRadius, 24, 12);

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

function addCrystalCathedralArcade(radius) {
    const vc   = this._venueVisualConfig || {};
    const mc   = this._venueMaterialConfig || {};
    const tint = parseColor(vc.colonnade_tint) || new THREE.Color(0xe6f0fb);

    const pierR      = radius + 0.8;
    const bayTarget  = 6.0;                                   // metres of arcade per bay
    const bayCount   = Math.max(10, Math.min(24, Math.round((Math.PI * 2 * pierR) / bayTarget)));
    const chord      = 2 * pierR * Math.sin(Math.PI / bayCount); // pier-centre span
    const PIER_H     = 13.0;
    const PIER_H_ALT = 12.2;                                  // deliberate ABAB mass rhythm
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

    const reflectionMode = resolveReflectionMode({
        isLowEnd: !!this.isLowEnd,
        isMobileTier: !!this._isMobileTier,
        declared: vc.floor_reflection === 'planar',
    });
    if (reflectionMode === 'planar') {
        addPlanarReflection(this, radius, { color: 0x5a6a85, resolution: 1024, blend: 'multiply' });
    } else if (reflectionMode === 'gloss') {
        const floor = this._circularFloor;
        if (floor && floor.material && !this.isLowEnd) {
            floor.material.metalness = 0.5;
            floor.material.roughness = Math.min(floor.material.roughness ?? 0.22, 0.12);
            floor.material.needsUpdate = true;
        }
    }
}

function addCrystalCathedralColonnade(radius) {
    const rng = this._venueRng;
    const vc  = this._venueVisualConfig || {};

    const tint = parseColor(vc.colonnade_tint) || new THREE.Color(0xdfeaff);
    const glassMat = makeGlassMaterial(this, { tint, opacity: 0.4 });

    const COUNT      = 12;
    const HEIGHT_MIN = 9;
    const HEIGHT_MAX = 15;

    for (let i = 0; i < COUNT; i++) {
        const angle = (i / COUNT) * Math.PI * 2;
        const r = radius + 0.35 + (rng.next() - 0.5) * 0.5;
        const h = HEIGHT_MIN + rng.next() * (HEIGHT_MAX - HEIGHT_MIN);
        const pillarRadius = 0.3 + rng.next() * 0.18;

        const pillar = new THREE.Mesh(
            new THREE.CylinderGeometry(pillarRadius * 0.8, pillarRadius, h, 8, 1, true),
            glassMat
        );
        pillar.position.set(Math.sin(angle) * r, h / 2, Math.cos(angle) * r);
        this.scene.add(pillar);

        if (i % 3 === 0) {
            const colors = [0xffaaaa, 0xaaffaa, 0xaaaaff, 0xffffaa, 0xffaaff, 0xaaffff];
            const c = colors[i % colors.length];
            const light = new THREE.PointLight(c, 0.5, 8);
            light.position.copy(pillar.position);
            this.scene.add(light);

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

}

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

    const bandTilt = 0.96 + rng.next() * 0.12;  // ≈ 55–62° from horizontal
    const bandYaw  = rng.next() * Math.PI * 2;  // band azimuth, seeded per venue

    const archCrownAz = (() => {
        const v = new THREE.Vector3(0, 0, -1).applyEuler(new THREE.Euler(bandTilt, bandYaw, 0));
        return Math.atan2(v.x, v.z);
    })();

    const makeShell = (defs, dist, speed) => {
        const tilt = new THREE.Group();
        tilt.rotation.set(bandTilt, bandYaw, 0);
        for (const d of defs) {
            const tex = getMassTexture(d.color, d.size > dist * 0.6 ? 512 : 256);
            const mat = new THREE.SpriteMaterial({
                map: tex,
                rotation: (rng.next() - 0.5) * Math.PI * 2, // no two masses read identical
                transparent: true,
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
        this._particleSystems.push({ obj: tilt, type: 'drift-rotate', speed });
        return tilt;
    };

    const massTexCache = new Map();
    const getMassTexture = (color, size) => {
        const key = `${color.getHexString()}:${size}`;
        if (!massTexCache.has(key)) massTexCache.set(key, makeNebulaMassTexture(rng, color, size));
        return massTexCache.get(key);
    };

    const bandSprite = (shellR, sizeF, hF, span) => {
        const a = Math.PI + (rng.next() + rng.next() - 1) * span;
        const h = (rng.next() - 0.5) * 2 * hF * shellR;
        return {
            x: Math.sin(a) * shellR, y: h, z: Math.cos(a) * shellR,
            size: shellR * sizeF * (0.8 + rng.next() * 0.4),
            a,
        };
    };

    const crownWeight = (a, span) => {
        const t = Math.min(1, Math.abs(Math.PI - a) / Math.max(0.001, span));
        return 0.7 + 0.45 * (0.5 + 0.5 * Math.cos(t * Math.PI));
    };

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
            { ...bandSprite(midR, 0.42, 0.12, 0.55), color: ACCENT,    opacity: 0.7  },
            ...(isLowEnd ? [] : bandHaze(midR, 12, 0.62, 0.2, 0.28, 0.4, DOMINANT, SECONDARY, rng, 2.7)),
        ],
        midR, 0.0042, // slightly faster → layered parallax against the far shell
    );

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

    {
        const count = isLowEnd ? 3 : 4;
        const geo   = new THREE.OctahedronGeometry(1, 0);
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
        this._particleSystems.push({ obj: pts, type: 'void-drift', phase: 0 });
    }

    {
        const ringR = radius * 0.62 + 2;
        const ringGeo = new THREE.TorusGeometry(ringR, 0.055, 8, 160);
        {
            const arcs = [];
            const brightCount = 2 + Math.round(rng.next()); // 2–3 bright arcs
            for (let i = 0; i < brightCount; i++) {
                arcs.push({ c: rng.next(), w: 0.035 + rng.next() * 0.035, a: 1.1 + rng.next() * 0.25 });
            }
            arcs.push({ c: rng.next(), w: 0.1 + rng.next() * 0.08, a: -0.2 - rng.next() * 0.08 }); // the dim reach
            const base = 0.3 + rng.next() * 0.05;
            const pos = ringGeo.attributes.position;
            const lum = new Float32Array(pos.count * 3);
            for (let i = 0; i < pos.count; i++) {
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

function makeNebulaMassTexture(rng, color, size = 512) {
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    const base = (color && isColorLike(color)) ? color : new THREE.Color(0x5a4ae0);
    const css = (c, a) => `rgba(${Math.round(c.r * 255)},${Math.round(c.g * 255)},${Math.round(c.b * 255)},${a.toFixed(3)})`;
    const wash = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size * 0.5);
    wash.addColorStop(0, css(base.clone().offsetHSL(0, -0.04, 0.05), 0.5));
    wash.addColorStop(0.55, css(base, 0.28));
    wash.addColorStop(1, css(base, 0));
    ctx.fillStyle = wash;
    ctx.fillRect(0, 0, size, size);
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


function addNebulaDriftStructure(radius) {
    const coherent = true;

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
        addPlanarReflection(this, radius, { color: 0xaab4c8, resolution: 1024 });
    } else if (mode === 'gloss') {
        const floor = this._circularFloor;
        if (floor && floor.material && !this.isLowEnd) {
            floor.material.metalness = 0.65;
            floor.material.roughness = 0.1;
            floor.material.needsUpdate = true;
        }
        addMoonLightStreak(this, radius, moon.position.clone());
    }

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
        if (!floor.material.map && typeof document !== 'undefined') {
            floor.material.map = makeLawnDetailTexture();
            floor.material.map.repeat.set(10, 10);
            floor.material.needsUpdate = true;
        }
        if (floor.material.roughness !== undefined) floor.material.roughness = 1;
        if (floor.material.metalness !== undefined) floor.material.metalness = 0;
    }

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

    const fogColor = parseColor(vc.fog_color) || new THREE.Color(0x0f1726);
    const fogNear = Number(vc.fog_near) || 20;
    const fogFar = Number(vc.fog_far) || 64;
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
        this._particleSystems = this._particleSystems || [];
        this._particleSystems.push({ obj: reflector, type: 'void-drift' });
    } else {
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

    if (!highFx) {
        addMoonLightStreak(this, R, moonDir, { color: 0xa8bcd8, opacity: 0.2, y: waterLevel + 0.012 });
    }

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
    const railZ1 = pier.footZ + 0.6;                    // start just past the bank
    const railZ0 = pav.z + pav.size / 2 + 0.1;          // stop at the pavilion's south edge
    const railLen = railZ1 - railZ0;
    const railMidZ = (railZ0 + railZ1) / 2;
    {
        const deckZ0 = pav.z - pav.size / 2 - 0.2;
        const deckZ1 = pier.footZ + 0.8;               // bites into the bank
        const len = deckZ1 - deckZ0;
        const parts = [];
        const deck = new THREE.BoxGeometry(pier.width, 0.1, len);
        parts.push(deck);
        // posts to the bed + rail stanchions (stanchions on the railed span)
        const nBays = Math.max(2, Math.round(railLen / 2.4));
        for (let i = 0; i <= nBays; i++) {
            const z = railZ1 - (railLen * i) / nBays;
            for (const sx of [-1, 1]) {
                const post = new THREE.CylinderGeometry(0.075, 0.085, 1.25, 8);
                post.translate(sx * (pier.width / 2 - 0.14), -0.55, z);
                parts.push(post);
                const rst = new THREE.BoxGeometry(0.05, 0.62, 0.05);
                rst.translate(sx * (pier.width / 2 - 0.05), 0.36, z);
                parts.push(rst);
            }
        }
        const nUnder = Math.max(1, Math.round((deckZ1 - railZ1 + (railZ0 - deckZ0)) / 2.4));
        for (let i = 0; i <= nUnder; i++) {
            const z = deckZ1 - ((deckZ1 - deckZ0) * i) / (nUnder + 1);
            if (z < railZ1 && z > railZ0) continue;     // already stanchioned above
            for (const sx of [-1, 1]) {
                const post = new THREE.CylinderGeometry(0.075, 0.085, 1.25, 8);
                post.translate(sx * (pier.width / 2 - 0.14), -0.55, z);
                parts.push(post);
            }
        }
        const pierMesh = new THREE.Mesh(mergeGeometries(parts, false), timberMat);
        pierMesh.castShadow = false;
        pierMesh.receiveShadow = highFx;
        pierGroup.add(pierMesh);
        // rails — dark steel, two runs, stopping at the pavilion deck
        const railParts = [];
        for (const sx of [-1, 1]) {
            const rail = new THREE.BoxGeometry(0.045, 0.045, railLen);
            rail.translate(sx * (pier.width / 2 - 0.05), 0.64, railMidZ);
            railParts.push(rail);
        }
        const railMesh = new THREE.Mesh(mergeGeometries(railParts, false), steelMat);
        pierGroup.add(railMesh);
        pierGroup.position.set(pier.x, 0, 0);
        this.scene.add(pierGroup);
        const proxyMat = new THREE.MeshBasicMaterial({ visible: false });
        for (const sx of [-1, 1]) {
            const railProxy = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.9, railLen), proxyMat);
            railProxy.position.set(sx * (pier.width / 2 - 0.05), 0.5, railMidZ);
            pierGroup.add(railProxy);
            railProxy.updateWorldMatrix(true, false);
            this.registerObstacle(railProxy, 0.25);
        }
    }

    const pavGroup = new THREE.Group();
    {
        const s = pav.size;
        const parts = [];
        const deck = new THREE.BoxGeometry(s, 0.14, s);
        deck.translate(0, pav.deckY - 0.07, 0);
        parts.push(deck);
        for (const cx of [-1, 1]) for (const cz of [-1, 1]) {
            const post = new THREE.BoxGeometry(0.09, 2.85, 0.09);
            post.translate(cx * (s / 2 - 0.22), pav.deckY + 1.425, cz * (s / 2 - 0.22));
            parts.push(post);
        }
        // an interior bench facing the view — seat resting on its skids
        const seat = new THREE.BoxGeometry(1.7, 0.055, 0.42);
        seat.translate(-s / 2 + 0.85, pav.deckY + 0.3125, 0);
        parts.push(seat);
        for (const bz of [-0.14, 0.14]) {
            const skid = new THREE.BoxGeometry(1.6, 0.05, 0.07);
            skid.translate(-s / 2 + 0.85, pav.deckY + 0.26, bz);
            parts.push(skid);
        }
        const body = new THREE.Mesh(mergeGeometries(parts, false), timberMat);
        body.receiveShadow = highFx;
        pavGroup.add(body);

        const roof = new THREE.Mesh(new THREE.BoxGeometry(s + 0.8, 0.1, s + 0.8), timberMat);
        roof.position.y = pav.deckY + 2.9;
        pavGroup.add(roof);
        const fascia = new THREE.Mesh(new THREE.BoxGeometry(s + 0.72, 0.06, s + 0.72), steelMat);
        fascia.position.y = pav.deckY + 2.82;
        pavGroup.add(fascia);
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

    const halfPier = 0.9;                       // rail inner face ≈ 0.74 + margin
    const halfPav = pav.size / 2 + 0.25;        // deck edge + player radius
    const pierZ0 = pav.z - pav.size / 2 - 0.2 + 0.05;   // deck north rim
    const pierZ1 = pier.footZ + 0.5;                    // bank-side apron
    const pavCos = Math.cos(pav.yaw);
    const pavSin = Math.sin(pav.yaw);
    const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
    this._lakeRegion = null;
    this._lakeSafe = null;
    this._lakeTick = function lakeShoreClamp() {
        if (this.arrivalActive || this.isInspecting || this._cameraScripted) return;
        const pos = this.camera.position;
        const px = pos.x, pz = pos.z;

        // Pier rectangle (axis-aligned — the pier runs along z).
        const onPier = Math.abs(px - pier.x) < halfPier && pz < pierZ1 && pz > pierZ0;
        // Pavilion rectangle in the pavilion's local (rotated) frame.
        const pdx = px - pav.x, pdz = pz - pav.z;
        const plx = pdx * pavCos - pdz * pavSin;
        const plz = pdx * pavSin + pdz * pavCos;
        const inPavilion = Math.abs(plx) < halfPav && Math.abs(plz) < halfPav;

        if (onPier || inPavilion) {
            this._lakeRegion = onPier ? 'pier' : 'pavilion';
            this._lakeSafe = { x: px, z: pz };
            return;
        }
        const edge = plan.shoreZ(px) - 0.15;    // toes at the waterline
        if (pz >= edge) {                        // on land
            this._lakeRegion = 'land';
            this._lakeSafe = { x: px, z: pz };
            return;
        }
        const region = this._lakeRegion;
        if (region === 'pier') {
            pos.x = clamp(px, pier.x - halfPier + 0.01, pier.x + halfPier - 0.01);
            pos.z = clamp(pz, pierZ0 + 0.01, pierZ1 - 0.01);
            return;
        }
        if (region === 'pavilion') {
            const nlx = clamp(plx, -halfPav + 0.01, halfPav - 0.01);
            const nlz = clamp(plz, -halfPav + 0.01, halfPav - 0.01);
            pos.x = pav.x + nlx * pavCos + nlz * pavSin;
            pos.z = pav.z - nlx * pavSin + nlz * pavCos;
            return;
        }
        // Walked into the lake from the land — the designed shoreline slide.
        pos.z = edge;
    };
    this._settleCamera = () => {
        const pos = this.camera.position;
        const onPier = Math.abs(pos.x - pier.x) < halfPier && pos.z < pierZ1 && pos.z > pierZ0;
        const pdx = pos.x - pav.x, pdz = pos.z - pav.z;
        const plx = pdx * pavCos - pdz * pavSin;
        const plz = pdx * pavSin + pdz * pavCos;
        const inPavilion = Math.abs(plx) < halfPav && Math.abs(plz) < halfPav;
        if (onPier || inPavilion || pos.z >= plan.shoreZ(pos.x) - 0.15) {
            this._lakeRegion = onPier ? 'pier' : (inPavilion ? 'pavilion' : 'land');
            this._lakeSafe = { x: pos.x, z: pos.z };
            return;                                 // already legal — adopt it
        }
        const zTo = plan.shoreZ(pos.x) - 0.15;
        if (this.reducedMotion) {
            pos.z = zTo;
        } else {
            gsap.to(pos, { z: zTo, duration: 0.6, ease: 'power2.out' });
        }
        this._lakeRegion = 'land';
        this._lakeSafe = { x: pos.x, z: zTo };
    };

    const farFloor = Math.ceil(domeR * 2.2);
    if (this.camera.far < farFloor) this.camera.far = farFloor;
}

function buildMediaWall(ctx, spec) {
    const bezelId = typeof spec.bezel === 'string' && spec.bezel ? spec.bezel : 'media-bezel';
    const bezel = ctx.scene.getObjectByName(`structure:${bezelId}`);
    if (!bezel?.isMesh) {
        console.warn(`[exospace] media_wall: bezel mesh 'structure:${bezelId}' not found — screen skipped.`);
        return;
    }

    const sw = Number(spec.screen?.w) || 1.5;
    const sh = Number(spec.screen?.h) || 0.84;
    const accent = parseColor(spec.accent || '0xd8a35a') || new THREE.Color(0xd8a35a);

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

    const mat = new THREE.MeshBasicMaterial({ map: tex, toneMapped: true });
    const screen = new THREE.Mesh(new THREE.PlaneGeometry(sw, sh), mat);
    const bezelDepth = bezel.geometry?.parameters?.depth ?? 0.08;
    screen.position.set(0, 0, bezelDepth / 2 + 0.004);
    bezel.add(screen);

    bezel.updateWorldMatrix(true, false);
    const faceNormal = new THREE.Vector3(0, 0, 1)
        .applyQuaternion(bezel.getWorldQuaternion(new THREE.Quaternion()));
    const centre = new THREE.Box3().setFromObject(ctx.scene).getCenter(new THREE.Vector3());
    const bezelPos = bezel.getWorldPosition(new THREE.Vector3());
    if (faceNormal.dot(centre.sub(bezelPos)) < 0) {
        screen.rotation.y = Math.PI;
    }

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
