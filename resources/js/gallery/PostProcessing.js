// ─────────────────────────────────────────────────────────────────────────────
// PostProcessing — bloom + vignette (off on low-end)
//
// EffectComposer is loaded from three/addons/postprocessing/ which Vite now
// bundles locally (no more unpkg CDN).
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
}
