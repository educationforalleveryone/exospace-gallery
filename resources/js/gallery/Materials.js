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

    const mat = new THREE.MeshStandardMaterial({
        color: 0xffffff,
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

    const lightingConfig = this.lightingConfig || { envIntensity: 1.0 };
    const envIntensity = lightingConfig.envIntensity || 1.0;

    const dir = TEXTURE_PATHS.floors[type];
    const cached = dir ? _textureCache.get(dir) : null;

    const matProps = {
        color: cached?.map ? 0xffffff : color,
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

    // Venue override (e.g. luxury-penthouse + dark-museum → gold)
    const venueFrameOverride = {
        'luxury-penthouse': 'gold',
        'dark-museum':      'gold',
    };
    const effectiveStyle = this._venueFrameOverride || venueFrameOverride[this._venueSlug] || style;
    const styleProps     = FRAME_STYLES[effectiveStyle] || FRAME_STYLES.modern;

    const lightingConfig = this.lightingConfig || { envIntensity: 1.0 };

    const frameMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: styleProps.color })
        : new THREE.MeshStandardMaterial({
            color: styleProps.color,
            roughness: styleProps.roughness,
            metalness: styleProps.metalness,
            envMapIntensity: 1.5 * (lightingConfig.envIntensity || 1.0),
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
