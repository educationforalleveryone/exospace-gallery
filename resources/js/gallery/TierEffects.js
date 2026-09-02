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
export function makeGlassMaterial(ctx, { tint = 0xffffff, opacity = 0.35 } = {}) {
    const tier = resolveGlassTier({
        isLowEnd: !!ctx.isLowEnd,
        isMobileTier: !!ctx._isMobileTier,
        declared: 'transmission',
    });

    if (tier === 'transmission') {
        // True glass — high tier has a guaranteed HDRI environment, so
        // transmission can never render null here (Renderer.js: _skipHdri is
        // only set on mobile/low-end).
        return new THREE.MeshPhysicalMaterial({
            color: tint,
            roughness: 0.05,
            metalness: 0.0,
            transmission: 0.92,
            thickness: 0.6,
            ior: 1.5,
            transparent: true,
            opacity: 1.0,
            envMapIntensity: 1.0,
            side: THREE.DoubleSide,
        });
    }

    if (tier === 'cheap') {
        // §11.3 designed fallback: reads as glass via opacity + low roughness
        // + specular response from the venue's own lights — no transmission
        // pass, no environment dependency, never null.
        return new THREE.MeshPhysicalMaterial({
            color: tint,
            roughness: 0.08,
            metalness: 0.0,
            transparent: true,
            opacity,
            envMapIntensity: 0.5,
            side: THREE.DoubleSide,
        });
    }

    // 'flat' — low-end material class (Lambert), still visibly glassy.
    return new THREE.MeshLambertMaterial({
        color: tint,
        transparent: true,
        opacity: Math.min(opacity, 0.3),
        side: THREE.DoubleSide,
    });
}

// ── Planar reflection (high tier) ───────────────────────────────────────────
// Replaces the venue's floor disc with a real planar reflector. The caller
// must pass the existing floor mesh via ctx._circularFloor (set by
// RoomBuilder.createRoomCircular) — it is HIDDEN, not disposed, so a
// session-level quality downgrade can restore it without a rebuild.
export function addPlanarReflection(ctx, radius, { color = 0xaab4c8, resolution = 1024 } = {}) {
    const floor = ctx._circularFloor;
    if (floor) floor.visible = false;

    // Cap the reflection target — a 1024² render of the scene per frame is
    // plenty for a dark, low-contrast lake at phone-to-desktop sizes.
    const reflector = new Reflector(new THREE.CircleGeometry(radius, 64), {
        clipBias: 0.003,
        textureWidth: resolution,
        textureHeight: resolution,
        // Reflector's `color` tints/darkens the mirror — deep lake blue-grey
        // keeps reflections moody instead of chrome-perfect.
        color,
    });
    reflector.rotation.x = -Math.PI / 2;
    reflector.position.y = 0.001;
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
