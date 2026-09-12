import * as THREE from 'three';
import { parseColor } from './config.js';
import { hashString, mulberry32, venueSeedSource } from './Rng.js';

export function normalizeMotion(speed, { deadZone = 0.18, refSpeed = 3.0, exponent = 0.8 } = {}) {
    if (!Number.isFinite(speed) || speed <= deadZone) return 0;
    const span = Math.max(0.001, refSpeed - deadZone);
    const t = Math.min(1, (speed - deadZone) / span);
    return Math.pow(t, exponent);
}

export function smoothGlitch(current, target, dt, { attackTau = 0.18, releaseTau = 1.1 } = {}) {
    const tau = target > current ? attackTau : releaseTau;
    const k = 1 - Math.exp(-Math.max(0, dt) / Math.max(0.001, tau));
    return current + (target - current) * k;
}

export function deriveArtworkVariation(seedSource) {
    const next = mulberry32(hashString(String(seedSource)));
    const rows = [16, 22, 28][Math.floor(next() * 3)];
    return {
        seed: Math.floor(next() * 8192),              // shader phase seed
        intensity: 0.72 + next() * 0.53,          // 0.72 .. 1.25
        rows,
    };
}

export function resolveReactiveMode({ isLowEnd = false, isMobileTier = false, reducedMotion = false, declared = null } = {}) {
    if (!declared || typeof declared !== 'object' || declared.enabled === false) {
        return { mode: 'off', channel: 0, scan: 0, block: 0, steps: 1, idle: 0, maxScale: 0 };
    }
    if (reducedMotion) {
        return { mode: 'calm', channel: 0.0022, scan: 0.25, block: 0.5, steps: 6, idle: 0, maxScale: 0.35 };
    }
    if (isLowEnd) {
        // Cheapest tier: same shader, smallest amplitudes, no idle cost path.
        return { mode: 'reduced', channel: 0.0026, scan: 0.45, block: 0.55, steps: 8, idle: 0, maxScale: 1 };
    }
    if (isMobileTier) {
        return { mode: 'reduced', channel: 0.0034, scan: 0.55, block: 0.75, steps: 10, idle: 0, maxScale: 1 };
    }
    return { mode: 'full', channel: 0.0042, scan: 0.7, block: 1.0, steps: 12, idle: 0.02, maxScale: 1 };
}

const REACTIVE_COMMON = /* glsl */`
uniform float uTime;      // seconds, scene-driven (stepped inside)
uniform float uGlitch;    // shared smoothed visitor-motion level 0..1
uniform float uIntensity; // per-artwork multiplier (deterministic variation)
uniform float uSeed;      // per-artwork phase seed
uniform float uRows;      // per-artwork tear-row density
uniform float uChannel;   // tier-scaled channel separation amplitude
uniform float uScan;      // tier-scaled scanline depth
uniform float uSteps;     // tier-scaled time-step granularity
uniform float uBlock;     // tier-scaled corruption-block displacement

float exHash13( float p3 ) {
    p3 = fract( p3 * 0.1031 );
    p3 *= p3 + 33.33;
    p3 *= p3 + p3;
    return fract( p3 );
}
float exHash23( vec3 p3 ) {
    p3 = fract( p3 * vec3( 0.1031, 0.1030, 0.0973 ) );
    p3 += dot( p3, p3.yxz + 33.33 );
    return fract( ( p3.x + p3.y ) * p3.z );
}
`;

const REACTIVE_MAP_FRAGMENT = /* glsl */`
#ifdef USE_MAP
    vec2 gUv      = vMapUv;
    float gAmp    = uGlitch * uIntensity;
    float tFast   = floor( uTime * uSteps ) / uSteps;
    float tSlow   = floor( uTime * uSteps * 0.22 ) / ( uSteps * 0.22 );

    if ( gAmp > 0.045 ) {
        // 1 — slice tearing: a glitch-scaled fraction of rows, bounded shift.
        float rowId    = floor( vMapUv.y * uRows );
        float rowGate  = step( 1.0 - gAmp * 0.32, exHash23( vec3( rowId, tFast, uSeed ) ) );
        float rowShift = exHash23( vec3( rowId + 7.7, tFast, uSeed ) ) - 0.5;
        gUv.x += rowGate * rowShift * 0.05 * gAmp;

        // 2 — block corruption: rare cells on the slow step, quantized jump.
        vec2 cell    = floor( vMapUv * vec2( 6.0, 4.0 ) );
        float cellG  = step( 1.0 - gAmp * 0.10, exHash23( vec3( cell, tSlow + uSeed * 1.7 ) ) );
        vec2 cellSft = vec2(
            exHash23( vec3( cell.x + 3.1, cell.y, tSlow + uSeed ) ) - 0.5,
            exHash23( vec3( cell.x, cell.y + 9.4, tSlow + uSeed * 1.3 ) ) - 0.5 ) * 0.045;
        gUv += cellG * cellSft * gAmp * uBlock;
        gUv = fract( gUv );
    }

    // 3 — channel separation (tier-scaled; branches out when negligible).
    float sep = uChannel * gAmp;
    vec3 gColor;
    if ( sep > 0.0008 ) {
        vec2 sDir = vec2( sep, 0.0 );
        gColor = vec3(
            texture2D( map, gUv + sDir ).r,
            texture2D( map, gUv ).g,
            texture2D( map, gUv - sDir ).b );
    } else {
        gColor = texture2D( map, gUv ).rgb;
    }

    // 4 — scanline interference (bounded, non-strobing).
    float scanBands = 0.5 + 0.5 * sin( vMapUv.y * 240.0 + uTime * 3.0 );
    gColor *= 1.0 - uScan * gAmp * 0.12 * scanBands;

    // 5 — rare luminance snap (signal life; suppressed on calm tier by its
    // reduced amplitude and absence of idle).
    gColor *= 1.0 + gAmp * 0.03 * step( 0.965, exHash13( tFast + uSeed ) );

    vec4 sampledDiffuseColor = vec4( gColor, 1.0 );
    #ifdef DECODE_VIDEO_TEXTURE
        // morton-order video texture decode (mirrors the stock chunk)
        sampledDiffuseColor = vec4( mix( pow( sampledDiffuseColor.rgb * 0.9478672985784805 + vec3( 0.05213270142218215 ), vec3( 2.4 ) ), sampledDiffuseColor.rgb, step( sampledDiffuseColor.b, vec3( 0.04045 ) ) ), sampledDiffuseColor.a );
    #endif
    diffuseColor *= sampledDiffuseColor;
#endif
`;

const QA_MOTION_KEY = 'EXOSPACE_QA_MOTION'; // deterministic harness override

export function initArtworkReactive() {
    if (this._reactive?.bezelMat) {
        this._reactive.bezelMat.dispose();
    }

    const declared = this._venueArtworkReactive || null;
    const mode = resolveReactiveMode({
        isLowEnd: !!this.isLowEnd,
        isMobileTier: !!this._isMobileTier,
        reducedMotion: !!this.reducedMotion,
        declared,
    });

    if (mode.mode === 'off') {
        this._reactive = null;
        return;
    }

    // Declaration tuning (all optional — the DB may adjust feel, never tier).
    const d = declared || {};
    const num = (v, fallback) => (Number.isFinite(Number(v)) && Number(v) > 0 ? Number(v) : fallback);

    this._reactive = {
        mode,
        shared: {
            uTime:    { value: 0 },
            uGlitch:  { value: 0 },
            uChannel: { value: mode.channel },
            uScan:    { value: mode.scan },
            uSteps:   { value: mode.steps },
            uBlock:   { value: mode.block },
        },
        // Signal tuning (declaration overrides the designed defaults)
        deadZone:   num(d.dead_zone, 0.18),
        refSpeed:   num(d.ref_speed, 3.0),
        attackTau:  num(d.attack, 0.18),
        releaseTau: num(d.release, 1.1),
        maxScale:   mode.maxScale * (Number.isFinite(Number(d.max_intensity)) ? Math.min(1, Math.max(0.05, Number(d.max_intensity))) : 1),
        idle:       mode.idle,
        // State
        level: 0,
        lastTs: 0,
        bezelMat: null,
        bezelBase: null,
        bezelAccent: typeof d.bezel_color === 'string' ? d.bezel_color : '0x00e5ff',
        seededSlug: this._venueSlug || 'venue',
    };
}

export function patchReactiveMaterial(material, artworkId) {
    const R = this._reactive;
    if (!R || !material) return material;

    const variation = deriveArtworkVariation(`${venueSeedSource(R.seededSlug)}:${artworkId}`);

    const perArtwork = {
        uSeed:      { value: variation.seed },
        uIntensity: { value: variation.intensity },
        uRows:      { value: variation.rows },
    };

    material.onBeforeCompile = (shader) => {
        Object.assign(shader.uniforms, R.shared, perArtwork);
        shader.fragmentShader = shader.fragmentShader
            .replace('#include <common>', `#include <common>\n${REACTIVE_COMMON}`)
            .replace('#include <map_fragment>', REACTIVE_MAP_FRAGMENT);
    };
    material.customProgramCacheKey = () => 'exospace-reactive-canvas-v1';

    return material;
}

export function makeReactiveBezelMaterial() {
    const R = this._reactive;
    if (!R) return null;
    if (!R.bezelMat) {
        const c = parseColor(R.bezelAccent) || new THREE.Color(0x00e5ff);
        c.multiplyScalar(1.15);
        R.bezelBase = c.clone();
        R.bezelMat = new THREE.MeshBasicMaterial({ color: c });
    }
    return R.bezelMat;
}

export function updateArtworkReactive() {
    const R = this._reactive;
    if (!R) return;

    const now = performance.now();
    let dt = R.lastTs ? (now - R.lastTs) / 1000 : 0.016;
    R.lastTs = now;
    if (dt > 0.1) dt = 0.1;   // tab-switch / hitch clamp
    if (dt < 0)    dt = 0;

    let speed = 0;
    const qa = (typeof window !== 'undefined') ? window[QA_MOTION_KEY] : undefined;
    const qaOverrideActive = qa !== undefined && qa !== null && Number.isFinite(Number(qa));
    if (qaOverrideActive) {
        speed = Number(qa);
    } else if (this.isMobile) {
        if (!this.arrivalActive) {
            const v = this.velocity;
            speed = Math.sqrt(v.x * v.x + v.z * v.z);
        }
    } else if (this.controls?.isLocked && !this.isInspecting && !this.arrivalActive) {
        const v = this.velocity;
        speed = Math.sqrt(v.x * v.x + v.z * v.z);
    }

    const target = normalizeMotion(speed, {
        deadZone: R.deadZone, refSpeed: R.refSpeed,
    }) * R.maxScale;

    if (qaOverrideActive) {
        R.level = target;
    } else {
        const idleFloor = R.idle * (0.75 + 0.25 * Math.sin(now * 0.0007));
        const leveled = Math.max(target, idleFloor);
        R.level = smoothGlitch(R.level, leveled, dt, {
            attackTau: R.attackTau, releaseTau: R.releaseTau,
        });
    }

    R.shared.uTime.value   = now * 0.001;
    R.shared.uGlitch.value = R.level;

    if (R.bezelMat && R.bezelBase && R.idle > 0) {
        const breathe = 1 + 0.1 * Math.sin(now * 0.0011);
        R.bezelMat.color.copy(R.bezelBase).multiplyScalar(breathe);
    }
    // calm/reduced tiers: static boundary — set once at creation, untouched.
}

export function disposeArtworkReactive() {
    if (this._reactive) {
        this._reactive.bezelMat?.dispose?.();
        this._reactive = null;
    }
}
