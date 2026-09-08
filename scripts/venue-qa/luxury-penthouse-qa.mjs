#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// luxury-penthouse-qa.mjs — the venue QA gate for Luxury Penthouse v2.0.0
// ("The Collector's Floor", 2026-09-08 identity pass).
//
//   node scripts/venue-qa/luxury-penthouse-qa.mjs
//
// Same layering as the other venue gates: this file pins CONTRACTS
// (config authority, geometry invariants, parity, determinism),
// tests/Feature/VenuePenthouseIterationTest.php pins the DB side, and
// scripts/harness/shoot.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the luxury-penthouse row declares the v2 identity:
//      the 5.2 m warm volume, the declared-absent environment ('none' +
//      env_intensity 0 — no rural_evening 404, no wrong sky), the warm rig
//      + exposure 0.78, texture_tint material authority + the honed-stone
//      floor, artwork legibility floor (base 0.22 + pool cap 12), declared
//      post-fx with the BLACK vignette blend (the stock grey veil class),
//      fog depth for the skyline, and the l-shape supported layout.
//   B. DB ↔ harness sync — the PHP-less harness renders the same JSON a
//      fresh install seeds (same pairs, equal values, 40 descriptors).
//   C. Composition invariants — driven through the REAL StructureBuilder +
//      real three.js (no GL):
//        • the v2 descriptor list validates; the legacy list still
//          validates (rollback body renders).
//        • the NEW l-shape wall anchors resolve against the real layout
//          meta at 12 and 40 works (spot values vs RoomBuilder math), and
//          square/corridor anchors are UNCHANGED (cross-venue safety).
//        • the build emits a sane draw-call count (≤ 48), the cove sits at
//          the ceiling reveal height with the declared emissive, the base
//          trim stands PROUD of the wall face (the buried-trim lesson),
//          the fireplace fills the end wall with the warm fire line, the
//          skyline is three grounded depth layers (near dim + far ghosts +
//          horizon glow beyond the mid layer), the stone-slab joints tile
//          the wing-A floor at 2.4 m, the lounge cluster is complete
//          (sofa/chair/lamp/plinth/sculpture), the glazing glass is a
//          tier-resolved transparent (opacity ≤ 0.25).
//        • collision: every obstacle-registered structure part lies inside
//          the walk domain (furniture never traps the visitor).
//        • low-end tier: the SAME composition in Lambert (degradation
//          parity — the residence survives the weakest device).
//        • determinism: two builds from the same seed are
//          transform-identical (the P0.3 contract).
//   D. JS/PHP hygiene — zero venue slugs in the shared modules, the
//      exporter owns every key the row uses (no schema bump needed), the
//      guarded migration exists and pins the same v2 identity, the harness
//      carries the legacy rollback body, shoot.mjs carries the scenarios.
// ─────────────────────────────────────────────────────────────────────────────
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const rel = (p) => path.join(root, p);

let failures = 0;
const ok = (name, cond, detail = '') => {
    if (cond) console.log(`  ✓ ${name}`);
    else { failures++; console.error(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`); }
};
const section = (name) => console.log(`\n── ${name} ${'─'.repeat(Math.max(1, 62 - name.length))}`);

// ── A. Seeder contract ──────────────────────────────────────────────────────
section('A. Seeder contract (luxury-penthouse row)');
const seederSrc = readFileSync(rel('database/seeders/VenueTemplateSeeder.php'), 'utf8');

function rowChunk() {
    const i = seederSrc.indexOf("'slug'          => 'luxury-penthouse'");
    if (i === -1) throw new Error('luxury-penthouse row not found in seeder');
    const nextSlug = seederSrc.indexOf("'slug'", i + 10);
    return seederSrc.slice(i, nextSlug === -1 ? undefined : nextSlug);
}
function arrayLiteral(chunk, key) {
    const k = chunk.indexOf(`'${key}'`);
    if (k === -1) return '';
    const open = chunk.indexOf('[', k);
    let depth = 0;
    for (let i = open; i < chunk.length; i++) {
        if (chunk[i] === '[') depth++;
        else if (chunk[i] === ']') { depth--; if (depth === 0) return chunk.slice(open, i + 1); }
    }
    return '';
}

const chunk = rowChunk();
const vc = arrayLiteral(chunk, 'visual_config');
const mc = arrayLiteral(chunk, 'material_config');
const structure = arrayLiteral(vc, 'structure');
const postFx = arrayLiteral(vc, 'post_fx');

ok('structure_pass is the rooms interpreter selector', /'structure_pass'\s*=>\s*'rooms'/.test(vc));
ok('glazing wall declared', /'glazing_wall'\s*=>\s*true/.test(vc));
ok('GRAND VOLUME: wall height 5.2 (was the 4.5 corridor)',
    /'wall_height'\s*=>\s*5\.2/.test(vc) && /'ceiling_height'\s*=>\s*5\.2/.test(vc));
ok('warm dark ceiling (0x14110d, not grave-black 0x080808)',
    /'ceiling_color'\s*=>\s*'0x14110d'/.test(vc) && !/'0x080808'/.test(vc));
ok('environment is DECLARED ABSENCE (none) — no rural_evening 404, no wrong sky',
    /'environment'\s*=>\s*'none'/.test(vc));
ok('env_intensity declared 0 (a declared 0 stays 0 — nullish authority)',
    /'env_intensity'\s*=>\s*0,/.test(vc));
ok('warm evening rig (ambient 0xe6d6bc @ 0.26, hemisphere 0.14)',
    /'ambient_color'\s*=>\s*'0xe6d6bc'/.test(vc) && /'ambient_intensity'\s*=>\s*0\.26/.test(vc) && /'hemisphere_intensity'\s*=>\s*0\.14/.test(vc));
ok('exposure lifted out of the murk (0.78, was 0.55)',
    /'tone_mapping_exposure'\s*=>\s*0\.78/.test(vc));
ok('fog carries the skyline depth (near 16, far 55 — was 8/25)',
    /'fog_near'\s*=>\s*16/.test(vc) && /'fog_far'\s*=>\s*55/.test(vc));
ok('artwork standing glow declared (0.22) + pool raised (12)',
    /'artwork_light_base'\s*=>\s*0\.22/.test(vc) && /'artwork_light_pool_cap'\s*=>\s*12/.test(vc));
ok('post_fx declares BLACK vignette (the stock grey veil can never ship)',
    /'vignette_blend'\s*=>\s*'black'/.test(postFx));
ok('post_fx bloom restrained (0.32 @ 0.85)',
    /'bloom_strength'\s*=>\s*0\.32/.test(postFx) && /'bloom_threshold'\s*=>\s*0\.85/.test(postFx));
ok('texture_tint declared (declared colours are authoritative)',
    /'texture_tint'\s*=>\s*true/.test(mc));
ok('wall tint declared (warm mineral white 0xe9e2d4)', /'wall_color'\s*=>\s*'0xe9e2d4'/.test(mc));
ok('floor tint declared (honed warm stone 0x9b8d78, not plastic marble)',
    /'floor_color'\s*=>\s*'0x9b8d78'/.test(mc) && /'floor_roughness'\s*=>\s*0\.42/.test(mc));
ok('floor reads at slab scale (floor_tile_meters 2.4)', /'floor_tile_meters'\s*=>\s*2\.4/.test(mc));
ok('descriptor payload present (40 entries)', (structure.match(/\['id' =>/g) || []).length === 40,
    `got ${(structure.match(/\['id' =>/g) || []).length}`);
ok('fireplace volume on the wing-A end wall (wall_front anchor)',
    /'id' => 'fireplace-stone'/.test(structure) && /'from' => 'wall_front'/.test(structure));
ok('perimeter cove declared (4 merged emissive strips)', (structure.match(/'id' => 'cove-/g) || []).length === 4);
ok('bronze base trim declared (4 merged strips)', (structure.match(/'id' => 'base-/g) || []).length === 4);
ok('skyline is three depth layers + horizon glow (far ghosts at 0.10 emissive)',
    /'id' => 'skyline-cool'/.test(structure) && /'id' => 'skyline-warm'/.test(structure) && /'id' => 'skyline-far'/.test(structure) && /'id' => 'horizon-glow'/.test(structure));
ok('legacy uniform-glow tower presets are GONE from the row (explicit dim materials)',
    !/'material' => 'tower_cool'/.test(structure) && !/'material' => 'tower_warm'/.test(structure));
ok('stone slab joints declared (procedural grout grid, 2.4 m)',
    /'id' => 'floor-joints'/.test(structure) && /'spacing' => \[0, 0, 2\.4\]/.test(structure));
ok('lounge curated: chair + lamp + plinth + sculpture + bench',
    /'id' => 'chair-seat'/.test(structure) && /'id' => 'lamp-shade'/.test(structure) && /'id' => 'plinth'/.test(structure) && /'id' => 'sculpture-torus'/.test(structure) && /'id' => 'bench-top'/.test(structure));
ok('terrace deck is warm wood (was dark_trim)', /'id' => 'terrace-deck'[^]*?'material' => 'wood_warm'/.test(structure));
ok('description names what renders (honesty matrix: walnut-and-stone wing, lounge at the glass)',
    /A private collector\\?'s floor at dusk/.test(chunk) && /walnut-and-stone/.test(chunk));
ok('version 2.0.0', /'version'\s*=>\s*'2\.0\.0'/.test(chunk));
ok('l-shape remains the supported hang (supported_layouts square + l-shape)',
    /'supported_layouts'\s*=>\s*\['square',\s*'l-shape'\]/.test(chunk));

// ── B. DB ↔ harness sync ────────────────────────────────────────────────────
section('B. DB ↔ harness sync (luxury-penthouse bodies)');
const harnessSrc = readFileSync(rel('scripts/harness/harness.html'), 'utf8');

// Extract the real VENUES object the harness renders (brace-matched).
function extractVenues() {
    const i = harnessSrc.indexOf('const VENUES = {');
    const open = harnessSrc.indexOf('{', i);
    let depth = 0;
    for (let k = open; k < harnessSrc.length; k++) {
        if (harnessSrc[k] === '{') depth++;
        else if (harnessSrc[k] === '}') { depth--; if (depth === 0) return new Function(`return (${harnessSrc.slice(open, k + 1)})`)(); }
    }
    throw new Error('VENUES object not brace-balanced');
}
const VENUES = extractVenues();
ok('harness carries the luxury-penthouse body', !!VENUES['luxury-penthouse']);
const hv = VENUES['luxury-penthouse'];
const hvc = hv?.visual_config ?? {};
const hmc = hv?.material_config ?? {};
const pairs = [
    ['wall_height 5.2', hvc.wall_height === 5.2 && /'wall_height'\s*=>\s*5\.2/.test(vc)],
    ['ceiling 0x14110d', hvc.ceiling_color === '0x14110d'],
    ['background 0x0a0b11 / fog 0x0a0b10', hvc.background_color === '0x0a0b11' && hvc.fog_color === '0x0a0b10'],
    ['fog 16/55', hvc.fog_near === 16 && hvc.fog_far === 55],
    ['ambient 0xe6d6bc @ 0.26', hvc.ambient_color === '0xe6d6bc' && hvc.ambient_intensity === 0.26],
    ['hemisphere 0.14', hvc.hemisphere_intensity === 0.14],
    ['spot 0.62 / fill 0.16', hvc.spot_intensity === 0.62 && hvc.fill_intensity === 0.16],
    ['exposure 0.78', hvc.tone_mapping_exposure === 0.78],
    ['environment none / env 0', hvc.environment === 'none' && hvc.env_intensity === 0],
    ['artwork light 0.22 / cap 12', hvc.artwork_light_base === 0.22 && hvc.artwork_light_pool_cap === 12],
    ['post_fx black vignette', hvc.post_fx?.vignette_blend === 'black' && hvc.post_fx?.bloom_strength === 0.32],
    ['glazing_wall + rooms pass', hvc.glazing_wall === true && hvc.structure_pass === 'rooms'],
    ['40 descriptors', Array.isArray(hvc.structure) && hvc.structure.length === 40],
    ['material tint authority', hmc.texture_tint === true && hmc.wall_color === '0xe9e2d4' && hmc.floor_color === '0x9b8d78'],
    ['floor_tile_meters 2.4', hmc.floor_tile_meters === 2.4],
    ['anchored fireplace fixtures (fire 16 + wash 3.5, both wall_front)', hv.lighting_fixtures?.length === 2 && hv.lighting_fixtures.every(f => f.anchor?.from === 'wall_front') && hv.lighting_fixtures[0].intensity === 16 && /'fire-glow'/.test(chunk) && /'anchor' => \['from' => 'wall_front'/.test(chunk)],
    ['l-shape default', hv.default_settings?.room_layout === 'l-shape'],
    ['version 2.0.0', hv.version === '2.0.0'],
];
for (const [name, cond] of pairs) ok(`harness ↔ seeder: ${name}`, cond);
ok('harness legacy rollback body present (v1.0.0 Rooms forensic variant)',
    !!VENUES['luxury-penthouse-legacy'] &&
    VENUES['luxury-penthouse-legacy'].version === '1.0.0' &&
    Array.isArray(VENUES['luxury-penthouse-legacy'].visual_config.structure) &&
    VENUES['luxury-penthouse-legacy'].visual_config.structure.length === 17);

// ── C. Composition invariants (real builder, real three, no GL) ─────────────
section('C. Composition invariants (real StructureBuilder, real three, no GL)');
const StructureBuilder = await import(pathToFileURL(rel('resources/js/gallery/StructureBuilder.js')));
const { validateStructure, resolveAnchor, buildStructure, SB_MATERIALS } = StructureBuilder;
const THREE = (await import('three')).default ?? (await import('three'));
// Mirror the runtime path: VenueDecorator applies the venue's declared
// wall_height to CONFIG BEFORE the room builds — the anchor heights and the
// at:'ceiling' cove derive from it.
const { CONFIG } = await import(pathToFileURL(rel('resources/js/gallery/config.js')));
CONFIG.room.wallHeight = 5.2;

ok('validateStructure(v2 payload) — valid', validateStructure(hvc.structure).valid,
    JSON.stringify(validateStructure(hvc.structure).errors.slice(0, 4)));
ok('validateStructure(legacy payload) — the rollback body still validates',
    validateStructure(VENUES['luxury-penthouse-legacy'].visual_config.structure).valid);
ok('additive material presets present (walnut, basalt)', !!SB_MATERIALS.walnut && !!SB_MATERIALS.basalt);
ok('existing material presets untouched (fabric_warm / bronze / steel_dark exact)',
    SB_MATERIALS.fabric_warm.color === '0x6a5a48' && SB_MATERIALS.bronze.roughness === 0.35 &&
    SB_MATERIALS.steel_dark.metalness === 0.85 && SB_MATERIALS.wood_dark.color === '0x4a3826');

// Layout meta — the EXACT createRoomLShape math (RoomBuilder L310-334).
function layoutMetaFor(count) {
    const spacing = 3.5, wingW = 6;
    const estCountA = Math.ceil(count * 0.6);
    const lenA = Math.max(12, (Math.ceil(estCountA / 2) * spacing) + spacing);
    const jZ = lenA / 2 - wingW;
    const zStart = -lenA / 2 + spacing, zLimit = jZ - spacing / 2;
    let spillFrom = count, sideA = 0, rowA = 0;
    for (let i = 0; i < count; i++) {
        if (zStart + rowA * spacing >= zLimit) { spillFrom = i; break; }
        sideA = 1 - sideA;
        if (sideA === 0) rowA++;
    }
    const actualCountB = count - spillFrom;
    const lenB = Math.max(12, (Math.ceil(actualCountB / 2) * spacing) + spacing * 2);
    return { type: 'l-shape', wingW, lenA, lenB, jZ, zStart, zLimit,
        aCX: wingW / 2, aCZ: 0, bCX: wingW + lenB / 2, bCZ: lenA / 2 - wingW / 2 };
}

// The new anchors must resolve against the REAL meta at both capacity ends.
for (const count of [12, 40]) {
    const meta = layoutMetaFor(count);
    const d = 0.3;
    const ctx = { _layoutMeta: meta, _glazing: { cx: meta.wingW + meta.lenB - d / 2, cz: meta.bCZ, inward: [-1, 0], width: meta.wingW, height: 5.2, wallId: 'wing_b_end' } };
    const wl = resolveAnchor(ctx, 'wall_left');
    const wf = resolveAnchor(ctx, 'wall_front');
    const wr = resolveAnchor(ctx, 'wall_right');
    const wi = resolveAnchor(ctx, 'wall_inner');
    const wb = resolveAnchor(ctx, 'wall_back');
    ok(`count=${count}: wall_left resolves at the west inner face (+x, span lenA)`,
        wl && wl.fwd[0] === 1 && Math.abs(wl.pos[0] - d / 2) < 1e-9 && Math.abs(wl.width - meta.lenA) < 1e-9);
    ok(`count=${count}: wall_front resolves at the south inner face (+z, span wingW)`,
        wf && wf.fwd[2] === 1 && Math.abs(wf.pos[2] - (-meta.lenA / 2 + d / 2)) < 1e-9 && Math.abs(wf.width - meta.wingW) < 1e-9);
    ok(`count=${count}: wall_right resolves on the upper east segment (−x, span jZ+lenA/2)`,
        wr && wr.fwd[0] === -1 && Math.abs(wr.width - (meta.jZ + meta.lenA / 2)) < 1e-9);
    ok(`count=${count}: wall_inner resolves at the wing-B north face (+z, span lenB)`,
        wi && wi.fwd[2] === 1 && Math.abs(wi.pos[2] - (meta.jZ + d / 2)) < 1e-9 && Math.abs(wi.width - meta.lenB) < 1e-9);
    ok(`count=${count}: wall_back spans the full colinear south run`,
        wb && wb.fwd[2] === -1 && Math.abs(wb.width - (meta.wingW + meta.lenB)) < 1e-9);
    ok(`count=${count}: glazing anchor unchanged (wing-B end, inward −x)`,
        ctx._glazing && resolveAnchor(ctx, 'glazing').fwd[0] === -1);
}

// CROSS-VENUE SAFETY: square + corridor anchors are UNTOUCHED by the edit.
{
    const sq = { _layoutMeta: { type: 'square', wallLength: 14 } };
    const co = { _layoutMeta: { type: 'corridor', length: 20, width: 8 } };
    const sqOk = ['wall_front', 'wall_back', 'wall_left', 'wall_right',
        'wall_front_outside', 'wall_back_outside', 'wall_left_outside', 'wall_right_outside']
        .every(a => { const r = resolveAnchor(sq, a); return r && Number.isFinite(r.pos[0]) && Number.isFinite(r.width); });
    const coOk = ['wall_front', 'wall_back', 'wall_left', 'wall_right']
        .every(a => { const r = resolveAnchor(co, a); return r && Number.isFinite(r.pos[0]) && Number.isFinite(r.width); });
    ok('square wall anchors unchanged (all 8 resolve)', sqOk);
    ok('corridor wall anchors unchanged (all 4 resolve)', coOk);
    ok('rotunda/circular still return null for wall anchors (only center/glazing)',
        resolveAnchor({ _layoutMeta: { type: 'rotunda' } }, 'wall_left') === null);
}

// The real build.
const meta = layoutMetaFor(12);
const ctxBase = () => {
    const scene = new THREE.Scene();
    const obstacles = [];
    return {
        scene, obstacles,
        _layoutMeta: meta,
        _glazing: { cx: meta.wingW + meta.lenB - 0.15, cz: meta.bCZ, inward: [-1, 0], width: meta.wingW, height: 5.2, wallId: 'wing_b_end' },
        isLowEnd: false, _isMobileTier: false, _venueSlug: 'luxury-penthouse',
        _particleSystems: [], _hangableSurfaces: [],
        registerObstacle: (m, p) => obstacles.push(m),
    };
};
const WALL_H = 5.2;

let draws = -1;
const sceneA = new THREE.Scene();
try {
    const ctx = ctxBase(); ctx.scene = sceneA;
    draws = buildStructure(ctx, hvc.structure);
    ok(`build emits a sane draw-call count (${draws} ≤ 48)`, draws > 10 && draws <= 48, `got ${draws}`);

    // ── Cove: at the ceiling reveal, declared emissive, merged.
    const cove = [];
    sceneA.traverse(o => { if (o.isMesh && /merged:ph-cove/.test(o.name || '')) cove.push(o); });
    ok('cove merged into ONE draw call', cove.length === 1, `got ${cove.length}`);
    if (cove.length === 1) {
        const box = new THREE.Box3().setFromObject(cove[0]);
        ok(`cove sits at the ceiling reveal (y ≈ ${WALL_H - 0.18} ± 0.02)`,
            Math.abs((box.min.y + box.max.y) / 2 - (WALL_H - 0.18)) < 0.02);
        ok('cove emissive is the declared warm reveal (0xffd9a0 @ 1.35)',
            cove[0].material.emissive?.getHexString() === 'ffd9a0' &&
            cove[0].material.emissiveIntensity === 1.35);
    }

    // ── Base trim: PROUD of the wall face (the buried-trim lesson).
    const base = [];
    sceneA.traverse(o => { if (o.isMesh && /merged:ph-base/.test(o.name || '')) base.push(o); });
    ok('base trim merged into ONE draw call', base.length === 1, `got ${base.length}`);
    if (base.length === 1) {
        const box = new THREE.Box3().setFromObject(base[0]);
        ok('base trim stands proud of the west wall face (min x ≥ 0.15)',
            box.min.x >= 0.15 - 1e-6, `min x ${box.min.x.toFixed(3)}`);
        ok(`base trim is a 0.14 m bronze line (top ≈ 0.14)`, Math.abs(box.max.y - 0.14) < 0.01);
        ok('base trim material is bronze (metalness 0.9)', base[0].material.metalness === 0.9);
    }

    // ── Fireplace: full-height basalt on the end wall + warm fire line.
    const stone = sceneA.children.find(o => /structure:fireplace-stone/.test(o.name || ''));
    const band = sceneA.children.find(o => /structure:fireplace-band/.test(o.name || ''));
    const mantel = sceneA.children.find(o => /structure:fireplace-mantel/.test(o.name || ''));
    ok('fireplace stone exists on the end wall', !!stone);
    if (stone) {
        const box = new THREE.Box3().setFromObject(stone);
        ok('fireplace stone spans the full wall height (0..5.2)',
            box.min.y < 0.01 && Math.abs(box.max.y - WALL_H) < 0.01);
        ok('fireplace stone hugs the south wall face (inside the room, proud of it)',
            box.min.z > -meta.lenA / 2 && box.min.z < -meta.lenA / 2 + 0.25);
        ok('fireplace stone is honed basalt (roughness 0.35)', stone.material.roughness === 0.35);
    }
    ok('warm fire line + walnut mantel exist', !!band && !!mantel);
    if (band) ok('fire line is the declared ember (0xff8a3d @ 1.5)',
        band.material.emissive?.getHexString() === 'ff8a3d' && band.material.emissiveIntensity === 1.5);
    if (mantel) ok('mantel is walnut (0x4a3421)', mantel.material.color?.getHexString() === '4a3421');

    // ── Skyline: three grounded depth layers + horizon glow.
    const towers = { near: [], warm: [], far: [] };
    sceneA.traverse(o => {
        if (!o.isMesh || !/structure:skyline-/.test(o.name || '')) return;
        if (/skyline-cool/.test(o.name)) towers.near.push(o);
        if (/skyline-warm/.test(o.name)) towers.warm.push(o);
        if (/skyline-far/.test(o.name)) towers.far.push(o);
    });
    const glow = sceneA.children.find(o => /structure:horizon-glow/.test(o.name || ''));
    ok('three skyline layers render (near/warm/far merged meshes)',
        towers.near.length === 1 && towers.warm.length === 1 && towers.far.length === 1);
    const layerBox = (m) => m && new THREE.Box3().setFromObject(m);
    const bn = layerBox(towers.near[0]), bw = layerBox(towers.warm[0]), bf = layerBox(towers.far[0]);
    ok('skyline is grounded (no floating towers)', [bn, bw, bf].every(b => b && b.min.y > -0.11 && b.min.y < 0.2));
    ok('depth layering: far layer extends beyond the near layer',
        bf && bn && bf.max.x > bn.max.x && bf.min.z <= bn.min.z);
    ok('near/warm towers DIMMED to silhouette-plus-glow (emissive ≤ 0.28, was 0.55)',
        towers.near[0]?.material.emissiveIntensity <= 0.28 && towers.warm[0]?.material.emissiveIntensity <= 0.28);
    ok('far towers are ghosts (emissive 0.10)', towers.far[0]?.material.emissiveIntensity === 0.10);
    ok('horizon glow is a distant warm band beyond the near layer',
        !!glow && glow.position.x > meta.wingW + meta.lenB + 20 && glow.material.emissive?.getHexString() === '8a6a40');

    // ── THE SCATTER-AREA BUG (found by the walk-through render): towers
    // must NEVER spawn inside the building — every skyline box starts
    // beyond the glazing plane.
    for (const [label, mesh] of [['cool', towers.near[0]], ['warm', towers.warm[0]], ['far', towers.far[0]]]) {
        if (!mesh) continue;
        const b = new THREE.Box3().setFromObject(mesh);
        ok(`skyline ${label}: every tower stands beyond the glazing plane (min x ≥ ${meta.wingW + meta.lenB})`,
            b.min.x >= meta.wingW + meta.lenB - 0.01, `min x ${b.min.x.toFixed(2)}`);
    }

    // ── The anchored fire-glow fixture resolves against the wall_front
    // anchor (the shared anchored-fixtures grammar — same math the
    // descriptors speak) and lands inside the room near the stone.
    {
        const d = 0.3;
        const anchor = resolveAnchor({ _layoutMeta: meta, _glazing: null }, 'wall_front');
        const o = [0, 0.95, 1.1];
        const fx = anchor.fwd[0], fz = anchor.fwd[2];
        const pos = [anchor.pos[0] + fz * o[0] + fx * o[2], o[1], anchor.pos[2] - fx * o[0] + fz * o[2]];
        ok('fire-glow resolves inside the room, near the fireplace face',
            pos[2] > -meta.lenA / 2 && pos[2] < -meta.lenA / 2 + 3 && pos[0] > 0.5 && pos[0] < meta.wingW - 0.5,
            `pos ${pos.map(v => v.toFixed(2))}`);
    }

    // ── Stone slab joints: procedural grout on the wing-A floor.
    const joints = sceneA.children.find(o => /structure:floor-joints/.test(o.name || ''));
    ok('floor slab joints render (merged grout grid)', !!joints);
    if (joints) {
        const box = new THREE.Box3().setFromObject(joints);
        ok('joints lie flat on the stone (y ≈ 0 ± 0.01)', Math.abs(box.max.y) < 0.02);
        ok('joints tile wing A (span ≈ wingW, length ≈ lenA)',
            Math.abs(box.max.x - box.min.x - (meta.wingW - 0.6)) < 0.8 &&
            Math.abs(box.max.z - box.min.z - (meta.lenA - 1.8)) < 1.2,
            `span x ${(box.max.x - box.min.x).toFixed(1)} z ${(box.max.z - box.min.z).toFixed(1)} vs wingW ${meta.wingW} lenA ${meta.lenA}`);
    }

    // ── Lounge cluster complete + sane.
    for (const id of ['lounge-pendant', 'lounge-rug', 'sofa-base', 'chair-seat', 'chair-back', 'lamp-shade', 'plinth', 'sculpture-torus', 'bench-top']) {
        const m = sceneA.children.find(o => o.name === `structure:${id}`);
        ok(`lounge/wing piece present: ${id}`, !!m);
    }
    const plinth = sceneA.children.find(o => o.name === 'structure:plinth');
    const sculp = sceneA.children.find(o => o.name === 'structure:sculpture-torus');
    if (plinth && sculp) {
        const pb = new THREE.Box3().setFromObject(plinth);
        ok('sculpture sits ON the plinth (base above plinth top)',
            sculp.position.y > pb.max.y - 0.2);
    }
    const sofa = sceneA.children.find(o => o.name === 'structure:sofa-base');
    const rug = sceneA.children.find(o => o.name === 'structure:lounge-rug');
    ok('sofa faces the glass from the rug (lounge composition intact)',
        sofa && rug && sofa.position.z === rug.position.z);

    // ── Glass: tier-resolved (transmission on desktop, cheap opacity
    // below) — accept EITHER restrained form.
    const glass = sceneA.children.find(o => o.name === 'structure:glazing-glass');
    const glassRestrained = glass && (
        (glass.material.transparent && glass.material.opacity <= 0.25) ||
        (glass.material.transmission !== undefined && glass.material.transmission >= 0.5));
    ok('glazing glass renders as restrained tier-resolved glass', glassRestrained,
        glass ? `type ${glass.material.type}` : 'missing');

    // ── Collision: every registered obstacle lies inside the walk domain.
    const ctxObst = ctxBase(); ctxObst.scene = new THREE.Scene();
    const drawsObst = buildStructure(ctxObst, hvc.structure);
    const margin = 0.55;
    const inside = ctxObst.obstacles.every(o => {
        const b = new THREE.Box3().setFromObject(o);
        return b.min.z > -meta.lenA / 2 - 0.01 && b.max.z < meta.lenA / 2 + 0.01 &&
               b.min.x > -0.01 && b.max.x < meta.wingW + meta.lenB + 0.01 &&
               b.min.y > -0.01 && b.max.y < WALL_H + 0.01;
    });
    ok(`every collide-registered structure part sits inside the walk domain (${ctxObst.obstacles.length} parts)`,
        inside && ctxObst.obstacles.length >= 6);
} catch (err) {
    ok('composition build probe', false, err.message);
}

// ── Low-end tier: same composition in Lambert.
try {
    const sceneL = new THREE.Scene();
    const ctx = ctxBase(); ctx.scene = sceneL; ctx.isLowEnd = true;
    const drawsL = buildStructure(ctx, hvc.structure);
    let lambert = 0, standard = 0, meshes = 0;
    sceneL.traverse(o => {
        if (!o.isMesh) return;
        meshes++;
        if (o.material?.isMeshLambertMaterial) lambert++;
        else if (o.material?.isMeshStandardMaterial) standard++;
    });
    ok(`low-end builds (${drawsL} draws) and goes FULL Lambert (identity survives)`,
        meshes > 10 && lambert > 0 && standard === 0,
        `meshes ${meshes}, lambert ${lambert}, standard ${standard}`);
} catch (err) {
    ok('low-end tier probe', false, err.message);
}

// ── Determinism: two builds from the same seed are transform-identical.
try {
    const snapshot = (scene) => {
        const parts = [];
        scene.updateMatrixWorld(true);
        scene.traverse(o => {
            if (!o.isMesh) return;
            const p = [o.position.x, o.position.y, o.position.z,
                o.rotation.x, o.rotation.y, o.rotation.z,
                o.geometry?.parameters?.width ?? 0, o.geometry?.parameters?.height ?? 0].map(v => +Number(v).toFixed(6));
            parts.push(p.join(','));
        });
        return parts.sort().join('|');
    };
    const s1 = new THREE.Scene(); const c1 = ctxBase(); c1.scene = s1;
    const s2 = new THREE.Scene(); const c2 = ctxBase(); c2.scene = s2;
    buildStructure(c1, hvc.structure);
    buildStructure(c2, hvc.structure);
    ok('determinism: two builds are transform-identical', snapshot(s1) === snapshot(s2));
} catch (err) {
    ok('determinism probe', false, err.message);
}

// ── D. JS/PHP hygiene ───────────────────────────────────────────────────────
section('D. JS/PHP hygiene + authority/parity');
const exporterSrc = readFileSync(rel('app/Services/VenueConfigExporter.php'), 'utf8');
const decoratorSrc = readFileSync(rel('resources/js/gallery/VenueDecorator.js'), 'utf8');
const structureBuilderSrc = readFileSync(rel('resources/js/gallery/StructureBuilder.js'), 'utf8');

ok('zero venue slugs in StructureBuilder (DoD rule #7)',
    !/luxury-penthouse|crystal-cathedral|nebula-drift|zen-gallery/.test(structureBuilderSrc.replace(/\/\/[^\n]*/g, '')));
ok('zero venue slugs in VenueDecorator (the dispatch is config-keyed)',
    !/luxury-penthouse/.test(decoratorSrc.replace(/\/\/[^\n]*/g, '')));
for (const key of ['structure', 'glazing_wall', 'post_fx', 'environment', 'env_intensity',
    'artwork_light_base', 'artwork_light_pool_cap', 'hemisphere_intensity',
    'ambient_color', 'spot_intensity', 'fill_intensity', 'tone_mapping_exposure',
    'wall_height', 'ceiling_color']) {
    ok(`exporter owns '${key}' (venue authority, no schema bump needed)`,
        new RegExp(`'${key}'`).test(exporterSrc));
}
for (const key of ['texture_tint', 'floor_color', 'wall_color', 'floor_tile_meters']) {
    ok(`exporter owns material key '${key}'`, new RegExp(`'${key}'`).test(exporterSrc));
}

const migrationPath = rel('database/migrations/2026_09_08_000005_luxury_penthouse_residence.php');
ok('guarded migration exists', existsSync(migrationPath));
if (existsSync(migrationPath)) {
    const mig = readFileSync(migrationPath, 'utf8');
    ok('migration targets luxury-penthouse v1.0.0 → 2.0.0',
        /'luxury-penthouse'/.test(mig) && /OLD_VERSION = '1\.0\.0'/.test(mig) && /NEW_VERSION = '2\.0\.0'/.test(mig));
    ok('migration pins the same v2 description (promise matrix)',
        mig.includes('A private collector\\\'s floor at dusk') || /floor at dusk/.test(mig));
    ok('migration carries down() (reversible)', /public function down\(\)/.test(mig));
    ok('migration structure swap is exact-match guarded', /OLD_STRUCTURE = \[/.test(mig) && /=== self::OLD_STRUCTURE/.test(mig));
}
ok('PHP iteration test exists', existsSync(rel('tests/Feature/VenuePenthouseIterationTest.php')));
ok('shoot.mjs carries the penthouse scenarios (incl. legacy rollback + low tier)',
    /pent-cam-lounge/.test(readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8')) &&
    /pent-tier-low-06/.test(readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8')) &&
    /pent-legacy-12/.test(readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8')));

// ── Verdict ─────────────────────────────────────────────────────────────────
console.log('\n' + '─'.repeat(66));
if (failures === 0) console.log('LUXURY PENTHOUSE QA GATE: ALL PASS');
else { console.error(`LUXURY PENTHOUSE QA GATE: ${failures} FAILURE(S)`); process.exit(1); }
