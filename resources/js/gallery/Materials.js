import * as THREE from 'three';
import { MATERIAL_PRESETS, FRAME_STYLES, TEXTURE_PATHS, parseColor } from './config.js';
import { mergeParts } from './GeometryUtils.js';

const _textureCache = new Map();

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

    const lightingConfig = this.lightingConfig || { envIntensity: 1.0 };
    const envIntensity = this._venueEnvIntensity ?? lightingConfig.envIntensity ?? 1.0;

    const dir = TEXTURE_PATHS.floors[type];
    const cached = dir ? _textureCache.get(dir) : null;

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

export async function preloadMaterialTextures(loader, wallType, floorType) {
    const wallDir  = TEXTURE_PATHS.walls[wallType];
    const floorDir = TEXTURE_PATHS.floors[floorType];
    await Promise.all([
        wallDir  ? loadPbrSet(loader, wallDir)  : Promise.resolve(),
        floorDir ? loadPbrSet(loader, floorDir) : Promise.resolve(),
    ]);
}

export function createFrame(width, height, style) {
    const frameDepth = 0.08;
    const frameWidth = 0.10;

    const effectiveStyle = this._venueFrameStyleOverride || style;
    const styleProps     = FRAME_STYLES[effectiveStyle] || FRAME_STYLES.modern;

    const lightingConfig = this.lightingConfig || { envIntensity: 1.0 };
    const frameEnvIntensity = this._venueEnvIntensity ?? lightingConfig.envIntensity ?? 1.0;

    const frameMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: styleProps.color })
        : new THREE.MeshStandardMaterial({
            color: styleProps.color,
            roughness: styleProps.roughness,
            metalness: styleProps.metalness,
            envMapIntensity: 1.5 * frameEnvIntensity,
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
