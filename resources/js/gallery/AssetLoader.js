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
        // PERF-5 FIX: Limit concurrent texture loads to 6 (browser HTTP/2
        // connection cap per origin). Previously, a 100-image gallery fired
        // 100 parallel TextureLoader.load() calls — each allocating an Image
        // element + decode pipeline. Now uses a simple semaphore.
        this.updateProgress(30, 'Loading artwork...');
        this.artworkImages = [];

        const MAX_CONCURRENT = 6;
        let loadIndex = 0;
        let completedCount = 0;
        const totalImages = data.images.length;

        const loadNext = () => {
            return new Promise(resolve => {
                if (loadIndex >= totalImages) { resolve(); return; }
                const img = data.images[loadIndex++];
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
                            ...img,
                        });

                        completedCount++;
                        const percent = 30 + (completedCount / totalImages) * 60;
                        this.updateProgress(percent, `Loading artwork ${completedCount}/${totalImages}`);
                        resolve();
                    },
                    undefined,
                    () => { completedCount++; resolve(); } // skip failed
                );
            });
        };

        // Launch MAX_CONCURRENT workers that pull from the queue
        const workers = [];
        for (let i = 0; i < Math.min(MAX_CONCURRENT, totalImages); i++) {
            workers.push((async () => {
                while (loadIndex < totalImages) {
                    await loadNext();
                }
            })());
        }
        await Promise.all(workers);

        // ── Build the room ───────────────────────────────────────────────────
        this.updateProgress(95, 'Building gallery...');
        this.buildGallery();

        this.updateProgress(100, 'Complete!');
        setTimeout(() => this.hideLoader(), 500);
    } catch (error) {
        console.error('Critical asset loading error:', error);
        // UX-1 FIX: Show error UI instead of freezing the curtain.
        // Previously, hideLoader() was a no-op (just console.log) — the
        // curtain stayed at whatever % it last updated, Enter button stayed
        // disabled, visitor was stuck. Now we show a proper error overlay.
        this.showLoadError(error);
    }
}

// UX-1: Show a load-error overlay with retry button.
// Replaces the silent freeze that left visitors stuck.
export function showLoadError(error) {
    const curtain = document.getElementById('entrance-curtain');
    if (!curtain) return;

    const errorHtml = `
        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(10,10,15,0.95);z-index:1000;text-align:center;padding:2rem;">
            <div style="width:56px;height:56px;margin-bottom:1.5rem;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <h2 style="color:#f1f5f9;font-size:1.4rem;font-weight:700;margin-bottom:0.5rem;">Gallery failed to load</h2>
            <p style="color:#94a3b8;font-size:0.9rem;max-width:360px;line-height:1.6;margin-bottom:1.5rem;">
                We couldn't load this 3D exhibition. This might be a temporary issue — please try again.
            </p>
            <button onclick="window.location.reload()" style="padding:0.75rem 2rem;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:white;border:none;border-radius:0.5rem;font-weight:600;font-size:0.9rem;cursor:pointer;transition:all 0.2s;">
                Retry
            </button>
            <a href="/discover" style="margin-top:1rem;color:#64748b;font-size:0.8rem;text-decoration:underline;">Browse other galleries</a>
        </div>
    `;

    curtain.innerHTML = errorHtml;
    curtain.style.display = 'flex';
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
