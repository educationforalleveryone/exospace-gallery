// ─────────────────────────────────────────────────────────────────────────────
// ArtworkReactive — movement-reactive artwork media (Cyber Gallery signature)
//
// WHY THIS EXISTS (Cyber Gallery iteration, v2.0.0 "Signal Room"):
//   Cyber Gallery's identity mechanic: the artworks are not framed prints —
//   they are LIVING DIGITAL MEDIA. When the visitor stands still the artworks
//   hold a clean, gallery-legible state. When the visitor moves, the canvases
//   develop a controlled digital instability (slice tearing, subtle channel
//   separation, scanline interference). When the visitor stops, the effect
//   decays smoothly and the artwork resolves back into clarity.
//
// ARCHITECTURE (follows the house rules — §10.2 / §11.3 / DoD #7):
//   • Config-declared, zero slug knowledge: a venue opts in by declaring
//     visual_config.artwork_reactive = { enabled: true, ...tuning }. The DB
//     is the sole source of the identity; this file only interprets keys.
//   • The effect belongs to the ARTWORKS, not the scene: it is injected into
//     each canvas material via onBeforeCompile — no post-processing pass, no
//     render targets, no extra draw calls for the distortion itself, no
//     scene-wide corruption. Walls / floor / structure never glitch.
//   • The signal reuses the EXISTING movement system: GalleryScene.velocity
//     (maintained by Movement.js on desktop and Mobile.js on touch). No
//     parallel movement detector is created.
//   • Deterministic: per-artwork variation derives from the seeded Rng
//     (hash(slug:galleryId:artworkId)) — the same gallery renders the same
//     character on every load. No Math.random() anywhere in this module.
//   • Performance: ONE shared uniform object set (uTime, uGlitch, tier
//     scalars) written twice per frame; per-artwork uniforms are static per
//     build; zero allocations in the update loop; the clean state branches
//     to a single texture fetch, identical cost to an unpatched material.
//
// TIER MODEL (degradation is DESIGNED — TierResolve pattern):
//   full    (high tier)  — all layers + a barely-perceptible idle presence
//   reduced (mobile)     — same shader, smaller amplitudes, no idle
//   reduced (low-end)    — same shader on MeshBasicMaterial, smallest
//                          amplitudes, no idle; the identity survives
//   calm    (reduced motion) — the effect stays discoverable but gentle
//                          (max 35% intensity, no idle shimmer, no flicker)
//   off     (not declared / enabled:false) — nothing registers, zero cost
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { parseColor } from './config.js';
import { hashString, mulberry32, venueSeedSource } from './Rng.js';

// ─────────────────────────────────────────────────────────────────────────────
// PURE DECISION CORE (no THREE import — unit-testable in plain Node, §10.8)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Map a planar movement speed (m/s) to a normalized reaction target 0..1.
 *
 * The dead zone swallows numeric noise and micro-adjustments (footstep jitter,
 * damping tails below ~0.18 m/s never trigger the artwork). `refSpeed` is the
 * speed that saturates the effect — walking pace (CONFIG.camera.maxSpeed = 3)
 * reads as "fully reactive"; sprinting saturates identically rather than
 * glitching harder than the artwork can survive. `exponent` < 1 lifts the
 * low end so slow walking is clearly perceptible (the reaction must read
 * before it must impress).
 *
 * @param {number} speed  planar speed in m/s
 * @param {object} o      { deadZone, refSpeed, exponent }
 * @returns {number} 0..1
 */
export function normalizeMotion(speed, { deadZone = 0.18, refSpeed = 3.0, exponent = 0.8 } = {}) {
    if (!Number.isFinite(speed) || speed <= deadZone) return 0;
    const span = Math.max(0.001, refSpeed - deadZone);
    const t = Math.min(1, (speed - deadZone) / span);
    return Math.pow(t, exponent);
}

/**
 * Asymmetric exponential smoothing — the responsive/decay heart of the effect.
 *
 * Rising (accelerating) uses the short attackTau so the artwork reacts within
 * a couple of frames of the visitor actually moving. Falling (stopped) uses
 * the longer releaseTau so the glitch "remains briefly, then settles" — never
 * the binary GLITCH=1 → GLITCH=0 snap the brief forbids. Exponential form is
 * frame-rate independent (verified by the venue QA script).
 *
 * @param {number} current  smoothed level 0..1
 * @param {number} target   desired level 0..1
 * @param {number} dt       seconds since last update (clamped by caller)
 * @param {object} o        { attackTau, releaseTau } seconds
 * @returns {number} new smoothed level
 */
export function smoothGlitch(current, target, dt, { attackTau = 0.18, releaseTau = 1.1 } = {}) {
    const tau = target > current ? attackTau : releaseTau;
    const k = 1 - Math.exp(-Math.max(0, dt) / Math.max(0.001, tau));
    return current + (target - current) * k;
}

/**
 * Per-artwork deterministic variation.
 *
 * The visitor must perceive "multiple digital artworks responding", not one
 * global shader applied identically to every image. Each artwork receives a
 * stable phase seed (its tearing pattern never synchronises with its
 * neighbours), an intensity multiplier (some works are more volatile than
 * others) and its own tear-row density. All values derive from
 * hash(slug:galleryId:artworkId) via the shared Rng — same gallery, same
 * character, every load (§13.6 determinism bar).
 *
 * @param {string} seedSource  `${venueSeedSource(slug)}:${artworkId}`
 * @returns {{seed:number, intensity:number, rows:number}}
 */
export function deriveArtworkVariation(seedSource) {
    // mulberry32 returns the raw next() function (createVenueRng wraps it —
    // here the direct form avoids an object allocation per artwork).
    const next = mulberry32(hashString(String(seedSource)));
    const rows = [16, 22, 28][Math.floor(next() * 3)];
    return {
        seed: Math.floor(next() * 8192),              // shader phase seed
        intensity: 0.72 + next() * 0.53,          // 0.72 .. 1.25
        rows,
    };
}

/**
 * Resolve the designed tier degradation for a declared reactive identity.
 *
 * Pure: (declaration, device tier, accessibility preference) → mode params.
 * The mode never removes the core mechanic (movement → reaction) — it scales
 * the LAYERS: channel separation amplitude, scanline depth, corruption-block
 * displacement, time-step granularity and the idle presence. Only an absent
 * or explicitly disabled declaration produces 'off'.
 *
 * @param {object} input
 * @param {boolean} input.isLowEnd
 * @param {boolean} input.isMobileTier
 * @param {boolean} input.reducedMotion
 * @param {object|null} input.declared  visual_config.artwork_reactive
 * @returns {{mode:'full'|'reduced'|'calm'|'off', channel:number, scan:number,
 *            block:number, steps:number, idle:number, maxScale:number}}
 */
export function resolveReactiveMode({ isLowEnd = false, isMobileTier = false, reducedMotion = false, declared = null } = {}) {
    if (!declared || typeof declared !== 'object' || declared.enabled === false) {
        return { mode: 'off', channel: 0, scan: 0, block: 0, steps: 1, idle: 0, maxScale: 0 };
    }
    if (reducedMotion) {
        // Vestibular safety: the mechanic stays legible (the venue keeps its
        // identity) but gentle — reduced separation, no rare flicker snaps,
        // no idle shimmer, hard 35% intensity ceiling.
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

// ─────────────────────────────────────────────────────────────────────────────
// GLSL — the artwork-side glitch language
//
// Controlled / precise / elegant, in that order:
//   1. horizontal slice tearing — only a glitch-scaled FRACTION of rows ever
//      shift, by a bounded amount (≤ ~2.5% of the canvas width), so the image
//      tears instead of dissolving;
//   2. block corruption — rare, cell-quantized displacements on a slower time
//      step (the "localized corruption" the brief allows at speed);
//   3. channel separation — sub-pixel-to-few-pixel RGB split, tier-scaled;
//   4. scanline interference — ≤ ~8% luminance banding, never strobing;
//   5. a rare +3% luminance snap on the fast step (signal interference life).
// Time is STEPPED (floor(uTime * uSteps) / uSteps): artifacts snap in digital
// increments instead of sliding smoothly — the difference between "living
// image" and "wobbly watermark".
//
// CLEAN-STATE CONTRACT: when the reaction is below its thresholds the shader
// takes a single texture fetch and multiplies — byte-identical output to the
// unpatched material. Stationary = beautiful and readable, always.
// ─────────────────────────────────────────────────────────────────────────────

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

// ─────────────────────────────────────────────────────────────────────────────
// RUNTIME (bound to the GalleryScene instance via .call(this))
// ─────────────────────────────────────────────────────────────────────────────

const QA_MOTION_KEY = 'EXOSPACE_QA_MOTION'; // deterministic harness override

/**
 * (Re)initialize the reactive registry for THIS build. Called by
 * GalleryScene.buildGallery before placeArtworks — rebuilds (Live Preview
 * structural reloads, context restores) start from a clean registry, the same
 * hygiene rule the artwork/particle registries follow.
 */
export function initArtworkReactive() {
    // Rebuild hygiene: drop the previous build's registry + bezel material
    // before re-resolving (context restores / rebuilds re-run this).
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
        // Shared bezel material (luminous display boundary) — one instance for
        // the whole build so its breathing costs one color write per frame.
        bezelMat: null,
        bezelBase: null,
        bezelAccent: typeof d.bezel_color === 'string' ? d.bezel_color : '0x00e5ff',
        seededSlug: this._venueSlug || 'venue',
    };
}

/**
 * Patch a canvas material into reactive media. Called by ArtworkPlacer.
 * makeArtworkGroup for venues that declare the identity. The material keeps
 * its class (MeshStandardMaterial / MeshBasicMaterial) so PBR lighting, the
 * focus highlight, and the progressive texture swap all keep working — the
 * glitch rides inside the existing shader.
 *
 * @param {THREE.Material} material  the canvas material (map already present)
 * @param {string|number}  artworkId stable artwork identifier
 * @returns {THREE.Material} the same material, patched
 */
export function patchReactiveMaterial(material, artworkId) {
    const R = this._reactive;
    if (!R || !material) return material;

    const variation = deriveArtworkVariation(`${venueSeedSource(R.seededSlug)}:${artworkId}`);

    // Per-artwork uniforms are STATIC per build — the frame loop never
    // touches them. Only the shared uTime/uGlitch are written per frame.
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
    // Stable cache key: all reactive canvases of a tier share ONE program
    // (the injected source is identical); unpatched materials never collide.
    material.customProgramCacheKey = () => 'exospace-reactive-canvas-v1';

    return material;
}

/**
 * Create the shared luminous bezel material (the thin luminous boundary of
 * the display methodology). HDR-ish color so the high-tier bloom reads it as
 * a soft glow — the neon-strip language of the venue, gathered around the
 * artwork itself. One material instance for the whole build.
 *
 * @returns {THREE.MeshBasicMaterial}
 */
export function makeReactiveBezelMaterial() {
    const R = this._reactive;
    if (!R) return null;
    if (!R.bezelMat) {
        // parseColor, never new THREE.Color(string) — the DB stores '0x…'
        // strings and THREE treats those as CSS color names (config.js note).
        const c = parseColor(R.bezelAccent) || new THREE.Color(0x00e5ff);
        // Just above the bloom luminance threshold (~0.82 vs 0.8) — the rim
        // reads as light and takes a soft bloom kiss. The earlier 1.9×
        // multiplier turned the bezel into a blazing light column at
        // inspection distance (the wash-out defect the close-read shot
        // exposed); restraint is what keeps it premium.
        c.multiplyScalar(1.15);
        R.bezelBase = c.clone();
        R.bezelMat = new THREE.MeshBasicMaterial({ color: c });
    }
    return R.bezelMat;
}

/**
 * Per-frame signal + uniform update. Called from GalleryScene.animate() AFTER
 * movement integration so the velocity is fresh. Allocation-free; two shared
 * uniform writes; one bezel color write; ~10 scalar ops total.
 */
export function updateArtworkReactive() {
    const R = this._reactive;
    if (!R) return;

    // ── Frame delta (independent of Movement's clock — getDelta() there is
    // consumed per frame and early-returns elsewhere would stall it).
    const now = performance.now();
    let dt = R.lastTs ? (now - R.lastTs) / 1000 : 0.016;
    R.lastTs = now;
    if (dt > 0.1) dt = 0.1;   // tab-switch / hitch clamp
    if (dt < 0)    dt = 0;

    // ── Speed signal — reused movement architecture, guarded ─────────────
    // Read velocity ONLY when the movement system is actually integrating
    // this frame. On pointer unlock moveState/velocity FREEZE (updateMovement
    // early-returns) — reading blindly would keep the glitch stuck on after
    // the visitor stopped to use the UI. Inspect mode and the arrival dolly
    // likewise own the camera without visitor translation.
    let speed = 0;
    const qa = (typeof window !== 'undefined') ? window[QA_MOTION_KEY] : undefined;
    const qaOverrideActive = qa !== undefined && qa !== null && Number.isFinite(Number(qa));
    if (qaOverrideActive) {
        // Deterministic harness/QA override: drive the SAME normalized signal
        // the live system uses (screenshots exercise the real math).
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

    // ── Normalize → smooth → write ────────────────────────────────────────
    const target = normalizeMotion(speed, {
        deadZone: R.deadZone, refSpeed: R.refSpeed,
    }) * R.maxScale;

    // QA steady-state: the harness override verifies the SHADER's response to
    // a known level — and the capture clock is deliberately stretched (the
    // shoot script pins the tier by slowing performance.now 250×), which
    // would freeze exponential smoothing mid-attack. Under the override the
    // level lands directly on the target; the smoothing math itself is pinned
    // separately by the venue QA script against the pure functions.
    if (qaOverrideActive) {
        R.level = target;
    } else {
        // Idle presence: a barely-perceptible floor (full tier only) so the
        // medium feels alive even at rest — gAmp stays under every distortion
        // threshold, so the clean-state render path is byte-identical to 0.
        const idleFloor = R.idle * (0.75 + 0.25 * Math.sin(now * 0.0007));
        const leveled = Math.max(target, idleFloor);
        R.level = smoothGlitch(R.level, leveled, dt, {
            attackTau: R.attackTau, releaseTau: R.releaseTau,
        });
    }

    R.shared.uTime.value   = now * 0.001;
    R.shared.uGlitch.value = R.level;

    // ── Bezel breathing — the room hums quietly ───────────────────────────
    // One shared material: one color write per frame, zero per-artwork cost.
    // Reduced-motion tiers keep a STATIC bezel (no pulsing).
    if (R.bezelMat && R.bezelBase && R.idle > 0) {
        const breathe = 1 + 0.1 * Math.sin(now * 0.0011);
        R.bezelMat.color.copy(R.bezelBase).multiplyScalar(breathe);
    }
    // calm/reduced tiers: static boundary — set once at creation, untouched.
}

/**
 * Dispose-time cleanup (rebuild hygiene — mirrors the artwork registry).
 */
export function disposeArtworkReactive() {
    if (this._reactive) {
        this._reactive.bezelMat?.dispose?.();
        this._reactive = null;
    }
}
