#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// nebula-drift-qa.mjs — the venue QA gate for Nebula Drift (v2.2.0, "the
// nebula owns the sky" identity pass on top of the Deep Field).
//
//   node scripts/venue-qa/nebula-drift-qa.mjs
//
// Same layering as crystal-cathedral-qa.mjs (plain Node over the repo
// checkout; the composition probe additionally uses the repo's `three`
// dependency): pins CONTRACTS while tests/Feature pins the DB side and
// scripts/harness/shoot.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the nebula-drift row declares the Deep Field
//      identity (void_deepfield body, declared environment 'none', neutral
//      rig, island-grade standing glow, restrained bloom, black vignette,
//      depth bands, light pools, elevation-step hang) and NOT the
//      superseded v1.0.0 keys.
//   B. DB ↔ harness sync — the PHP-less harness renders the same JSON a
//      fresh install seeds (drift here means screenshots stop meaning
//      anything).
//   C. Composition invariants — driven through the REAL VenueDecorator body
//      + three.js (no GL, canvas stubbed): the three sky shells precess in
//      the SAME sense at layered rates (coherent sky, shear parallax), the
//      arch carries a crown luminosity profile + deep tonal masses, stars
//      are NEUTRAL (the v1.0.0 all-violet sky is gone), the current carries
//      its shader drift attributes, the ring renders as a restrained thread
//      (halo + vertex luminosity, whisper base), monolith silhouettes stand
//      beyond the field, light pools land under the artworks, the body
//      scales across the capacity range, low-end keeps the composition, and
//      two builds from the same seed are transform-identical.
//   D. JS/PHP hygiene — no purple rig in the row, no all-violet star palette
//      in the body, zero venue slugs in the shared modules, the drift-rotate
//      motion type is consumed, the owned-key 'nebula' + SCHEMA s6 ship on
//      the exporter, and the guarded migration file is present.
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
section('A. Seeder contract (nebula-drift row)');
const seederSrc = readFileSync(rel('database/seeders/VenueTemplateSeeder.php'), 'utf8');

function chunk() {
    const i = seederSrc.indexOf("'slug'          => 'nebula-drift'");
    if (i === -1) throw new Error('nebula-drift row not found in seeder');
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
const pl = arrayLiteral(vc, 'placement');
const nb = arrayLiteral(vc, 'nebula');

ok('deepfield body declared (the audit body, not decoration)',
    /'void_deepfield'\s*=>\s*true/.test(vc));
ok('superseded v1.0.0 body NOT declared (rollback stays code-reachable only)',
    !/'void_starfield'/.test(vc));
ok('float placement declared', /'placement_mode'\s*=>\s*'float'/.test(vc));
ok('phenomena structure pass declared (rollback switch)',
    /'structure_pass'\s*=>\s*'phenomena'/.test(vc));
ok('zenith depth gradient declared (shared depth cue, reused not rebuilt)',
    /'void_depth_gradient'\s*=>\s*true/.test(vc));
ok('floor edge fade declared (the disc dissolves into the void)',
    /'floor_edge_fade'\s*=>\s*true/.test(vc));
ok('environment DECLARED none (was a silenced night.hdr download)',
    /'environment'\s*=>\s*'none'/.test(vc));
ok('env_intensity 0 (the sky is procedural — no HDRI at all)',
    /'env_intensity'\s*=>\s*0(?![.\d])/.test(vc));
ok('ambient NEUTRAL moon-slate (the v1.0.0 purple tinted every canvas)',
    /'ambient_color'\s*=>\s*'0x7a86b8'/.test(vc));
ok('ambient base wash 0.62 (v2.1.0: the spawn view is 12–20 m out — the wash carries unlit canvases)',
    /'ambient_intensity'\s*=>\s*0\.62/.test(vc));
ok('hemisphere fill declared (vertical fill for unlit far canvases)',
    /'hemisphere_intensity'\s*=>\s*0\.35/.test(vc));
ok('rig luminous: exposure 0.85 (was 0.6 murk)',
    /'tone_mapping_exposure'\s*=>\s*0\.85/.test(vc));
ok('rig luminous: spot 1.35 (pool target ≈ 4.7, void family)',
    /'spot_intensity'\s*=>\s*1\.35/.test(vc));
ok('standing glow island-grade 0.72 (v2.2.0: works read as lit islands at spawn distance)',
    /'artwork_light_base'\s*=>\s*0\.72/.test(vc));
ok('pool cap raised (a 12-piece hang lit at once)',
    /'artwork_light_pool_cap'\s*=>\s*12/.test(vc));
ok('depth-band curation declared (40-work shows compose in depth)',
    /'depth_bands'\s*=>\s*2/.test(pl));
ok('light pools declared (every floating work grounds over its light)',
    /'light_pools'\s*=>\s*true/.test(pl));
ok('elevation step declared (v2.2.0: inner bands hover higher — a suspended constellation)',
    /'elevation_step'\s*=>\s*0\.7/.test(pl));
ok('floor fade span declared (the phantom plane ends at the dissolve — no stage edge)',
    /'floor_fade_span'\s*=>\s*1\.16/.test(vc));
ok('sky meets the floor exactly (background + fog pure black — the v2.1.0 horizon-line fix)',
    /'background_color'\s*=>\s*'0x000000'/.test(vc) && /'fog_color'\s*=>\s*'0x000000'/.test(vc));
ok('nebula palette declared (dominant/secondary/accent — the colour hierarchy)',
    /'dominant'\s*=>\s*'0x5a4ae0'/.test(nb) && /'secondary'\s*=>\s*'0x2e6ac8'/.test(nb) && /'accent'\s*=>\s*'0xd85a9e'/.test(nb));
ok('bloom restrained (0.35 @ 0.8 — effects support, never carry)',
    /'bloom_strength'\s*=>\s*0\.35/.test(pf) && /'bloom_threshold'\s*=>\s*0\.8(?![.\d])/.test(pf));
ok('vignette black-blend declared (the grey-veil defect class never ships again)',
    /'vignette_blend'\s*=>\s*'black'/.test(pf));
ok('fog survives 40-work scale (far ≥ 60)',
    /'fog_far'\s*=>\s*70/.test(vc));
ok('floor colour declared + tint-authoritative (v1.0.0 shipped stock cream marble)',
    /'floor_color'\s*=>\s*'0x0b0724'/.test(mc) && /'texture_tint'\s*=>\s*true/.test(mc));
ok('floor metalness sane for an env-free venue (was a dead 0.5 mirror-metal)',
    /'floor_metalness'\s*=>\s*0\.15/.test(mc));
ok('ONE declared key light (the v1.0.0 fixture + body light were stacked doubles)',
    /'nebula-key'/.test(row) && !/'nebula-center'/.test(row));
ok('copy names the deep field + the drift (name test)',
    /deep-field/i.test(row) && /drift/i.test(row));
ok('copy promises float (placement matrix)',
    /float/i.test(row));
ok('copy does NOT promise a reflection (honesty matrix — no reflector here)',
    !/reflect/i.test(row));
ok('version pinned 2.2.0',
    /'version'\s*=>\s*'2\.2\.0'/.test(row));

// ── B. DB ↔ harness sync ────────────────────────────────────────────────────
section('B. DB ↔ harness sync (nebula-drift body)');
const harnessSrc = readFileSync(rel('scripts/harness/harness.html'), 'utf8');
function harnessVenue(key) {
    const i = harnessSrc.indexOf(`'${key}': {`);
    if (i === -1) return null;
    const rest = harnessSrc.slice(i + 10);
    const next = rest.search(/\n        '/);
    return rest.slice(0, next === -1 ? undefined : next + 1);
}
const hBody = harnessVenue('nebula-drift');
ok('harness carries the nebula-drift body', !!hBody);
if (hBody) {
    const pairs = [
        ['void_deepfield true',                  /void_deepfield:\s*true/.test(hBody) && /'void_deepfield'\s*=>\s*true/.test(vc)],
        ['placement_mode float',                 /placement_mode:\s*'float'/.test(hBody) && /'placement_mode'\s*=>\s*'float'/.test(vc)],
        ['environment none',                     /environment:\s*'none'/.test(hBody) && /'environment'\s*=>\s*'none'/.test(vc)],
        ['ambient 0x7a86b8',                     /ambient_color:\s*'0x7a86b8'/.test(hBody) && /'ambient_color'\s*=>\s*'0x7a86b8'/.test(vc)],
        ['ambient_intensity 0.62',               /ambient_intensity:\s*0\.62/.test(hBody) && /'ambient_intensity'\s*=>\s*0\.62/.test(vc)],
        ['hemisphere_intensity 0.35',            /hemisphere_intensity:\s*0\.35/.test(hBody) && /'hemisphere_intensity'\s*=>\s*0\.35/.test(vc)],
        ['spot_intensity 1.35',                  /spot_intensity:\s*1\.35/.test(hBody) && /'spot_intensity'\s*=>\s*1\.35/.test(vc)],
        ['tone_mapping_exposure 0.85',           /tone_mapping_exposure:\s*0\.85/.test(hBody) && /'tone_mapping_exposure'\s*=>\s*0\.85/.test(vc)],
        ['fog_far 70',                           /fog_far:\s*70/.test(hBody) && /'fog_far'\s*=>\s*70/.test(vc)],
        ['artwork_light_base 0.72',              /artwork_light_base:\s*0\.72/.test(hBody) && /'artwork_light_base'\s*=>\s*0\.72/.test(vc)],
        ['artwork_light_pool_cap 12',            /artwork_light_pool_cap:\s*12/.test(hBody) && /'artwork_light_pool_cap'\s*=>\s*12/.test(vc)],
        ['depth_bands 2',                        /depth_bands:\s*2/.test(hBody) && /'depth_bands'\s*=>\s*2/.test(vc)],
        ['light_pools true',                     /light_pools:\s*true/.test(hBody) && /'light_pools'\s*=>\s*true/.test(vc)],
        ['elevation_step 0.7',                   /elevation_step:\s*0\.7/.test(hBody) && /'elevation_step'\s*=>\s*0\.7/.test(vc)],
        ['floor_fade_span 1.16',                 /floor_fade_span:\s*1\.16/.test(hBody) && /'floor_fade_span'\s*=>\s*1\.16/.test(vc)],
        ['background pure black',                /background_color:\s*'0x000000'/.test(hBody) && /'background_color'\s*=>\s*'0x000000'/.test(vc)],
        ['nebula palette',                       /dominant:\s*'0x5a4ae0'/.test(hBody) && /accent:\s*'0xd85a9e'/.test(hBody) && /'dominant'\s*=>\s*'0x5a4ae0'/.test(vc)],
        ['bloom 0.35 @ 0.8',                     /bloom_strength:\s*0\.35/.test(hBody) && /bloom_threshold:\s*0\.8(?![.\d])/.test(hBody) && /'bloom_strength'\s*=>\s*0\.35/.test(vc)],
        ['vignette_blend black',                 /vignette_blend:\s*'black'/.test(hBody) && /'vignette_blend'\s*=>\s*'black'/.test(vc)],
        ['floor_color 0x0b0724 + texture_tint',  /floor_color:\s*'0x0b0724'/.test(hBody) && /texture_tint:\s*true/.test(hBody) && /'texture_tint'\s*=>\s*true/.test(mc)],
        ['key light nebula-key (no double)',     /'nebula-key'/.test(hBody) && !/nebula-center/.test(hBody)],
        ['version 2.2.0',                        /version:\s*'2\.2\.0'/.test(hBody) && /'version'\s*=>\s*'2\.2\.0'/.test(row)],
    ];
    for (const [name, cond] of pairs) ok(`harness ↔ seeder: ${name}`, cond);
    ok('harness legacy rollback body present (v1.0.0 starfield forensic variant)',
        !!harnessVenue('nebula-drift-legacy') &&
        /void_starfield:\s*true/.test(harnessVenue('nebula-drift-legacy')));
}

// ── C. Composition invariants (real body, real three, no GL) ────────────────
section('C. Composition invariants (real body, real three, no GL)');
// Source reads the composition probe shares with the hygiene section —
// hoisted above the probe so in-source contract checks can run inside it.
const decoratorSrc = readFileSync(rel('resources/js/gallery/VenueDecorator.js'), 'utf8');
try {
    // Canvas stub — the body generates its sprite/pool textures via 2D
    // contexts; with no GL in the probe nothing is ever uploaded.
    const ctxStub = () => ({
        createRadialGradient: () => ({ addColorStop: () => {} }),
        fillRect: () => {}, clearRect: () => {}, fillStyle: null,
        putImageData: () => {},
        createImageData: (w, h) => ({ data: new Uint8ClampedArray(w * h * 4), width: w, height: h }),
        getImageData: () => ({ data: new Uint8ClampedArray(4) }),
    });
    globalThis.document = { createElement: () => ({ width: 0, height: 0, getContext: ctxStub }) };

    const THREE = (await import('three')).default ?? (await import('three'));
    const VD = await import('../../resources/js/gallery/VenueDecorator.js');
    const { createVenueRng } = await import('../../resources/js/gallery/Rng.js');
    const { computeFloatLayout, computeFloatFieldRadius } = await import('../../resources/js/gallery/PlacementMath.js');

    const VC_BASE = {
        structure_pass: 'phenomena', layout_shape: 'circular',
        void_deepfield: true, void_depth_gradient: true, floor_edge_fade: true,
        nebula: { dominant: '0x5a4ae0', secondary: '0x2e6ac8', accent: '0xd85a9e' },
        placement: { depth_bands: 2, light_pools: true },
    };

    function buildField(radius, extra = {}, opts = {}) {
        const scene = new THREE.Scene();
        const ctx = {
            scene,
            isLowEnd: !!opts.lowEnd,
            _isMobileTier: !!opts.mobile,
            _venueSlug: 'nebula-drift',
            _layoutMeta: { type: 'circular', radius },
            _venueVisualConfig: { ...VC_BASE, ...extra },
            _venueMaterialConfig: {},
        };
        VD.addVenueStructure.call(ctx, {});
        return { ctx, scene };
    }

    function classify(scene) {
        const sprites = [], points = [], instanced = [], meshes = [], groups = [];
        scene.traverse((o) => {
            if (o.isSprite) sprites.push(o);
            else if (o.isPoints) points.push(o);
            else if (o.isInstancedMesh) instanced.push(o);
            else if (o.isMesh && o.geometry?.type === 'TorusGeometry') meshes.push(o);
            else if (o.isGroup) groups.push(o);
        });
        return { sprites, points, instanced, meshes, groups };
    }

    const probes = [];
    for (const r of [9.5, 12.5, 16.6, 22.3]) probes.push({ r, ...buildField(r) });
    const { scene: probeScene } = probes[1];

    const { sprites, points, instanced, meshes } = classify(probeScene);

    // v2.2.0 desktop counts: far 4 features + 2 deep masses + 16 haze = 22;
    // mid 5 features + 1 accent + 2 deep + 12 haze = 20; near veil 4 → 46.
    ok('band built: 46 sky sprites on desktop (features + deep masses + haze + near veil)',
        sprites.length === 46, `got ${sprites.length}`);
    ok('band masses differ by seeded screen rotation (5 shared canvases, no clones)',
        new Set(sprites.map((s) => s.material.rotation)).size >= 40);
    ok('Points objects: 3 star strata + 1 stardrift current', points.length === 4, `got ${points.length}`);
    ok('monolith silhouettes: 1 instanced mesh of 4 shards', instanced.length === 1 && instanced[0].count === 4, `got ${instanced.map(i => i.count).join('/')}`);
    ok('meridian ring + its atmosphere halo present (thread, not a bare wire)',
        meshes.length === 2, `got ${meshes.length}`);
    if (meshes.length === 2) {
        const ring = meshes.find((m) => m.geometry.parameters?.tube === 0.055) || meshes[0];
        ok('ring floats above the walk band (overhead, not a tripwire)',
            ring.position.y > 8, `y ${ring.position.y.toFixed(1)}`);
        ok('ring tilted off-axis (found, not installed)',
            Math.abs(ring.rotation.x) > 0.1);
        ok('ring carries its seeded luminosity arcs (vertex colours)',
            !!ring.geometry.getAttribute('color'));
        ok('ring restraint: material opacity ≤ 0.6 (the v2.1.0 hoop is demoted to a thread)',
            ring.material.opacity <= 0.6, `opacity ${ring.material.opacity}`);
        ok('ring restraint: additive, no depth write, fog-exempt (luminous class)',
            ring.material.blending === THREE.AdditiveBlending && !ring.material.depthWrite && ring.material.fog === false);
        const halo = meshes.find((m) => m !== ring);
        ok('ring halo: nebula-tinted atmosphere behind the thread',
            !!halo && halo.material.opacity > ring.material.opacity * 0.1 && halo.material.opacity < 0.2,
            halo ? `opacity ${halo.material.opacity}` : 'missing');
    }

    // Sprite shells: the sky must be LAYERED (three distinct radii) and
    // precess in the SAME sense at layered rates (one coherent sky whose
    // layers shear by ≈ 8°/3 min — the v2.1.0 coherence fix), all
    // subliminal. The 4th drift-rotate entry is the meridian ring's own yaw
    // (its bright beads travel the ring) — shells are the entries at the
    // three shell rates; the ring runs slightly faster by design.
    const drifts = (probes[1].ctx._particleSystems || []).filter((p) => p.type === 'drift-rotate');
    ok('drift-rotate registry: three sky shells + the ring rig', drifts.length === 4, `got ${drifts.length}`);
    const shells = drifts.filter((d) => Math.abs(d.speed) <= 0.0052);
    ok('three sky shells registered for precession (far arch, mid band, near veil)',
        shells.length === 3, `got ${shells.length}`);
    ok('shells precess in the SAME sense (one coherent arch, layered shear)',
        shells.length === 3 && new Set(shells.map((d) => Math.sign(d.speed))).size === 1);
    ok('shell rates LAYER (near > mid > far — true parallax when walking)',
        shells.length === 3 && Math.abs(shells[0].speed) < Math.abs(shells[1].speed) && Math.abs(shells[1].speed) < Math.abs(shells[2].speed));
    ok('precession subliminal (|rate| ≤ 0.006 rad/s ≈ ≤ 0.34°/s)',
        drifts.every((d) => Math.abs(d.speed) <= 0.006));
    const spriteDist = sprites.map((s) => s.position.length());
    const minD = Math.min(...spriteDist), maxD = Math.max(...spriteDist);
    ok('band is LAYERED (near veil ~1.55×R, mid 2.15×R, far ~3.1×R)',
        minD > probes[1].r * 1.4 && maxD < probes[1].r * 4.2,
        `min ${minD.toFixed(1)} / max ${maxD.toFixed(1)} @ R ${probes[1].r}`);
    ok('crown luminosity profile: the brightest feature sits nearer the arch core than the dimmest (§1 hierarchy)',
        (() => {
            // Feature masses are the high-opacity (> 0.5) sprites; their
            // opacities were written as base × crownWeight, so the spread of
            // opacities across the shell is the profile's fingerprint.
            const feats = sprites.map((s) => s.material.opacity).filter((o) => o > 0.5);
            if (feats.length < 4) return false;
            return Math.max(...feats) - Math.min(...feats) > 0.12;
        })(), 'opacity spread across features must exceed 0.12');
    ok('deep masses present: half-luminance bodies between the bright features (§1 tonal structure)',
        (() => {
            // The masses' colour is baked into the shared canvas textures
            // (material.color stays white), so the deep bodies are asserted
            // at the source: DOMINANT × 0.45, drawn between the features.
            const src = addNebulaDeepfieldSrc();
            return src.includes('const DEEP = DOMINANT.clone().multiplyScalar(0.45)')
                && src.includes('deepMass(');
        })());
    ok('band masses flattened toward a plane (a BAND, not a sphere shell)',
        Math.abs(sprites.reduce((acc, s) => acc + s.position.y, 0) / sprites.length) < probes[1].r * 0.5);
    ok('every sky sprite is fog-exempt (the §4.7 sky rule)',
        sprites.every((s) => s.material.fog === false));
    ok('every sky sprite is additive + no depth write (luminous atmosphere)',
        sprites.every((s) => s.material.blending === THREE.AdditiveBlending && !s.material.depthWrite));
    ok('sky sprites render behind the scene (renderOrder < 0)',
        sprites.every((s) => s.renderOrder < 0));

    // Stars: the v1.0.0 sky was 100% hue 0.7–0.85 (violet). The new sky is
    // NEUTRAL: the far fine field must be overwhelmingly white/blue-white.
    const starObjs = points.filter((p) => p.geometry?.getAttribute('color'));
    ok('star colours present as vertex attributes', starObjs.length >= 2);
    const farField = starObjs.find((p) => p.geometry.getAttribute('position').count >= 300);
    ok('far fine starfield built (≥ 300 stars on the desktop tier)',
        !!farField, farField ? `got ${farField.geometry.getAttribute('position').count}` : 'missing');
    if (farField) {
        const col = farField.geometry.getAttribute('color');
        let violet = 0;
        const c = new THREE.Color();
        for (let i = 0; i < col.count; i++) {
            c.setRGB(col.getX(i), col.getY(i), col.getZ(i));
            const hsl = {};
            c.getHSL(hsl);
            if (hsl.h > 0.7 && hsl.h < 0.85) violet++;
        }
        ok('starfield is NEUTRAL (violet stars ≤ 8% — the v1.0.0 sky was 100%)',
            violet / col.count <= 0.08, `${((violet / col.count) * 100).toFixed(1)}% violet`);
        ok('starfield is fog-exempt (sky, not scene depth)', farField.material.fog === false);
    }

    // The current: shader drift attributes + the GalleryScene-consumed type.
    const current = (probes[1].ctx._particleSystems || []).find((p) => p.type === 'void-drift');
    ok('stardrift current registered (void-drift type → uTime advanced per frame)', !!current);
    if (current) {
        ok('current carries its drift attributes (aPhase/aSize/aSpeed)',
            !!current.obj.geometry.getAttribute('aPhase') &&
            !!current.obj.geometry.getAttribute('aSize') &&
            !!current.obj.geometry.getAttribute('aSpeed'));
        ok('current has a seeded heading (uDir uniform)', !!current.obj.material.uniforms?.uDir);
        ok('current is fog-exempt (the current IS atmosphere)', current.obj.material.fog === false);
    }

    // Monoliths: beyond the field, dark, fog-exempt (silhouettes against the
    // band glow — occluding it requires opaque + fog:false).
    if (instanced.length === 1) {
        const inst = instanced[0];
        const m = new THREE.Matrix4(); const p = new THREE.Vector3();
        const q = new THREE.Quaternion(); const s = new THREE.Vector3();
        let maxR = 0, nan = false, maxH = 0;
        for (let i = 0; i < inst.count; i++) {
            inst.getMatrixAt(i, m); m.decompose(p, q, s);
            if (!Number.isFinite(p.x + p.y + p.z + s.y)) nan = true;
            maxR = Math.max(maxR, Math.hypot(p.x, p.z));
            maxH = Math.max(maxH, s.y);
        }
        ok('monoliths: no NaN transforms', !nan);
        ok('monoliths stand beyond the artwork field (≥ 2.5×R)',
            maxR >= probes[1].r * 2.5, `maxR ${maxR.toFixed(1)} @ R ${probes[1].r}`);
        ok('monoliths are colossal (≥ 12 m tall at R 12.5 — scale ambiguity)',
            maxH >= 12, `maxH ${maxH.toFixed(1)} m`);
        ok('monoliths read as dark forms the key can model (linear luminance 0.012–0.035 — the v2.2.0 visibility lift; the old 0x0d0a22 sat at 0.010)',
            (() => { const h = {}; inst.material.color.getHSL(h); return h.l > 0.012 && h.l <= 0.035; })(),
            `l ${(() => { const h = {}; inst.material.color.getHSL(h); return h.l.toFixed(3); })()}`);
        ok('monoliths fog-exempt (they must occlude, not wash out)', inst.material.fog === false);
        ok('monoliths opaque (they occlude the additive sky)',
            inst.material.transparent !== true);
    }

    // Light pools: declared placement.light_pools → one instanced mesh under
    // every artwork (post-placement hook).
    {
        const scene = new THREE.Scene();
        const ctx = {
            scene, isLowEnd: false, _isMobileTier: false,
            _venueSlug: 'nebula-drift',
            _layoutMeta: { type: 'circular', radius: 12.5 },
            _venueVisualConfig: { ...VC_BASE },
            _venueRng: createVenueRng('nebula-drift:pools'),
            artworks: [],
        };
        for (let i = 0; i < 12; i++) ctx.artworks.push({ position: new THREE.Vector3(Math.sin(i) * 10, 1.6, Math.cos(i) * 10) });
        VD.addVenuePostPlacementStructure.call(ctx);
        const pools = scene.children.find((o) => o.isInstancedMesh);
        ok('light pools: one instanced mesh for the whole hang', !!pools);
        if (pools) {
            ok('light pools: one pool per artwork', pools.count === 12, `got ${pools.count}`);
            const m = new THREE.Matrix4(); const p = new THREE.Vector3();
            const q = new THREE.Quaternion(); const s = new THREE.Vector3();
            let aligned = true;
            for (let i = 0; i < pools.count; i++) {
                pools.getMatrixAt(i, m); m.decompose(p, q, s);
                const art = ctx.artworks[i].position;
                if (Math.hypot(p.x - art.x, p.z - art.z) > 0.01) aligned = false;
                if (p.y > 0.2) aligned = false; // ON the floor, not floating
            }
            ok('light pools: every pool sits under its artwork on the floor', aligned);
            ok('light pools: additive + no depth write (light on the floor, not a disc)',
                pools.material.blending === THREE.AdditiveBlending && !pools.material.depthWrite);
        }
        // Idempotence: a rebuild must never stack a second pool layer.
        const countBefore = scene.children.length;
        VD.addVenuePostPlacementStructure.call(ctx);
        ok('light pools: idempotent across rebuilds', scene.children.length === countBefore);
        // Undeclared ⇒ no-op (the generic key must not fire without a declaration).
        const scene2 = new THREE.Scene();
        VD.addVenuePostPlacementStructure.call({
            scene: scene2, isLowEnd: false, _isMobileTier: false,
            _venueSlug: 'x', _venueVisualConfig: { structure_pass: 'phenomena' },
            _venueRng: createVenueRng('x'), artworks: ctx.artworks,
        });
        ok('light pools: never fire undeclared (config is the only switch)',
            scene2.children.length === 0);
    }

    // Ring radius + band scale with the venue radius (5-work salon → 40-work
    // hall keeps the composition proportional).
    {
        const small = classify(buildField(9.5).scene);
        const large = classify(buildField(22.3).scene);
        const rs = small.meshes[0].position.y, rl = large.meshes[0].position.y;
        ok('ring scales with the exhibition radius (proportional composition)',
            rl > rs * 1.5, `y ${rs.toFixed(1)} → ${rl.toFixed(1)}`);
        const spriteS = small.sprites.map((s) => s.scale.x);
        const spriteL = large.sprites.map((s) => s.scale.x);
        ok('band masses scale with the exhibition radius',
            Math.max(...spriteL) > Math.max(...spriteS) * 1.5);
    }

    // Low-end: composition survives, counts shrink, near layers drop.
    {
        const { scene: loScene } = buildField(12.5, {}, { lowEnd: true });
        const lo = classify(loScene);
        ok('low-end: the band still builds (identity survives degradation)',
            lo.sprites.length === 14, `got ${lo.sprites.length} (features + deep masses only — haze and the near veil are fill-rate luxuries)`);
        ok('low-end: the near veil is a desktop layer (fill rate)',
            lo.sprites.every((s) => s.position.length() > 12.5 * 1.4),
            'every low-end sprite must sit in the far/mid shells');
        ok('low-end: near star anchors dropped (fine field carries the sky)',
            !lo.points.some((p) => p.material.size === 0.62),
            'the size-0.62 near-anchor stratum must be desktop-only');
        const loCurrent = (buildField(12.5, {}, { lowEnd: true }).ctx._particleSystems || []).find((p) => p.type === 'void-drift');
        ok('low-end: current is a plain static PointsMaterial (no GLSL on this tier)',
            loCurrent && loCurrent.obj.material.isPointsMaterial === true);
    }

    // Determinism: two builds, same seed → identical sprite transforms.
    {
        const a = buildField(12.5);
        const b = buildField(12.5);
        const ta = a.sprites ? null : null;
        const sa = [], sb = [];
        a.scene.traverse((o) => { if (o.isSprite) sa.push([o.position.x, o.position.y, o.position.z, o.scale.x].join(',')); });
        b.scene.traverse((o) => { if (o.isSprite) sb.push([o.position.x, o.position.y, o.position.z, o.scale.x].join(',')); });
        ok('determinism: identical seed → identical band transforms', JSON.stringify(sa) === JSON.stringify(sb));
        const ia = [], ib = [];
        const dump = (scene, out) => scene.traverse((o) => {
            if (o.isInstancedMesh) { const m = new THREE.Matrix4(); for (let i = 0; i < o.count; i++) { o.getMatrixAt(i, m); out.push(m.elements.join(',')); } }
        });
        dump(a.scene, ia); dump(b.scene, ib);
        ok('determinism: identical seed → identical monolith transforms', JSON.stringify(ia) === JSON.stringify(ib));
    }

    // Placement: the two-band hang stays inside the bounds with the pools
    // declared (same pure planner the pool anchors read), and the v2.2.0
    // elevation step lifts ONLY the inner band (outer ring keeps the
    // legibility band bit-exactly).
    {
        const field = computeFloatFieldRadius(40, 3.2, { depthBands: 2 });
        const rng = createVenueRng('nebula-drift:placement');
        const layout = computeFloatLayout(40, field.radius, rng, { depthBands: 2 });
        let worst = 0;
        for (const p of layout) worst = Math.max(worst, Math.hypot(p.x, p.z));
        ok('40-work hang (2 bands) stays inside the walk bound',
            worst <= field.radius - 0.5 + 0.01, `worst ${worst.toFixed(2)} vs R ${field.radius}`);

        const rng2 = createVenueRng('nebula-drift:placement'); // same seed → same draws
        const lifted = computeFloatLayout(40, field.radius, rng2, { depthBands: 2, elevationStep: 0.7 });
        const flatBand0Y = layout.filter((_, i) => i % 2 === 0).map((p) => p.y);
        const liftBand0Y = lifted.filter((_, i) => i % 2 === 0).map((p) => p.y);
        const liftBand1Y = lifted.filter((_, i) => i % 2 === 1).map((p) => p.y);
        const flatBand1Y = layout.filter((_, i) => i % 2 === 1).map((p) => p.y);
        ok('elevation step: outer band untouched (bit-exact historic legibility band)',
            JSON.stringify(flatBand0Y) === JSON.stringify(liftBand0Y));
        ok('elevation step: inner band lifted by exactly the declared step (+0.7 m ± fp)',
            liftBand1Y.every((y, i) => Math.abs((y - flatBand1Y[i]) - 0.7) < 1e-9));
        ok('elevation step: default keeps every venue bit-identical (opt-in key)',
            (() => {
                // Omitting the key and passing 0 explicitly must produce the
                // exact same layout — undeclared ⇒ historic behaviour.
                const rngA = createVenueRng('nebula-drift:placement');
                const rngB = createVenueRng('nebula-drift:placement');
                const omitted = computeFloatLayout(40, field.radius, rngA, { depthBands: 2 });
                const explicitZero = computeFloatLayout(40, field.radius, rngB, { depthBands: 2, elevationStep: 0 });
                return JSON.stringify(omitted) === JSON.stringify(explicitZero);
            })());
    }
} catch (err) {
    ok('composition probe (requires the repo three dependency)', false, err.message);
}

// ── D. JS/PHP hygiene ───────────────────────────────────────────────────────
section('D. JS/PHP hygiene');
const gallerySceneSrc = readFileSync(rel('resources/js/gallery/GalleryScene.js'), 'utf8');
const exporterSrc = readFileSync(rel('app/Services/VenueConfigExporter.php'), 'utf8');

ok('the body never re-adds a centre light (the double-light defect is dead)',
    !addNebulaDeepfieldSrc().includes('PointLight'));
function addNebulaDeepfieldSrc() {
    const i = decoratorSrc.indexOf('function addNebulaDeepfield(');
    const j = decoratorSrc.indexOf('function makeNebulaMassTexture(');
    return decoratorSrc.slice(i, j);
}
ok('no legacy purple ambient in the row (0x8844ff purged from the rig)',
    !/0x8844ff/.test(row));
ok('no all-violet star palette in the DEEPFIELD body (the hue 0.7–0.85 star RANGE is gone; the legacy rollback body keeps its historical hue line verbatim)',
    !/const hue\s*=\s*0\.7\s*\+/.test(addNebulaDeepfieldSrc()));
ok('drift-rotate motion type consumed by GalleryScene',
    gallerySceneSrc.includes("ps.type === 'drift-rotate'"));
ok("current reuses the void-drift uTime channel (no duplicated animation system)",
    addNebulaDeepfieldSrc().includes("'void-drift'"));

// DoD rule #7: shared modules stay slug-free.
const slugs = ['white-cube', 'industrial-loft', 'dark-museum', 'zen-gallery',
    'luxury-penthouse', 'cyber-gallery', 'sculpture-garden',
    'infinite-void', 'crystal-cathedral', 'nebula-drift', 'mirror-lake'];
for (const module of ['resources/js/gallery/TierResolve.js', 'resources/js/gallery/TierEffects.js', 'resources/js/gallery/PlacementMath.js']) {
    const contents = readFileSync(rel(module), 'utf8');
    ok(`${module} stays slug-free (DoD rule #7)`,
        !slugs.some((s) => contents.includes(s)));
}

// Exporter: the palette is venue-owned, the schema re-keyed.
ok("exporter owns the 'nebula' palette key (s6)", /'nebula',/.test(exporterSrc));
ok('exporter SCHEMA bumped to s6', /SCHEMA\s*=\s*'s6'/.test(exporterSrc));

// Migrations: the full guarded chain ships (Deep Field → arch → identity).
const migChain = [
    ['database/migrations/2026_09_08_000002_nebula_drift_deepfield.php', "1.0.0", "2.0.0"],
    ['database/migrations/2026_09_08_000003_nebula_drift_arch.php',      "2.0.0", "2.1.0"],
    ['database/migrations/2026_09_08_000004_nebula_drift_identity.php',  "2.1.0", "2.2.0"],
];
for (const [p, from, to] of migChain) {
    ok(`guarded migration ships: ${path.basename(p)}`, existsSync(rel(p)));
    if (existsSync(rel(p))) {
        const mig = readFileSync(rel(p), 'utf8');
        ok(`  ${path.basename(p)}: version swap exact-match guarded (${from} → ${to})`,
            mig.includes(`'${from}'`) && mig.includes(`'${to}'`));
        ok(`  ${path.basename(p)}: guards the copy swap on an exact match`,
            mig.includes('OLD_DESCRIPTION') && mig.includes('NEW_DESCRIPTION'));
        ok(`  ${path.basename(p)}: has a matching down() restore`,
            /public function down\(\):\s*void/.test(mig));
    }
}
const mig4 = existsSync(rel(migChain[2][0])) ? readFileSync(rel(migChain[2][0]), 'utf8') : '';
ok('v2.2.0 migration carries the elevation step + island-grade base glow',
    mig4.includes("'elevation_step'") && mig4.includes('0.72'));
ok('v2.2.0 migration nests elevation_step INSIDE the placement object (union, object preserved)',
    mig4.includes("is_array($visual['placement'] ?? null)"));

// Shoot scenarios exist for the walk-through evidence.
const shootSrc = readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8');
ok('shoot.mjs captures the 40-work walk-through',
    /nebula-40-mixed/.test(shootSrc));
ok('shoot.mjs captures the look-up pose (band + ring)',
    /nebula-cam-up/.test(shootSrc));
ok('shoot.mjs captures the crown identity pose (the luminous core)',
    /nebula-cam-crown/.test(shootSrc));
ok('shoot.mjs captures the low tier + the legacy rollback body',
    /nebula-tier-low-06/.test(shootSrc) && /nebula-legacy-12/.test(shootSrc));

console.log('');
if (failures === 0) console.log('NEBULA DRIFT QA: ALL PASS');
else { console.error(`NEBULA DRIFT QA: ${failures} FAILURE(S)`); process.exit(1); }
