import * as THREE from 'three';
import { CONFIG } from './config.js';
import { PostProcessing } from './PostProcessing.js';
import { reportException } from '../monitoring.js';

export function earlyLowEndCheck() {
    if (typeof window !== 'undefined' && window.__EXOSPACE_QA_TIER === 'high') return false;
    if (typeof window !== 'undefined' && window.__EXOSPACE_QA_TIER === 'low') return true;
    if (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) return true;
    if (navigator.deviceMemory && navigator.deviceMemory < 4) return true;
    return false;
}

export function isCoarsePointer() {
    return !!(window.matchMedia?.('(pointer: coarse)').matches
        || (navigator.maxTouchPoints > 0 && 'ontouchstart' in window));
}

export function initRenderer() {
    if (this.renderer) {
        this.renderer.setSize(window.innerWidth, window.innerHeight);
        return;
    }

    const earlyLowEnd = earlyLowEndCheck();
    const coarse = isCoarsePointer();
    const dpr = window.devicePixelRatio || 1;

    try {
        this.renderer = new THREE.WebGLRenderer({
            antialias: !earlyLowEnd && !(coarse && dpr >= 1.5),
            powerPreference: earlyLowEnd ? 'low-power' : 'high-performance',
        });
    } catch (error) {
        // Distinguishes "this device cannot render 3D at all" from network /
        // data failures so showLoadError can skip its Retry button.
        error.webglUnavailable = true;
        throw error;
    }

    this.renderer.setSize(window.innerWidth, window.innerHeight);
    this.renderer.setPixelRatio(coarse ? Math.min(dpr, 1.25) : Math.min(dpr, 1.5));
    this.renderer.shadowMap.enabled = CONFIG.performance.shadowsEnabled;
    this.renderer.shadowMap.type    = THREE.PCFSoftShadowMap;
    this.renderer.toneMapping       = THREE.ACESFilmicToneMapping;
    this.renderer.toneMappingExposure = 0.8;
    this.renderer.outputColorSpace  = THREE.SRGBColorSpace;
    this.renderer.info.autoReset = false;

    this.container.appendChild(this.renderer.domElement);

    this._contextLost = false;
    this._contextLostReported = false;

    this.renderer.domElement.addEventListener('webglcontextlost', (e) => {
        e.preventDefault();
        this._contextLost = true;
        console.warn('WebGL context lost — pausing render loop until the browser restores it');

        if (!this._contextLostReported) {
            this._contextLostReported = true;
            reportException(new Error('WebGL context lost'), { area: 'viewer-webgl' });
        }

        showContextLostOverlay();
    }, false);

    // three.js re-uploads GPU resources for the existing scene graph after a
    // context restore — the scene, listeners and loaded assets must stay as
    // they are; rebuilding here duplicated the whole room and every texture.
    this.renderer.domElement.addEventListener('webglcontextrestored', () => {
        if (this._disposed) {
            this._contextLost = true;
            return;
        }
        this._contextLost = false;

        hideContextLostOverlay();
    }, false);
}

/**
 * Context loss freezes the render loop; visitors need to know what happened
 * and that a reload restores it. The overlay is created on demand so every
 * page hosting the viewer (exhibition, venue preview) gets the same recovery
 * UI without each layout having to ship the markup.
 */
function showContextLostOverlay() {
    let overlay = document.getElementById('webgl-recovery');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'webgl-recovery';
        overlay.innerHTML = `
            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(10,10,15,0.92);z-index:2000;text-align:center;padding:2rem;font-family:system-ui,-apple-system,sans-serif;">
                <h2 style="color:#f1f5f9;font-size:1.3rem;font-weight:700;margin:0 0 0.5rem;">The exhibition paused</h2>
                <p style="color:#94a3b8;font-size:0.9rem;max-width:360px;line-height:1.6;margin:0 0 1.5rem;">
                    Your device temporarily interrupted the 3D rendering. Reload to re-enter the exhibition.
                </p>
                <button type="button" data-webgl-recovery-reload style="padding:0.75rem 2rem;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:white;border:none;border-radius:0.5rem;font-weight:600;font-size:0.9rem;cursor:pointer;">
                    Reload exhibition
                </button>
            </div>
        `;
        overlay.style.position = 'fixed';
        overlay.style.inset = '0';
        overlay.style.display = 'none';
        overlay.style.zIndex = '2000';
        overlay.addEventListener('click', (e) => {
            if (e.target.closest('[data-webgl-recovery-reload]')) window.location.reload();
        });
        document.body.appendChild(overlay);
    }
    overlay.style.display = 'block';
}

function hideContextLostOverlay() {
    const overlay = document.getElementById('webgl-recovery');
    if (overlay) overlay.style.display = 'none';
}

export function detectLowEnd() {
    let isLowEnd = earlyLowEndCheck();
    const reasons = [];
    const qaTier = (typeof window !== 'undefined' && window.__EXOSPACE_QA_TIER) || null;

    this.isMobile = isCoarsePointer();

    if (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) {
        isLowEnd = true;
        reasons.push(`CPU cores: ${navigator.hardwareConcurrency}`);
    }

    try {
        const gl = this.renderer.getContext();
        const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
        if (debugInfo) {
            const rendererStr = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || '';
            const vendorStr   = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL)   || '';
            if (window.EXOSPACE_DEBUG) console.log('🎮 GPU:', rendererStr, '|', vendorStr);

            const isSoftware     = /Microsoft Basic Render|SwiftShader|llvmpipe|softpipe|ANGLE.*Basic/i.test(rendererStr);
            const isBudgetMobile = /Mali-[34567]|Mali-T|Adreno [23]|Adreno [45]0[0-5]|PowerVR SGX|PowerVR G6|VideoCore/i.test(rendererStr);
            const isOldIntel     = /Intel.*HD Graphics [234]\d{3}|Intel.*HD Graphics 5[0-4]\d|Intel.*GMA/i.test(rendererStr);

            if (isSoftware && qaTier !== 'high')     { isLowEnd = true; reasons.push(`Software renderer: ${rendererStr}`); }
            if (isBudgetMobile && qaTier !== 'high') { isLowEnd = true; reasons.push(`Budget mobile GPU: ${rendererStr}`); }
            if (isOldIntel && qaTier !== 'high')     { isLowEnd = true; reasons.push(`Old Intel iGPU: ${rendererStr}`); }
        }
    } catch (e) {
        reasons.push('GPU info unavailable');
    }

    if (navigator.deviceMemory && navigator.deviceMemory < 4) {
        isLowEnd = true;
        reasons.push(`RAM: ${navigator.deviceMemory}GB`);
    }

    this._scheduleFpsBenchmark = _scheduleFpsBenchmark;
    this._scheduleFpsBenchmark();

    const reducedMotion = window.EXOSPACE_REDUCED_MOTION === true;
    this.reducedMotion = reducedMotion;

    this.isLowEnd = isLowEnd;
    if (isLowEnd) {
        if (window.EXOSPACE_DEBUG) console.log('⚡ Low-end mode:', reasons.join(', '));
        applyLowEndSettings.call(this);
    } else if (this.isMobile) {
        if (window.EXOSPACE_DEBUG) console.log('📱 Mobile tier: pixelRatio 1.25, HDRI off, 4 pooled lights');
        applyMobileSettings.call(this);
    } else {
        if (window.EXOSPACE_DEBUG) console.log('🚀 High-end mode: full quality enabled');
        if (this._postFx) { this._postFx.dispose(); this._postFx = null; }
        this._postFx = new PostProcessing(this.renderer, this.scene, this.camera);
    }

    return isLowEnd;
}

function _scheduleFpsBenchmark() {
    // Already flagged low-end? Skip — no point burning 3s of rAF to confirm.
    if (this.isLowEnd) return;

    const SETTLE_POLL_MS   = 250;
    const SETTLE_TIMEOUT_MS = 30000; // loader never settled (empty state/error) — abort
    const WARMUP_MS    = 2000;
    const SAMPLE_FRAMES = 60;
    const SAMPLE_MIN_MS = 1000;      // never decide on a shorter window
    const SAMPLE_CAP_MS = 5000;      // ultra-slow devices still get a verdict
    const FPS_THRESHOLD = 35;

    let waitStart = null;

    const waitLoop = () => {
        if (this._disposed) return;
        if (this._assetsSettledAt == null) {
            if (waitStart == null) waitStart = performance.now();
            if (performance.now() - waitStart > SETTLE_TIMEOUT_MS) {
                if (window.EXOSPACE_DEBUG) console.warn('⚡ FPS benchmark: loader never settled — skipping');
                return;
            }
            setTimeout(waitLoop, SETTLE_POLL_MS);
            return;
        }
        requestAnimationFrame(measureFrame);
    };

    let warmupStart = null;
    let sampleStart = null;
    let frames      = 0;
    let hideTs      = null;
    const hiddenRanges = []; // [start, end] wall-clock spans while tab was hidden

    const onVisibility = () => {
        if (document.hidden)      hideTs = performance.now();
        else if (hideTs != null) { hiddenRanges.push([hideTs, performance.now()]); hideTs = null; }
    };
    document.addEventListener('visibilitychange', onVisibility);

    const hiddenWithin = (start, end) => {
        let t = 0;
        for (const [a, b] of hiddenRanges) {
            const lo = Math.max(a, start);
            const hi = Math.min(b, end);
            if (hi > lo) t += hi - lo;
        }
        return t;
    };

    const measureFrame = (ts) => {
        if (this._disposed) {
            document.removeEventListener('visibilitychange', onVisibility);
            return;
        }

        if (warmupStart == null) warmupStart = ts;
        if (ts - warmupStart - hiddenWithin(warmupStart, ts) < WARMUP_MS) {
            requestAnimationFrame(measureFrame);
            return;
        }

        if (sampleStart == null) sampleStart = ts;
        frames++;

        const elapsed = (ts - sampleStart) - hiddenWithin(sampleStart, ts);
        if (frames >= SAMPLE_FRAMES || elapsed >= SAMPLE_CAP_MS) {
            document.removeEventListener('visibilitychange', onVisibility);
            const measuredFps = frames / (Math.max(elapsed, 1) / 1000);
            if (measuredFps < FPS_THRESHOLD && !this.isLowEnd) {
                if (window.EXOSPACE_DEBUG) console.log(`⚡ FPS benchmark: ${measuredFps.toFixed(1)} fps < ${FPS_THRESHOLD} — downgrading to low-end mode`);
                applyLowEndSettings.call(this);
            } else if (window.EXOSPACE_DEBUG) {
                console.log(`✅ FPS benchmark: ${measuredFps.toFixed(1)} fps — high-end confirmed`);
            }
            return;
        }

        requestAnimationFrame(measureFrame);
    };

    waitLoop();
}

// Apply all low-end quality reductions in one place
export function applyLowEndSettings() {
    this.renderer.setPixelRatio(1);
    this.renderer.shadowMap.enabled = false;
    if (this.scene.fog && !this._venueFogDeclared) {
        this.scene.fog.near = Math.max(2, this.scene.fog.near * 0.4);
        this.scene.fog.far  = Math.max(8, this.scene.fog.far  * 0.5);
    }
    document.body.classList.add('low-end-device');
    this.isLowEnd = true;
    this._skipHdri      = true;  // HDRI is 10MB — skip on low-end
    this._maxAnisotropy = 1;     // anisotropic filtering is expensive on budget GPUs
}

export function applyMobileSettings() {
    this._isMobileTier = true;
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.25));
    if (this.scene.fog && !this._venueFogDeclared) {
        this.scene.fog.near = Math.min(this.scene.fog.near, 8);
        this.scene.fog.far  = Math.min(this.scene.fog.far, 24);
    }
    this._maxActiveLights = 4;
    this._skipHdri        = true;   // the single biggest mobile payload
    this._maxAnisotropy   = 2;
    document.body.classList.add('mobile-tier');
    if (this._postFx) { this._postFx.dispose(); this._postFx = null; }
    this._postFx = new PostProcessing(this.renderer, this.scene, this.camera);
}
