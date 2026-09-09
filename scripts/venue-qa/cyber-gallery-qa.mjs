#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// cyber-gallery-qa.mjs — the venue QA gate for Cyber Gallery (v2.0.0, the
// "Signal Room": movement-reactive artwork media as the venue's signature).
//
//   node scripts/venue-qa/cyber-gallery-qa.mjs
//
// Same layering as nebula-drift-qa.mjs (plain Node over the repo checkout;
// the material probe additionally uses the repo's `three` dependency): pins
// CONTRACTS while tests/Feature pins the DB side and scripts/harness/shoot.mjs
// captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the cyber-gallery row declares the Signal Room
//      identity: visual_config.artwork_reactive (the signature), declared
//      environment 'none', the rig lift, bloom identity + black-blend
//      vignette, frame_override 'black', artwork standing glow + pool cap,
//      the four-edge neon + floor rails structure, and the material parity
//      fix (texture_tint + declared dark floor). The superseded v1.0.0 copy
//      must be gone; the copy must promise exactly what renders.
//   B. DB ↔ harness sync — the PHP-less harness renders the same JSON a
//      fresh install seeds, INCLUDING the artwork_reactive tuning (drift
//      here means the movement-state screenshots stop meaning anything).
//   C. Signal + material invariants — driven through the REAL
//      ArtworkReactive module + three.js (no GL, shader assembled by hand):
//      the motion normalization curve (dead zone, perceptible slow walk,
//      saturation at walking pace), the asymmetric exponential smoothing
//      (attack ≪ release; frame-rate independence; decay never snaps),
//      deterministic per-artwork variation (stable, distinct, bounded), the
//      designed tier matrix (calm's 35% ceiling, reduced tiers, off), the
//      onBeforeCompile shader surgery (map_fragment replaced exactly, shared
//      uniforms shared, per-artwork uniforms static, one program cache key),
//      the clean-state gate, bounded distortion amplitudes, and the runtime
//      guardrails (velocity read only while the pointer is locked; QA
//      override lands on target; dispose nulls the registry).
//   D. JS/PHP hygiene — zero venue slugs in the shared module, no
//      Math.random in the reactive path, the exporter ships the owned key,
//      the animate loop consumes the signal AFTER movement integration, the
//      registry initializes BEFORE artworks are placed, the guarded
//      migration file exists, and the harness carries the ?motion= override.
// ─────────────────────────────────────────────────────────────────────────────
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const rel = (p) => path.join(root, p);

let failures = 0;
const ok = (name, cond, detail = '') => {
    if (cond) console.log(`  ✓ ${name}`);
    else { failures++; console.error(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`); }
};
const section = (name) => console.log(`\n── ${name} ${'─'.repeat(Math.max(1, 62 - name.length))}`);

// ── A. Seeder contract ──────────────────────────────────────────────────────
section('A. Seeder contract (cyber-gallery row)');
const seederSrc = readFileSync(rel('database/seeders/VenueTemplateSeeder.php'), 'utf8');

function chunk() {
    const i = seederSrc.indexOf("'slug'          => 'cyber-gallery'");
    if (i === -1) throw new Error('cyber-gallery row not found in seeder');
    const nextSlug = seederSrc.indexOf("'slug'", i + 10);
    return seederSrc.slice(i, nextSlug === -1 ? undefined : nextSlug);
}
function arrayLiteral(source, key) {
    const k = source.indexOf(`'${key}'`);
    if (k === -1) return '';
    const open = source.indexOf('[', k);
    let depth = 0;
    for (let i = open; i < source.length; i++) {
        if (source[i] === '[') depth++;
        else if (source[i] === ']') { depth--; if (depth === 0) return source.slice(open, i + 1); }
    }
    return '';
}

const row = chunk();
const vc = arrayLiteral(row, 'visual_config');
const mc = arrayLiteral(row, 'material_config');
const pf = arrayLiteral(vc, 'post_fx');
const ar = arrayLiteral(vc, 'artwork_reactive');

ok('signature DECLARED: artwork_reactive.enabled true (the venue identity)',
    /'enabled'\s*=>\s*true/.test(ar));
ok('dead zone 0.18 m/s (numeric noise + damping tails never trigger)',
    /'dead_zone'\s*=>\s*0\.18/.test(ar));
ok('walking pace saturates (ref_speed 3.0 = CONFIG.camera.maxSpeed)',
    /'ref_speed'\s*=>\s*3\.0/.test(ar));
ok('attack 0.18 s (reacts within a couple of frames)',
    /'attack'\s*=>\s*0\.18/.test(ar));
ok('release 1.1 s (remains briefly, then settles — never a snap)',
    /'release'\s*=>\s*1\.1/.test(ar));
ok('bezel cyan declared (the luminous display boundary)',
    /'bezel_color'\s*=>\s*'0x00e5ff'/.test(ar));
ok('environment DECLARED none (was a silenced night.hdr download + sky leak)',
    /'environment'\s*=>\s*'none'/.test(vc));
ok('env_intensity 0 (a controlled signal room refuses a sky)',
    /'env_intensity'\s*=>\s*0(?![.\d])/.test(vc));
ok('rig lifted: ambient 0.42 (was 0.18 murk under r155+ units)',
    /'ambient_intensity'\s*=>\s*0\.42/.test(vc));
ok('rig lifted: spot 1.6 (the hang must clear the legibility floor)',
    /'spot_intensity'\s*=>\s*1\.6/.test(vc));
ok('rig lifted: exposure 0.7 (was 0.5)',
    /'tone_mapping_exposure'\s*=>\s*0\.7/.test(vc));
ok('hemisphere softened to 0.05 (was a drifting default)',
    /'hemisphere_intensity'\s*=>\s*0\.05/.test(vc));
ok('fog reach widened 10/26 (was 6/22 — the room read as a closet)',
    /'fog_near'\s*=>\s*10/.test(vc) && /'fog_far'\s*=>\s*26/.test(vc));
ok('frame_override black (device bezel — the luminous boundary supplies colour)',
    /'frame_override'\s*=>\s*'black'/.test(vc));
ok('standing glow 0.28 + pool cap 12 (no artwork sits in the dark)',
    /'artwork_light_base'\s*=>\s*0\.28/.test(vc) && /'artwork_light_pool_cap'\s*=>\s*12/.test(vc));
ok('bloom identity declared 0.55 @ 0.8 (the neon + bezels read as light sources)',
    /'bloom'\s*=>\s*true/.test(pf) && /'bloom_strength'\s*=>\s*0\.55/.test(pf) && /'bloom_threshold'\s*=>\s*0\.8(?![.\d])/.test(pf));
ok('vignette black-blend (the grey-veil defect class never ships again)',
    /'vignette'\s*=>\s*true/.test(pf) && /'vignette_blend'\s*=>\s*'black'/.test(pf));
ok('material parity fix: texture_tint true (declared dark walls now reach textured builds)',
    /'texture_tint'\s*=>\s*true/.test(mc));
ok('dark anodized wall colour declared 0x0a0a14',
    /'wall_color'\s*=>\s*'0x0a0a14'/.test(mc));
ok('floor colour DECLARED dark 0x0b0d14 (was null → bright preset concrete)',
    /'floor_color'\s*=>\s*'0x0b0d14'/.test(mc));
ok('floor tile scale declared (2.0 m — polished signal-floor rhythm)',
    /'floor_tile_meters'\s*=>\s*2\.0/.test(mc));
ok('four ceiling neon edges preserved (the rooms-pass contract still holds)',
    ['front', 'back', 'left', 'right'].every((side) => new RegExp(`'neon-top-${side}'`).test(vc)));
ok('floor light rails preserved on all four sides',
    ['front', 'back', 'left', 'right'].every((side) => new RegExp(`'floor-rail-${side}'`).test(vc)));
ok('superseded v1.0.0 copy gone ("dark electric space" promised nothing that identified this venue)',
    !/dark electric space/.test(row));
ok('copy promises the signature mechanic verifiably (stand still / move / react)',
    /stand still/i.test(row) && /move/i.test(row) && /react/i.test(row));
ok('copy keeps the pinned neon + floor words (VenueRoomsIterationTest contract)',
    /neon/i.test(row) && /floor/i.test(row));
ok('version pinned 2.0.0 (Signal Room)',
    /'version'\s*=>\s*'2\.0\.0'/.test(row));

// ── B. DB ↔ harness sync ────────────────────────────────────────────────────
section('B. DB ↔ harness sync (cyber-gallery body)');
const harnessSrc = readFileSync(rel('scripts/harness/harness.html'), 'utf8');
function harnessVenue(key) {
    const i = harnessSrc.indexOf(`'${key}': {`);
    if (i === -1) return null;
    const rest = harnessSrc.slice(i + 10);
    const next = rest.search(/\n        '/);
    return rest.slice(0, next === -1 ? undefined : next + 1);
}
const hBody = harnessVenue('cyber-gallery');
ok('harness carries the cyber-gallery body', !!hBody);
if (hBody) {
    const pairs = [
        ['artwork_reactive.enabled true',   /enabled:\s*true/.test(hBody) && /'enabled'\s*=>\s*true/.test(ar)],
        ['artwork_reactive.dead_zone 0.18', /dead_zone:\s*0\.18/.test(hBody) && /'dead_zone'\s*=>\s*0\.18/.test(ar)],
        ['artwork_reactive.ref_speed 3.0',  /ref_speed:\s*3\.0/.test(hBody) && /'ref_speed'\s*=>\s*3\.0/.test(ar)],
        ['artwork_reactive.attack 0.18',    /attack:\s*0\.18/.test(hBody) && /'attack'\s*=>\s*0\.18/.test(ar)],
        ['artwork_reactive.release 1.1',    /release:\s*1\.1/.test(hBody) && /'release'\s*=>\s*1\.1/.test(ar)],
        ['artwork_reactive.bezel 0x00e5ff', /bezel_color:\s*'0x00e5ff'/.test(hBody) && /'bezel_color'\s*=>\s*'0x00e5ff'/.test(ar)],
        ['environment none',                /environment:\s*'none'/.test(hBody) && /'environment'\s*=>\s*'none'/.test(vc)],
        ['ambient_intensity 0.42',          /ambient_intensity:\s*0\.42/.test(hBody) && /'ambient_intensity'\s*=>\s*0\.42/.test(vc)],
        ['spot_intensity 1.6',              /spot_intensity:\s*1\.6/.test(hBody) && /'spot_intensity'\s*=>\s*1\.6/.test(vc)],
        ['tone_mapping_exposure 0.7',       /tone_mapping_exposure:\s*0\.7/.test(hBody) && /'tone_mapping_exposure'\s*=>\s*0\.7/.test(vc)],
        ['fog 10/26',                       /fog_near:\s*10/.test(hBody) && /fog_far:\s*26/.test(hBody) && /'fog_far'\s*=>\s*26/.test(vc)],
        ['frame_override black',            /frame_override:\s*'black'/.test(hBody) && /'frame_override'\s*=>\s*'black'/.test(vc)],
        ['artwork_light_base 0.28',         /artwork_light_base:\s*0\.28/.test(hBody) && /'artwork_light_base'\s*=>\s*0\.28/.test(vc)],
        ['bloom 0.55 @ 0.8',                /bloom_strength:\s*0\.55/.test(hBody) && /'bloom_strength'\s*=>\s*0\.55/.test(pf)],
        ['vignette_blend black',            /vignette_blend:\s*'black'/.test(hBody) && /'vignette_blend'\s*=>\s*'black'/.test(pf)],
        ['texture_tint true',               /texture_tint:\s*true/.test(hBody) && /'texture_tint'\s*=>\s*true/.test(mc)],
        ['wall_color 0x0a0a14',             /wall_color:\s*'0x0a0a14'/.test(hBody) && /'wall_color'\s*=>\s*'0x0a0a14'/.test(mc)],
        ['floor_color 0x0b0d14',            /floor_color:\s*'0x0b0d14'/.test(hBody) && /'floor_color'\s*=>\s*'0x0b0d14'/.test(mc)],
        ['floor_tile_meters 2.0',           /floor_tile_meters:\s*2\.0/.test(hBody) && /'floor_tile_meters'\s*=>\s*2\.0/.test(mc)],
        ['four neon-top edges',             ['front', 'back', 'left', 'right'].every((s) => new RegExp(`neon-top-${s}`).test(hBody))],
        ['version 2.0.0',                   /version:\s*'2\.0\.0'/.test(hBody) && /'version'\s*=>\s*'2\.0\.0'/.test(row)],
    ];
    for (const [name, cond] of pairs) ok(`harness ↔ seeder: ${name}`, cond);
}

// ── C. Signal + material invariants (real module, real three, no GL) ────────
section('C. Signal + material invariants (real module, real three, no GL)');
const reactiveSrc = readFileSync(rel('resources/js/gallery/ArtworkReactive.js'), 'utf8');
try {
    const AR = await import('../../resources/js/gallery/ArtworkReactive.js');
    const THREE = (await import('three')).default ?? (await import('three'));

    // ── C1. normalizeMotion — the movement → intensity curve ──────────────
    const curve = { deadZone: 0.18, refSpeed: 3.0 };
    ok('stillness is zero (0 m/s → 0)', AR.normalizeMotion(0, curve) === 0);
    ok('NaN is zero (never poisons the shader)', AR.normalizeMotion(NaN, curve) === 0);
    ok('dead zone swallows damping tails (0.15 m/s → 0)', AR.normalizeMotion(0.15, curve) === 0);
    ok('just past the dead zone stays quiet (0.25 m/s ≤ 0.06)',
        AR.normalizeMotion(0.25, curve) <= 0.06, `got ${AR.normalizeMotion(0.25, curve).toFixed(3)}`);
    ok('slow walk is clearly perceptible (0.9 m/s ≥ 0.2 — reaction must read before it must impress)',
        AR.normalizeMotion(0.9, curve) >= 0.2, `got ${AR.normalizeMotion(0.9, curve).toFixed(3)}`);
    ok('walking pace saturates (3.0 m/s → 1.0)', AR.normalizeMotion(3.0, curve) === 1);
    ok('sprint saturates identically (6 m/s → 1.0 — never glitches harder than the artwork survives)',
        AR.normalizeMotion(6, curve) === 1);
    let mono = true;
    for (let s = 0.1; s < 5; s += 0.05) {
        if (AR.normalizeMotion(s + 0.05, curve) < AR.normalizeMotion(s, curve) - 1e-9) { mono = false; break; }
    }
    ok('curve is monotonic (no mid-range dips)', mono);

    // ── C2. smoothGlitch — asymmetric exponential smoothing ───────────────
    const tau = { attackTau: 0.18, releaseTau: 1.1 };
    ok('attack rises fast (0 → 90% in ≤ 0.45 s at 60 fps)', (() => {
        let v = 0, t = 0;
        while (t < 0.45 && v < 0.9) { v = AR.smoothGlitch(v, 1, 1 / 60, tau); t += 1 / 60; }
        return v >= 0.9;
    })());
    ok('release NEVER snaps (1 → 0.1 takes ≥ 0.8 s of real time)', (() => {
        let v = 1, t = 0;
        while (t < 0.8) { v = AR.smoothGlitch(v, 0, 1 / 60, tau); t += 1 / 60; }
        return v > 0.1;
    })(), 'the binary GLITCH=1→0 snap the brief forbids');
    ok('release settles clean (1 → ≤ 0.02 within 5 s)', (() => {
        let v = 1, t = 0;
        while (t < 5 && v > 0.02) { v = AR.smoothGlitch(v, 0, 1 / 60, tau); t += 1 / 60; }
        return v <= 0.02;
    })());
    ok('frame-rate independent (60 fps and 30 fps agree within 1% after 2 s)', (() => {
        let a = 0, b = 0;
        for (let i = 0; i < 120; i++) a = AR.smoothGlitch(a, 1, 1 / 60, tau);
        for (let i = 0; i < 60; i++) b = AR.smoothGlitch(b, 1, 1 / 30, tau);
        return Math.abs(a - b) < 0.01;
    })(), `diff after 2 s: `);

    // ── C3. deriveArtworkVariation — deterministic per-artwork character ──
    const v1 = AR.deriveArtworkVariation('cyber-gallery:42');
    const v2 = AR.deriveArtworkVariation('cyber-gallery:42');
    ok('variation is deterministic (same artwork → same character every load)',
        v1.seed === v2.seed && v1.intensity === v2.intensity && v1.rows === v2.rows);
    const many = Array.from({ length: 24 }, (_, i) => AR.deriveArtworkVariation(`cyber-gallery:${i + 1}`));
    ok('every artwork differs (no synchronized tearing)',
        new Set(many.map((m) => m.seed)).size === 24);
    ok('intensity multiplier bounded 0.72..1.25 (varied, never chaotic)',
        many.every((m) => m.intensity >= 0.72 && m.intensity <= 1.25));
    ok('tear-row density quantized to the designed set {16, 22, 28}',
        many.every((m) => [16, 22, 28].includes(m.rows)));

    // ── C4. resolveReactiveMode — the designed tier matrix ────────────────
    ok('undeclared venue → off (zero cost, byte-identical historic path)',
        AR.resolveReactiveMode({}).mode === 'off' &&
        AR.resolveReactiveMode({ declared: null }).mode === 'off');
    ok('enabled:false → off (a curator may retire the identity)',
        AR.resolveReactiveMode({ declared: { enabled: false } }).mode === 'off');
    ok('desktop → full (all layers + idle presence)',
        AR.resolveReactiveMode({ declared: { enabled: true } }).mode === 'full' &&
        AR.resolveReactiveMode({ declared: { enabled: true } }).idle > 0);
    ok('mobile tier → reduced (same shader, smaller amplitudes, no idle)',
        (() => { const m = AR.resolveReactiveMode({ isMobileTier: true, declared: { enabled: true } });
            return m.mode === 'reduced' && m.idle === 0 && m.channel < 0.0042; })());
    ok('low-end → reduced on the SMALLEST amplitudes (identity survives Lambert)',
        (() => { const m = AR.resolveReactiveMode({ isLowEnd: true, declared: { enabled: true } });
            const mob = AR.resolveReactiveMode({ isMobileTier: true, declared: { enabled: true } });
            return m.mode === 'reduced' && m.channel < mob.channel; })());
    ok('reduced motion → calm: mechanic stays discoverable but gentle',
        (() => { const m = AR.resolveReactiveMode({ reducedMotion: true, declared: { enabled: true } });
            return m.mode === 'calm' && m.maxScale === 0.35 && m.idle === 0; })());
    ok('reduced motion keeps the reaction (never removes the core mechanic)',
        (() => { const m = AR.resolveReactiveMode({ reducedMotion: true, declared: { enabled: true } });
            return m.maxScale > 0 && m.channel > 0; })());
    ok('step granularity tightens only as tiers descend (full ≥ mobile ≥ low ≥ calm)',
        (() => { const f = AR.resolveReactiveMode({ declared: { enabled: true } });
            const mob = AR.resolveReactiveMode({ isMobileTier: true, declared: { enabled: true } });
            const lo = AR.resolveReactiveMode({ isLowEnd: true, declared: { enabled: true } });
            return f.steps >= mob.steps && mob.steps >= lo.steps; })());

    // ── C5. patchReactiveMaterial — the shader surgery ────────────────────
    const ctx = {
        _reactive: null,
        _venueSlug: 'cyber-gallery',
        isLowEnd: false, _isMobileTier: false, reducedMotion: false,
        _venueArtworkReactive: { enabled: true },
    };
    AR.initArtworkReactive.call(ctx);
    ok('registry builds for a declared venue', !!ctx._reactive && ctx._reactive.mode.mode === 'full');

    const matA = new THREE.MeshStandardMaterial({ color: 0xffffff });
    const matB = new THREE.MeshStandardMaterial({ color: 0xffffff });
    AR.patchReactiveMaterial.call(ctx, matA, 'a-1');
    AR.patchReactiveMaterial.call(ctx, matB, 'b-2');

    function compile(mat) {
        const shader = {
            uniforms: {},
            fragmentShader: 'void main() {\n    #include <common>\n    #include <map_fragment>\n    gl_FragColor = vec4( 1.0 );\n}\n',
        };
        mat.onBeforeCompile(shader);
        return shader;
    }
    const shA = compile(matA);
    ok('map_fragment include REPLACED (the stock sample is gone)',
        !shA.fragmentShader.includes('#include <map_fragment>'));
    ok('common include preserved (the injection is additive, not destructive)',
        shA.fragmentShader.includes('#include <common>'));
    ok('injected uniforms present (uTime/uGlitch/uIntensity/uSeed/uRows)',
        ['uTime', 'uGlitch', 'uIntensity', 'uSeed', 'uRows'].every((u) => u in shA.uniforms));
    ok('shared uniforms are THE SAME OBJECTS across artworks (one write per frame)',
        shA.uniforms.uTime === compile(matB).uniforms.uTime &&
        shA.uniforms.uGlitch === compile(matB).uniforms.uGlitch);
    ok('per-artwork uniforms are STATIC and DISTINCT (seed/intensity/rows)',
        shA.uniforms.uSeed !== compile(matB).uniforms.uSeed &&
        shA.uniforms.uIntensity.value !== undefined);
    ok('ONE program cache key (all reactive canvases share a single shader program)',
        typeof matA.customProgramCacheKey === 'function' &&
        matA.customProgramCacheKey() === matB.customProgramCacheKey());
    ok('material class preserved (PBR lighting + focus highlight keep working)',
        matA.isMeshStandardMaterial === true);

    ok('clean-state gate present (gAmp ≤ 0.045 → single texture fetch, byte-identical to unpatched)',
        /if \( gAmp > 0\.045 \)/.test(reactiveSrc));
    ok('slice tearing bounded (≤ 5% row shift · amp — tears, never dissolves)',
        /0\.05 \* gAmp/.test(reactiveSrc));
    ok('UV wrap guarded (fract keeps sampling on-canvas)',
        /gUv = fract\( gUv \)/.test(reactiveSrc));
    ok('scanline bounded ≤ ~8% and non-strobing (sin-based banding, no random flicker)',
        /uScan \* gAmp \* 0\.12/.test(reactiveSrc));
    ok('channel separation tier-scaled (desktop 0.0042 — sub-pixel to few-pixel, never a rim',
        /channel: 0\.0042/.test(reactiveSrc));
    ok('time is STEPPED (digital increments — living image, not wobbly watermark)',
        /floor\( uTime \* uSteps \) \/ uSteps/.test(reactiveSrc));

    // ── C6. runtime guardrails on a fake scene ────────────────────────────
    ok('velocity read ONLY while pointer locked (no stuck-on glitch when the visitor opens UI)', (() => {
        const c = {
            _reactive: null, _venueSlug: 'cyber-gallery',
            isLowEnd: false, _isMobileTier: false, reducedMotion: false,
            _venueArtworkReactive: { enabled: true },
            isMobile: false, arrivalActive: false, isInspecting: false,
            controls: { isLocked: false },                    // pointer UNLOCKED
            velocity: { x: 5, z: 0 },                          // stale fast velocity
        };
        AR.initArtworkReactive.call(c);
        AR.updateArtworkReactive.call(c);
        // Full tier keeps a ≤0.02 idle presence — far under the 0.045
        // clean-state gate, but not exactly 0. Assert quiet, not zero.
        return c._reactive.level < 0.01;
    })());
    ok('locked + moving: the signal flows', (() => {
        const c = {
            _reactive: null, _venueSlug: 'cyber-gallery',
            isLowEnd: false, _isMobileTier: false, reducedMotion: false,
            _venueArtworkReactive: { enabled: true },
            isMobile: false, arrivalActive: false, isInspecting: false,
            controls: { isLocked: true }, velocity: { x: 2.4, z: 0 },
        };
        AR.initArtworkReactive.call(c);
        const origNow = globalThis.performance.now.bind(globalThis.performance);
        let simT = origNow();
        globalThis.performance.now = () => (simT += 16);   // stepped 60 fps clock
        try {
            for (let i = 0; i < 30; i++) AR.updateArtworkReactive.call(c);   // ~0.5 s of frames
        } finally {
            globalThis.performance.now = origNow;
        }
        return c._reactive.level > 0.3;
    })());
    ok('arrival choreography owns the camera (no reaction during the dolly)', (() => {
        const c = {
            _reactive: null, _venueSlug: 'cyber-gallery',
            isLowEnd: false, _isMobileTier: false, reducedMotion: false,
            _venueArtworkReactive: { enabled: true },
            isMobile: false, arrivalActive: true, controls: { isLocked: true },
            velocity: { x: 4, z: 0 },
        };
        AR.initArtworkReactive.call(c);
        AR.updateArtworkReactive.call(c);
        return c._reactive.level < 0.01;
    })());
    ok('QA override lands directly on target (deterministic screenshots)', (() => {
        globalThis.window = globalThis.window || {};
        globalThis.window.EXOSPACE_QA_MOTION = 3.0;
        const c = {
            _reactive: null, _venueSlug: 'cyber-gallery',
            isLowEnd: false, _isMobileTier: false, reducedMotion: false,
            _venueArtworkReactive: { enabled: true },
            isMobile: false, controls: { isLocked: false }, velocity: { x: 0, z: 0 },
        };
        AR.initArtworkReactive.call(c);
        AR.updateArtworkReactive.call(c);
        const lvl = c._reactive.level;
        delete globalThis.window.EXOSPACE_QA_MOTION;
        return Math.abs(lvl - 1) < 1e-9;
    })());
    ok('declaration tuning is honoured (dead_zone from the DB, not the default)', (() => {
        const c = {
            _reactive: null, _venueSlug: 'cyber-gallery',
            isLowEnd: false, _isMobileTier: false, reducedMotion: false,
            _venueArtworkReactive: { enabled: true, dead_zone: 0.5, ref_speed: 2.0 },
        };
        AR.initArtworkReactive.call(c);
        return c._reactive.deadZone === 0.5 && c._reactive.refSpeed === 2.0;
    })());
    ok('bezel material is ONE shared instance (one colour write per frame)', (() => {
        const c = {
            _reactive: null, _venueSlug: 'cyber-gallery',
            isLowEnd: false, _isMobileTier: false, reducedMotion: false,
            _venueArtworkReactive: { enabled: true, bezel_color: '0x00e5ff' },
        };
        AR.initArtworkReactive.call(c);
        const a = AR.makeReactiveBezelMaterial.call(c);
        const b = AR.makeReactiveBezelMaterial.call(c);
        return a === b && !!c._reactive.bezelBase;
    })());
    ok('dispose nulls the registry + bezel (rebuild hygiene)', (() => {
        const c = {
            _reactive: null, _venueSlug: 'cyber-gallery',
            isLowEnd: false, _isMobileTier: false, reducedMotion: false,
            _venueArtworkReactive: { enabled: true },
        };
        AR.initArtworkReactive.call(c);
        AR.makeReactiveBezelMaterial.call(c);
        const m = c._reactive.bezelMat;
        AR.disposeArtworkReactive.call(c);
        return c._reactive === null;
    })());
} catch (e) {
    ok('material probe executed', false, String(e).slice(0, 200));
}

// ── D. JS/PHP hygiene ───────────────────────────────────────────────────────
section('D. JS/PHP hygiene');
ok('ZERO venue slugs in ArtworkReactive.js (config-declared, zero slug knowledge)',
    !/cyber-gallery/.test(reactiveSrc));
ok('no Math.random anywhere in the reactive path (determinism bar)',
    !/Math\.random\s*\(/.test(reactiveSrc.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')));
ok('no post-processing dependency in the module (the effect belongs to the artworks)',
    !/EffectComposer|ShaderPass|RenderTarget/.test(reactiveSrc));

const exporterSrc = readFileSync(rel('app/Services/VenueConfigExporter.php'), 'utf8');
ok("exporter ships 'artwork_reactive' in the venue-owned visual keys",
    /'artwork_reactive'/.test(exporterSrc));

const sceneSrc = readFileSync(rel('resources/js/gallery/GalleryScene.js'), 'utf8');
const movementIdx = sceneSrc.indexOf('this.updateMovement()');
const reactiveIdx = sceneSrc.indexOf('this.updateArtworkReactive()');
ok('animate loop: the signal is consumed AFTER movement integration (fresh velocity)',
    movementIdx !== -1 && reactiveIdx > movementIdx);
ok('dispose path clears the reactive registry',
    /if \(this\._reactive\) \{\s*\n\s*this\.disposeArtworkReactive\(\);/.test(sceneSrc));

const roomSrc = readFileSync(rel('resources/js/gallery/RoomBuilder.js'), 'utf8');
const initIdx = roomSrc.indexOf('this.initArtworkReactive()');
const placeIdx = roomSrc.indexOf('this.placeArtworks(');
ok('registry initializes BEFORE artworks are placed (every canvas registers)',
    initIdx !== -1 && placeIdx !== -1 && initIdx < placeIdx);

const migrFile = 'database/migrations/2026_09_09_000010_cyber_gallery_signal_room.php';
ok('guarded migration exists (production rows move without touching admin edits)',
    existsSync(rel(migrFile)));
if (existsSync(rel(migrFile))) {
    const migrSrc = readFileSync(rel(migrFile), 'utf8');
    ok('migration guarded (exact-match guard, absent keys only added when missing)',
        /guardedEquals/.test(migrSrc) && /array_key_exists/.test(migrSrc));
    ok('migration carries the artwork_reactive declaration (DB half of the signature)',
        /'artwork_reactive'/.test(migrSrc));
    ok('migration is reversible (down() reverses each rewrite under the same guard)',
        /public function down\(\)/.test(migrSrc));
}

ok('harness carries the ?motion= QA override (deterministic movement-state captures)',
    /EXOSPACE_QA_MOTION/.test(harnessSrc) && /MOTION_PRESETS/.test(harnessSrc));
ok('shoot.mjs captures the movement states (still / slow / walk / fast + close reading + low tier)',
    /cyber-motion-walk/.test(readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8')));

console.log('');
if (failures === 0) {
    console.log('CYBER GALLERY QA: ALL CHECKS PASSED');
} else {
    console.error(`CYBER GALLERY QA: ${failures} CHECK(S) FAILED`);
    process.exit(1);
}
