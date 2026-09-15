import * as THREE from 'three';
import { EffectComposer }   from 'three/addons/postprocessing/EffectComposer.js';
import { RenderPass }       from 'three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass }  from 'three/addons/postprocessing/UnrealBloomPass.js';
import { ShaderPass }       from 'three/addons/postprocessing/ShaderPass.js';
import { VignetteShader }   from 'three/addons/shaders/VignetteShader.js';
import { OutputPass }       from 'three/addons/postprocessing/OutputPass.js';
import { CONFIG } from './config.js';

const ExospaceVignetteShader = {
    name: 'ExospaceVignetteShader',
    uniforms: {
        tDiffuse:     { value: null },
        offset:       { value: 1.0 },
        darkness:     { value: 1.0 },
        blendTarget:  { value: new THREE.Vector3(0, 0, 0) },
    },
    vertexShader: VignetteShader.vertexShader,
    fragmentShader: /* glsl */`
        uniform float offset;
        uniform float darkness;
        uniform vec3  blendTarget;

        uniform sampler2D tDiffuse;

        varying vec2 vUv;

        void main() {
            vec4 texel = texture2D( tDiffuse, vUv );
            vec2 uv = ( vUv - vec2( 0.5 ) ) * vec2( offset );
            gl_FragColor = vec4( mix( texel.rgb, blendTarget, dot( uv, uv ) ), texel.a );
        }`,
};

export class PostProcessing {
    constructor(renderer, scene, camera) {
        this.renderer = renderer;
        this.scene    = scene;
        this.camera   = camera;

        const reducedMotion = window.EXOSPACE_REDUCED_MOTION === true;
        if (reducedMotion) {
            // Still create a minimal composer so render() works
            this.composer = new EffectComposer(renderer);
            this.composer.addPass(new RenderPass(scene, camera));
            this.composer.addPass(new OutputPass());
            this._onResize = () => this.resize();
            window.addEventListener('resize', this._onResize);
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

        if (bloomCfg.vignette) {
            this.vignettePass = new ShaderPass(ExospaceVignetteShader);
            this.vignettePass.uniforms['offset'].value   = bloomCfg.vignetteOffset;
            this.vignettePass.uniforms['darkness'].value = bloomCfg.vignetteDarkness;
            this._vignetteBlend = 'grey';
            this._syncVignetteBlendTarget();
            this.composer.addPass(this.vignettePass);
        }

        // Output pass — handles color space conversion at the end of the chain
        this.composer.addPass(new OutputPass());

        this._onResize = () => this.resize();
        window.addEventListener('resize', this._onResize);
    }

    applyVenueConfig(fx) {
        if (!fx || typeof fx !== 'object') return;
        this._venueBloom = fx.bloom;
        if (this.bloomPass) {
            if (fx.bloom === false) this.bloomPass.enabled = false;
            if (fx.bloom_strength  != null) this.bloomPass.strength  = fx.bloom_strength;
            if (fx.bloom_threshold != null) this.bloomPass.threshold = fx.bloom_threshold;
            if (fx.bloom_radius    != null) this.bloomPass.radius    = fx.bloom_radius;
        }
        if (this.vignettePass) {
            if (fx.vignette === false) this.vignettePass.enabled = false;
            if (fx.vignette_darkness != null) this.vignettePass.uniforms['darkness'].value = fx.vignette_darkness;
            if (fx.vignette_offset   != null) this.vignettePass.uniforms['offset'].value  = fx.vignette_offset;
            if (fx.vignette_blend === 'black' || fx.vignette_blend === 'grey') {
                this._vignetteBlend = fx.vignette_blend;
                this._syncVignetteBlendTarget();
            }
        }
    }

    _syncVignetteBlendTarget() {
        if (!this.vignettePass) return;
        const t = this.vignettePass.uniforms['blendTarget'].value;
        if (this._vignetteBlend === 'black') t.set(0, 0, 0);
        else t.setScalar(1 - this.vignettePass.uniforms['darkness'].value);
    }

    setBloomStrength(value) {
        if (this.bloomPass) this.bloomPass.strength = value;
    }

    setBloomEnabled(enabled) {
        if (!this.bloomPass) return;
        this.bloomPass.enabled = enabled && this._venueBloom !== false;
    }

    resize() {
        const w = window.innerWidth;
        const h = window.innerHeight;
        this.composer.setPixelRatio(this.renderer.getPixelRatio());
        this.composer.setSize(w, h);
    }

    // Called by PerformanceControls after an adaptive DPR change.
    syncPixelRatio() {
        if (!this.composer) return;
        this.composer.setPixelRatio(this.renderer.getPixelRatio());
        this.composer.setSize(window.innerWidth, window.innerHeight);
    }

    render() {
        this.composer.render();
    }

    dispose() {
        if (this._disposed) return;
        this._disposed = true;
        if (this._onResize) window.removeEventListener('resize', this._onResize);
        this._onResize = null;
        const composer = this.composer;
        if (!composer) return;
        for (const pass of (composer.passes || [])) {
            if (typeof pass.dispose === 'function') {
                try { pass.dispose(); } catch (e) { /* pass may hold a dead context */ }
            }
        }
        composer.renderTarget1?.dispose?.();
        composer.renderTarget2?.dispose?.();
        composer.passes = [];
        this.composer = null;
    }

    applyPatch(patch) {
        if (!patch || typeof patch !== 'object') return;

        if (this.bloomPass) {
            if (patch.bloom_strength  !== undefined && patch.bloom_strength  !== null) {
                this.bloomPass.strength  = patch.bloom_strength;
                if (this._venueBloom === false) this.bloomPass.enabled = true;
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
                this._syncVignetteBlendTarget();
            }
            if (patch.vignette_offset   !== undefined && patch.vignette_offset   !== null) {
                this.vignettePass.uniforms['offset'].value   = patch.vignette_offset;
            }
        }
    }
}
