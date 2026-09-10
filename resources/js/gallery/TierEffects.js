// ─────────────────────────────────────────────────────────────────────────────
// TierEffects — declared tier-fallback effects, material/geometry side
//
// Iteration 2 "Phenomena" (roadmap P1.2 / §11.3). This module is the GENERIC
// interpreter for effects a venue declares in its config JSON. It contains
// ZERO slug references — venues opt in per key, and every decision keyword
// comes from TierResolve.js (the pure, unit-tested decision core):
//
//   visual_config.glass_material = 'transmission'
//     → resolveGlassTier(): 'transmission' (high) | 'cheap' (mobile)
//       | 'flat' (low-end). The mobile/low-end modes exist because
//       MeshPhysicalMaterial.transmission renders NULL without an HDRI
//       environment (mobile + low-end both skip the HDRI) — the verified
//       defect this module exists to make impossible. §11.3: cheap glass =
//       opacity + low roughness, no transmission pass, renders everywhere.
//
//   visual_config.floor_reflection = 'planar'
//     → resolveReflectionMode(): 'planar' (high — real THREE.Reflector) |
//       'gloss' (mobile/low — designed dark-gloss mood: light-streak plane;
//       the venue's own near-zero-roughness floor + moonlight provide the
//       specular response). Mirror Lake's promise is kept on high tier and
//       degraded WITH DIGNITY elsewhere — never to a plain grey plane.
//
//   visual_config.floor_edge_fade = true
//     → resolveFloorFadeMode(): 'shader' | 'basic'. The void-family floor
//       disc dissolves into the venue background instead of ending at a
//       visible geometric seam (§4.2 "the endless must read").
//
// PERFORMANCE NOTES (§11.4 process rule — per-effect perf cost):
//   • transmission: +1 transmission pass on glass meshes, HIGH tier only.
//   • planar reflection: the Reflector renders the scene one extra time per
//     frame at a capped 1024² target (high tier only). Measured impact and
//     draw-call accounting are documented in the Iteration 2 report.
//   • floor fade: one extra transparent ring mesh, no per-frame cost.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { Reflector } from 'three/addons/objects/Reflector.js';
import {
    resolveGlassTier,
    resolveReflectionMode,
    resolveFloorFadeMode,
} from './TierResolve.js';

// ── Glass ────────────────────────────────────────────────────────────────────
// Build the glass material for a declared venue, at the resolved tier.
// `tint` keeps each venue's glass hue without re-introducing slug knowledge —
// the caller passes its own colour.
//
// CATHEDRAL AUDIT extension (2026-09-07): optional `flatShading` +
// `roughness` + `thickness` overrides. Crystal reads through FACETS, not
// smooth tubes — flat shading turns every polygon into a plane with its own
// normal so the architecture catches light as cut stone would (§13). All
// options default to the historical values, so existing callers (Penthouse
// glazing via StructureBuilder {glass:true}, the legacy cathedral colonnade)
// render bit-identically to before.
export function makeGlassMaterial(ctx, { tint = 0xffffff, opacity = 0.35, flatShading = false, roughness, thickness, declared } = {}) {
    const tier = resolveGlassTier({
        isLowEnd: !!ctx.isLowEnd,
        isMobileTier: !!ctx._isMobileTier,
        // v2.1.0: a descriptor may declare the 'cheap' glass class (broad
        // sheen, no transmission pass). Default 'transmission' — every
        // existing caller resolves bit-identically.
        declared: declared ?? 'transmission',
    });

    if (tier === 'transmission') {
        // True glass — high tier has a guaranteed HDRI environment, so
        // transmission can never render null here (Renderer.js: _skipHdri is
        // only set on mobile/low-end).
        return new THREE.MeshPhysicalMaterial({
            color: tint,
            roughness: roughness ?? 0.05,
            metalness: 0.0,
            transmission: 0.92,
            thickness: thickness ?? 0.6,
            ior: 1.5,
            transparent: true,
            opacity: 1.0,
            envMapIntensity: 1.0,
            flatShading,
            side: THREE.DoubleSide,
        });
    }

    if (tier === 'cheap') {
        // §11.3 designed fallback: reads as glass via opacity + low roughness
        // + specular response from the venue's own lights — no transmission
        // pass, no environment dependency, never null.
        return new THREE.MeshPhysicalMaterial({
            color: tint,
            roughness: roughness ?? 0.08,
            metalness: 0.0,
            transparent: true,
            opacity,
            envMapIntensity: 0.5,
            flatShading,
            side: THREE.DoubleSide,
        });
    }

    // 'flat' — low-end material class (Lambert), still visibly glassy.
    return new THREE.MeshLambertMaterial({
        color: tint,
        transparent: true,
        opacity: Math.min(opacity, 0.3),
        flatShading,
        side: THREE.DoubleSide,
    });
}

// ── Planar reflection (high tier) ───────────────────────────────────────────
// Replaces the venue's floor disc with a real planar reflector. The caller
// must pass the existing floor mesh via ctx._circularFloor (set by
// RoomBuilder.createRoomCircular) — it is HIDDEN, not disposed, so a
// session-level quality downgrade can restore it without a rebuild.
//
// CATHEDRAL DEPLOY REVIEW (2026-09-08): opt-in `blend` mode. Reflector's
// stock fragment uses an OVERLAY blend with `color` — and overlay with a
// tint brighter than 0.5 BRIGHTENS midtones while leaving near-white pixels
// at full luminance. On Mirror Lake (dark scene, moonlit water) that is the
// intended look; on the Cathedral the mirror sat under a LIT arcade with
// bloom-capable emissives, so reflected artworks came back at ~full
// brightness, re-entered the main-pass bloom (threshold 0.82) and grew
// halos brighter than the originals — the deployed screenshot read as a
// DUPLICATED WORLD, not a polished floor. `blend: 'multiply'` swaps the
// blend for `base.rgb * color`: every reflected luminance is scaled DOWN by
// the tint (whites cap at the tint itself, far below the bloom threshold),
// which is exactly how polished dark stone behaves. Default stays
// 'overlay' with the stock shader path, so Mirror Lake renders bit-exactly
// as shipped.
const MULTIPLY_REFLECTOR_SHADER = {
    name: 'ReflectorMultiplyShader',
    uniforms: {
        color: { value: null },
        tDiffuse: { value: null },
        textureMatrix: { value: null },
    },
    vertexShader: /* glsl */`
        uniform mat4 textureMatrix;
        varying vec4 vUv;

        #include <common>
        #include <logdepthbuf_pars_vertex>

        void main() {

            vUv = textureMatrix * vec4( position, 1.0 );

            gl_Position = projectionMatrix * modelViewMatrix * vec4( position, 1.0 );

            #include <logdepthbuf_vertex>

        }`,
    fragmentShader: /* glsl */`
        uniform vec3 color;
        uniform sampler2D tDiffuse;
        varying vec4 vUv;

        #include <logdepthbuf_pars_fragment>

        void main() {

            #include <logdepthbuf_fragment>

            vec4 base = texture2DProj( tDiffuse, vUv );

            // Multiply tint: the mirror darkens BY the color uniformly —
            // polished-stone physics, no overlay midtone reversal.
            gl_FragColor = vec4( base.rgb * color, 1.0 );

            #include <tonemapping_fragment>
            #include <colorspace_fragment>

        }`,
};

export function addPlanarReflection(ctx, radius, { color = 0xaab4c8, resolution = 1024, blend = 'overlay' } = {}) {
    const floor = ctx._circularFloor;
    if (floor) floor.visible = false;

    // Cap the reflection target — a 1024² render of the scene per frame is
    // plenty for a dark, low-contrast lake at phone-to-desktop sizes.
    const reflector = new Reflector(new THREE.CircleGeometry(radius, 64), {
        clipBias: 0.003,
        textureWidth: resolution,
        textureHeight: resolution,
        // Reflector's `color` tints/darkens the mirror — deep lake blue-grey
        // keeps reflections moody instead of chrome-perfect (overlay), or
        // scales them down uniformly (multiply — the cathedral's polished
        // dark slate).
        color,
        shader: blend === 'multiply' ? MULTIPLY_REFLECTOR_SHADER : undefined,
    });
    reflector.rotation.x = -Math.PI / 2;
    reflector.position.y = 0.001;
    ctx.scene.add(reflector);
    return reflector;
}

// ── Water reflection (Mirror Lake v3.0.0 "The Still Shore") ────────────────
// A WATER, not a chrome tile. §7 of the lake brief is explicit: a literal
// mirror surface is not a lake. Where addPlanarReflection serves polished
// stone (Cathedral) and moody dark glass (the v1 lake), the flagship water
// gets its own shader on top of Reflector's projected texture:
//
//   • DEPTH TINT — the reflection is multiplied by a deep blue-green, the
//     way real still water returns only a fraction of the incident light;
//   • RIPPLE — two slow, crossing wave trains perturb the projected lookup
//     by a couple of texels at 1024². Perceptible as life, never as waves;
//     the amplitude ships at ~0.002 of the projected UV (uRipple);
//   • SHEEN — a ±3% large-scale luminance breathing across the surface, so
//     the lake is never a uniform gradient fill;
//   • MANUAL FOG — Reflector shaders bypass scene fog, which would leave a
//     crisp water rectangle floating in the haze at distance; the shader
//     blends to the venue's own fog colour over the same range.
//
// Motion: uTime is advanced by the generic particle-system loop (the caller
// registers the reflector as a 'void-drift' entry), which means reduced-
// motion visitors and low-end devices automatically get the calm static
// surface — the same vestibular contract as the void dust.
//
// PERFORMANCE: same cost class as addPlanarReflection — one extra scene
// render per frame into a capped target, high tier only.
export const WATER_REFLECTOR_SHADER = {
    name: 'ReflectorWaterShader',
    uniforms: {
        color:    { value: null },   // depth tint (multiplied onto the reflection)
        tDiffuse: { value: null },
        textureMatrix: { value: null },
        uTime:    { value: 0 },
        uRipple:  { value: 0.0021 }, // projected-UV wobble amplitude
        uFogColor: { value: null },
        uFogNear:  { value: 18 },
        uFogFar:   { value: 62 },
    },
    vertexShader: /* glsl */`
        uniform mat4 textureMatrix;
        varying vec4 vUv;
        varying vec3 vWorld;

        #include <common>
        #include <logdepthbuf_pars_vertex>

        void main() {
            vUv = textureMatrix * vec4( position, 1.0 );
            vec4 world = modelMatrix * vec4( position, 1.0 );
            vWorld = world.xyz;
            gl_Position = projectionMatrix * modelViewMatrix * vec4( position, 1.0 );

            #include <logdepthbuf_vertex>
        }`,
    fragmentShader: /* glsl */`
        uniform vec3 color;
        uniform sampler2D tDiffuse;
        uniform float uTime;
        uniform float uRipple;
        uniform vec3 uFogColor;
        uniform float uFogNear;
        uniform float uFogFar;
        varying vec4 vUv;
        varying vec3 vWorld;

        #include <logdepthbuf_pars_fragment>

        void main() {

            #include <logdepthbuf_fragment>

            // Two slow crossing wave trains, anchored to world space so the
            // pattern does not swim with the camera. Still-water calm: the
            // lookup moves by a couple of texels, nothing more.
            float w1 = sin( vWorld.x * 1.6 + uTime * 0.30 ) * 0.62;
            float w2 = sin( vWorld.z * 2.2 - vWorld.x * 0.55 + uTime * 0.19 );
            vec2 duv = vUv.xy / max( vUv.w, 1e-4 );
            vec4 base = texture2D( tDiffuse, duv + vec2( w1 + w2, w2 * 0.6 ) * uRipple );

            // Depth tint (multiplied — the water returns a fraction of the
            // light) + the living-surface sheen (±3%, very slow).
            float sheen = 0.97 + 0.03 * sin( vWorld.x * 0.33 + vWorld.z * 0.26 + uTime * 0.11 );
            vec3 water = base.rgb * color * sheen;

            // Manual fog: distant water dissolves into the venue haze instead
            // of ending at a crisp mirrored rectangle.
            float fogF = smoothstep( uFogNear, uFogFar, length( vWorld - cameraPosition ) );
            gl_FragColor = vec4( mix( water, uFogColor, fogF ), 1.0 );

            #include <tonemapping_fragment>
            #include <colorspace_fragment>

        }`,
};

export function addWaterReflection(ctx, {
    radius = 20,
    level = -0.24,
    color = 0x39434f,
    resolution = 1024,
    fogColor = 0x101826,
    fogNear = 18,
    fogFar = 62,
    ripple = 0.0021,
} = {}) {
    const reflector = new Reflector(new THREE.CircleGeometry(radius, 72), {
        clipBias: 0.003,
        textureWidth: resolution,
        textureHeight: resolution,
        color,
        shader: WATER_REFLECTOR_SHADER,
    });
    reflector.rotation.x = -Math.PI / 2;
    reflector.position.y = level;
    const u = reflector.material.uniforms;
    if (u) {
        if (u.uRipple) u.uRipple.value = ripple;
        if (u.uFogColor) u.uFogColor.value = new THREE.Color(fogColor);
        if (u.uFogNear) u.uFogNear.value = fogNear;
        if (u.uFogFar) u.uFogFar.value = fogFar;
    }
    ctx.scene.add(reflector);
    return reflector;
}

// ── Dark-gloss mood (mobile / low-end fallback) ─────────────────────────────
// One light-streak plane laid on the water toward the moon direction —
// additive, so it reads on Lambert (low-end) AND PBR (mobile) floors alike.
// `moonDir` is the (normalised-ish) direction the venue's moon sits at; the
// streak runs from centre toward it, like moonlight catching still water.
export function addMoonLightStreak(ctx, radius, moonDir, { color = 0xb0c8ff, opacity = 0.16 } = {}) {
    const dir = moonDir.clone();
    dir.y = 0;
    if (dir.lengthSq() < 1e-6) dir.set(0.8, 0, -0.5);
    dir.normalize();

    const len = radius * 1.35;
    const geo = new THREE.PlaneGeometry(1.6, len, 1, 1);
    const mat = new THREE.MeshBasicMaterial({
        color,
        transparent: true,
        opacity,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
    });
    const streak = new THREE.Mesh(geo, mat);
    streak.rotation.x = -Math.PI / 2;
    // Lay the plane's long axis along the moon direction, offset toward it.
    streak.position.set(dir.x * radius * 0.3, 0.005, dir.z * radius * 0.3);
    streak.rotation.z = -Math.atan2(dir.x, dir.z);
    ctx.scene.add(streak);
    return streak;
}

// ── Floor edge fade (void family) ───────────────────────────────────────────
// A ring that starts slightly INSIDE the floor disc's edge (hiding the seam)
// and ramps to the venue background colour just outside it — the disc then
// reads as dissolving into the void. Declared per venue; colour always comes
// from the venue's own background_color (generic, never slug-derived).
export function addFloorEdgeFade(ctx, radius, bgColor, { inner = 0.9, rampEnd = 1.14, outer = 2.2 } = {}) {
    const mode = resolveFloorFadeMode({ isLowEnd: !!ctx.isLowEnd, declared: true });
    if (mode === 'none') return null;

    const bg = (bgColor && bgColor.isColor) ? bgColor : new THREE.Color(bgColor ?? 0x000000);

    let mat;
    if (mode === 'shader') {
        mat = new THREE.ShaderMaterial({
            transparent: true,
            depthWrite: false,
            uniforms: {
                uColor: { value: bg },
                uInner: { value: inner },   // fraction of radius where fade starts
                uRamp:  { value: rampEnd }, // fraction of radius where it is fully bg
            },
            vertexShader: /* glsl */`
                varying vec2 vPos;
                void main() {
                    vPos = position.xy;
                    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
                }
            `,
            fragmentShader: /* glsl */`
                uniform vec3 uColor;
                uniform float uInner;
                uniform float uRamp;
                varying vec2 vPos;
                void main() {
                    float d = length(vPos);
                    float t = smoothstep(uInner, uRamp, d);
                    // Fully opaque bg colour from the ramp outward.
                    gl_FragColor = vec4(uColor, t);
                }
            `,
        });
    } else {
        // 'basic' — low-end: one flat ring at the venue background colour,
        // laid over the outer band of the disc. Cheap, seam-hiding, honest.
        mat = new THREE.MeshBasicMaterial({
            color: bg,
            transparent: true,
            opacity: 1.0,
            depthWrite: false,
        });
    }

    // The ring is a flat mesh in the XZ plane; vPos = position.xy is its
    // local 2D coordinate, so build it unrotated and rotate afterwards.
    const ring = new THREE.Mesh(new THREE.RingGeometry(radius * inner, radius * outer, 64, 1), mat);
    ring.rotation.x = -Math.PI / 2;
    ring.position.y = 0.002;
    ring.renderOrder = 1; // after the floor
    ctx.scene.add(ring);
    return ring;
}
