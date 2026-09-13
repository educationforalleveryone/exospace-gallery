import * as THREE from 'three';
import { GLTFLoader }      from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader }     from 'three/addons/loaders/DRACOLoader.js';
import { KTX2Loader }      from 'three/addons/loaders/KTX2Loader.js';
import * as _HDRLoaderModule from 'three/addons/loaders/HDRLoader.js';
import * as _RGBELoaderModule from 'three/addons/loaders/RGBELoader.js';
const _HDRLoader = _HDRLoaderModule.HDRLoader || _HDRLoaderModule.RGBELoader || _RGBELoaderModule.RGBELoader;
import { CONFIG } from './config.js';
import { preloadMaterialTextures } from './Materials.js';

// Singleton loaders (created lazily on first use)
let _gltfLoader  = null;
let _dracoLoader = null;
let _ktx2Loader  = null;

let _bitmapLoader = null;
function createArtworkLoader() {
    if (typeof createImageBitmap !== 'undefined' && typeof fetch !== 'undefined') {
        _bitmapLoader = new THREE.ImageBitmapLoader();
        // Default imageOrientation is 'none' — no pixel-level flip baked in.
        return _bitmapLoader;
    }
    return new THREE.TextureLoader();
}

function flipTextureVertically(tex) {
    tex.center.set(0.5, 0.5);
    tex.repeat.set(1, -1);
    tex.needsUpdate = true;
}

function loadArtworkTexture(loader, url, onLoad, onError) {
    if (loader instanceof THREE.ImageBitmapLoader) {
        loader.load(url, (bitmap) => {
            const tex = new THREE.Texture(bitmap);
            flipTextureVertically(tex);
            onLoad(tex);
        }, undefined, onError);
    } else {
        loader.load(url, onLoad, undefined, onError);
    }
}

function getDracoLoader(renderer) {
    if (!_dracoLoader) {
        _dracoLoader = new DRACOLoader();
        _dracoLoader.setDecoderPath('/decoders/draco/');
        _dracoLoader.setDecoderConfig({ type: 'wasm' });
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
        if (this?.renderer) {
            _gltfLoader.setKTX2Loader(getKtx2Loader(this.renderer));
        }
    }
    return _gltfLoader;
}

export function pickTextureUrl(img, scene) {
    const t = img.textures;
    if (!t) return img.url; // legacy payload (galleries created before texture variants existed)
    if (scene.isLowEnd)  return t.small  || t.medium || t.large || img.url;
    if (scene.isMobile)   return t.medium || t.large  || t.small || img.url;
    return t.large || t.medium || t.small || img.url;
}

export async function loadAssets() {
    const textureLoader = new THREE.TextureLoader();
    const artworkLoader = createArtworkLoader();
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

        const totalImages = data.images.length;

        this.artworkImages = data.images.map(img => ({
            texture: null,      // full-quality variant — filled on arrival
            thumbTexture: null, // blur-up placeholder (desktop high-end)
            aspectRatio: img.aspectRatio || (img.width && img.height ? img.width / img.height : 1),
            ...img,              // backend fields: id, url, textures, metadata…
        }));

        const deepLinkArtworkId = data.deepLinkArtworkId;
        const order = data.images.map((_, i) => i);
        if (deepLinkArtworkId) {
            const di = order.find(i => data.images[i].id === deepLinkArtworkId);
            if (di !== undefined && di > 0) {
                order.splice(order.indexOf(di), 1);
                order.unshift(di);
            }
        }

        const MAX_CONCURRENT = 6;  // browser HTTP/2 connection cap
        const FIRST_BATCH    = Math.min(6, totalImages);

        const finalizeTexture = (tex) => {
            tex.colorSpace      = THREE.SRGBColorSpace;
            tex.generateMipmaps = !this.isLowEnd;
            tex.anisotropy      = safeAnisotropy;
            if (!tex.generateMipmaps) tex.minFilter = THREE.LinearFilter;

            const maxDim = CONFIG.performance.textureMaxSize || 2048;
            const image  = tex.image;
            if (image && image.width && Math.max(image.width, image.height) > maxDim) {
                const scale = maxDim / Math.max(image.width, image.height);
                const w = Math.max(1, Math.round(image.width * scale));
                const h = Math.max(1, Math.round(image.height * scale));
                const cv = document.createElement('canvas');
                cv.width = w; cv.height = h;
                cv.getContext('2d').drawImage(image, 0, 0, w, h);
                tex.dispose(); // never reached the GPU — release the decode buffer
                const resized = new THREE.CanvasTexture(cv);
                resized.colorSpace      = THREE.SRGBColorSpace;
                resized.generateMipmaps = !this.isLowEnd;
                resized.anisotropy      = safeAnisotropy;
                if (!resized.generateMipmaps) resized.minFilter = THREE.LinearFilter;
                return resized;
            }
            return tex;
        };

        const loadTextureFor = (index) => new Promise(resolve => {
            const img = this.artworkImages[index];
            const url = pickTextureUrl(img, this);
            loadArtworkTexture(artworkLoader, url, (tex) => {
                    img.texture = finalizeTexture(tex);
                    img._loadedUrl = url; // focus-upgrade bookkeeping
                    resolve(true);
                },
                () => resolve(false) // network/decode failure — placeholder stays
            );
        });

        const loadThumbFor = (index) => new Promise(resolve => {
            const img = this.artworkImages[index];
            const thumbUrl = img.textures?.thumb;
            // No real thumbnail available (legacy gallery) — skip silently.
            if (!thumbUrl || thumbUrl === img.url) { resolve(); return; }
            loadArtworkTexture(artworkLoader, thumbUrl, (tex) => {
                tex.colorSpace      = THREE.SRGBColorSpace;
                tex.generateMipmaps = false; // 1px-to-400px blur-up needs no mips
                tex.minFilter       = THREE.LinearFilter; // no mips → no mip filter (black-sample guard)
                tex.anisotropy      = 1;
                img.thumbTexture = tex;
                resolve();
            }, () => resolve());
        });

        // Generic bounded-concurrency queue runner.
        const runPooled = async (indices, loaderFn, basePct, spanPct, label) => {
            const total = indices.length;
            if (total === 0) return;
            let cursor = 0;
            let completed = 0;
            const worker = async () => {
                while (cursor < total) {
                    const idx = indices[cursor++];
                    await loaderFn(idx);
                    completed++;
                    this.updateProgress(
                        basePct + (completed / total) * spanPct,
                        `${label} ${completed}/${total}`
                    );
                }
            };
            const n = Math.min(MAX_CONCURRENT, total);
            await Promise.all(Array.from({ length: n }, () => worker()));
        };

        const useThumbs = !this.isLowEnd && !this.isMobile;
        if (useThumbs && totalImages > FIRST_BATCH) {
            this.updateProgress(12, 'Preparing exhibition...');
            await runPooled(order, loadThumbFor, 12, 8, 'Preparing');
        }

        this.updateProgress(22, 'Loading first artworks...');
        await runPooled(order.slice(0, FIRST_BATCH), loadTextureFor, 22, 33, 'Loading artwork');

        // ── Build the room — walkable NOW, remaining art streams in ─────────
        this.updateProgress(60, 'Building gallery...');
        this.buildGallery();

        if (totalImages <= FIRST_BATCH) {
            // Small gallery — everything is already loaded; classic behaviour.
            this.updateProgress(100, 'Complete!');
            setTimeout(() => this.hideLoader(), 500);
            return;
        }

        this._enterReady = true;
        this.updateProgress(62, 'Ready — enter now, remaining artworks still loading');

        // ── PHASE B — stream the rest in the background ─────────────────────
        await runPooled(
            order.slice(FIRST_BATCH),
            (idx) => {
                const img = this.artworkImages[idx];
                if (img._loadedUrl && img._loadedUrl === img.textures?.large) {
                    return Promise.resolve();
                }
                return loadTextureFor(idx).then(loaded => {
                    if (loaded) this.applyArtworkTexture(this.artworkImages[idx]);
                });
            },
            62, 38, 'Streaming artwork'
        );

        this.updateProgress(100, 'Complete!');
        setTimeout(() => this.hideLoader(), 500);
    } catch (error) {
        console.error('Critical asset loading error:', error);
        this.showLoadError(error);
    }
}

export function upgradeFocusedArtworkTexture(artworkGroup) {
    if (!this._isMobileTier) return;
    if (!artworkGroup || !this.artworkImages) return;

    const img = this.artworkImages.find(i => i.id === artworkGroup.userData.id);
    const largeUrl = img?.textures?.large;
    if (!img || !largeUrl) return;
    if (img._loadedUrl === largeUrl || img._upgradeInFlight) return;

    img._upgradeInFlight = true;
    const loader = _bitmapLoader || createArtworkLoader();

    loadArtworkTexture(loader, largeUrl,
        (tex) => {
            img._upgradeInFlight = false;
            // Visitor may have left / artwork may have been disposed
            if (this._disposed || !this.artworks) return;

            const previous = img.texture;
            img.texture = tex;
            img._loadedUrl = largeUrl;

            tex.colorSpace      = THREE.SRGBColorSpace;
            tex.generateMipmaps = !this.isLowEnd;
            if (!tex.generateMipmaps) tex.minFilter = THREE.LinearFilter;
            tex.anisotropy      = this._maxAnisotropy ?? 2;
            tex.needsUpdate     = true;

            this.applyArtworkTexture(img);

            // Free the superseded medium texture's GPU copy
            if (previous && previous !== tex) previous.dispose();
        },
        () => { img._upgradeInFlight = false; } // network fail — keep medium
    );
}

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
            <button id="gallery-load-error-retry" style="padding:0.75rem 2rem;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:white;border:none;border-radius:0.5rem;font-weight:600;font-size:0.9rem;cursor:pointer;transition:all 0.2s;">
                Retry
            </button>
            <a href="/discover" style="margin-top:1rem;color:#64748b;font-size:0.8rem;text-decoration:underline;">Browse other galleries</a>
        </div>
    `;

    curtain.innerHTML = errorHtml;
    curtain.style.display = 'flex';
    document.getElementById('gallery-load-error-retry')
        ?.addEventListener('click', () => window.location.reload());
}

export function loadEnvironmentMap() {
    if (this._skipHdri) return; // low-end: skip the 10MB HDRI

    if (this._venueEnvIntensity === 0) return;

    const venueVisual = this._venueVisualConfig || null;

    // ── Resolve the HDRI path through the venue authority chain ─────────
    let hdriPath = this._customHdriUrl || null;   // 1. bespoke upload
    let declaredNone = false;

    if (!hdriPath && venueVisual && 'environment' in venueVisual &&
        venueVisual.environment != null) {
        if (venueVisual.environment === 'none') {
            declaredNone = true;                  // the venue refuses a sky
        } else {
            hdriPath = CONFIG.environments[venueVisual.environment] ?? null;
            if (!hdriPath) {
                console.warn(`[exospace] venue "${this._venueSlug || '?'}" declared unknown environment "${venueVisual.environment}" — rendering without an environment.`);
            }
        }
    }

    if (!declaredNone && !hdriPath) {
        const preset = this.lightingPreset || 'bright';
        hdriPath = (CONFIG.lighting[preset] || CONFIG.lighting.bright).hdri;
    }

    if (!hdriPath || declaredNone) return;

    const preset = this.lightingPreset || 'bright';
    const lightingConfig = CONFIG.lighting[preset] || CONFIG.lighting.bright;

    const rgbeLoader = new _HDRLoader();
    rgbeLoader.load(
        hdriPath,
        (texture) => {
            texture.mapping = THREE.EquirectangularReflectionMapping;
            this.scene.environment = texture;
            const envIntensity = this._venueEnvIntensity ?? lightingConfig.envIntensity;
            if (envIntensity !== undefined) {
                this.scene.environmentIntensity = envIntensity;
            }
            if (this._venueVisualConfig?.tone_mapping_exposure == null &&
                lightingConfig.toneMappingExposure !== undefined) {
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