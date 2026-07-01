// ─────────────────────────────────────────────────────────────────────────────
// AssetLoader — textures, HDRIs, GLBs (with DRACO + KTX2), audio
//
// Key differences from the old code:
//   - DRACOLoader is registered once on the GLTFLoader so compressed GLBs work
//   - KTX2Loader is registered so compressed textures work (future-proofing)
//   - All loaders come from three/addons — bundled by Vite, no CDN
//   - Material PBR sets are preloaded so Materials.js can read them synchronously
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { GLTFLoader }      from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader }     from 'three/addons/loaders/DRACOLoader.js';
import { KTX2Loader }      from 'three/addons/loaders/KTX2Loader.js';
// Three.js r163+ deprecated RGBELoader in favour of HDRLoader. The file is
// still at examples/jsm/loaders/RGBELoader.js but the class emits a console
// warning. We import via namespace and pick whichever export exists — this
// silences the deprecation warning without breaking on older three versions.
import * as _HDRLoaderModule from 'three/addons/loaders/RGBELoader.js';
const _HDRLoader = _HDRLoaderModule.HDRLoader || _HDRLoaderModule.RGBELoader;
import { CONFIG } from './config.js';
import { preloadMaterialTextures } from './Materials.js';

// Singleton loaders (created lazily on first use)
let _gltfLoader  = null;
let _dracoLoader = null;
let _ktx2Loader  = null;

function getDracoLoader(renderer) {
    if (!_dracoLoader) {
        _dracoLoader = new DRACOLoader();
        // Decoder wasm files live in /decoders/ — copied by Vite plugin or
        // manually placed from node_modules/three/examples/jsm/libs/draco/.
        _dracoLoader.setDecoderPath('/decoders/');
        _dracoLoader.setDecoderConfig({ type: 'js' });
    }
    return _dracoLoader;
}

function getKtx2Loader(renderer) {
    if (!_ktx2Loader) {
        _ktx2Loader = new KTX2Loader();
        _ktx2Loader.setTranscoderPath('/decoders/basis/');
        if (renderer) _ktx2Loader.detectSupport(renderer);
    }
    return _ktx2Loader;
}

export function getGltfLoader() {
    if (!_gltfLoader) {
        _gltfLoader = new GLTFLoader();
        _gltfLoader.setDRACOLoader(getDracoLoader(this?.renderer));
        // KTX2 support — only enable if renderer is available (it always is
        // by the time GLBs load, but be defensive)
        if (this?.renderer) {
            _gltfLoader.setKTX2Loader(getKtx2Loader(this.renderer));
        }
    }
    return _gltfLoader;
}

// ── Main asset load — runs on GalleryScene boot ──────────────────────────────
export async function loadAssets() {
    const textureLoader = new THREE.TextureLoader();
    const data = window.GALLERY_DATA;

    // Lighting preset (needed early to pick the right HDRI)
    const preset = data.lighting_preset || 'bright';
    this.lightingPreset = preset;
    this.lightingConfig = CONFIG.lighting[preset] || CONFIG.lighting.bright;

    this.updateProgress(5, 'Initializing textures...');

    try {
        const safeAnisotropy = this._maxAnisotropy !== undefined
            ? this._maxAnisotropy
            : Math.min(this.renderer.capabilities.getMaxAnisotropy(), 4);

        const configureTexture = (tex) => {
            tex.colorSpace      = THREE.SRGBColorSpace;
            tex.generateMipmaps = !this.isLowEnd;
            tex.anisotropy      = safeAnisotropy;
            return tex;
        };

        // ── Preload PBR sets for the gallery's wall + floor types ────────────
        this.updateProgress(10, 'Loading wall + floor materials...');
        await preloadMaterialTextures(textureLoader, data.wall_texture, data.floor_material);

        // ── Canvas normal map (tactile art surface) — high-end only ──────────
        if (!this.isLowEnd) {
            await new Promise(resolve => {
                textureLoader.load('/assets/textures/shared/canvas_normal.jpg', (tex) => {
                    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
                    this.textures.canvasNormal = tex;
                    resolve();
                }, undefined, () => resolve());
            });
        }

        // ── Load artworks ────────────────────────────────────────────────────
        this.updateProgress(30, 'Loading artwork...');
        this.artworkImages = [];

        const artworkPromises = data.images.map((img, index) => {
            return new Promise(resolve => {
                textureLoader.load(
                    img.url,
                    (texture) => {
                        texture.colorSpace      = THREE.SRGBColorSpace;
                        texture.generateMipmaps = !this.isLowEnd;
                        texture.anisotropy      = safeAnisotropy;

                        const aspectRatio = img.aspectRatio ||
                            (texture.image.width / texture.image.height) || 1;

                        this.artworkImages.push({
                            id: img.id,
                            texture,
                            aspectRatio,
                            title: img.title,
                            description: img.description,
                            ...img, // keep all metadata (artist, price, medium, etc.)
                        });

                        const percent = 30 + ((index + 1) / data.images.length) * 60;
                        this.updateProgress(percent, `Loading artwork ${index + 1}/${data.images.length}`);
                        resolve();
                    },
                    undefined,
                    () => resolve() // skip failed images silently
                );
            });
        });
        await Promise.all(artworkPromises);

        // ── Build the room ───────────────────────────────────────────────────
        this.updateProgress(95, 'Building gallery...');
        this.buildGallery();

        this.updateProgress(100, 'Complete!');
        setTimeout(() => this.hideLoader(), 500);
    } catch (error) {
        console.error('Critical asset loading error:', error);
        this.hideLoader();
    }
}

// ── HDRI environment map — non-blocking, fades in after room renders ─────────
export function loadEnvironmentMap() {
    if (this._skipHdri) return; // low-end: skip the 10MB HDRI

    const preset = this.lightingPreset || 'bright';
    const lightingConfig = CONFIG.lighting[preset] || CONFIG.lighting.bright;
    const hdriPath = this._customHdriUrl || lightingConfig.hdri;
    if (!hdriPath) return;

    const rgbeLoader = new _HDRLoader();
    rgbeLoader.load(
        hdriPath,
        (texture) => {
            texture.mapping = THREE.EquirectangularReflectionMapping;
            this.scene.environment = texture;
            if (lightingConfig.envIntensity !== undefined) {
                this.scene.environmentIntensity = lightingConfig.envIntensity;
            }
            if (lightingConfig.toneMappingExposure !== undefined) {
                this.renderer.toneMappingExposure = lightingConfig.toneMappingExposure;
            }
        },
        undefined,
        () => { /* silent fail — standard lights cover this */ }
    );
}

// ── GLB decoration loader — used by VenueDecorator.loadDecorations ───────────
export async function loadGlb(url) {
    const loader = getGltfLoader.call(this);
    const gltf = await loader.loadAsync(url);
    return gltf.scene;
}
