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

// PERF-E25 (3D audit — decode off the main thread): artwork textures decode
// via createImageBitmap() on a browser background thread when available.
// The previous path (TextureLoader → HTMLImageElement) defers decode to the
// first texImage2D upload, which lands on the MAIN thread — during phase-B
// streaming that meant visible hitches every time a texture swapped in while
// the visitor was walking. ImageBitmaps arrive fully decoded.
//
// Orientation note — BUGFIX (artwork rendered upside down):
// three's WebGLTextures upload path skips the UNPACK_FLIP_Y_WEBGL pixelStorei
// call entirely for ImageBitmap sources (confirmed against the installed
// three version — see uploadTexture()'s `isImageBitmap` branch), so
// Texture#flipY has NO effect on a bitmap-backed texture. The previous
// approach compensated by baking the flip into the bitmap's PIXELS at
// decode time via createImageBitmap's `imageOrientation: 'flipY'` option.
// That decode-time flip is NOT reliably consistent across GPU/driver
// backends — Chromium's accelerated image-decode path (e.g. the ANGLE
// Direct3D11 backend on Windows) has known discrepancies here, which is
// exactly the class of bug that renders artwork upside down on some
// machines and right-side up on others for the identical file.
// Fix: never bake a pixel-level flip. Decode with the untouched
// orientation ('none', the default) and instead flip at the UV-SAMPLING
// stage via the texture's transform (center + repeat) — pure shader math,
// so it is 100% consistent across every GPU/driver/OS combination.
let _bitmapLoader = null;
function createArtworkLoader() {
    if (typeof createImageBitmap !== 'undefined' && typeof fetch !== 'undefined') {
        _bitmapLoader = new THREE.ImageBitmapLoader();
        // Default imageOrientation is 'none' — no pixel-level flip baked in.
        return _bitmapLoader;
    }
    return new THREE.TextureLoader();
}

// Flip a texture vertically via its UV transform (center + repeat) instead
// of via pixel data or the (for-ImageBitmap-ignored) flipY pixelStorei flag.
// Scaling repeat.y by -1 around center (0.5, 0.5) maps v -> 1 - v for any
// v already in [0,1], so no wrap mode is needed and nothing samples outside
// the texture.
function flipTextureVertically(tex) {
    tex.center.set(0.5, 0.5);
    tex.repeat.set(1, -1);
    tex.needsUpdate = true;
}

// Normalized artwork-texture fetch: always resolves a THREE.Texture to
// onLoad (constructing one around the ImageBitmap when on the bitmap path —
// needsUpdate is required for a manually constructed Texture).
function loadArtworkTexture(loader, url, onLoad, onError) {
    if (loader instanceof THREE.ImageBitmapLoader) {
        loader.load(url, (bitmap) => {
            const tex = new THREE.Texture(bitmap);
            // BUGFIX: the bitmap is undoctored (imageOrientation:'none'), and
            // flipY is ignored for ImageBitmap sources by the renderer — so
            // without this, the artwork uploads exactly as decoded, which
            // reads upside down against the room's UV convention. Flip it
            // at the UV-sampling stage instead (see flipTextureVertically).
            flipTextureVertically(tex);
            onLoad(tex);
        }, undefined, onError);
    } else {
        // TextureLoader path (createImageBitmap unavailable): the browser's
        // normal HTMLImageElement upload DOES honour UNPACK_FLIP_Y_WEBGL, and
        // Texture#flipY defaults to true — this path was already correct and
        // is left untouched.
        loader.load(url, onLoad, undefined, onError);
    }
}

function getDracoLoader(renderer) {
    if (!_dracoLoader) {
        _dracoLoader = new DRACOLoader();
        // Decoder wasm files live in /decoders/ — copied by Vite plugin or
        // manually placed from node_modules/three/examples/jsm/libs/draco/.
        _dracoLoader.setDecoderPath('/decoders/');
        // PERF-A5 (3D audit F5): was { type: 'js' } — the asm.js decoder.
        // The wasm decoder decodes the same GLBs 2-4x faster. Both files
        // ship in /decoders/draco/ (draco_wasm_wrapper.js + draco_decoder.wasm),
        // so switching is purely a speed win with identical output.
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
        // KTX2 support — only enable if renderer is available (it always is
        // by the time GLBs load, but be defensive)
        if (this?.renderer) {
            _gltfLoader.setKTX2Loader(getKtx2Loader(this.renderer));
        }
    }
    return _gltfLoader;
}

// ── Capability-based artwork texture selection ─────────────────────────────
// PERF-A1 (3D audit F1): the backend now ships a `textures` map per artwork
// (thumb 400 / small 768 / medium 1024 / large 2048 WebP conversions,
// falling back to the original URL when a conversion is missing).
// Pick the variant that matches what the device can actually display:
//
//   low-end  → small  (768px)   — pixelRatio 1, short fog, small far plane
//   mobile   → medium (1024px)  — artwork occupies ~600-900 device px at most
//   desktop  → large  (2048px)  — headroom for focus-mode close inspection
//
// Every tier falls back through the chain to img.url (the original), so
// legacy galleries without Spatie conversions behave exactly as before.
export function pickTextureUrl(img, scene) {
    const t = img.textures;
    if (!t) return img.url; // legacy payload (pre-iteration-1 galleries)
    if (scene.isLowEnd)  return t.small  || t.medium || t.large || img.url;
    if (scene.isMobile)   return t.medium || t.large  || t.small || img.url;
    return t.large || t.medium || t.small || img.url;
}

// ── Main asset load — runs on GalleryScene boot ──────────────────────────────
//
// PERF-C9 (3D audit F9): PROGRESSIVE LOADING. The old flow blocked the Enter
// button until 100% of artwork textures had downloaded + decoded — on a
// 30-image gallery over a slow connection that was a minute of staring at a
// progress bar while the room itself was ready in seconds.
//
// New two-phase flow:
//   Phase A (blocking):  PBR materials → blur-up thumbnails (desktop) → the
//                        first FIRST_BATCH artwork textures (deep-linked
//                        artwork prioritised) → room builds → ENTER UNLOCKS.
//   Phase B (background): remaining textures stream in with the same 6-way
//                        concurrency pool and swap into their slots live.
//
// Every canvas material is created with a map from the very start (thumb or
// a shared 1×1 dark placeholder) so a texture swap never changes the shader
// program — the pop-in costs zero recompiles (see ArtworkPlacer).
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

        // ── Create placeholder entries for ALL artworks up-front ────────────
        // Wall geometry is sized from the total count, every slot is framed,
        // and textures attach as they arrive. (Previously, an image whose
        // download failed was skipped entirely and the wall had a hole.)
        this.artworkImages = data.images.map(img => ({
            texture: null,      // full-quality variant — filled on arrival
            thumbTexture: null, // blur-up placeholder (desktop high-end)
            aspectRatio: img.aspectRatio || (img.width && img.height ? img.width / img.height : 1),
            ...img,              // backend fields: id, url, textures, metadata…
        }));

        // Loading order — a deep-linked artwork (?artwork=<id>) jumps the
        // queue so the shared link's target is inside the first batch.
        const deepLinkArtworkId = data.deepLinkArtworkId;
        const order = data.images.map((_, i) => i);
        if (deepLinkArtworkId) {
            const di = order.find(i => data.images[i].id === deepLinkArtworkId);
            if (di !== undefined && di > 0) {
                order.splice(order.indexOf(di), 1);
                order.unshift(di);
            }
        }

        const MAX_CONCURRENT = 6;  // PERF-5: browser HTTP/2 connection cap
        const FIRST_BATCH    = Math.min(6, totalImages);

        // Configure + (rarely) downscale a freshly loaded artwork texture.
        // PERF-C19 (3D audit): uploads are capped at 2048px server-side, but
        // legacy galleries may still reference larger originals — those would
        // be uploaded to the GPU at full size. CONFIG.performance.textureMaxSize
        // existed but was never wired; it is now the enforced ceiling.
        const finalizeTexture = (tex) => {
            tex.colorSpace      = THREE.SRGBColorSpace;
            tex.generateMipmaps = !this.isLowEnd;
            tex.anisotropy      = safeAnisotropy;
            // Mipmap-less textures must not keep the mip-mapping min filter:
            // an incomplete mip chain samples BLACK on strict WebGL2 drivers
            // (low-end tier showed unreadable/black artworks). LinearFilter
            // is the correct pair for generateMipmaps=false.
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
                // No UV flip needed here: `image` is the untouched (unflipped)
                // decoded bitmap, and CanvasTexture's source is an
                // HTMLCanvasElement (not an ImageBitmap), so the renderer DOES
                // honour flipY for it — CanvasTexture defaults flipY=true,
                // which correctly orients it the same way a normal
                // TextureLoader image does.
                return resized;
            }
            return tex;
        };

        const loadTextureFor = (index) => new Promise(resolve => {
            const img = this.artworkImages[index];
            const url = pickTextureUrl(img, this);
            loadArtworkTexture(artworkLoader, url, (tex) => {
                    img.texture = finalizeTexture(tex);
                    img._loadedUrl = url; // PERF-E27: focus-upgrade bookkeeping
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

        // ── PHASE A ──────────────────────────────────────────────────────────
        // Blur-up thumbnails: desktop high-end only — mobile/low-end spend
        // their bytes on the real (medium/small) variants instead.
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

        // Enter unlocks with the room built — the entire point of PERF-C9.
        // updateProgress() reads this flag to enable the button early.
        this._enterReady = true;
        this.updateProgress(62, 'Ready — enter now, remaining artworks still loading');

        // ── PHASE B — stream the rest in the background ─────────────────────
        await runPooled(
            order.slice(FIRST_BATCH),
            (idx) => {
                const img = this.artworkImages[idx];
                // PERF-E27: if a focus-mode upgrade already fetched the LARGE
                // variant for this artwork, don't let phase B overwrite it
                // with the tier default (medium on mobile) — that would be a
                // visual downgrade of a piece the visitor is looking at.
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
        // UX-1 FIX: Show error UI instead of freezing the curtain.
        // Previously, hideLoader() was a no-op (just console.log) — the
        // curtain stayed at whatever % it last updated, Enter button stayed
        // disabled, visitor was stuck. Now we show a proper error overlay.
        // (PERF-C9: if the visitor already entered, the curtain is gone and
        // this is a no-op — the room stays usable with placeholders.)
        this.showLoadError(error);
    }
}

// ── Focus-mode texture refinement (PERF-E27 / 3D audit) ───────────────────
// On the mobile tier, artworks load the 1024px "medium" variant — the right
// trade-off for wall viewing, but slightly soft when the visitor walks up
// and inspects a piece (focus distance 1.8 m fills the phone screen). When
// they focus an artwork, fetch the 2048px "large" variant on demand, swap
// it in during the 1.5 s camera tween, and dispose the medium texture —
// progressive refinement driven by intent, so only inspected pieces ever
// pay the large-variant bytes.
//
// Desktop already loads `large` and low-end stays on `small` deliberately
// (GPU memory), so this only activates on the mobile tier. Idempotent and
// race-safe against phase-B streaming via the _loadedUrl bookkeeping.
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

            // finalizeTexture is loadAssets-scoped; apply the same standard
            // configuration here (mobile tier keeps mipmaps + anisotropy 2).
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
            <button id="gallery-load-error-retry" style="padding:0.75rem 2rem;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:white;border:none;border-radius:0.5rem;font-weight:600;font-size:0.9rem;cursor:pointer;transition:all 0.2s;">
                Retry
            </button>
            <a href="/discover" style="margin-top:1rem;color:#64748b;font-size:0.8rem;text-decoration:underline;">Browse other galleries</a>
        </div>
    `;

    curtain.innerHTML = errorHtml;
    curtain.style.display = 'flex';
    // BUGFIX: an inline onclick="" attribute is blocked by the page's CSP
    // (script-src has no 'unsafe-inline' and hashes don't cover event-handler
    // attributes). Wire the retry button up via addEventListener instead —
    // this runs from the already-trusted bundled script, so CSP allows it.
    document.getElementById('gallery-load-error-retry')
        ?.addEventListener('click', () => window.location.reload());
}

// ── HDRI environment map — non-blocking, fades in after room renders ─────────
//
// ── ENVIRONMENT AUTHORITY (s4) ───────────────────────────────────────────────
// The venue's declared environment IS its sky; the gallery's lighting preset
// NEVER picks it. This is the fix for the Dark Museum "sky/cloud on the
// floor" incident: the gallery column used to resolve the HDRI, so a stale
// gallery-era preset ('bright' → studio.hdr, or 'moody' → rural_evening.hdr
// with its cloud deck) installed a daytime/dusk sky inside the night museum,
// and its reflections read as a bright cloudy sheen on the polished dark
// stone. Resolution order:
//
//   1. hdri_url            — the venue's bespoke upload (wins over everything)
//   2. visual_config.environment — the venue's declared stock environment
//                            (studio | rural_evening | night | none)
//   3. resolved lighting preset hdri — the fill-in for venues that declare
//                            no environment (and for venue-less legacy
//                            galleries). Venue-safe because the preset
//                            itself is venue-resolved upstream.
//
// 'none' (or env_intensity: 0) means the venue does not want an environment —
// the ~10 MB HDR transfer is skipped entirely.
export function loadEnvironmentMap() {
    if (this._skipHdri) return; // low-end: skip the 10MB HDRI

    // A venue declaring env_intensity = 0 has silenced the environment at the
    // source (§10.2 — the declaration is the identity). Downloading a ~10 MB
    // HDR only to multiply it by zero was pure waste on every desktop visit
    // of such venues (Infinite Void paid this on every load). Zero means the
    // venue does not want an environment — skip the transfer entirely.
    if (this._venueEnvIntensity === 0) return;

    const venueVisual = this._venueVisualConfig || null;

    // ── Resolve the HDRI path through the venue authority chain ─────────
    let hdriPath = this._customHdriUrl || null;   // 1. bespoke upload
    let declaredNone = false;

    if (!hdriPath && venueVisual && 'environment' in venueVisual &&
        venueVisual.environment != null) {
        // 2. the venue's declared stock environment (null = undeclared —
        // the editor select's empty state — handled by the preset fallback
        // below, same as pre-s4 venues that never declared the key).
        if (venueVisual.environment === 'none') {
            declaredNone = true;                  // the venue refuses a sky
        } else {
            hdriPath = CONFIG.environments[venueVisual.environment] ?? null;
            if (!hdriPath) {
                // An EXPLICIT but unknown stock name (hand-authored advanced
                // JSON). Never silently substitute a generic sky — the venue
                // declared something the renderer cannot resolve, so it gets
                // no environment (standard lights still cover the room) and
                // the author finds out immediately.
                console.warn(`[exospace] venue "${this._venueSlug || '?'}" declared unknown environment "${venueVisual.environment}" — rendering without an environment.`);
            }
        }
    }

    if (!declaredNone && !hdriPath) {
        // 3. fill-in for venues that declare no environment (and venue-less
        // legacy galleries): the RESOLVED preset's HDRI. For venue-managed
        // galleries the preset is venue-resolved (VenueConfigExporter::
        // presetForGallery) — the gallery's own lighting_preset column can
        // no longer diverge it, so this stays venue-consistent.
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
            // The environment STRENGTH is also venue-owned: env_intensity in
            // visual_config overrides the preset's envIntensity (which now
            // only fills in for venues that declare nothing — and for
            // venue-managed galleries the preset itself is venue-resolved,
            // see VenueConfigExporter::presetForGallery).
            const envIntensity = this._venueEnvIntensity ?? lightingConfig.envIntensity;
            if (envIntensity !== undefined) {
                this.scene.environmentIntensity = envIntensity;
            }
            // The preset's exposure must not CLOBBER the venue's declared
            // exposure. applyVenueConfig already set the venue value — the
            // HDRI arriving seconds later silently reverted it (the declared
            // tone_mapping_exposure looked like it "didn't work"). The venue
            // declaration is the identity source; the preset only fills in
            // for venues that declare nothing.
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