// ─────────────────────────────────────────────────────────────────────────────
// Renderer — WebGLRenderer init + low-end device detection
//
// All methods are exported as plain functions and bound to the GalleryScene
// instance via .call(this). This keeps GalleryScene.js small while still
// letting us use `this.renderer`, `this.camera`, etc.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { CONFIG } from './config.js';
import { PostProcessing } from './PostProcessing.js';

// Quick check that runs BEFORE renderer creation — used to decide antialias +
// powerPreference. Returns true for low-core or low-RAM devices.
export function earlyLowEndCheck() {
    if (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) return true;
    if (navigator.deviceMemory && navigator.deviceMemory < 4) return true;
    return false;
}

// PERF-B7 (3D audit F7): touch-primary device detection — the same signal
// Mobile.js uses for its control scheme. Needed here (BEFORE loadAssets)
// so the mobile quality tier and texture variant selection know the device
// class as early as possible.
export function isCoarsePointer() {
    return !!(window.matchMedia?.('(pointer: coarse)').matches
        || (navigator.maxTouchPoints > 0 && 'ontouchstart' in window));
}

// Creates the WebGLRenderer, attaches it to #canvas-container, sets tone-mapping
// and color space. Called from GalleryScene.init().
export function initRenderer() {
    // PERF-B10 (3D audit F10): a WebGL context restore calls init() again,
    // which used to create a SECOND renderer + canvas (duplicate WebGL
    // contexts leak GPU memory) . If a renderer already exists, keep it —
    // the restored context belongs to the same canvas — and just re-size.
    if (this.renderer) {
        this.renderer.setSize(window.innerWidth, window.innerHeight);
        return;
    }

    const earlyLowEnd = earlyLowEndCheck();
    const coarse = isCoarsePointer();
    const dpr = window.devicePixelRatio || 1;

    this.renderer = new THREE.WebGLRenderer({
        // PERF-B7: MSAA is redundant when the device pixel ratio already
        // exceeds ~1.5 (the extra samples are invisible) and it is one of the
        // most expensive fixed-function features on mobile GPUs.
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
    // PERF-D24 (3D audit): counters accumulate across ALL composer passes
    // (autoReset would wipe them at each intermediate render). GalleryScene
    // resets them once per frame — the ?debug=1 panel then shows TRUE
    // per-frame draw calls, which is how the PERF-D21 merges get verified.
    this.renderer.info.autoReset = false;

    this.container.appendChild(this.renderer.domElement);

    // S-7: WebGL context-loss handling.
    // On Windows with switchable GPUs, driver crashes, or sleep/wake,
    // the WebGL context can be lost. Without handling, the renderer
    // silently stops and the visitor sees a frozen frame.
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
        // PERF-B10: if the page was disposed (pagehide/beforeunload) before
        // the restore fired, do NOT resurrect a dead scene.
        if (this._disposed) {
            this._contextLost = true;
            return;
        }
        this._contextLost = false;

        const overlay = document.getElementById('webgl-recovery');
        if (overlay) overlay.style.display = 'none';

        // The scene needs to be re-initialized — re-create materials,
        // re-upload textures, re-set renderer state.
        // GalleryScene.dispose() was called on context loss (if wired),
        // so we re-init from scratch.
        if (this.init) {
            try {
                this.init();
            } catch (e) {
                console.error('Scene rebuild failed after context restore:', e);
            }
        }
    }, false);
}

// Full hardware tier detection: CPU + GPU + RAM + runtime FPS benchmark.
// Sets this.isLowEnd, applies quality reductions, and starts a deferred
// FPS benchmark that can downgrade mid-session.
export function detectLowEnd() {
    let isLowEnd = earlyLowEndCheck();
    const reasons = [];

    // PERF-B7 (3D audit F7): establish the device class BEFORE textures load
    // so pickTextureUrl() and the mobile tier below take effect from the
    // first request. Mobile.js re-sets this.isMobile later to the same value.
    this.isMobile = isCoarsePointer();

    // ── CPU cores ─────────────────────────────────────────────────────────
    if (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) {
        isLowEnd = true;
        reasons.push(`CPU cores: ${navigator.hardwareConcurrency}`);
    }

    // ── GPU string analysis (best effort) ──────────────────────────────────
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

            if (isSoftware)     { isLowEnd = true; reasons.push(`Software renderer: ${rendererStr}`); }
            if (isBudgetMobile) { isLowEnd = true; reasons.push(`Budget mobile GPU: ${rendererStr}`); }
            if (isOldIntel)     { isLowEnd = true; reasons.push(`Old Intel iGPU: ${rendererStr}`); }
        }
    } catch (e) {
        reasons.push('GPU info unavailable');
    }

    // ── RAM ────────────────────────────────────────────────────────────────
    if (navigator.deviceMemory && navigator.deviceMemory < 4) {
        isLowEnd = true;
        reasons.push(`RAM: ${navigator.deviceMemory}GB`);
    }

    // Schedule a deferred FPS benchmark that can downgrade mid-session if
    // the GPU detection missed a slow device. The benchmark warms up for 2s
    // then samples 20 frames — if average FPS is below 35, we apply low-end
    // settings retroactively.
    this._scheduleFpsBenchmark = _scheduleFpsBenchmark;
    this._scheduleFpsBenchmark();

    // (Task H37 / audit C4) — respect prefers-reduced-motion. Users with
    // vestibular disorders need bloom, vignette, and camera lean disabled.
    // We treat reduced-motion the same as low-end for rendering purposes,
    // but ALSO set a flag so other modules (Tour.js, Controls.js) can
    // disable motion-based effects.
    const reducedMotion = window.EXOSPACE_REDUCED_MOTION === true;
    this.reducedMotion = reducedMotion;

    if (reducedMotion) {
        isLowEnd = true;
        reasons.push('prefers-reduced-motion: reduce');
    }

    this.isLowEnd = isLowEnd;
    if (isLowEnd) {
        console.log('⚡ Low-end mode:', reasons.join(', '));
        applyLowEndSettings.call(this);
    } else if (this.isMobile) {
        // PERF-B7 (3D audit F7): modern phones pass every check above (8
        // cores, 8 GB) and used to receive the FULL desktop quality stack —
        // DPR 1.5 + MSAA + bloom + 6 proximity lights + a ~10 MB HDRI. Mid-
        // range phones cannot sustain that. The mobile tier trims cost in
        // ways that are genuinely invisible at phone screen sizes:
        //   • pixel ratio capped at 1.25 (the canvas is ≤ ~420 CSS px wide)
        //   • HDRI skipped (ambient + hemisphere carry the lighting)
        //   • 4 pooled artwork lights instead of 6-8
        //   • anisotropy capped at 2
        //   • bloom off by default (PerformanceControls 'auto' → mobile)
        // The deferred FPS benchmark can still downgrade further, and users
        // can override via the ?debug=1 quality panel.
        console.log('📱 Mobile tier: pixelRatio 1.25, HDRI off, 4 pooled lights');
        applyMobileSettings.call(this);
    } else {
        console.log('🚀 High-end mode: full quality enabled');
        // Initialize post-processing only on high-end.
        // Re-init safety (context restore): the previous composer references
        // the DEAD context's render targets and the OLD scene/camera objects —
        // dispose it before creating a fresh one (its window resize listener
        // used to leak on every rebuild).
        if (this._postFx) { this._postFx.dispose(); this._postFx = null; }
        this._postFx = new PostProcessing(this.renderer, this.scene, this.camera);
    }

    return isLowEnd;
}

// ── FPS benchmark — runs 2s after load, samples 20 frames, downgrades if slow ─
function _scheduleFpsBenchmark() {
    // Already flagged low-end? Skip — no point burning 3s of rAF to confirm.
    if (this.isLowEnd) return;

    let frameCount = 0;
    let startTime = null;
    const SAMPLE_FRAMES = 20;
    const FPS_THRESHOLD = 35;
    const WARMUP_MS = 2000;

    const measureFrame = (timestamp) => {
        if (!startTime) startTime = timestamp;

        const elapsed = timestamp - startTime;
        if (elapsed < WARMUP_MS) {
            requestAnimationFrame(measureFrame);
            return;
        }

        frameCount++;

        if (frameCount >= SAMPLE_FRAMES) {
            const measuredFps = frameCount / ((timestamp - startTime - WARMUP_MS) / 1000);
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

    requestAnimationFrame(measureFrame);
}

// Apply all low-end quality reductions in one place
export function applyLowEndSettings() {
    this.renderer.setPixelRatio(1);
    this.renderer.shadowMap.enabled = false;
    if (this.scene.fog) {
        // SCALE the venue's declared fog range instead of overriding it with
        // absolute constants — a venue's fog colour/intent (bright-gallery
        // haze, museum gloom) must survive degradation (config is the identity
        // source; only the reach shrinks).
        this.scene.fog.near = Math.max(2, this.scene.fog.near * 0.4);
        this.scene.fog.far  = Math.max(8, this.scene.fog.far  * 0.5);
    }
    document.body.classList.add('low-end-device');
    this.isLowEnd = true;
    this._skipHdri      = true;  // HDRI is 10MB — skip on low-end
    this._maxAnisotropy = 1;     // anisotropic filtering is expensive on budget GPUs
}

// PERF-B7 (3D audit F7): mobile quality tier — see detectLowEnd for rationale.
// Distinct from low-end: PBR materials + per-artwork lighting stay on; only
// the costs that are invisible at phone sizes are trimmed.
export function applyMobileSettings() {
    this._isMobileTier = true;
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.25));
    if (this.scene.fog) {
        this.scene.fog.near = Math.min(this.scene.fog.near, 8);
        this.scene.fog.far  = Math.min(this.scene.fog.far, 24);
    }
    this._maxActiveLights = 4;
    this._skipHdri        = true;   // the single biggest mobile payload
    this._maxAnisotropy   = 2;
    document.body.classList.add('mobile-tier');
    // Post-processing stays available (vignette is cheap); PerformanceControls
    // resolves 'auto' → the mobile quality level, which disables bloom.
    // Re-init safety: dispose the previous composer first (see detectLowEnd).
    if (this._postFx) { this._postFx.dispose(); this._postFx = null; }
    this._postFx = new PostProcessing(this.renderer, this.scene, this.camera);
}
