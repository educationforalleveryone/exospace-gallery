import * as THREE from 'three';
import { CONFIG } from './config.js';
import { PostProcessing } from './PostProcessing.js';

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

    this.renderer = new THREE.WebGLRenderer({
        antialias: !earlyLowEnd && !(coarse && dpr >= 1.5),
        powerPreference: earlyLowEnd ? 'low-power' : 'high-performance',
    });
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

    this.renderer.domElement.addEventListener('webglcontextlost', (e) => {
        e.preventDefault();
        this._contextLost = true;
        console.error('WebGL context lost — attempting recovery...');

        // Show a recovery overlay if one exists
        const overlay = document.getElementById('webgl-recovery');
        if (overlay) overlay.style.display = 'flex';
    }, false);

    this.renderer.domElement.addEventListener('webglcontextrestored', () => {
        console.log('WebGL context restored — rebuilding scene...');
        if (this._disposed) {
            this._contextLost = true;
            return;
        }
        this._contextLost = false;

        const overlay = document.getElementById('webgl-recovery');
        if (overlay) overlay.style.display = 'none';

        if (this.init) {
            try {
                this.init();
            } catch (e) {
                console.error('Scene rebuild failed after context restore:', e);
            }
        }
    }, false);
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
            console.log('🎮 GPU:', rendererStr, '|', vendorStr);

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
    if (reducedMotion) {
        console.log('♿ Reduced motion: motion effects disabled (render tier unaffected)');
    }

    this.isLowEnd = isLowEnd;
    if (isLowEnd) {
        console.log('⚡ Low-end mode:', reasons.join(', '));
        applyLowEndSettings.call(this);
    } else if (this.isMobile) {
        console.log('📱 Mobile tier: pixelRatio 1.25, HDRI off, 4 pooled lights');
        applyMobileSettings.call(this);
    } else {
        console.log('🚀 High-end mode: full quality enabled');
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
                console.warn('⚡ FPS benchmark: loader never settled — skipping');
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
                console.log(`⚡ FPS benchmark: ${measuredFps.toFixed(1)} fps < ${FPS_THRESHOLD} — downgrading to low-end mode`);
                applyLowEndSettings.call(this);
            } else {
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
