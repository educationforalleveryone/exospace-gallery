// ─────────────────────────────────────────────────────────────────────────────
// Materials — wall / floor / frame material factories
//
// Each factory reads from the venue's material_config (if set) so admins can
// tune roughness/metalness/normal-strength per venue without touching code.
// All materials now use PBR (MeshStandardMaterial) on high-end with optional
// normal + roughness + AO maps — the old code only loaded a diffuse map.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { MATERIAL_PRESETS, FRAME_STYLES, TEXTURE_PATHS, parseColor } from './config.js';
import { mergeParts } from './GeometryUtils.js';

// Cache loaded textures per material directory so we don't re-fetch the same
// PBR set when a venue uses the same material twice (e.g. wall + ceiling).
const _textureCache = new Map();

// Load all available PBR maps for a material directory.
// Returns { map, normalMap, roughnessMap, aoMap } — any missing file is null.
async function loadPbrSet(loader, dirPath) {
    if (_textureCache.has(dirPath)) return _textureCache.get(dirPath);

    const files = ['color.jpg', 'normal.jpg', 'roughness.jpg', 'ao.jpg'];
    const result = { map: null, normalMap: null, roughnessMap: null, aoMap: null };

    await Promise.all(files.map(file => new Promise(resolve => {
        loader.load(
            `${dirPath}/${file}`,
            tex => {
                tex.colorSpace = (file === 'color.jpg') ? THREE.SRGBColorSpace : THREE.NoColorSpace;
                tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
                // POST-DEPLOY HOTFIX (2026-09-05): pin aoMap to UV channel 0.
                // ao.jpg files now ship for walls/white + floors/marble (the
                // production 404s); room geometry only has the `uv` attribute,
                // and the default aoMap channel is version-dependent across
                // three releases. channel 0 = sample `uv` — guaranteed correct
                // tiling with the diffuse map, no uv1 plumbing needed.
                if (file === 'ao.jpg') tex.channel = 0;
                if (file === 'normal.jpg')      result.normalMap     = tex;
                else if (file === 'roughness.jpg') result.roughnessMap = tex;
                else if (file === 'ao.jpg')      result.aoMap         = tex;
                else                              result.map           = tex;
                resolve();
            },
            undefined,
            () => resolve() // file missing — skip silently
        );
    })));

    _textureCache.set(dirPath, result);
    return result;
}

// Wall material factory — checks venue material_config for overrides
export function getWallMaterial(type) {
    const presets = MATERIAL_PRESETS.walls;
    const preset  = presets[type] || presets.white;

    // Apply venue-level overrides from the JSON config
    const vc = this._venueMaterialConfig;
    const color          = vc?.wall_color          ? parseColor(vc.wall_color)         : new THREE.Color(preset.color);
    const roughness      = vc?.wall_roughness      ?? preset.roughness;
    const metalness      = vc?.wall_metalness      ?? preset.metalness;
    const normalStrength = vc?.wall_normal_strength ?? preset.normalStrength;

    // Low-end: drop PBR + textures, use Lambert with a flat colour
    if (this.isLowEnd) {
        return new THREE.MeshLambertMaterial({ color, side: THREE.FrontSide });
    }

    const dir = TEXTURE_PATHS.walls[type];
    const cached = dir ? _textureCache.get(dir) : null;
    if (!cached || !cached.map) {
        // No texture available — flat colour PBR
        return new THREE.MeshStandardMaterial({
            color, roughness, metalness, side: THREE.FrontSide,
        });
    }

    // ── Venue color authority over textures (config root-cause fix) ──────
    // The old path replaced the venue's declared colour with 0xffffff the
    // moment a texture existed, so a venue declaring wall_color was silently
    // ignored on every textured build (and matched its own no-texture
    // fallback only in sandboxes). material_config.texture_tint = true
    // opts the venue into "my declared colours are authoritative" — the map
    // is then tinted by the declared colour instead of overriding it.
    // Venues that do not declare the flag keep the historical 0xffffff
    // behaviour (Modern White Cube renders unchanged).
    const tinted = vc?.texture_tint === true;
    const matColor = tinted ? color : 0xffffff;

    const mat = new THREE.MeshStandardMaterial({
        color: matColor,
        map: cached.map.clone(),
        normalMap: cached.normalMap ? cached.normalMap.clone() : null,
        roughnessMap: cached.roughnessMap ? cached.roughnessMap.clone() : null,
        aoMap: cached.aoMap ? cached.aoMap.clone() : null,
        roughness,
        metalness,
        normalScale: new THREE.Vector2(normalStrength, normalStrength),
        side: THREE.FrontSide,
    });
    // Mark cloned textures for update
    mat.map.needsUpdate = true;
    if (mat.normalMap)     mat.normalMap.needsUpdate = true;
    if (mat.roughnessMap)  mat.roughnessMap.needsUpdate = true;
    if (mat.aoMap)         mat.aoMap.needsUpdate = true;
    return mat;
}

// Floor material factory — same pattern as walls
export function getFloorMaterial(type) {
    const presets = MATERIAL_PRESETS.floors;
    const preset  = presets[type] || presets.wood;

    const vc = this._venueMaterialConfig;
    const color          = vc?.floor_color          ? parseColor(vc.floor_color)        : new THREE.Color(preset.color);
    const roughness      = vc?.floor_roughness      ?? preset.roughness;
    const metalness      = vc?.floor_metalness      ?? preset.metalness;
    const normalStrength = vc?.floor_normal_strength ?? preset.normalStrength;

    if (this.isLowEnd) {
        return new THREE.MeshLambertMaterial({ color });
    }

    // ── ENVIRONMENT STRENGTH AUTHORITY (s4) ──────────────────────────────
    // The venue's declared env_intensity wins; the resolved preset only
    // fills in. Reading the PRESET alone is what reflected a bright studio
    // sky into the Dark Museum's polished dark stone at 2.1× its declared
    // strength (0.18 effective vs the declared 0.14 × 0.6 = 0.084) — the
    // "cloudy sheen on the floor" half of the deployed incident. The
    // nullish coalescing keeps a DECLARED 0 at 0 (silenced environment is
    // identity, not "use the preset").
    const lightingConfig = this.lightingConfig || { envIntensity: 1.0 };
    const envIntensity = this._venueEnvIntensity ?? lightingConfig.envIntensity ?? 1.0;

    const dir = TEXTURE_PATHS.floors[type];
    const cached = dir ? _textureCache.get(dir) : null;

    // Same venue-colour authority rule as the walls above: without the
    // texture_tint opt-in a declared floor_color never reached textured
    // builds (Infinite Void shipped a bright marble floor in production
    // while its config declared 0x0a0a0a — the preview/product split).
    const tinted = vc?.texture_tint === true;

    const matProps = {
        color: cached?.map ? (tinted ? color : 0xffffff) : color,
        roughness,
        metalness,
        envMapIntensity: 0.6 * envIntensity, // floor reflects HDRI subtly
        normalScale: new THREE.Vector2(normalStrength, normalStrength),
    };

    if (cached?.map) {
        matProps.map          = cached.map.clone();          matProps.map.needsUpdate = true;
        if (cached.normalMap)    { matProps.normalMap    = cached.normalMap.clone();    matProps.normalMap.needsUpdate = true; }
        if (cached.roughnessMap) { matProps.roughnessMap = cached.roughnessMap.clone(); matProps.roughnessMap.needsUpdate = true; }
        if (cached.aoMap)        { matProps.aoMap        = cached.aoMap.clone();        matProps.aoMap.needsUpdate = true; }
    }

    return new THREE.MeshStandardMaterial(matProps);
}

// Preload PBR sets for the venue's wall + floor types.
// Called from AssetLoader.loadAssets() before room build.
export async function preloadMaterialTextures(loader, wallType, floorType) {
    const wallDir  = TEXTURE_PATHS.walls[wallType];
    const floorDir = TEXTURE_PATHS.floors[floorType];
    await Promise.all([
        wallDir  ? loadPbrSet(loader, wallDir)  : Promise.resolve(),
        floorDir ? loadPbrSet(loader, floorDir) : Promise.resolve(),
    ]);
}

// Frame factory — a single merged mesh framing the artwork.
// Frame style can be overridden per venue (e.g. dark museum → gold).
//
// PERF-D21 (3D audit F21): the frame used to be a Group of 4 scaled unit-box
// Meshes = 4 draw calls per artwork (120 draw calls for a 30-piece gallery,
// before even counting the canvases). The four pieces now merge into ONE
// geometry at build time — visually identical, one draw call per frame.
//
// PERF-D23: the material carries an emissive channel locked at intensity 0.
// FocusMode tweens emissiveIntensity when the visitor aims at an artwork —
// premium focus feedback for zero per-frame cost when idle.
export function createFrame(width, height, style) {
    const frameDepth = 0.08;
    const frameWidth = 0.10;

    // Venue override (e.g. dark museum → gold frames).
    // ── Iteration 6 (P2.2): the slug-keyed venueFrameOverride map is DELETED.
    // Frame style flows through visual_config.frame_override (the seeder has
    // carried it for every override venue since Iteration 0 — the map was
    // config-shadowed dead code). Admin-created venues declare their own.
    const effectiveStyle = this._venueFrameOverride || style;
    const styleProps     = FRAME_STYLES[effectiveStyle] || FRAME_STYLES.modern;

    // s4: venue-declared env_intensity wins over the preset (the frame
    // reflected the gallery's stale preset sky at 3.2× the museum's
    // declared strength — same root cause as the floor).
    const lightingConfig = this.lightingConfig || { envIntensity: 1.0 };
    const frameEnvIntensity = this._venueEnvIntensity ?? lightingConfig.envIntensity ?? 1.0;

    const frameMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: styleProps.color })
        : new THREE.MeshStandardMaterial({
            color: styleProps.color,
            roughness: styleProps.roughness,
            metalness: styleProps.metalness,
            envMapIntensity: 1.5 * frameEnvIntensity,
            // PERF-D23: highlight-ready emissive (0 until focused — see
            // FocusMode._setFrameHighlight)
            emissive: new THREE.Color(styleProps.color),
            emissiveIntensity: 0,
        });

    const pieces = [
        { geo: new THREE.BoxGeometry(width + frameWidth * 2, frameWidth, frameDepth), pos: [0,  height / 2 + frameWidth / 2, 0] },
        { geo: new THREE.BoxGeometry(width + frameWidth * 2, frameWidth, frameDepth), pos: [0, -height / 2 - frameWidth / 2, 0] },
        { geo: new THREE.BoxGeometry(frameWidth, height, frameDepth), pos: [-width / 2 - frameWidth / 2, 0, 0] },
        { geo: new THREE.BoxGeometry(frameWidth, height, frameDepth), pos: [ width / 2 + frameWidth / 2, 0, 0] },
    ];
    const frameGeo = mergeParts(pieces);
    pieces.forEach(p => p.geo.dispose());

    const frame = new THREE.Mesh(frameGeo, frameMat);
    frame.name = 'artwork-frame';
    frame.castShadow = !this.isLowEnd;

    return frame;
}

// Exposed so AssetLoader can populate the texture cache before room build
export { loadPbrSet };
