#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// sculpture-garden-qa.mjs — the venue QA gate for Outdoor Sculpture Garden
// (v3.0.0, "The Curated Walk": landscape-first exhibition identity).
//
//   node scripts/venue-qa/sculpture-garden-qa.mjs
//
// Same layering as cyber-gallery-qa.mjs (plain Node over the repo checkout;
// section C additionally imports the repo's real GardenLayout + Rng modules):
// pins CONTRACTS while tests/Feature pins the DB side and
// scripts/harness/shoot.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the sculpture-garden row declares the landscape
//      identity: placement_mode 'garden' (the curated courts), the field
//      sizing (bonus + floor), the declared environment absence + sky IBL
//      strength, the hemisphere sky/ground daylight tints, the ceiling-orb
//      opt-out, bloom-off post_fx (daylight needs no glow), the artwork
//      standing glow, and the sun_shadows gate. The superseded v2 copy must
//      be gone; the copy must promise exactly what renders.
//   B. DB ↔ harness sync — the PHP-less harness renders the same JSON a
//      fresh install seeds (drift here means the screenshots stop meaning
//      anything), and shoot.mjs carries the garden scenario set.
//   C. Landscape invariants — driven through the REAL GardenLayout module:
//      determinism (identical seed → identical plan), the validator passing
//      across the full capacity range, the role hierarchy mix, terrain
//      finiteness + courts/spawn actually flat, closed-form camera-follow
//      cost (allocation-free shape), the walk network reaching every court,
//      easel geometry living behind the canvas plane, and merge-safe
//      canopy geometry (cones toNonIndexed — the mixed-index crash class).
//   D. JS/PHP hygiene — zero venue slugs in the pure module, no Math.random
//      in the garden path, the exporter ships the owned keys, the animate
//      loop consumes the tick AFTER movement, RoomBuilder builds the plan
//      BEFORE structure/placement, the rng is guarded against mid-build
//      reseed, the guarded migration file exists, and the spawn never sits
//      inside the central sculpture (the v2 head-in-the-knot defect).
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
section('A. Seeder contract (sculpture-garden row)');
const seederSrc = readFileSync(rel('database/seeders/VenueTemplateSeeder.php'), 'utf8');

function chunk() {
    const i = seederSrc.indexOf("'slug'          => 'sculpture-garden'");
    if (i === -1) throw new Error('sculpture-garden row not found in seeder');
    const nextSlug = seederSrc.indexOf("'slug'", i + 10);
    return seederSrc.slice(i, nextSlug === -1 ? undefined : nextSlug);
}
function arrayLiteral(source, key) {
    // Anchor on the KEY => [ shape — the key's name also appears as VALUES
    // elsewhere (structure_pass => 'garden', the tags array).
    const m = source.match(new RegExp(`'${key}'\\s*=>\\s*\\[`));
    if (!m) return '';
    const k = m.index;
    const open = source.indexOf('[', k);
    let depth = 0;
    for (let i = open; i < source.length; i++) {
        if (source[i] === '[') depth++;
        else if (source[i] === ']') { depth--; if (depth === 0) return source.slice(open, i + 1); }
    }
    return '';
}

{
    const row = chunk();
    ok('version pins 3.0.0', /'version'\s+=>\s+'3\.0\.0'/.test(row));
    ok('structure_pass selects the garden interpreter', /'structure_pass'\s+=>\s+'garden'/.test(row));
    ok('circular + open_air declared', /'layout_shape'\s+=>\s+'circular'/.test(row) && /'open_air'\s+=>\s+true/.test(row));
    ok('placement_mode declares the curated walk', /'placement_mode'\s+=>\s+'garden'/.test(row));
    ok('field radius bonus + floor declared', /'field_radius_bonus'\s+=>\s+2\.2/.test(row) && /'field_radius_min'\s+=>\s+12\.5/.test(row));
    ok('environment none (no HDRI download)', /'environment'\s+=>\s+'none'/.test(row));
    ok('sky IBL strength declared (0.22)', /'env_intensity'\s+=>\s+0\.22/.test(row));
    ok('hemisphere daylight tints declared', /'hemisphere_sky_color'\s+=>\s+'0xbfd9ee'/.test(row) && /'hemisphere_ground_color'\s+=>\s+'0x51663c'/.test(row));
    ok('hemisphere intensity declared (0.4)', /'hemisphere_intensity'\s+=>\s+0\.4/.test(row));
    ok('ceiling orb opted out', /'ceiling_fill_light'\s+=>\s+false/.test(row));
    ok('sun shadows stay config-gated', /'sun_shadows'\s+=>\s+true/.test(row));
    ok('artwork standing glow declared (0.22)', /'artwork_light_base'\s+=>\s+0\.22/.test(row));
    const garden = arrayLiteral(row, 'garden');
    ok('garden block gates the sky environment', /'sky_environment'\s+=>\s+true/.test(garden));
    const postFx = arrayLiteral(row, 'post_fx');
    ok('bloom OFF (daylight needs no glow)', /'bloom'\s+=>\s+false/.test(postFx));
    ok('vignette blends to black', /'vignette_blend'\s+=>\s+'black'/.test(postFx));
    ok('v2.0.0 copy is gone', !row.includes('along a winding path'));
    ok('copy promises the curated discovery', row.includes('discovered one by one'));
    const material = arrayLiteral(row, 'material_config');
    ok('grass floor declared', material.includes("'floor_color'            => '0x3a6a2a'") && /'floor_material'\s+=>\s+'grass'/.test(row));
}

// ── B. DB ↔ harness sync ────────────────────────────────────────────────────
section('B. DB ↔ harness sync (sculpture-garden body)');
{
    const harnessSrc = readFileSync(rel('scripts/harness/harness.html'), 'utf8');
    const i = harnessSrc.indexOf("'sculpture-garden': {");
    ok('harness carries the garden venue', i !== -1);
    const next = harnessSrc.indexOf("version: '3.0.0',", i);
    const row = harnessSrc.slice(i, next);

    // The identity keys must match the seeder byte-for-byte (screenshots
    // stop meaning anything on drift — cyber QA gate B precedent).
    const syncPairs = [
        ["placement_mode: 'garden'", /'placement_mode'\s+=>\s+'garden'/],
        ['field_radius_bonus: 2.2', /'field_radius_bonus'\s+=>\s+2\.2/],
        ['field_radius_min: 12.5', /'field_radius_min'\s+=>\s+12\.5/],
        ["environment: 'none'", /'environment'\s+=>\s+'none'/],
        ['env_intensity: 0.22', /'env_intensity'\s+=>\s+0\.22/],
        ['hemisphere_intensity: 0.4', /'hemisphere_intensity'\s+=>\s+0\.4/],
        ["hemisphere_sky_color: '0xbfd9ee'", /'hemisphere_sky_color'\s+=>\s+'0xbfd9ee'/],
        ["hemisphere_ground_color: '0x51663c'", /'hemisphere_ground_color'\s+=>\s+'0x51663c'/],
        ['ceiling_fill_light: false', /'ceiling_fill_light'\s+=>\s+false/],
        ['artwork_light_base: 0.22', /'artwork_light_base'\s+=>\s+0\.22/],
        ['sun_shadows: true', /'sun_shadows'\s+=>\s+true/],
        ['sky_environment: true', /'sky_environment'\s+=>\s+true/],
        ['bloom: false', /'bloom'\s+=>\s+false/],
        ["floor_color: '0x3a6a2a'", /'floor_color'\s+=>\s+'0x3a6a2a'/],
    ];
    for (const [harnessKey, seederRe] of syncPairs) {
        ok(`sync: ${harnessKey}`, row.includes(harnessKey) && seederRe.test(seederSrc));
    }

    const shootSrc = readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8');
    for (const id of ['garden-05', 'garden-12-mixed', 'garden-30-mixed', 'garden-cam-arrival',
        'garden-cam-promenade', 'garden-cam-court', 'garden-cam-close', 'garden-cam-distance',
        'garden-cam-boundary', 'garden-cam-horizon', 'garden-tier-low-12']) {
        ok(`scenario pinned: ${id}`, shootSrc.includes(`'${id}'`));
    }
}

// ── C. Landscape invariants (real module, real rng) ─────────────────────────
section('C. Landscape invariants (real GardenLayout module)');
{
    const { buildGardenPlan, validateGardenPlan, samplePolyline, GARDEN_DEFAULTS } =
        await import(rel('resources/js/gallery/GardenLayout.js'));
    const { createVenueRng } = await import(rel('resources/js/gallery/Rng.js'));

    // The garden's field sizing (mirrors RoomBuilder's declared application).
    const gardenRadius = (count) =>
        Math.max(12.5, Math.max(count * 3.5, 30) / (2 * Math.PI) + 2.2);

    // C1. Determinism — same seed, same plan (every array, not a sample).
    const a = buildGardenPlan({ radius: gardenRadius(30), count: 30, rng: createVenueRng('sculpture-garden:qa') });
    const b = buildGardenPlan({ radius: gardenRadius(30), count: 30, rng: createVenueRng('sculpture-garden:qa') });
    ok('deterministic plan (courts, trees, shrubs, paths)',
        JSON.stringify(a.courts) === JSON.stringify(b.courts) &&
        JSON.stringify(a.trees) === JSON.stringify(b.trees) &&
        JSON.stringify(a.shrubs) === JSON.stringify(b.shrubs) &&
        JSON.stringify(a.paths.map(p => p.kind)) === JSON.stringify(b.paths.map(p => p.kind)));

    // C2. The validator passes across the full capacity range (5..30).
    let allValid = true;
    const perCount = {};
    for (const count of [1, 5, 8, 12, 18, 20, 25, 30]) {
        const plan = buildGardenPlan({ radius: gardenRadius(count), count, rng: createVenueRng(`sculpture-garden:cap-${count}`) });
        const v = validateGardenPlan(plan);
        perCount[count] = { courts: plan.courts.length, trees: plan.trees.length, ok: v.ok };
        if (!v.ok) { allValid = false; console.error(`      count=${count}:`, v.violations.slice(0, 3)); }
    }
    ok('validator passes for counts 1,5,8,12,18,20,25,30', allValid, JSON.stringify(perCount));
    ok('role hierarchy exists at capacity (primary/secondary/transitional)', (() => {
        const roles = perCount[30];
        return roles.ok && roles.courts === 30;
    })());
    const roles30 = buildGardenPlan({ radius: gardenRadius(30), count: 30, rng: createVenueRng('sculpture-garden:roles') })
        .courts.reduce((m, c) => ((m[c.role] = (m[c.role] || 0) + 1), m), {});
    ok('role mix at 30: 3 primary / 18 secondary / 9 transitional',
        roles30.primary === 3 && roles30.secondary === 18 && roles30.transitional === 9, JSON.stringify(roles30));

    // C3. Terrain: finite everywhere; courts + spawn actually FLAT (the
    // camera-follow and easel placement both consume this field).
    {
        const plan = buildGardenPlan({ radius: gardenRadius(12), count: 12, rng: createVenueRng('sculpture-garden:terrain') });
        let bad = 0, maxCourtDrift = 0;
        for (let i = 0; i < 4000; i++) {
            const x = Math.sin(i * 1.7) * 40, z = Math.cos(i * 2.3) * 40;
            if (!Number.isFinite(plan.terrain.height(x, z))) bad++;
        }
        for (const c of plan.courts) {
            const h = plan.terrain.height(c.x, c.z);
            const hEdge = plan.terrain.height(c.x + 1.2, c.z);
            maxCourtDrift = Math.max(maxCourtDrift, Math.abs(h - hEdge));
        }
        const spawnFlat = Math.abs(plan.terrain.height(plan.spawn.x, plan.spawn.z)) < 1e-6;
        ok('terrain finite across a 80 m sweep', bad === 0);
        ok('courts sit level (edge drift ≤ 0.02 m over 1.2 m)', maxCourtDrift <= 0.02, `max ${maxCourtDrift.toFixed(4)}`);
        ok('spawn plaza is flat', spawnFlat);
        // The skirt is a ROLLING landscape — hills AND valleys (rolling
        // amplitude beyond the hedge line), never a flat plane extension.
        let maxLift = 0;
        for (let ang = 0; ang < 12; ang++) {
            const t = (ang / 12) * Math.PI * 2;
            const h = plan.terrain.height(Math.sin(t) * plan.radius * 2.2, Math.cos(t) * plan.radius * 2.2);
            maxLift = Math.max(maxLift, h);
        }
        ok('distant landscape rolls beyond the hedge (hill ≥ 0.3 m)', maxLift > 0.3, `max ${maxLift.toFixed(2)} m`);
    }

    // C4. Walks reach every court (reachability band) — pinned by the
    // validator, re-asserted here as the design contract.
    {
        const plan = buildGardenPlan({ radius: gardenRadius(20), count: 20, rng: createVenueRng('sculpture-garden:walks') });
        const v = validateGardenPlan(plan);
        const reachable = plan.courts.every((c) => {
            let best = Infinity;
            for (const p of plan.paths) for (const s of p.samples) {
                const d = Math.hypot(c.x - s[0], c.z - s[1]);
                if (d < best) best = d;
            }
            return best <= 8.5;
        });
        ok('every court reachable from the walk network (≤ 8.5 m)', reachable && v.ok);
    }

    // C5. Easel geometry lives BEHIND the canvas plane (the v3 orientation
    // contract — no easel ever paints over its artwork again).
    {
        const placerSrc = readFileSync(rel('resources/js/gallery/ArtworkPlacer.js'), 'utf8');
        const fnStart = placerSrc.indexOf('export function _addEasel');
        const fn = placerSrc.slice(fnStart, placerSrc.indexOf('export function', fnStart + 10));
        const zs = [...fn.matchAll(/pos: \[[^\]]*,\s*(-?[\d.]+)\]/g)].map(m => parseFloat(m[1]));
        ok('every easel part sits at z ≤ 0 (behind the canvas plane)', zs.length >= 4 && zs.every(z => z <= 0.001), JSON.stringify(zs));
        ok('easel yaw = canvasYaw (local +z mirrors the canvas front)', /easel\.rotation\.y = canvasYaw;/.test(fn));
    }

    // C6. Merge-safe canopy geometry (the mixed-index crash class).
    {
        const decSrc = readFileSync(rel('resources/js/gallery/VenueDecorator.js'), 'utf8');
        const fnStart = decSrc.indexOf('function addSculptureGardenStructure');
        const fn = decSrc.slice(fnStart, decSrc.indexOf('\n// ──', fnStart));
        const cones = [...fn.matchAll(/new THREE\.ConeGeometry\([^)]*\)(\.toNonIndexed\(\))?/g)];
        ok('every canopy cone converts to non-indexed (merge safety)', cones.length >= 3 && cones.every(m => m[1] === '.toNonIndexed()'));
        ok('canopies merge per tone (≤ 3 draw calls)', /canopyParts\[t\.tone\]/.test(fn));
        ok('stones merge per kind (2 draw calls)', fn.includes('promStones') && fn.includes('loopStones'));
        ok('sky environment is PMREM from the dome (no HDRI asset)', fn.includes('PMREMGenerator') && fn.includes('fromScene'));
        ok('fog is radius-aware (courts never fogged)', fn.includes('radius * 1.6'));
        ok('sky dome inside a 3.7R camera-far floor (the far-clip disc defect class stays dead)',
            fn.includes('radius * 2.9') && fn.includes('radius * 3.7') && fn.includes('updateProjectionMatrix'));
        ok('ground-follow tick registered', fn.includes('this._gardenTick'));
    }

    // C7. samplePolyline sanity (walks stone-sampling contract).
    {
        const pts = [[0, 0], [0, 10]];
        const s = samplePolyline(pts, 1);
        ok('polyline sampling covers endpoints at ~step spacing', s.length >= 10 &&
            Math.hypot(s[0][0], s[0][1]) < 1e-6 &&
            Math.hypot(s[s.length - 1][0] - 0, s[s.length - 1][1] - 10) < 0.5);
    }

    // C8. Defaults pin the design constants QA talks about.
    ok('defaults: walkClearance 1.55 / minCourtGap 3.4 / spawnClearance 3.2',
        GARDEN_DEFAULTS.walkClearance === 1.55 && GARDEN_DEFAULTS.minCourtGap === 3.4 && GARDEN_DEFAULTS.spawnClearance === 3.2);
}

// ── D. JS/PHP hygiene ───────────────────────────────────────────────────────
section('D. JS/PHP hygiene');
{
    const gardenSrc = readFileSync(rel('resources/js/gallery/GardenLayout.js'), 'utf8');
    ok('pure module: zero venue slugs', !/sculpture-garden|infinite-void|nebula-drift|mirror-lake|cyber-gallery/.test(gardenSrc));
    ok('pure module: no Math.random', !gardenSrc.includes('Math.random'));
    ok('pure module: no THREE/DOM imports', !gardenSrc.includes("from 'three'") && !gardenSrc.includes('document.'));

    const placerSrc = readFileSync(rel('resources/js/gallery/ArtworkPlacer.js'), 'utf8');
    ok('garden placement registers collision obstacles', /registerObstacle\(group, 0\.35\)/.test(placerSrc));
    ok('garden placement falls back to the ring on plan/artwork mismatch', /plan\.courts\.length !== this\.artworkImages\.length/.test(placerSrc));

    const roomSrc = readFileSync(rel('resources/js/gallery/RoomBuilder.js'), 'utf8');
    const circDef = roomSrc.indexOf('export function createRoomCircular');
    ok('plan builds in RoomBuilder BEFORE structure/placement',
        roomSrc.indexOf('buildGardenPlan({') !== -1 &&
        roomSrc.indexOf('buildGardenPlan({') < roomSrc.indexOf('this.addVenueStructure(data);', circDef));
    ok('rng guarded against mid-build reseed',
        /if \(!this\._venueRng\) this\._venueRng = createVenueRng/.test(readFileSync(rel('resources/js/gallery/VenueDecorator.js'), 'utf8')));
    ok('rebuild hygiene clears the plan + tick', /this\._gardenPlan = null;\s*\n\s*this\._gardenTick = null;/.test(roomSrc));

    const sceneSrc = readFileSync(rel('resources/js/gallery/GalleryScene.js'), 'utf8');
    const moveIdx = sceneSrc.indexOf('this.updateMovementMobile()') !== -1
        ? Math.min(sceneSrc.indexOf('this.updateMovementMobile()'), sceneSrc.indexOf('this.updateMovement();'))
        : sceneSrc.indexOf('this.updateMovement();');
    const tickIdx = sceneSrc.indexOf('this._gardenTick()');
    const reactiveIdx = sceneSrc.indexOf('this.updateArtworkReactive()');
    ok('animate consumes the tick AFTER movement + reactive', tickIdx > moveIdx && tickIdx > reactiveIdx);

    const exporterSrc = readFileSync(rel('app/Services/VenueConfigExporter.php'), 'utf8');
    for (const key of ['\'garden\',', '\'ceiling_fill_light\',', '\'field_radius_bonus\',', '\'field_radius_min\',', '\'hemisphere_sky_color\',', '\'hemisphere_ground_color\',']) {
        ok(`exporter owns ${key.replace(/[',]/g, '')}`, exporterSrc.includes(key));
    }

    ok('guarded migration exists', existsSync(rel('database/migrations/2026_09_09_000011_sculpture_garden_curated_walk.php')));

    // The v2 head-in-the-knot defect stays dead: the spawn is derived from
    // the plan (gate plaza), never (0, 0).
    ok('spawn comes from the plan, never the centre', /camera\.position\.set\(spawn\.x, CONFIG\.camera\.height, spawn\.z\)/.test(roomSrc));
}

console.log(failures === 0
    ? '\nALL CHECKS PASSED — the Curated Walk contract holds.'
    : `\n${failures} CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
