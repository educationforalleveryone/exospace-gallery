// ─────────────────────────────────────────────────────────────────────────────
// PostProcessing — bloom + vignette (off on low-end)
//
// EffectComposer is loaded from three/addons/postprocessing/ which Vite now
// bundles locally (no more unpkg CDN).
//
// NEW (Live Preview): applyPatch(patch) — accepts a partial post-fx patch
// like { bloom_strength: 0.8, vignette_darkness: 0.4 } and applies it to
// the existing passes without rebuilding the composer. Called by
// GalleryScene.applyLiveOverride() when a curator drags a post-fx slider
// in the admin Live Preview panel.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { EffectComposer }   from 'three/addons/postprocessing/EffectComposer.js';
import { RenderPass }       from 'three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass }  from 'three/addons/postprocessing/UnrealBloomPass.js';
import { ShaderPass }       from 'three/addons/postprocessing/ShaderPass.js';
import { VignetteShader }   from 'three/addons/shaders/VignetteShader.js';
import { OutputPass }       from 'three/addons/postprocessing/OutputPass.js';
import { CONFIG } from './config.js';

export class PostProcessing {
    constructor(renderer, scene, camera) {
        this.renderer = renderer;
        this.scene    = scene;
        this.camera   = camera;

        // (Task H43 / audit C4) — if the user prefers reduced motion,
        // skip bloom + vignette entirely. These effects can cause
        // vestibular discomfort (pulsing glow, edge darkening that
        // shifts with camera movement).
        const reducedMotion = window.EXOSPACE_REDUCED_MOTION === true;
        if (reducedMotion) {
            console.log('⚡ PostProcessing: reduced-motion — bloom + vignette disabled');
            // Still create a minimal composer so render() works
            this.composer = new EffectComposer(renderer);
            this.composer.addPass(new RenderPass(scene, camera));
            this.composer.addPass(new OutputPass());
            window.addEventListener('resize', () => this.resize());
            return;
        }

        const w = window.innerWidth;
        const h = window.innerHeight;

        this.composer = new EffectComposer(renderer);
        this.composer.addPass(new RenderPass(scene, camera));

        // Bloom — makes gold frames + neon strips glow
        const bloomCfg = CONFIG.postFx;
        this.bloomPass = new UnrealBloomPass(
            new THREE.Vector2(w, h),
            bloomCfg.bloomStrength,
            bloomCfg.bloomRadius,
            bloomCfg.bloomThreshold
        );
        if (bloomCfg.bloom) this.composer.addPass(this.bloomPass);

        // Vignette — subtle cinematic darkening at edges
        if (bloomCfg.vignette) {
            this.vignettePass = new ShaderPass(VignetteShader);
            this.vignettePass.uniforms['offset'].value   = bloomCfg.vignetteOffset;
            this.vignettePass.uniforms['darkness'].value = bloomCfg.vignetteDarkness;
            this.composer.addPass(this.vignettePass);
        }

        // Output pass — handles color space conversion at the end of the chain
        this.composer.addPass(new OutputPass());

        // Resize handler
        window.addEventListener('resize', () => this.resize());
    }

    setBloomStrength(value) {
        if (this.bloomPass) this.bloomPass.strength = value;
    }

    setBloomEnabled(enabled) {
        if (!this.bloomPass) return;
        this.bloomPass.enabled = enabled;
    }

    resize() {
        const w = window.innerWidth;
        const h = window.innerHeight;
        this.composer.setSize(w, h);
    }

    render() {
        this.composer.render();
    }

    /**
     * Apply a partial post-FX patch to the running composer.
     *
     * Patch shape (all keys optional):
     *   {
     *     bloom_strength:     number  0..2   — UnrealBloomPass.strength
     *     bloom_threshold:    number  0..1   — UnrealBloomPass.threshold (luminance cutoff)
     *     bloom_radius:       number  0..1   — UnrealBloomPass.radius (glow spread)
     *     vignette_darkness:  number  0..1   — VignetteShader darkness uniform
     *     vignette_offset:    number  0..2   — VignetteShader offset uniform
     *   }
     *
     * Null values are ignored — the curator's "Reset" button should send
     * the venue default explicitly rather than null.
     *
     * Called from GalleryScene.applyLiveOverride(). Safe to call repeatedly
     * (idempotent — sets values, doesn't accumulate).
     */
    applyPatch(patch) {
        if (!patch || typeof patch !== 'object') return;

        if (this.bloomPass) {
            if (patch.bloom_strength  !== undefined && patch.bloom_strength  !== null) {
                this.bloomPass.strength  = patch.bloom_strength;
            }
            if (patch.bloom_threshold !== undefined && patch.bloom_threshold !== null) {
                this.bloomPass.threshold = patch.bloom_threshold;
            }
            if (patch.bloom_radius    !== undefined && patch.bloom_radius    !== null) {
                this.bloomPass.radius    = patch.bloom_radius;
            }
        }

        if (this.vignettePass) {
            if (patch.vignette_darkness !== undefined && patch.vignette_darkness !== null) {
                this.vignettePass.uniforms['darkness'].value = patch.vignette_darkness;
            }
            if (patch.vignette_offset   !== undefined && patch.vignette_offset   !== null) {
                this.vignettePass.uniforms['offset'].value   = patch.vignette_offset;
            }
        }
    }
}
