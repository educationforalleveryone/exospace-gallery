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

// Creates the WebGLRenderer, attaches it to #canvas-container, sets tone-mapping
// and color space. Called from GalleryScene.init().
export function initRenderer() {
    const earlyLowEnd = earlyLowEndCheck();

    this.renderer = new THREE.WebGLRenderer({
        antialias: !earlyLowEnd,
        powerPreference: earlyLowEnd ? 'low-power' : 'high-performance',
    });
    this.renderer.setSize(window.innerWidth, window.innerHeight);
    this.renderer.setPixelRatio(earlyLowEnd ? 1 : Math.min(window.devicePixelRatio, 2));
    this.renderer.shadowMap.enabled = CONFIG.performance.shadowsEnabled;
    this.renderer.shadowMap.type    = THREE.PCFSoftShadowMap;
    this.renderer.toneMapping       = THREE.ACESFilmicToneMapping;
    this.renderer.toneMappingExposure = 0.8;
    this.renderer.outputColorSpace  = THREE.SRGBColorSpace;

    this.container.appendChild(this.renderer.domElement);
}

// Full hardware tier detection: CPU + GPU + RAM + runtime FPS benchmark.
// Sets this.isLowEnd, applies quality reductions, and starts a deferred
// FPS benchmark that can downgrade mid-session.
export function detectLowEnd() {
    let isLowEnd = earlyLowEndCheck();
    const reasons = [];

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

    this.isLowEnd = isLowEnd;
    if (isLowEnd) {
        console.log('⚡ Low-end mode:', reasons.join(', '));
        applyLowEndSettings.call(this);
    } else {
        console.log('🚀 High-end mode: full quality enabled');
        // Initialize post-processing only on high-end
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
        this.scene.fog.near = 5;
        this.scene.fog.far  = 14;
    }
    document.body.classList.add('low-end-device');
    this.isLowEnd = true;
    this._skipHdri      = true;  // HDRI is 10MB — skip on low-end
    this._maxAnisotropy = 1;     // anisotropic filtering is expensive on budget GPUs
}
