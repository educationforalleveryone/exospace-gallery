import * as THREE from 'three';
import { Reflector } from 'three/addons/objects/Reflector.js';
import {
    resolveGlassTier,
    resolveReflectionMode,
    resolveFloorFadeMode,
} from './TierResolve.js';

export function makeGlassMaterial(ctx, { tint = 0xffffff, opacity = 0.35, flatShading = false, roughness, thickness, declared } = {}) {
    const tier = resolveGlassTier({
        isLowEnd: !!ctx.isLowEnd,
        isMobileTier: !!ctx._isMobileTier,
        declared: declared ?? 'transmission',
    });

    if (tier === 'transmission') {
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

    const reflector = new Reflector(new THREE.CircleGeometry(radius, 64), {
        clipBias: 0.003,
        textureWidth: resolution,
        textureHeight: resolution,
        color,
        shader: blend === 'multiply' ? MULTIPLY_REFLECTOR_SHADER : undefined,
    });
    reflector.rotation.x = -Math.PI / 2;
    reflector.position.y = 0.001;
    ctx.scene.add(reflector);
    return reflector;
}

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

export function addMoonLightStreak(ctx, radius, moonDir, { color = 0xb0c8ff, opacity = 0.16, y = 0.005 } = {}) {
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
    streak.position.set(dir.x * radius * 0.3, y, dir.z * radius * 0.3);
    streak.rotation.z = -Math.atan2(dir.x, dir.z);
    ctx.scene.add(streak);
    return streak;
}

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
        mat = new THREE.MeshBasicMaterial({
            color: bg,
            transparent: true,
            opacity: 1.0,
            depthWrite: false,
        });
    }

    const ring = new THREE.Mesh(new THREE.RingGeometry(radius * inner, radius * outer, 64, 1), mat);
    ring.rotation.x = -Math.PI / 2;
    ring.position.y = 0.002;
    ring.renderOrder = 1; // after the floor
    ctx.scene.add(ring);
    return ring;
}
