#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// sculpture-garden-qa.mjs — the venue QA gate for Outdoor Sculpture Garden
// (v4.0.0, "The Sculpture Park": the ASSET-DRIVEN environment).
//
//   node scripts/venue-qa/sculpture-garden-qa.mjs
//
// Same layering as cyber-gallery-qa.mjs (plain Node over the repo checkout;
// section C additionally imports the repo's real GardenLayout/GardenAssets/Rng
// modules): pins CONTRACTS while tests/Feature pins the DB side and
// scripts/harness/shoot.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the sculpture-garden row declares the landscape
//      identity AND the v4 asset manifest (garden.assets_base + 7 role
//      filenames the owner fills under public/assets/venues/sculpture-garden/).
//   B. DB ↔ harness sync — the PHP-less harness renders the same JSON a
//      fresh install seeds (drift here means the screenshots stop meaning
//      anything), and shoot.mjs carries the garden scenario set + the
//      deterministic async-asset gate.
//   C. Landscape invariants — driven through the REAL modules: determinism,
//      validator across the full capacity range, role hierarchy, terrain
//      finiteness + flat courts, reachability, PANEL-STAND geometry (all
//      parts behind the canvas plane), the PRIMITIVE BAN (no Cone/Icosahedron/
//      box-hedge vegetation may return), the gravel ribbon/disc vocabulary,
//      asset resolution + graceful-missing + instancing math, PMREM sky env,
//      radius-aware fog, the far-clip floor, the ground-follow tick.
//   D. JS/PHP hygiene — zero venue slugs in the pure modules, no Math.random,
//      exporter owns the keys, animate order, RoomBuilder build order, the
//      guarded migrations (v3 identity + v4 asset manifest), root-relative
//      asset URLs, the settled gate the harness polls.
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
    ok('version pins 4.0.0', /'version'\s+=>\s+'4\.0\.0'/.test(row));
    ok('structure_pass selects the garden interpreter', /'structure_pass'\s+=>\s+'garden'/.test(row));
    ok('circular + open_air declared', /'layout_shape'\s+=>\s+'circular'/.test(row) && /'open_air'\s+=>\s+true/.test(row));
    ok('placement_mode declares the curated walk', /'placement_mode'\s+=>\s+'garden'/.test(row));
    ok('field radius bonus + floor declared', /'field_radius_bonus'\s+=>\s+2\.6/.test(row) && /'field_radius_min'\s+=>\s+14/.test(row));
    ok('environment none (no HDRI download)', /'environment'\s+=>\s+'none'/.test(row));
    ok('sky IBL strength declared (0.22)', /'env_intensity'\s+=>\s+0\.22/.test(row));
    ok('hemisphere daylight tints declared', /'hemisphere_sky_color'\s+=>\s+'0xbfd9ee'/.test(row) && /'hemisphere_ground_color'\s+=>\s+'0x51663c'/.test(row));
    ok('hemisphere intensity declared (0.4)', /'hemisphere_intensity'\s+=>\s+0\.4/.test(row));
    ok('ceiling orb opted out', /'ceiling_fill_light'\s+=>\s+false/.test(row));
    ok('sun shadows stay config-gated', /'sun_shadows'\s+=>\s+true/.test(row));
    ok('artwork standing glow declared (0.22)', /'artwork_light_base'\s+=>\s+0\.22/.test(row));

    const garden = arrayLiteral(row, 'garden');
    ok('garden block gates the sky environment', /'sky_environment'\s+=>\s+true/.test(garden));
    ok('asset base is ROOT-RELATIVE (page-relative breaks nested routes)',
        /'assets_base'\s+=>\s+'\/assets\/venues\/sculpture-garden\/'/.test(garden));
    for (const role of ['tree_large', 'tree_medium', 'tree_accent', 'shrub', 'grass', 'boulder', 'bench']) {
        ok(`asset manifest declares role: ${role}`, new RegExp(`'${role}'\\s+=>\\s+'[\\w-]+\\.glb'`).test(garden));
    }
    ok('asset filenames follow the versioned 01 convention',
        /'tree_large'\s+=>\s+'tree_large_01\.glb'/.test(garden) && /'shrub'\s+=>\s+'shrub_01\.glb'/.test(garden));

    const postFx = arrayLiteral(row, 'post_fx');
    ok('bloom OFF (daylight needs no glow)', /'bloom'\s+=>\s+false/.test(postFx));
    ok('vignette blends to black', /'vignette_blend'\s+=>\s+'black'/.test(postFx));
    ok('v2/v3 copy is gone', !row.includes('along a winding path') && !row.includes('hedges and rolling meadow'));
    ok('copy promises the gravel walk + museum stands', row.includes('gravel walk') && row.includes('museum stands'));
    const material = arrayLiteral(row, 'material_config');
    ok('muted lawn green declared', material.includes("'floor_color'            => '0x5e7a46'") && /'floor_material'\s+=>\s+'grass'/.test(row));
    ok('calmer grass tile scale (3 m)', material.includes("'floor_tile_meters'     => 3.0,"));
}

// ── B. DB ↔ harness sync ────────────────────────────────────────────────────
section('B. DB ↔ harness sync (sculpture-garden body)');
{
    const harnessSrc = readFileSync(rel('scripts/harness/harness.html'), 'utf8');
    const i = harnessSrc.indexOf("'sculpture-garden': {");
    ok('harness carries the garden venue', i !== -1);
    const next = harnessSrc.indexOf("version: '4.0.0',", i);
    const row = harnessSrc.slice(i, next);

    const syncPairs = [
        ["placement_mode: 'garden'", /'placement_mode'\s+=>\s+'garden'/],
        ['field_radius_bonus: 2.6', /'field_radius_bonus'\s+=>\s+2\.6/],
        ['field_radius_min: 14', /'field_radius_min'\s+=>\s+14/],
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
        ["floor_color: '0x5e7a46'", /'floor_color'\s+=>\s+'0x5e7a46'/],
        ["background_color: '0xdfe2d1'", /'background_color'\s+=>\s+'0xdfe2d1'/],
    ];
    for (const [harnessKey, seederRe] of syncPairs) {
        ok(`sync: ${harnessKey}`, row.includes(harnessKey) && seederRe.test(seederSrc));
    }
    ok('harness mirrors the asset manifest', row.includes("assets_base: '/assets/venues/sculpture-garden/'") &&
        row.includes("tree_large: 'tree_large_01.glb'") && row.includes("bench: 'bench_01.glb'"));

    const shootSrc = readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8');
    for (const id of ['garden-05', 'garden-12-mixed', 'garden-30-mixed', 'garden-cam-arrival',
        'garden-cam-promenade', 'garden-cam-court', 'garden-cam-close', 'garden-cam-distance',
        'garden-cam-boundary', 'garden-cam-horizon', 'garden-tier-low-12']) {
        ok(`scenario pinned: ${id}`, shootSrc.includes(`'${id}'`));
    }
    ok('shoot waits for the deterministic asset gate before capture',
        shootSrc.includes('_gardenAssetsSettled') && shootSrc.includes('sculpture-garden'));
}

// ── C. Landscape invariants (real modules, real rng) ────────────────────────
section('C. Landscape invariants (real GardenLayout + GardenAssets modules)');
{
    const { buildGardenPlan, validateGardenPlan, samplePolyline, GARDEN_DEFAULTS } =
        await import(rel('resources/js/gallery/GardenLayout.js'));
    const { createVenueRng } = await import(rel('resources/js/gallery/Rng.js'));
    const assets = await import(rel('resources/js/gallery/GardenAssets.js'));

    // The garden's field sizing (mirrors RoomBuilder's declared application).
    
    const gardenRadius = (count) =>
        Math.max(14, Math.max(count * 3.5, 30) / (2 * Math.PI) + 2.6);

    // C1. Determinism — same seed, same plan (every array, not a sample).
    const a = buildGardenPlan({ radius: gardenRadius(30), count: 30, rng: createVenueRng('sculpture-garden:qa') });
    const b = buildGardenPlan({ radius: gardenRadius(30), count: 30, rng: createVenueRng('sculpture-garden:qa') });
    ok('deterministic plan (courts, trees, shrubs, boulders, benches, paths)',
        JSON.stringify(a.courts) === JSON.stringify(b.courts) &&
        JSON.stringify(a.trees) === JSON.stringify(b.trees) &&
        JSON.stringify(a.shrubs) === JSON.stringify(b.shrubs) &&
        JSON.stringify(a.boulders) === JSON.stringify(b.boulders) &&
        JSON.stringify(a.benches) === JSON.stringify(b.benches) &&
        JSON.stringify(a.paths.map(p => p.kind)) === JSON.stringify(b.paths.map(p => p.kind)));

    // C2. The validator passes across the full capacity range (1..30).
    let allValid = true;
    const perCount = {};
    for (const count of [1, 5, 8, 12, 18, 20, 25, 30]) {
        const plan = buildGardenPlan({ radius: gardenRadius(count), count, rng: createVenueRng(`sculpture-garden:cap-${count}`) });
        const v = validateGardenPlan(plan);
        perCount[count] = { courts: plan.courts.length, trees: plan.trees.length, ok: v.ok };
        if (!v.ok) { allValid = false; console.error(`      count=${count}:`, v.violations.slice(0, 3)); }
    }
    ok('validator passes for counts 1,5,8,12,18,20,25,30', allValid, JSON.stringify(perCount));
    const roles30 = buildGardenPlan({ radius: gardenRadius(30), count: 30, rng: createVenueRng('sculpture-garden:roles') })
        .courts.reduce((m, c) => ((m[c.role] = (m[c.role] || 0) + 1), m), {});
    ok('role mix at 30: 3 primary / 18 secondary / 9 transitional',
        roles30.primary === 3 && roles30.secondary === 18 && roles30.transitional === 9, JSON.stringify(roles30));

    // C2b. The vegetation carries ASSET ROLES (the v4 contract) and the
    // designed ensembles exist: a gate pair, a backdrop, a horizon treeline.
    {
        const plan = buildGardenPlan({ radius: gardenRadius(12), count: 12, rng: createVenueRng('sculpture-garden:roles') });
        const treeRoles = new Set(plan.trees.map(t => t.role));
        const rolesValid = ['tree_large', 'tree_medium', 'tree_accent'].some(r => treeRoles.has(r));
        ok('trees carry asset roles from the manifest vocabulary', rolesValid,
            JSON.stringify([...treeRoles]));
        ok('planting carries shrub/grass roles', plan.shrubs.every(s => s.role === 'shrub' || s.role === 'grass'));
        ok('horizon treeline exists beyond the playable bound',
            plan.trees.some(t => t.purpose === 'horizon' && Math.hypot(t.x, t.z) > plan.radius));
        ok('benches face composed targets (yaw present)', plan.benches.every(bn => Number.isFinite(bn.yaw)));
        ok('ensembles: gate + backdrop + screen groves seeded',
            ['gate', 'backdrop', 'screen'].every(p => plan.groves.some(g => g.purpose === p && g.trees.length)));
    }

    // C3. Terrain: finite everywhere; courts + spawn actually FLAT.
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
        let maxLift = 0;
        for (let ang = 0; ang < 12; ang++) {
            const t = (ang / 12) * Math.PI * 2;
            const h = plan.terrain.height(Math.sin(t) * plan.radius * 2.2, Math.cos(t) * plan.radius * 2.2);
            maxLift = Math.max(maxLift, h);
        }
        ok('distant landscape rolls beyond the bound (hill ≥ 0.3 m)', maxLift > 0.3, `max ${maxLift.toFixed(2)} m`);
    }

    // C4. Walks reach every court (reachability band).
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

    // C5. Panel-stand geometry: every part sits BEHIND the canvas plane
    // (z ≤ 0 in stand-local space — the v3 easel orientation contract,
    // carried by the v4 instanced museum stands).
    {
        const placerSrc = readFileSync(rel('resources/js/gallery/ArtworkPlacer.js'), 'utf8');
        const fnStart = placerSrc.indexOf('export function _addPanelStands');
        const fn = placerSrc.slice(fnStart, placerSrc.indexOf('\n// ──', fnStart) === -1 ? placerSrc.indexOf('export function', fnStart + 10) : placerSrc.indexOf('\n// ──', fnStart));
        ok('museum panel stands exist (the easel tripod is retired from the garden path)', fn.includes('InstancedMesh'));
        ok('stand posts + sled + plaque sit behind/at the canvas plane',
            /-0\.08/.test(fn) && /-0\.055/.test(fn) && !/pos: \[[^\]]*,\s*0\.[2-9]\]/.test(fn));
        ok('stand posts lean back like gallery hardware', /LEAN = -0\.055/.test(fn));
        ok('stands instanced (n artworks → 3 draw calls, not n meshes)',
            /new THREE\.InstancedMesh\(postGeo, steelMat, n \* 2\)/.test(fn));
        ok('garden placement falls back to the ring on plan/artwork mismatch',
            /plan\.courts\.length !== this\.artworkImages\.length/.test(placerSrc));
        ok('garden placement registers collision obstacles', /registerObstacle\(group, 0\.35\)/.test(placerSrc));
    }

    // C6. The garden body: primitive ban + gravel vocabulary + rig.
    {
        const decSrc = readFileSync(rel('resources/js/gallery/VenueDecorator.js'), 'utf8');
        const fnStart = decSrc.indexOf('function addSculptureGardenStructure');
        const fn = decSrc.slice(fnStart, decSrc.indexOf('\n// ── VOID', fnStart));
        ok('PRIMITIVE BAN: no cone/icosahedron vegetation may return',
            !fn.includes('ConeGeometry') && !fn.includes('IcosahedronGeometry'));
        ok('PRIMITIVE BAN: the box hedge ring stays dead', !fn.includes('hedgeParts'));
        ok('walks render as continuous gravel ribbons', fn.includes('buildGravelRibbon') && fn.includes('buildGravelDisc'));
        ok('soil underlay gives the walks a crisp edge', fn.includes('soilMat'));
        ok('hero court: low travertine drum (not the tall trophy plinth)',
            fn.includes('CylinderGeometry(0.98, 1.08, 0.55, 40)'));
        ok('vegetation comes from the asset layer (no inline tree builder)',
            fn.includes('buildGardenAssetInstances') && fn.includes('groupAnchorsByRole'));
        ok('missing assets skip gracefully (no placeholder geometry)',
            fn.includes('loadGardenAssets') && !fn.includes('PLACEHOLDER'));
        ok('sky environment is PMREM from the dome (no HDRI asset)', fn.includes('PMREMGenerator') && fn.includes('fromScene'));
        ok('fog is radius-aware (courts never fogged)', fn.includes('radius * 1.55'));
        ok('sky dome inside a 3.7R camera-far floor (the far-clip disc defect class stays dead)',
            fn.includes('radius * 2.9') && fn.includes('radius * 3.7') && fn.includes('updateProjectionMatrix'));
        ok('ground-follow tick registered', fn.includes('this._gardenTick'));
        ok('async asset gate exposed (QA harness polls it)', fn.includes('_gardenAssetsSettled'));
    }

    // C7. Asset resolution: root-relative normalization + manifest defaults
    // + explicit opt-out + graceful missing handling.
    {
        const { resolveGardenAssetRequests, GARDEN_ASSET_MANIFEST, groupAnchorsByRole } = assets;
        // Relative bases normalize to root-relative (the page-relative bug).
        const reqs = resolveGardenAssetRequests({ assets_base: 'assets/venues/sculpture-garden/', assets: {} });
        ok('relative assets_base normalizes root-relative',
            reqs.length === Object.keys(GARDEN_ASSET_MANIFEST).length &&
            reqs.every(r => r.url.startsWith('/assets/venues/sculpture-garden/')));
        // Explicit null opts a role out (no request, no 404 noise).
        const opted = resolveGardenAssetRequests({ assets: { bench: null } });
        ok('declared-null role opts out cleanly', !opted.some(r => r.role === 'bench'));
        // Unknown roles in config are ignored; undeclared roles take defaults.
        const defaulted = resolveGardenAssetRequests({ assets: { mystery: 'x.glb' } });
        ok('unknown config roles ignored, manifest defaults hold',
            defaulted.length === Object.keys(GARDEN_ASSET_MANIFEST).length &&
            defaulted.find(r => r.role === 'bench').url.endsWith('bench_01.glb'));
        // Grouping keys every anchor by role, dropping role-less entries.
        const grouped = groupAnchorsByRole([
            { role: 'shrub', x: 1, z: 2 }, { role: 'shrub', x: 3, z: 4 }, { x: 5, z: 6 },
        ]);
        ok('anchors group per role (role-less entries dropped)',
            grouped.shrub?.length === 2 && !grouped.bench);
    }

    // C8. samplePolyline sanity + design constants.
    {
        const pts = [[0, 0], [0, 10]];
        const s = samplePolyline(pts, 1);
        ok('polyline sampling covers endpoints at ~step spacing', s.length >= 10 &&
            Math.hypot(s[0][0], s[0][1]) < 1e-6 &&
            Math.hypot(s[s.length - 1][0] - 0, s[s.length - 1][1] - 10) < 0.5);
    }
    ok('defaults: walkClearance 1.55 / minCourtGap 3.4 / spawnClearance 3.2',
        GARDEN_DEFAULTS.walkClearance === 1.55 && GARDEN_DEFAULTS.minCourtGap === 3.4 && GARDEN_DEFAULTS.spawnClearance === 3.2);
}

// ── D. JS/PHP hygiene ───────────────────────────────────────────────────────
section('D. JS/PHP hygiene');
{
    const gardenSrc = readFileSync(rel('resources/js/gallery/GardenLayout.js'), 'utf8');
    ok('pure plan module: zero venue slugs', !/sculpture-garden|infinite-void|nebula-drift|mirror-lake|cyber-gallery/.test(gardenSrc));
    ok('pure plan module: no Math.random', !gardenSrc.includes('Math.random'));
    ok('pure plan module: no THREE/DOM imports', !gardenSrc.includes("from 'three'") && !gardenSrc.includes('document.'));

    const gaSrc = readFileSync(rel('resources/js/gallery/GardenAssets.js'), 'utf8');
    ok('asset module: zero venue slugs', !/sculpture-garden(?![\w/-]*glb)|infinite-void|nebula-drift/.test(gaSrc.replace('assets/venues/sculpture-garden/', '')));
    ok('asset module: no Math.random', !gaSrc.includes('Math.random'));
    ok('asset module: per-role fault isolation (allSettled-equivalent try/catch)',
        gaSrc.includes('catch (err)') && gaSrc.includes('missing.push'));

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

    ok('guarded v3 migration exists (identity chain)', existsSync(rel('database/migrations/2026_09_09_000011_sculpture_garden_curated_walk.php')));
    ok('guarded v4 migration exists (asset manifest chain)', existsSync(rel('database/migrations/2026_09_09_000012_sculpture_garden_asset_park.php')));

    // The v2 head-in-the-knot defect stays dead: the spawn is derived from
    // the plan (gate plaza), never (0, 0).
    ok('spawn comes from the plan, never the centre', /camera\.position\.set\(spawn\.x, CONFIG\.camera\.height, spawn\.z\)/.test(roomSrc));
}

// ── E. Iteration-5 contracts (asset completeness + artwork rear presentation)
section('E. Iteration-5: asset completeness + artwork rear presentation');
{
    // E1. Every manifest role must resolve to a file that SHIPS. The v4
    // manifest declared `bench` without a file — correct graceful-skip
    // behaviour, but it produced a recurring production 404. The manifest↔
    // files parity is now a gate: a role is either shipped or explicitly
    // nulled in the manifest.
    const assets = await import(rel('resources/js/gallery/GardenAssets.js'));
    const requests = assets.resolveGardenAssetRequests({});
    let allShipped = true;
    for (const req of requests) {
        const fp = rel('public/' + req.url.replace(/^\//, ''));
        if (!existsSync(fp)) { allShipped = false; console.error(`    missing: ${req.url}`); }
    }
    ok('every manifest role ships its GLB (no load-time 404s)', allShipped);
    ok('bench role ships (bench_01.glb)', existsSync(rel('public/assets/venues/sculpture-garden/bench_01.glb')));

    // E2. Artwork REAR PRESENTATION (the global empty-frame fix): every
    // artwork group carries a backing board — rotated π (faces the rear),
    // 6 mm behind the canvas, covering the frame opening, and ONE shared
    // material instance across all artworks. The canvas itself stays
    // FrontSide (front presentation + progressive swap + Cyber reactive
    // untouched).
    const { makeArtworkGroup } = await import(rel('resources/js/gallery/ArtworkPlacer.js'));
    const { createFrame } = await import(rel('resources/js/gallery/Materials.js'));
    const ctx = { isLowEnd: false, textures: {}, _reactive: false, artworks: [], createFrame };
    const g1 = makeArtworkGroup.call(ctx, { id: 'qa-1', aspectRatio: 1.5, title: 'QA 1' }, { frame_style: 'modern' }).group;
    const g2 = makeArtworkGroup.call(ctx, { id: 'qa-2', aspectRatio: 0.8, title: 'QA 2' }, { frame_style: 'gold' }).group;
    const backing1 = g1.children.find(c => c.name === 'artwork-backing');
    const backing2 = g2.children.find(c => c.name === 'artwork-backing');
    ok('artwork group contains a rear backing board', !!backing1 && !!backing2);
    if (backing1) {
        const canvas1 = g1.children.find(c => c.name === 'artwork-canvas');
        ok('backing faces the REAR (rotation.y = π)', Math.abs(Math.abs(backing1.rotation.y) - Math.PI) < 1e-6);
        ok('backing sits just behind the canvas plane (z = −6 mm)', Math.abs(backing1.position.z + 0.006) < 1e-6);
        ok('backing fills the frame opening (≥ canvas area)',
            backing1.geometry.parameters.width >= canvas1.geometry.parameters.width &&
            backing1.geometry.parameters.height >= canvas1.geometry.parameters.height);
        ok('canvas stays FrontSide (no mirrored backside)',
            canvas1.material.side === 0 /* THREE.FrontSide */);
        ok('backing material shared across artworks (one instance)',
            backing1.material === backing2.material && ctx._artworkBackingMat === backing1.material);
        ok('backing casts no shadow', backing1.castShadow === false);
    }

    // E3. The /track transport fix: every event (incl. dwell + perf) travels
    // via fetch with the CSRF header; sendBeacon (which cannot carry
    // headers → guaranteed 419) is gone.
    const analyticsSrc = readFileSync(rel('resources/js/gallery/Analytics.js'), 'utf8');
    ok('analytics: no sendBeacon transport left', !analyticsSrc.includes('sendBeacon('));
    ok('analytics: keepalive unload-safe transport present', analyticsSrc.includes('keepalive: true'));
    ok('analytics: CSRF header on every send', analyticsSrc.includes("'X-CSRF-TOKEN'"));
    ok('analytics: dead-session backoff exists', analyticsSrc.includes('_csrfDead'));

    // E4. CSP: the narrow blob: allowance for the three.js ImageBitmapLoader
    // blob fetch path (GLB-embedded textures) is declared in the middleware.
    const securitySrc = readFileSync(rel('app/Http/Middleware/SecurityHeaders.php'), 'utf8');
    ok('CSP: connect-src allows blob: (GLB-embedded texture decode)',
        /"connect-src[^"]*blob:/.test(securitySrc));
}

console.log(failures === 0
    ? '\nALL CHECKS PASSED — the Sculpture Park contract holds.'
    : `\n${failures} CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
