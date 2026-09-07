#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// crystal-cathedral-qa.mjs — the venue QA gate for Crystal Cathedral.
//
//   node scripts/venue-qa/crystal-cathedral-qa.mjs
//
// Same layering as infinite-void-qa.mjs (plain Node over the repo checkout;
// the geometry probe additionally uses the repo's `three` dependency):
// pins CONTRACTS while tests/Feature pins the DB side and
// scripts/harness/shoot.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the crystal-cathedral row declares the Luminous
//      Arcade identity (void_arcade body, declared environment, standing
//      glow, restrained bloom, planar reflection, depth-band placement) and
//      NOT the superseded bodies.
//   B. DB ↔ harness sync — the PHP-less harness renders the same JSON a
//      fresh install seeds (drift here means screenshots stop meaning
//      anything).
//   C. Architecture invariants — driven through the REAL VenueDecorator body
//      + three.js (no GL): adaptive bay plan (chord/apex always under the
//      pier crown), arch arcs meeting their springers and each other at the
//      apex, piers outside the walk bound, vault ribs converging on the boss
//      ring, no NaNs — plus the REAL float placement modules (bound
//      containment, depth bands, determinism).
//   D. JS/PHP hygiene — the rainbow pastel palette is gone from the
//      decorator, the rollback chain (void_colonnade/void_shards) still
//      dispatches, zero venue slugs in the shared modules, the ENVIRONMENTS
//      constant exists (it was referenced-but-undefined before the audit),
//      and the parity fix ships on the public controller.
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
section('A. Seeder contract (crystal-cathedral row)');
const seederSrc = readFileSync(rel('database/seeders/VenueTemplateSeeder.php'), 'utf8');

function chunk() {
    const i = seederSrc.indexOf("'slug'          => 'crystal-cathedral'");
    if (i === -1) throw new Error('crystal-cathedral row not found in seeder');
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

ok('arcade body declared (the architecture, not decoration)',
    /'void_arcade'\s*=>\s*true/.test(vc));
ok('superseded bodies NOT declared (rollback chain stays code-reachable only)',
    !/'void_colonnade'/.test(vc) && !/'void_shards'/.test(vc));
ok('float placement declared', /'placement_mode'\s*=>\s*'float'/.test(vc));
ok('phenomena structure pass declared (rollback switch)',
    /'structure_pass'\s*=>\s*'phenomena'/.test(vc));
ok('true glass declared (tier-resolved downstream)',
    /'glass_material'\s*=>\s*'transmission'/.test(vc));
ok('ice-white crystal tint declared (blue lives in the sky, not the material)',
    /'colonnade_tint'\s*=>\s*'0xe6f0fb'/.test(vc));
ok('environment DECLARED (was a preset accident — studio.hdr by drift)',
    /'environment'\s*=>\s*'studio'/.test(vc));
ok('env_intensity declared (deliberate glass definition)',
    /'env_intensity'\s*=>\s*0\.22/.test(vc));
ok('hemisphere declared (vertical gradient cue — v2.1.0 wall lift)',
    /'hemisphere_intensity'\s*=>\s*0\.30/.test(vc));
ok('zenith depth gradient declared (the look-up mandate)',
    /'void_depth_gradient'\s*=>\s*true/.test(vc));
ok('standing glow declared (void family sits beyond the proximity radius)',
    /'artwork_light_base'\s*=>\s*0\.38/.test(vc));
ok('pool cap raised (every artwork of a 12-piece hang lit at once)',
    /'artwork_light_pool_cap'\s*=>\s*12/.test(vc));
ok('depth-band curation declared (40-work shows stay inside ~17 m)',
    /'depth_bands'\s*=>\s*2/.test(vc));
ok('planar reflection declared (the copy promises it — honesty matrix)',
    /'floor_reflection'\s*=>\s*'planar'/.test(vc));
ok('bloom restrained + threshold raised (catches oculus + seam only)',
    /'bloom_strength'\s*=>\s*0\.42/.test(vc) && /'bloom_threshold'\s*=>\s*0\.82/.test(vc));
ok('rig luminous: exposure ≥ 0.85 (was 0.6 murk)',
    /'tone_mapping_exposure'\s*=>\s*0\.85/.test(vc));
ok('rig luminous: spot ≥ 1.0 (pool target ≈ 4.0)',
    /'spot_intensity'\s*=>\s*1\.15/.test(vc));
ok('fog survives 40-work scale (far ≥ 60)',
    /'fog_far'\s*=>\s*62/.test(vc));
ok('art-bay stone declared opaque + dark (controlled presentation)',
    /'wall_color'\s*=>\s*'0x131a26'/.test(mc));
ok('slate floor declared + tint-authoritative',
    /'floor_color'\s*=>\s*'0x1a2230'/.test(mc) && /'texture_tint'\s*=>\s*true/.test(mc));
ok('copy names the colonnade (verticality gate)',
    /colonnade/.test(row));
ok('copy promises float (placement matrix)',
    /float/.test(row));
ok('copy promises the reflection (reflection matrix)',
    /reflect/.test(row));
ok('no pastel-rainbow light palette in the row (the nightclub is gone)',
    !/0xffaaaa|0xaaffaa|0xaaaaff|0xffffaa|0xffaaff|0xaaffff/.test(row));

// ── B. DB ↔ harness sync ────────────────────────────────────────────────────
section('B. DB ↔ harness sync (crystal-cathedral body)');
const harnessSrc = readFileSync(rel('scripts/harness/harness.html'), 'utf8');
function harnessVenue(key) {
    const i = harnessSrc.indexOf(`'${key}': {`);
    if (i === -1) return null;
    // The next venue entry starts at a line with EXACTLY 8 spaces + quote;
    // matching "8 spaces" alone would stop at the first 12-space body line.
    const rest = harnessSrc.slice(i + 10);
    const next = rest.search(/\n        '/);
    return rest.slice(0, next === -1 ? undefined : next + 1);
}
const hBody = harnessVenue('crystal-cathedral');
ok('harness carries the crystal-cathedral body', !!hBody);
if (hBody) {
    const pairs = [
        ['void_arcade: true',                    /'void_arcade'\s*=>\s*true/.test(vc)],
        ['placement_mode: float',                /placement_mode:\s*'float'/.test(hBody) && /'placement_mode'\s*=>\s*'float'/.test(vc)],
        ['glass_material: transmission',         /glass_material:\s*'transmission'/.test(hBody) && /'glass_material'\s*=>\s*'transmission'/.test(vc)],
        ['colonnade_tint 0xe6f0fb',              /colonnade_tint:\s*'0xe6f0fb'/.test(hBody) && /'colonnade_tint'\s*=>\s*'0xe6f0fb'/.test(vc)],
        ['background_color 0x070b14',            /background_color:\s*'0x070b14'/.test(hBody) && /'background_color'\s*=>\s*'0x070b14'/.test(vc)],
        ['ambient_intensity 0.34',               /ambient_intensity:\s*0\.34/.test(hBody) && /'ambient_intensity'\s*=>\s*0\.34/.test(vc)],
        ['spot_intensity 1.15',                  /spot_intensity:\s*1\.15/.test(hBody) && /'spot_intensity'\s*=>\s*1\.15/.test(vc)],
        ['tone_mapping_exposure 0.85',           /tone_mapping_exposure:\s*0\.85/.test(hBody) && /'tone_mapping_exposure'\s*=>\s*0\.85/.test(vc)],
        ['artwork_light_base 0.38',              /artwork_light_base:\s*0\.38/.test(hBody) && /'artwork_light_base'\s*=>\s*0\.38/.test(vc)],
        ['depth_bands 2',                        /depth_bands:\s*2/.test(hBody) && /'depth_bands'\s*=>\s*2/.test(vc)],
        ['bloom_threshold 0.82',                 /bloom_threshold:\s*0\.82/.test(hBody) && /'bloom_threshold'\s*=>\s*0\.82/.test(vc)],
        ['floor_reflection planar',              /floor_reflection:\s*'planar'/.test(hBody) && /'floor_reflection'\s*=>\s*'planar'/.test(vc)],
        ['environment studio',                   /environment:\s*'studio'/.test(hBody) && /'environment'\s*=>\s*'studio'/.test(vc)],
        ['hemisphere_intensity 0.30 (v2.1.0 wall lift)', /hemisphere_intensity:\s*0\.30/.test(hBody) && /'hemisphere_intensity'\s*=>\s*0\.30/.test(vc)],
        ['vignette_blend black (v2.1.0 grey-veil fix)',  /vignette_blend:\s*'black'/.test(hBody) && /'vignette_blend'\s*=>\s*'black'/.test(vc)],
        ['wall_color 0x131a26',                  /wall_color:\s*'0x131a26'/.test(hBody) && /'wall_color'\s*=>\s*'0x131a26'/.test(mc)],
        ['floor_color 0x1a2230',                 /floor_color:\s*'0x1a2230'/.test(hBody) && /'floor_color'\s*=>\s*'0x1a2230'/.test(mc)],
    ];
    for (const [name, cond] of pairs) ok(`harness ↔ seeder: ${name}`, cond);
    ok('harness legacy rollback body present (void_colonnade forensic variant)',
        !!harnessVenue('crystal-cathedral-legacy') &&
        /void_colonnade:\s*true/.test(harnessVenue('crystal-cathedral-legacy')));
}

// ── C. Architecture + placement invariants (real modules) ───────────────────
section('C. Architecture invariants (real body, real three, no GL)');
try {
    const THREE = (await import('three')).default ?? (await import('three'));
    const VD = await import('../../resources/js/gallery/VenueDecorator.js');

    function buildArcade(radius, extra = {}) {
        const added = [];
        const ctx = {
            scene: { add: (o) => added.push(o) },
            isLowEnd: false,
            _isMobileTier: false,
            _venueSlug: 'crystal-cathedral',
            _layoutMeta: { type: 'circular', radius },
            _venueVisualConfig: {
                structure_pass: 'phenomena', void_arcade: true,
                colonnade_tint: '0xe6f0fb', floor_reflection: 'planar',
                ...extra,
            },
            _venueMaterialConfig: {
                wall_color: '0x131a26', wall_roughness: 0.3, wall_metalness: 0.06,
                floor_color: '0x1a2230', floor_roughness: 0.22, floor_metalness: 0.2,
            },
            _circularFloor: null,
            registerObstacle() {},
        };
        VD.addVenueStructure.call(ctx, {});
        return { ctx, added, radius };
    }

    const probes = [];
    for (const r of [10, 11.5, 16.6, 22.3]) probes.push(buildArcade(r));

    for (const { ctx, added, radius } of probes) {
        void ctx;
        const piers = added.find((o) => o.isInstancedMesh && o.geometry?.parameters?.radiusBottom === 0.72);
        ok(`[R=${radius}] arcade built (structure meshes present)`, added.length >= 14, `got ${added.length}`);
        if (!piers) { ok(`[R=${radius}] piers found`, false); continue; }
        const count = piers.count;

        // Pier ring: radius + alternating heights, no NaN.
        let maxR = 0, nan = false, heights = new Set();
        const m = new THREE.Matrix4();
        const p = new THREE.Vector3();
        const q = new THREE.Quaternion();
        const s = new THREE.Vector3();
        for (let i = 0; i < count; i++) {
            piers.getMatrixAt(i, m);
            m.decompose(p, q, s);
            if (!Number.isFinite(p.x + p.y + p.z)) nan = true;
            maxR = Math.max(maxR, Math.hypot(p.x, p.z));
            heights.add(Math.round(s.y * 10) / 10);
        }
        ok(`[R=${radius}] piers: ${count} instances, no NaN`, !nan);
        ok('pier heights alternate (ABAB rhythm, 13.0 / 12.2)',
            heights.has(13) && heights.has(12.2), [...heights].join('/'));
        ok('bay plan adaptive (chord ≈ 6 m: 10–24 bays)', count >= 10 && count <= 24, `count ${count}`);
        ok('piers ring at radius + 0.8 (outside the walk bound)',
            Math.abs(maxR - (radius + 0.8)) < 0.01, `maxR ${maxR.toFixed(2)}`);

        // Shared scratch vector for the probes below.
        const w = new THREE.Vector3();

        // Arch arcs: springers meet the piers, both arcs meet at the apex.
        const instanced = added.filter((o) => o.isInstancedMesh);
        const torusArcs = instanced.filter((o) => o.geometry?.parameters?.arc === Math.PI / 3);
        ok('two arc instanced meshes (left + right)', torusArcs.length === 2);
        if (torusArcs.length === 2) {
            const chord = torusArcs[0].geometry.parameters.radius;
            // Recompute the adaptive spring exactly as the body does.
            const spring = Math.min(7.4, 12.9 - chord * 0.866);
            const apexY = spring + chord * 0.866;
            ok('apex stays under the 13.0 pier crown', apexY < 13, `apex ${apexY.toFixed(2)}`);
            // The instance quaternion is Ry(yaw)·Rz(sweep): the sweep turns
            // the 0°→60° arc WITHIN its own plane, then the yaw orients the
            // plane along the chord. Construction order: torusArcs[0] = arcsL
            // (centred on B = pier i+1, sweep 120°), torusArcs[1] = arcsR
            // (centred on A = pier i, sweep 0). Landmarks in the arc's OWN
            // frame:
            //   arcsL (centred B, sweep 120°): 0° → apex, 60° → A (pier i)
            //   arcsR (centred A, sweep 0):    0° → B (pier i+1), 60° → apex
            const g0  = new THREE.Vector3(chord, 0, 0);
            const g60 = new THREE.Vector3(chord * Math.cos(Math.PI / 3), chord * Math.sin(Math.PI / 3), 0);
            const near = (a, b, eps = 0.25) => a.distanceTo(b) < eps;
            const pierXZ = (i) => {
                piers.getMatrixAt(((i % count) + count) % count, m); m.decompose(p, q, s);
                return new THREE.Vector3(p.x, 0, p.z);
            };
            const arcOrigin = (mesh, i) => {
                mesh.getMatrixAt(i, m); m.decompose(p, q, s);
                return new THREE.Vector3(p.x, 0, p.z);
            };
            const arcAt = (mesh, i, local) => {
                mesh.getMatrixAt(i, m); m.decompose(p, q, s);
                return w.copy(local).applyQuaternion(q).add(p).clone();
            };
            const springerAt = (i) => new THREE.Vector3(pierXZ(i).x, spring, pierXZ(i).z); // springing height
            const arcsL = torusArcs[0], arcsR = torusArcs[1];
            let originsOK = true, springersOK = true, apexOK = true;
            for (let i = 0; i < count; i++) {
                originsOK &&= near(arcOrigin(arcsL, i), pierXZ(i + 1), 0.01);
                originsOK &&= near(arcOrigin(arcsR, i), pierXZ(i), 0.01);
                const startL = arcAt(arcsL, i, g60); // arcsL 60° → A
                springersOK &&= near(startL, springerAt(i), 0.3);
                const apexL = arcAt(arcsL, i, g0);   // arcsL 0° → apex
                const startR = arcAt(arcsR, i, g0);  // arcsR 0° → B
                springersOK &&= near(startR, springerAt(i + 1), 0.3);
                const apexR = arcAt(arcsR, i, g60);  // arcsR 60° → apex
                apexOK &&= near(apexL, apexR, 0.3);
                apexOK &&= Math.abs(apexR.y - apexY) < 0.05;
            }
            ok('arc origins centred on their springing piers', originsOK);
            ok('arch springers meet the pier axes', springersOK);
            ok('both arcs meet at the apex (pointed, not round)', apexOK);
        }

        // Vault ribs converge: transformed curve end = boss radius at boss height.
        const ribs = instanced.find((o) => o.geometry?.parameters?.path);
        ok('rib vault instanced mesh present', !!ribs);
        if (ribs) {
            const curve = ribs.geometry.parameters.path;
            const end = curve.getPoint(1);
            const start = curve.getPoint(0);
            ribs.getMatrixAt(0, m); m.decompose(p, q, s);
            w.copy(end).applyQuaternion(q);
            ok('ribs end at the boss ring height (18.8)', Math.abs(w.y - 18.8) < 0.01, w.y.toFixed(2));
            w.copy(start).applyQuaternion(q);
            ok('ribs spring at 12.6 near the pier band', Math.abs(w.y - 12.6) < 0.01);
        }

        // Opaque art wall below the artwork hover band; luminous accents present.
        ok('oculus + seam + medallion present',
            added.some((o) => o.geometry?.type === 'CircleGeometry') &&
            added.some((o) => o.geometry?.type === 'RingGeometry') &&
            added.some((o) => o.material?.blending === THREE.AdditiveBlending));
        ok('exactly one key SpotLight (PERF-B18 budget: rainbows removed)',
            added.filter((o) => o.isSpotLight).length === 1 &&
            added.filter((o) => o.isPointLight).length === 0);

        // DEPLOY REVIEW (2026-09-08): the planar reflector must use the
        // MULTIPLY blend — the stock overlay blend brightened reflection
        // midtones and left emissive whites at full luminance, so the
        // deployed floor read as a duplicated world (user-reported) and the
        // reflected frames re-entered bloom. Multiply caps reflected whites
        // at the tint (≈0.35 < 0.82 bloom threshold) — polished stone.
        const reflector = added.find((o) => o.material?.uniforms?.tDiffuse);
        ok('planar reflector built on the declared path', !!reflector);
        if (reflector) {
            ok('reflector blend is MULTIPLY (no overlay midtone reversal)',
                /base\.rgb \* color/.test(reflector.material.fragmentShader) &&
                !/blendOverlay/.test(reflector.material.fragmentShader));
            ok('reflector tint is the dark steel 0x5a6a85 (whites cap under bloom threshold)',
                reflector.material.uniforms.color.value.getHex() === 0x5a6a85,
                reflector.material.uniforms.color.value.getHexString());
        }
        // Dressed-stone trim: the bay framing must be lighter than the wall
        // field (the "framed bays of stone" the copy promises must render).
        const trims = added.filter((o) => o.geometry?.type === 'BoxGeometry');
        ok('bay trim instanced meshes present (pilasters + headers)', trims.length === 2);
        const trimMat = trims[0]?.material;
        const wallBack = added.find((o) => o.geometry?.type === 'CylinderGeometry' && o.material?.side === THREE.BackSide);
        if (trimMat && wallBack) {
            ok('trim tone is lighter than the wall field (bays read against stone)',
                trimMat.color.getHex() !== wallBack.material.color.getHex() &&
                trimMat.color.getHex() > wallBack.material.color.getHex(),
                `${trimMat.color.getHexString()} vs ${wallBack.material.color.getHexString()}`);
        }
    }

    // Adaptive bay plan across the radius range must keep the crown under 13.
    let crownOK = true;
    for (let r = 10; r <= 22.4; r += 0.5) {
        const pierR = r + 0.8;
        const count = Math.max(10, Math.min(24, Math.round((Math.PI * 2 * pierR) / 6.0)));
        const chord = 2 * pierR * Math.sin(Math.PI / count);
        if (Math.min(7.4, 12.9 - chord * 0.866) + chord * 0.866 >= 13) crownOK = false;
    }
    ok('crown < 13.0 for every exhibition radius 10→22.4', crownOK);

    // ── Placement: the REAL float modules, determinism + bounds ────────────
    const { computeFloatLayout, computeFloatFieldRadius } = await import('../../resources/js/gallery/PlacementMath.js');
    const { createVenueRng } = await import('../../resources/js/gallery/Rng.js');
    for (const count of [5, 12, 40]) {
        const radius = computeFloatFieldRadius(count, 3.5, { depthBands: 2 }).radius;
        const rngA = createVenueRng('crystal-cathedral:77');
        const a = computeFloatLayout(count, radius, rngA, { depthBands: 2 });
        const rngB = createVenueRng('crystal-cathedral:77');
        const b = computeFloatLayout(count, radius, rngB, { depthBands: 2 });
        ok(`[${count} works] float layout deterministic`, JSON.stringify(a) === JSON.stringify(b));
        let inside = true;
        for (const pt of a) {
            if (Math.hypot(pt.x, pt.z) > radius - 0.5) inside = false;   // walk bound
            if (pt.y < 0.6 || pt.y > 3.2) inside = false;                // hover band
        }
        ok(`[${count} works] all works inside the bound + hover band`, inside, `radius ${radius.toFixed(2)}`);
        // Depth bands pay off at scale: past ~20 works the banded radius is
        // strictly tighter than the legacy linear ring (40 works: 16.6 vs
        // 22.3 m); small shows stay at or near the composed 10–11.5 m floor.
        const linear = Math.max(10, (count * 3.5) / (2 * Math.PI));
        if (count >= 20) ok(`[${count} works] banded radius tighter than linear`, radius < linear, `${radius.toFixed(2)} vs ${linear.toFixed(2)}`);
        else ok(`[${count} works] radius stays walkable (≤ linear + 2 m)`, radius <= linear + 2, `${radius.toFixed(2)} vs ${linear.toFixed(2)}`);
    }
} catch (err) {
    ok('architecture probe (requires the repo three dependency)', false, err.message);
}

// ── D. JS / PHP hygiene ─────────────────────────────────────────────────────
section('D. JS / PHP hygiene');
const decoratorSrc = readFileSync(rel('resources/js/gallery/VenueDecorator.js'), 'utf8');
ok('rainbow pastel palette removed from the decorator',
    !/0xffaaaa|0xaaffaa|0xaaaaff|0xffffaa|0xffaaff|0xaaffff/.test(decoratorSrc.replace(/addCrystalCathedralLegacyShards[\s\S]*$/, '')) ||
    !/0xffaaaa|0xaaffaa|0xaaaaff|0xffffaa|0xffaaff|0xaaffff/.test(decoratorSrc.split('addCrystalCathedralArcade')[0] + decoratorSrc.split('addCrystalCathedralArcade')[1]?.split('// LEGACY BODY')[0]));
ok('arcade body dispatch present (void_arcade → new body)',
    /vc\.void_arcade === true/.test(decoratorSrc) && /addCrystalCathedralArcade\.call/.test(decoratorSrc));
ok('rollback bodies still dispatch (void_colonnade / void_shards)',
    /vc\.void_colonnade === true/.test(decoratorSrc) && /vc\.void_shards === true/.test(decoratorSrc));
ok('no Math.random in the arcade body',
    !/Math\.random/.test(decoratorSrc.split('function addCrystalCathedralArcade')[1]?.split('// CRYSTAL CATHEDRAL — composed vertical light architecture')[0] ?? ''));
ok('no venue slugs in TierResolve.js', !/crystal|white-cube|nebula|mirror-lake|zen/.test(readFileSync(rel('resources/js/gallery/TierResolve.js'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*/g, '')));

const modelSrc = readFileSync(rel('app/Models/VenueTemplate.php'), 'utf8');
ok('ENVIRONMENTS constant EXISTS (was referenced-but-undefined — fatal on venue save)',
    /const ENVIRONMENTS\s*=\s*\['studio',\s*'rural_evening',\s*'night',\s*'none'\]/.test(modelSrc));
ok('void_arcade joins STRUCTURED_VISUAL_KEYS', /'void_arcade',/.test(modelSrc));
const requestSrc = readFileSync(rel('app/Http/Requests/SuperAdmin/VenueTemplateRequest.php'), 'utf8');
ok('void_arcade validated in the super-admin request', /void_arcade/.test(requestSrc));
const publicCtrl = readFileSync(rel('app/Http/Controllers/GalleryViewController.php'), 'utf8');
ok('public path resolves preset/layout through the venue authority (parity fix)',
    /presetForGallery\(\$gallery\)/.test(publicCtrl) && /layoutForGallery\(\$gallery\)/.test(publicCtrl));

const migrationPath = rel('database/migrations/2026_09_07_000001_crystal_cathedral_architecture.php');
ok('guarded migration exists', existsSync(migrationPath));
if (existsSync(migrationPath)) {
    const mig = readFileSync(migrationPath, 'utf8');
    ok('migration guards every replacement (admin edits win)', /=== \$from/.test(mig) && /'from'/.test(mig));
    ok('migration removes only the superseded flag (guarded)', /removedKeys/.test(mig) && /void_colonnade/.test(mig));
}

console.log(failures === 0
    ? '\nALL CRYSTAL CATHEDRAL QA CHECKS PASSED'
    : `\n${failures} CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
