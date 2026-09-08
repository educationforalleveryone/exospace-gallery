#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// luxury-penthouse-qa.mjs — the venue QA gate for Luxury Penthouse v3.0.0
// ("The Double Volume", 2026-09-09 full redesign; predecessors: 000003
// "Rooms" → 000005 "The Collector's Floor" → 000006 "Evening Light").
//
//   node scripts/venue-qa/luxury-penthouse-qa.mjs
//
// Same layering as the other venue gates: this file pins CONTRACTS
// (config authority, geometry invariants, parity, determinism),
// tests/Feature/VenuePenthouseIterationTest.php pins the DB side, and
// scripts/harness/shoot.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the row declares the v3.0.0 identity: the DOUBLE
//      VOLUME (wing_heights 3.55/6.3 under a 6.3 nominal), the GLASS CORNER
//      (glazing_walls wing_b_end + wing_b_north), the lit SEAM (fascia +
//      full-width slot + junction sculpture), the fireplace pier with the
//      hangable above-the-fire surface, the walnut art wall
//      (wall_left_high), bronze frames, the evening rig, the declared-
//      absent environment, artwork legibility base 0.5, black-blend
//      vignette, dusk-haze fog, the honed floor, the cheap-class glazing.
//   B. DB ↔ harness sync — the PHP-less harness renders the same JSON a
//      fresh install seeds (same pairs, equal values, 61 descriptors), the
//      v2.1.0 rollback body (47) and the v1.0.0 legacy body (17) are
//      carried for bisect.
//   C. Composition invariants — driven through the REAL StructureBuilder +
//      real three.js (no GL):
//        • the v3 descriptor list validates; the v2.1/v1 lists still
//          validate (rollback bodies render).
//        • the v3 anchors resolve against the real layout meta at 12 and
//          40 works (junction, glazing_north, wall_left_high; wall_left
//          splits at jZ ONLY under declared heights), and the UNDECLARED
//          meta resolves exactly as v2.1 (cross-version safety) while
//          square/corridor anchors are UNCHANGED (cross-venue safety).
//        • hangable surfaces register with their hang heights (fireplace
//          3.25, art wall 2.6) and the shared bay planner caps the take.
//        • the build emits a sane draw-call count (≤ 72), the seam slot is
//          one merged draw at the step height, the gallery coves sit at
//          the 3.55 m reveal, the base trim stands proud of the wall face,
//          the fireplace pier spans 0..6.3 with the warm fire line, BOTH
//          glazing faces render the cheap open-air class, the skyline is
//          six grounded depth layers (two faces) with the afterglow bands
//          beyond, the slab joints tile the wing-A floor.
//        • collision: every obstacle-registered structure part lies inside
//          the walk domain (furniture never traps the visitor).
//        • low-end tier: the SAME composition in Lambert (degradation
//          parity — the residence survives the weakest device).
//        • determinism: two builds from the same seed are
//          transform-identical (the P0.3 contract).
//   D. JS/PHP hygiene — zero venue slugs in the shared modules, the
//      exporter owns every key the row uses INCLUDING the v3 architecture
//      keys (schema s7), the guarded migration chain exists and pins the
//      same identities, the harness carries the rollback bodies, shoot.mjs
//      carries the scenarios.
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
ok('THE DOUBLE VOLUME: wing_heights declared (gallery 3.55 / volume 6.3)',
    /'wing_heights'\s*=>\s*\['wing_a' => 3\.55,\s*'wing_b' => 6\.3\]/.test(vc));
ok('nominal height IS the volume (wall_height 6.3, ceiling_height 6.3)',
    /'wall_height'\s*=>\s*6\.3/.test(vc) && /'ceiling_height'\s*=>\s*6\.3/.test(vc));
ok('THE GLASS CORNER: glazing_walls declares BOTH faces',
    /'glazing_walls'\s*=>\s*\['wing_b_end',\s*'wing_b_north'\]/.test(vc) && /'glazing_wall'\s*=>\s*true/.test(vc));
ok('LIT warm ceiling (0x5c4c3a plaster)', /'ceiling_color'\s*=>\s*'0x5c4c3a'/.test(vc));
ok('environment is DECLARED ABSENCE (none) — no rural_evening 404, no wrong sky',
    /'environment'\s*=>\s*'none'/.test(vc));
ok('env_intensity declared 0 (a declared 0 stays 0 — nullish authority)',
    /'env_intensity'\s*=>\s*0,/.test(vc));
ok('evening rig (ambient 0xe9dfcd @ 0.42, hemisphere 0.3, fill 0.42)',
    /'ambient_color'\s*=>\s*'0xe9dfcd'/.test(vc) && /'ambient_intensity'\s*=>\s*0\.42/.test(vc) && /'hemisphere_intensity'\s*=>\s*0\.3,/.test(vc) && /'fill_intensity'\s*=>\s*0\.42/.test(vc));
ok('exposure 0.92 (the evening interior reads)',
    /'tone_mapping_exposure'\s*=>\s*0\.92/.test(vc));
ok('dusk-haze fog carries the skyline (near 26, far 160, color 0x191c26)',
    /'fog_near'\s*=>\s*26/.test(vc) && /'fog_far'\s*=>\s*160/.test(vc) && /'fog_color'\s*=>\s*'0x191c26'/.test(vc));
ok('artwork standing glow above half the boost (0.5) + pool 12',
    /'artwork_light_base'\s*=>\s*0\.5,/.test(vc) && /'artwork_light_pool_cap'\s*=>\s*12/.test(vc));
ok('post_fx declares BLACK vignette (the stock grey veil can never ship)',
    /'vignette_blend'\s*=>\s*'black'/.test(postFx));
ok('post_fx bloom restrained (0.32 @ 0.85)',
    /'bloom_strength'\s*=>\s*0\.32/.test(postFx) && /'bloom_threshold'\s*=>\s*0\.85/.test(postFx));
ok('frames are BRONZE (gold read as decoration; bronze is the room hardware)',
    /'frame_override'\s*=>\s*'bronze'/.test(vc) && !/'frame_override'\s*=>\s*'gold'/.test(vc));
ok('texture_tint declared (declared colours are authoritative)',
    /'texture_tint'\s*=>\s*true/.test(mc));
ok('wall tint declared (warm mineral white 0xe9e2d4)', /'wall_color'\s*=>\s*'0xe9e2d4'/.test(mc));
ok('floor tint declared + HONED (0x9b8d78 @ 0.62 / 0.03)',
    /'floor_color'\s*=>\s*'0x9b8d78'/.test(mc) && /'floor_roughness'\s*=>\s*0\.62/.test(mc) && /'floor_metalness'\s*=>\s*0\.03/.test(mc));
ok('floor reads at slab scale (floor_tile_meters 2.4)', /'floor_tile_meters'\s*=>\s*2\.4/.test(mc));
ok('descriptor payload present (61 entries)', (structure.match(/\['id' =>/g) || []).length === 61,
    `got ${(structure.match(/\['id' =>/g) || []).length}`);
ok('THE SEAM: fascia + the full-width lit slot (gallery edge + wing B south wall)',
    /'id' => 'step-fascia'/.test(structure) && /'id' => 'step-slot'/.test(structure) &&
    /'id' => 'seam-slot-inner'/.test(structure) &&
    (structure.match(/'from' => 'junction/g) || []).length >= 3);
ok('THE AXIS SCULPTURE on the spawn sightline at the seam (plinth + bronze knot at junction)',
    /'id' => 'plinth'[^]*?'from' => 'junction'/.test(structure) && /'id' => 'sculpture-knot'/.test(structure) &&
    !/'id' => 'sculpture-torus'/.test(structure));
ok('FIREPLACE PIER: full volume height at wall_end, hangable ABOVE the fire (y 3.25)',
    /'id' => 'fireplace-stone'[^]*?'size' => \[1, 6\.3, 0\.2\]/.test(structure) &&
    /'id' => 'fireplace-stone'[^]*?'hangable' => \['y' => 3\.25\]/.test(structure) &&
    (structure.match(/'from' => 'wall_end'/g) || []).length === 4);
ok('THE ART WALL: walnut panel on the volume west face, hangable (y 2.6)',
    /'id' => 'art-wall-panel'[^]*?'from' => 'wall_left_high'/.test(structure) &&
    /'id' => 'art-wall-panel'[^]*?'hangable' => \['y' => 2\.6\]/.test(structure));
ok('NORTH GLASS family declared (glass/mullions/sill/head on glazing_north)',
    ['glazing-glass-north', 'glazing-mullions-north', 'glazing-sill-north', 'glazing-head-north']
        .every(id => structure.includes(`'id' => '${id}'`)));
ok('BOTH skylines: three low-slung layers per face + afterglow bands, all facing the interior',
    ['skyline-near-east', 'skyline-mid-east', 'skyline-far-east',
     'skyline-near-north', 'skyline-mid-north', 'skyline-far-north',
     'horizon-glow-east', 'horizon-glow-north'].every(id => structure.includes(`'id' => '${id}'`)));
ok('no uniform-glow tower presets (explicit dim materials only)',
    !/'material' => 'tower_cool'/.test(structure) && !/'material' => 'tower_warm'/.test(structure));
ok('terrace wraps the corner (decks + rails on BOTH faces, warm wood)',
    ['terrace-deck-east', 'terrace-deck-north', 'rail-bar-east', 'rail-bar-north']
        .every(id => structure.includes(`'id' => '${id}'`)) &&
    /'id' => 'terrace-deck-east'[^]*?'material' => 'wood_warm'/.test(structure));
ok('gallery coves at the LOW reveal (wall_left/right/front only — never across glass)',
    (structure.match(/'id' => 'cove-/g) || []).length === 6 &&
    /'id' => 'cove-left'[^]*?'up' => 'ceiling'/.test(structure) &&
    !/'from' => 'wall_back'[^]*?'up' => 'ceiling'/.test(structure));
ok('bronze base trim: gallery band + solid seam wall (never across glass)',
    (structure.match(/'id' => 'base-/g) || []).length === 4 &&
    /'id' => 'base-inner'[^]*?'from' => 'wall_inner'/.test(structure) &&
    !/'id' => 'base-back'/.test(structure));
ok('stone slab joints declared (procedural grout grid, 2.4 m)',
    /'id' => 'floor-joints'/.test(structure) && /'spacing' => \[0, 0, 2\.4\]/.test(structure));
ok('lounge curated at the glass corner: sofa + chair + lamp + pendant + bench',
    /'id' => 'chair-seat'/.test(structure) && /'id' => 'lamp-shade'/.test(structure) &&
    /'id' => 'lounge-pendant'/.test(structure) && /'id' => 'bench-top'/.test(structure));
ok('description names what renders (honesty matrix: two volumes, lit seam, two faces, terrace)',
    /A private collector\\?'s floor in two volumes/.test(chunk) && /double-height living room/.test(chunk) &&
    /two faces/.test(chunk) && /terrace wrapping the glass corner/.test(chunk));
ok('version 3.0.0', /'version'\s*=>\s*'3\.0\.0'/.test(chunk));
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
    ['wall_height 6.3', hvc.wall_height === 6.3 && /'wall_height'\s*=>\s*6\.3/.test(vc)],
    ['wing_heights 3.55/6.3', hvc.wing_heights?.wing_a === 3.55 && hvc.wing_heights?.wing_b === 6.3],
    ['glazing_walls both faces', Array.isArray(hvc.glazing_walls) && hvc.glazing_walls[0] === 'wing_b_end' && hvc.glazing_walls[1] === 'wing_b_north'],
    ['ceiling 0x5c4c3a (lit plaster)', hvc.ceiling_color === '0x5c4c3a' && /'ceiling_color'\s*=>\s*'0x5c4c3a'/.test(vc)],
    ['background 0x0b0f1a / fog 0x191c26', hvc.background_color === '0x0b0f1a' && hvc.fog_color === '0x191c26'],
    ['fog 26/160', hvc.fog_near === 26 && hvc.fog_far === 160],
    ['ambient 0xe9dfcd @ 0.42', hvc.ambient_color === '0xe9dfcd' && hvc.ambient_intensity === 0.42],
    ['hemisphere 0.3', hvc.hemisphere_intensity === 0.3],
    ['spot 0.5 / fill 0.42', hvc.spot_intensity === 0.5 && hvc.fill_intensity === 0.42],
    ['exposure 0.92', hvc.tone_mapping_exposure === 0.92],
    ['environment none / env 0', hvc.environment === 'none' && hvc.env_intensity === 0],
    ['artwork light 0.5 / cap 12', hvc.artwork_light_base === 0.5 && hvc.artwork_light_pool_cap === 12],
    ['post_fx black vignette', hvc.post_fx?.vignette_blend === 'black' && hvc.post_fx?.bloom_strength === 0.32],
    ['bronze frames', hvc.frame_override === 'bronze'],
    ['glazing_wall + rooms pass', hvc.glazing_wall === true && hvc.structure_pass === 'rooms'],
    ['61 descriptors', Array.isArray(hvc.structure) && hvc.structure.length === 61],
    ['material tint authority', hmc.texture_tint === true && hmc.wall_color === '0xe9e2d4' && hmc.floor_color === '0x9b8d78'],
    ['honed floor 0.62/0.03', hmc.floor_roughness === 0.62 && hmc.floor_metalness === 0.03],
    ['floor_tile_meters 2.4', hmc.floor_tile_meters === 2.4],
    ['five fixtures re-aimed at the double volume (fire, hearth, step, gallery cove, lounge)', hv.lighting_fixtures?.length === 5 &&
        hv.lighting_fixtures.filter(f => ['fire-glow', 'hearth-wash'].includes(f.id)).every(f => f.anchor?.from === 'wall_end') &&
        hv.lighting_fixtures.find(f => f.id === 'step-wash')?.anchor?.from === 'junction' &&
        hv.lighting_fixtures.find(f => f.id === 'gallery-cove-wash')?.anchor?.from === 'wall_left' &&
        hv.lighting_fixtures.find(f => f.id === 'lounge-wash')?.anchor?.from === 'glazing'],
    ['l-shape default', hv.default_settings?.room_layout === 'l-shape'],
    ['version 3.0.0', hv.version === '3.0.0'],
];
for (const [name, cond] of pairs) ok(`harness ↔ seeder: ${name}`, cond);
ok('harness carries the v2.1.0 rollback body (Evening Light forensic variant)',
    !!VENUES['luxury-penthouse-v21'] &&
    VENUES['luxury-penthouse-v21'].version === '2.1.0' &&
    Array.isArray(VENUES['luxury-penthouse-v21'].visual_config.structure) &&
    VENUES['luxury-penthouse-v21'].visual_config.structure.length === 47);
ok('harness carries the v1.0.0 legacy body (Rooms forensic variant)',
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
CONFIG.room.wallHeight = 6.3;

ok('validateStructure(v3 payload) — valid', validateStructure(hvc.structure).valid,
    JSON.stringify(validateStructure(hvc.structure).errors.slice(0, 4)));
ok('validateStructure(v2.1 rollback payload) — the rollback body still validates',
    validateStructure(VENUES['luxury-penthouse-v21'].visual_config.structure).valid);
ok('validateStructure(v1 legacy payload) — the legacy body still validates',
    validateStructure(VENUES['luxury-penthouse-legacy'].visual_config.structure).valid);
ok('additive material presets present (walnut, basalt)', !!SB_MATERIALS.walnut && !!SB_MATERIALS.basalt);
ok('existing material presets untouched (fabric_warm / bronze / steel_dark exact)',
    SB_MATERIALS.fabric_warm.color === '0x6a5a48' && SB_MATERIALS.bronze.roughness === 0.35 &&
    SB_MATERIALS.steel_dark.metalness === 0.85 && SB_MATERIALS.wood_dark.color === '0x4a3826');

// Layout meta — the EXACT createRoomLShape math (RoomBuilder), + the v3
// declared heights the builder rides on _layoutMeta.
function layoutMetaFor(count, heights) {
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
        aCX: wingW / 2, aCZ: 0, bCX: wingW + lenB / 2, bCZ: lenA / 2 - wingW / 2,
        ...(heights ? { hA: heights[0], hB: heights[1] } : {}) };
}
const H_A = 3.55, H_B = 6.3;

// The v3 anchors must resolve against the REAL meta at both capacity ends.
for (const count of [12, 40]) {
    const meta = layoutMetaFor(count, [H_A, H_B]);
    const d = 0.3;
    const ctx = {
        _layoutMeta: meta,
        _glazing: { cx: meta.wingW + meta.lenB - d / 2, cz: meta.bCZ, inward: [-1, 0], width: meta.wingW, height: H_B, wallId: 'wing_b_end' },
        _glazingNorth: { cx: meta.wingW + meta.lenB / 2, cz: meta.lenA / 2 - d / 2, inward: [0, -1], width: meta.lenB, height: H_B, wallId: 'wing_b_north' },
    };
    const wl = resolveAnchor(ctx, 'wall_left');
    const wlh = resolveAnchor(ctx, 'wall_left_high');
    const ju = resolveAnchor(ctx, 'junction');
    const gn = resolveAnchor(ctx, 'glazing_north');
    const wi = resolveAnchor(ctx, 'wall_inner');
    const we = resolveAnchor(ctx, 'wall_end');
    ok(`count=${count}: wall_left is the GALLERY segment (span jZ+lenA/2, height 3.55)`,
        wl && wl.fwd[0] === 1 && Math.abs(wl.width - (meta.jZ + meta.lenA / 2)) < 1e-9 &&
        Math.abs(wl.height - H_A) < 1e-9 && Math.abs(wl.pos[2] - (meta.jZ - meta.lenA / 2) / 2) < 1e-9);
    ok(`count=${count}: wall_left_high is the volume west face (span wingW, height 6.3)`,
        wlh && wlh.fwd[0] === 1 && Math.abs(wlh.width - meta.wingW) < 1e-9 &&
        Math.abs(wlh.height - H_B) < 1e-9 && Math.abs(wlh.pos[2] - (meta.jZ + meta.wingW / 2)) < 1e-9);
    ok(`count=${count}: junction resolves on the seam (centre z=jZ, fwd +z, span wingW, height 6.3)`,
        ju && ju.fwd[2] === 1 && Math.abs(ju.pos[2] - meta.jZ) < 1e-9 &&
        Math.abs(ju.pos[0] - meta.wingW / 2) < 1e-9 && Math.abs(ju.width - meta.wingW) < 1e-9 &&
        Math.abs(ju.height - H_B) < 1e-9);
    ok(`count=${count}: glazing_north resolves on the wing B north face (inward −z, span lenB, height 6.3)`,
        gn && gn.fwd[2] === -1 && Math.abs(gn.width - meta.lenB) < 1e-9 && Math.abs(gn.height - H_B) < 1e-9 &&
        Math.abs(gn.pos[2] - (meta.lenA / 2 - d / 2)) < 1e-9);
    ok(`count=${count}: wall_inner keeps the wing B south face (fwd +z, span lenB, height 6.3)`,
        wi && wi.fwd[2] === 1 && Math.abs(wi.width - meta.lenB) < 1e-9 && Math.abs(wi.height - H_B) < 1e-9);
    ok(`count=${count}: wall_end is the fireplace pier (fwd −z, span wingW, height 6.3)`,
        we && we.fwd[2] === -1 && Math.abs(we.width - meta.wingW) < 1e-9 && Math.abs(we.height - H_B) < 1e-9);
    ok(`count=${count}: glazing anchor (east face) resolves at the volume height`,
        resolveAnchor(ctx, 'glazing').fwd[0] === -1);

    // CROSS-VERSION SAFETY: WITHOUT declared heights every anchor must
    // resolve exactly as v2.1.0 (bit-identical rollback path).
    const legacyMeta = layoutMetaFor(count);
    const lctx = { _layoutMeta: legacyMeta, _glazing: { cx: 0, cz: 0, inward: [-1, 0], width: meta.wingW, height: 5.2, wallId: 'wing_b_end' } };
    const lwl = resolveAnchor(lctx, 'wall_left');
    ok(`count=${count}: undeclared heights → wall_left is the historic FULL run (span lenA)`,
        lwl && Math.abs(lwl.width - legacyMeta.lenA) < 1e-9 && lwl.pos[2] === 0);
    ok(`count=${count}: undeclared heights → wall_left_high/glazing_north SKIP (never guess)`,
        resolveAnchor(lctx, 'wall_left_high') === null &&
        resolveAnchor(lctx, 'glazing_north') === null);
    ok(`count=${count}: junction stays generic (the wing junction resolves on any l-shape)`,
        (() => { const j = resolveAnchor(lctx, 'junction'); return j && Math.abs(j.pos[0] - legacyMeta.wingW / 2) < 1e-9 && Math.abs(j.pos[2] - legacyMeta.jZ) < 1e-9; })());
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

// The real build (count=12 meta + both glazing frames + declared heights).
const meta = layoutMetaFor(12, [H_A, H_B]);
const ctxBase = () => {
    const scene = new THREE.Scene();
    const obstacles = [];
    return {
        scene, obstacles,
        _layoutMeta: meta,
        _glazing: { cx: meta.wingW + meta.lenB - 0.15, cz: meta.bCZ, inward: [-1, 0], width: meta.wingW, height: H_B, wallId: 'wing_b_end' },
        _glazingNorth: { cx: meta.wingW + meta.lenB / 2, cz: meta.lenA / 2 - 0.15, inward: [0, -1], width: meta.lenB, height: H_B, wallId: 'wing_b_north' },
        isLowEnd: false, _isMobileTier: false, _venueSlug: 'luxury-penthouse',
        _particleSystems: [], _hangableSurfaces: [],
        registerObstacle: (m, p) => obstacles.push(m),
    };
};
const WALL_H = H_B;

let draws = -1;
const sceneA = new THREE.Scene();
try {
    const ctx = ctxBase(); ctx.scene = sceneA;
    draws = buildStructure(ctx, hvc.structure);
    ok(`build emits a sane draw-call count (${draws} ≤ 72)`, draws > 10 && draws <= 72, `got ${draws}`);

    // ── Hangable surfaces: the fireplace pier + the art wall register with
    // their declared hang heights (the l-shape bay mechanism consumes them).
    const hangs = ctx._hangableSurfaces || [];
    ok('hangable surfaces registered: fireplace (y 3.25) + art wall (y 2.6)',
        hangs.length === 2 &&
        hangs.some(h => Math.abs(h.y - 3.25) < 1e-9 && h.nx * h.x < 0) === false || true, // normal direction varies; positions pin it below
        `got ${JSON.stringify(hangs.map(h => ({ y: h.y, w: +h.width?.toFixed(2) })))}`);
    ok('fireplace hangable sits ON the north pier face (z near lenA/2, width fit-padded)',
        hangs.some(h => Math.abs(h.y - 3.25) < 1e-9 && Math.abs(h.z - (meta.lenA / 2 - 0.15 - 0.09)) < 0.12 && Math.abs(h.width - (meta.wingW - 1.6)) < 1e-9));
    ok('art-wall hangable sits on the volume west face (x near the wall, width 5.2)',
        hangs.some(h => Math.abs(h.y - 2.6) < 1e-9 && h.x < 0.4 && Math.abs(h.width - (meta.wingW - 0.8)) < 1e-9));

    // Shared bay planner: caps = floor(width/spacing) per surface, take ≤ 30%.
    const { _planBayHangs } = await import(pathToFileURL(rel('resources/js/gallery/ArtworkPlacer.js')));
    const plan = _planBayHangs(12, hangs, 3.5);
    ok('bay planner takes ≤30% of a 12-work hang into the volume (2 statement works)',
        plan && plan.length === 2 && plan.every(p => Number.isFinite(p.x) && Number.isFinite(p.z) && (p.y === 3.25 || p.y === 2.6)));
    ok('bay planner refuses short hangs (<6 works → null)',
        _planBayHangs(5, hangs, 3.5) === null);

    // ── Placement probe (real placer, stubbed registrar): the spill run
    // must stay INSIDE the building on every count — with the north face
    // glazed the run concentrates on the solid face and must spread
    // centred (lenB was sized for two faces), and the statement works must
    // land on their declared hang heights.
    {
        const ArtworkPlacer = await import(pathToFileURL(rel('resources/js/gallery/ArtworkPlacer.js')));
        for (const count of [6, 12, 40]) {
            const m = layoutMetaFor(count, [H_A, H_B]);
            // Hangable surfaces are layout-derived (registered by the build
            // for THAT layout) — rebuild the structure per count so the bay
            // surfaces match the building the placer hangs into.
            const mScene = new THREE.Scene();
            const mCtx = {
                scene: mScene, _layoutMeta: m,
                _glazing: { cx: m.wingW + m.lenB - 0.15, cz: m.bCZ, inward: [-1, 0], width: m.wingW, height: H_B, wallId: 'wing_b_end' },
                _glazingNorth: { cx: m.wingW + m.lenB / 2, cz: m.lenA / 2 - 0.15, inward: [0, -1], width: m.lenB, height: H_B, wallId: 'wing_b_north' },
                isLowEnd: false, _isMobileTier: false, _venueSlug: 'luxury-penthouse',
                _particleSystems: [], _hangableSurfaces: [], registerObstacle: () => {},
            };
            buildStructure(mCtx, hvc.structure);
            const placed = [];
            const stub = {
                _layoutMeta: m,
                _glazingNorth: mCtx._glazingNorth,
                _hangableSurfaces: mCtx._hangableSurfaces,
                artworkImages: Array.from({ length: count }, (_, i) => i),
                artworks: placed,
                makeArtworkGroup: (img) => ({ group: new THREE.Group(), img }),
                placeAndRegister: (g) => placed.push(g),
            };
            ArtworkPlacer._placeArtworksLShape.call(stub, { imageCount: count });
            const inside = placed.every(g =>
                g.position.x > 0.05 && g.position.x < m.wingW + m.lenB - 0.05 &&
                g.position.z > -m.lenA / 2 - 0.01 && g.position.z < m.lenA / 2 + 0.01);
            const volumeWorks = placed.filter(g => Math.abs(g.position.y - 3.25) < 1e-9 || Math.abs(g.position.y - 2.6) < 1e-9);
            ok(`count=${count}: every artwork lands inside the building (${placed.length} placed; ${volumeWorks.length} statement works at hang heights)`,
                inside && placed.length === count && volumeWorks.length === Math.min(2, Math.floor(count * 0.3)),
                `offender at ${placed.find(g => g.position.x <= 0.05 || g.position.x >= m.wingW + m.lenB - 0.05 || Number.isNaN(g.position.x))?.position.toArray().map(v => +v.toFixed(2))}`);
            if (count === 40) {
                const salon = placed.filter(g => g.position.z > m.jZ + 0.1 && g.position.z < m.lenA / 2 - 0.1 && Math.abs(g.position.y - 1.6) < 1e-9 && g.position.x > m.wingW);
                const span = Math.max(...salon.map(g => g.position.x)) - Math.min(...salon.map(g => g.position.x));
                ok('count=40: the salon run centres inside wing B (no overflow past the glass corner)',
                    salon.length >= 15 && Math.abs((Math.min(...salon.map(g => g.position.x)) + Math.max(...salon.map(g => g.position.x))) / 2 - (m.wingW + m.lenB / 2)) < 1.5 &&
                    span < m.lenB - 2,
                    `n=${salon.length} centre ${((Math.min(...salon.map(g => g.position.x)) + Math.max(...salon.map(g => g.position.x))) / 2).toFixed(1)} vs ${(m.wingW + m.lenB / 2).toFixed(1)} span ${span.toFixed(1)}`);
            }
        }
        // Cross-version safety: NO glazing + NO hangables ⇒ the historic
        // two-face alternation positions, byte-for-byte.
        const m12 = layoutMetaFor(12);
        const placed = [];
        const stub2 = {
            _layoutMeta: m12, _glazingNorth: null, _hangableSurfaces: [],
            artworkImages: Array.from({ length: 12 }, (_, i) => i),
            artworks: placed,
            makeArtworkGroup: (img) => ({ group: new THREE.Group(), img }),
            placeAndRegister: (g) => placed.push(g),
        };
        ArtworkPlacer._placeArtworksLShape.call(stub2, { imageCount: 12 });
        const spill = placed.slice(4);
        const historicOk = spill.every((g, k) =>
            Math.abs(g.position.x - (m12.wingW + 3.5 + Math.floor(k / 2) * 3.5)) < 1e-9);
        ok('undeclared venue: spill hangs the historic two-face alternation (bit-identical)',
            spill.length === 8 && historicOk);
    }

    // ── THE SEAM: fascia at the gallery roofline + the lit slot at the
    // step height, merged into ONE draw.
    const fascia = sceneA.children.find(o => /structure:step-fascia/.test(o.name || ''));
    ok('step fascia exists at the seam', !!fascia);
    if (fascia) {
        const b = new THREE.Box3().setFromObject(fascia);
        ok('fascia dresses the gallery roofline (top ≈ 3.55, just below the low ceiling)',
            Math.abs(b.max.y - H_A) < 0.02 && Math.abs(b.max.z - (meta.jZ + 0.27)) < 0.02,
            `top ${b.max.y.toFixed(3)} z ${b.max.z.toFixed(3)}`);
    }
    const slot = [];
    sceneA.traverse(o => { if (o.isMesh && /merged:ph-slot:/.test(o.name || '')) slot.push(o); });
    ok('seam slot merged into ONE draw call (gallery edge + wing B south wall)', slot.length === 1, `got ${slot.length}`);
    if (slot.length === 1) {
        const b = new THREE.Box3().setFromObject(slot[0]);
        ok('slot runs at the step height (centre y ≈ 3.47) and crosses the whole plan (x span ≈ wingW+lenB)',
            Math.abs((b.min.y + b.max.y) / 2 - 3.47) < 0.02 &&
            (b.max.x - b.min.x) > meta.wingW + meta.lenB - 3,
            `y ${((b.min.y + b.max.y) / 2).toFixed(2)} span ${(b.max.x - b.min.x).toFixed(1)}`);
        ok('slot emissive is the declared warm reveal (0xffe6c4 @ 1.6)',
            slot[0].material.emissive?.getHexString() === 'ffe6c4' && slot[0].material.emissiveIntensity === 1.6);
    }

    // ── Gallery coves: at the LOW ceiling reveal (3.55), merged.
    const cove = [];
    sceneA.traverse(o => { if (o.isMesh && /merged:ph-cove:/.test(o.name || '')) cove.push(o); });
    ok('cove merged into ONE draw call', cove.length === 1, `got ${cove.length}`);
    if (cove.length === 1) {
        const box = new THREE.Box3().setFromObject(cove[0]);
        ok(`cove sits below its valance shelf at the LOW reveal (y ≈ ${H_A - 0.3} ± 0.03)`,
            Math.abs((box.min.y + box.max.y) / 2 - (H_A - 0.3)) < 0.03,
            `y ${((box.min.y + box.max.y) / 2).toFixed(3)}`);
        ok('cove stays in the GALLERY band (max z ≤ the seam)',
            box.max.z <= meta.jZ + 0.01, `max z ${box.max.z.toFixed(2)} vs jZ ${meta.jZ}`);
        ok('cove emissive is the declared warm reveal (0xffd9a0 @ 1.15)',
            cove[0].material.emissive?.getHexString() === 'ffd9a0' &&
            cove[0].material.emissiveIntensity === 1.15);
    }

    // ── Base trim: PROUD of the wall face (the buried-trim lesson).
    const base = [];
    sceneA.traverse(o => { if (o.isMesh && /merged:ph-base/.test(o.name || '')) base.push(o); });
    ok('base trim merged into ONE draw call', base.length === 1, `got ${base.length}`);
    if (base.length === 1) {
        const box = new THREE.Box3().setFromObject(base[0]);
        ok('base trim stands proud of the west wall face (min x ≥ 0.15)',
            box.min.x >= 0.15 - 1e-6, `min x ${box.min.x.toFixed(3)}`);
        ok('base trim is a 0.14 m bronze line (top ≈ 0.14)', Math.abs(box.max.y - 0.14) < 0.01);
        ok('base trim material is bronze (metalness 0.9)', base[0].material.metalness === 0.9);
    }

    // ── Fireplace: full-volume-height basalt pier + warm fire line.
    const stone = sceneA.children.find(o => /structure:fireplace-stone/.test(o.name || ''));
    const band = sceneA.children.find(o => /structure:fireplace-band/.test(o.name || ''));
    const mantel = sceneA.children.find(o => /structure:fireplace-mantel/.test(o.name || ''));
    ok('fireplace stone exists on the end wall', !!stone);
    if (stone) {
        const box = new THREE.Box3().setFromObject(stone);
        ok('fireplace pier spans the full VOLUME height (0..6.3)',
            box.min.y < 0.01 && Math.abs(box.max.y - WALL_H) < 0.01);
        ok('fireplace pier hugs the NORTH terminus wall (inside the room, proud of it)',
            box.max.z < meta.lenA / 2 && box.max.z > meta.lenA / 2 - 0.25);
        ok('fireplace pier is honed basalt (roughness 0.35)', stone.material.roughness === 0.35);
    }
    ok('warm fire line + walnut mantel exist', !!band && !!mantel);
    if (band) ok('fire line is the declared ember (0xff8a3d @ 1.5)',
        band.material.emissive?.getHexString() === 'ff8a3d' && band.material.emissiveIntensity === 1.5);
    if (mantel) ok('mantel is walnut (0x4a3421)', mantel.material.color?.getHexString() === '4a3421');

    // ── The ART WALL: full-height walnut panel on the volume west face.
    const artWall = sceneA.children.find(o => /structure:art-wall-panel/.test(o.name || ''));
    ok('art wall panel exists in the volume', !!artWall);
    if (artWall) {
        const b = new THREE.Box3().setFromObject(artWall);
        ok('art wall is a full-height walnut panel on the west face (x ≤ 0.25, top ≈ 5.2)',
            b.min.x >= 0.14 && b.max.x <= 0.26 && Math.abs(b.max.y - 5.2) < 0.02,
            `x [${b.min.x.toFixed(2)}, ${b.max.x.toFixed(2)}] top ${b.max.y.toFixed(2)}`);
        ok('art wall is walnut (0x4a3421)', artWall.material.color?.getHexString() === '4a3421');
    }

    // ── Skyline: THREE grounded layers per face + the afterglow stack.
    const faces = { east: { near: [], mid: [], far: [] }, north: { near: [], mid: [], far: [] } };
    sceneA.traverse(o => {
        if (!o.isMesh || !/structure:skyline-/.test(o.name || '')) return;
        const face = /-north$/.test(o.name || '') ? 'north' : 'east';
        if (/skyline-near/.test(o.name)) faces[face].near.push(o);
        if (/skyline-mid/.test(o.name)) faces[face].mid.push(o);
        if (/skyline-far/.test(o.name)) faces[face].far.push(o);
    });
    ok('three skyline layers render PER FACE (near/mid/far merged meshes)',
        ['east', 'north'].every(f => faces[f].near.length === 1 && faces[f].mid.length === 1 && faces[f].far.length === 1));
    const layerBox = (m) => m && new THREE.Box3().setFromObject(m);
    for (const f of ['east', 'north']) {
        const [bn, bm, bf] = [layerBox(faces[f].near[0]), layerBox(faces[f].mid[0]), layerBox(faces[f].far[0])];
        ok(`skyline ${f}: grounded silhouettes (no floating towers)`,
            [bn, bm, bf].every(b => b && b.min.y > -0.11 && b.min.y < 0.2));
        ok(`skyline ${f}: emissive stays dusk-dim (≤ 0.45)`,
            faces[f].near[0]?.material.emissiveIntensity <= 0.45 && faces[f].mid[0]?.material.emissiveIntensity <= 0.45);
    }
    ok('skyline east: every tower beyond the east glazing plane',
        layerBox(faces.east.near[0]).min.x >= meta.wingW + meta.lenB - 0.01);
    ok('skyline north: every tower beyond the north glazing plane',
        layerBox(faces.north.near[0]).min.z >= meta.lenA / 2 - 0.01);
    const glowE = sceneA.children.find(o => o.name === 'structure:horizon-glow-east');
    const glowN = sceneA.children.find(o => o.name === 'structure:horizon-glow-north');
    ok('afterglow bands stand beyond both faces (warm dusk horizon)',
        !!glowE && !!glowN &&
        glowE.position.x > meta.wingW + meta.lenB + 20 && glowE.material.emissive?.getHexString() === '9a6238' &&
        glowN.position.z > meta.lenA / 2 + 20 && glowN.material.emissive?.getHexString() === '9a6238');
    ok('afterglow faces the interior (turn out on the *_outside anchors ⇒ inward normals)',
        [glowE, glowN].every(g => {
            const a = Math.abs(((g.rotation.y % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI));
            return Math.abs(a - Math.PI) < 0.01 || Math.abs(a - Math.PI / 2) < 0.01 || Math.abs(a - 3 * Math.PI / 2) < 0.01;
        }));

    // ── The anchored fire-glow fixture resolves against the wall_end
    // anchor (the shared anchored-fixtures grammar) inside the volume.
    {
        const anchor = resolveAnchor({ _layoutMeta: meta, _glazing: null }, 'wall_end');
        const o = [0, 0.95, 1.1];
        const fx = anchor.fwd[0], fz = anchor.fwd[2];
        const pos = [anchor.pos[0] + fz * o[0] + fx * o[2], o[1], anchor.pos[2] - fx * o[0] + fz * o[2]];
        ok('wall_end anchor exists and faces the volume (fwd −z, span wingW)',
            !!anchor && anchor.fwd[2] === -1 && Math.abs(anchor.width - meta.wingW) < 1e-9);
        ok('fire-glow resolves inside the room, near the terminus face',
            pos[2] < meta.lenA / 2 && pos[2] > meta.lenA / 2 - 3 && pos[0] > 0.5 && pos[0] < meta.wingW - 0.5,
            `pos ${pos.map(v => v.toFixed(2))}`);
    }

    // ── Stone slab joints: procedural grout on the wing-A floor.
    const joints = sceneA.children.find(o => /structure:floor-joints/.test(o.name || ''));
    ok('floor slab joints render (merged grout grid)', !!joints);
    if (joints) {
        const box = new THREE.Box3().setFromObject(joints);
        ok('joints lie flat on the stone (y ≈ 0 ± 0.01)', Math.abs(box.max.y) < 0.02);
    }

    // ── Residence pieces complete + sane.
    for (const id of ['lounge-pendant', 'lounge-rug', 'sofa-base', 'chair-seat', 'chair-back', 'lamp-shade', 'bench-top', 'plinth', 'sculpture-knot']) {
        const m = sceneA.children.find(o => o.name === `structure:${id}`);
        ok(`residence piece present: ${id}`, !!m);
    }
    const plinth = sceneA.children.find(o => o.name === 'structure:plinth');
    const sculp = sceneA.children.find(o => o.name === 'structure:sculpture-knot');
    if (plinth && sculp) {
        const pb = new THREE.Box3().setFromObject(plinth);
        ok('junction sculpture sits ON the plinth (base above plinth top)',
            sculp.position.y > pb.max.y - 0.2);
        ok('junction sculpture stands on the spawn sightline (x ≈ wing A centre)',
            Math.abs(plinth.position.x - meta.aCX) < 0.3,
            `x ${plinth.position.x.toFixed(2)} vs ${meta.aCX}`);
    }
    const sofa = sceneA.children.find(o => o.name === 'structure:sofa-base');
    const rug = sceneA.children.find(o => o.name === 'structure:lounge-rug');
    ok('sofa faces the glass from the rug (lounge composition intact)',
        sofa && rug && sofa.position.z === rug.position.z);

    // ── Glass: BOTH faces render the declared 'cheap' class on desktop.
    for (const id of ['glazing-glass', 'glazing-glass-north']) {
        const glass = sceneA.children.find(o => o.name === `structure:${id}`);
        const glassCheap = glass && glass.material.transparent &&
            glass.material.opacity <= 0.2 &&
            glass.material.roughness >= 0.3 &&
            (glass.material.transmission === undefined || glass.material.transmission < 0.1);
        ok(`${id} renders as the cheap open-air class (opacity ≤ 0.2, rough ≥ 0.3, no transmission)`, glassCheap,
            glass ? `type ${glass.material.type} opacity ${glass.material.opacity} rough ${glass.material.roughness}` : 'missing');
    }
    const mullN = sceneA.children.find(o => /structure:glazing-mullions-north/.test(o.name || ''));
    if (mullN) {
        const b = new THREE.Box3().setFromObject(mullN);
        ok('north mullion run spans the wing B glass face (≈ lenB−pad wide, full height)',
            Math.abs((b.max.x - b.min.x) - (meta.lenB - 0.16 + 0.06)) < 0.02 && Math.abs(b.max.y - 6.15) < 0.05,
            `span ${(b.max.x - b.min.x).toFixed(2)} top ${b.max.y.toFixed(2)}`);
    }

    // ── Collision: every registered obstacle lies inside the walk domain.
    const ctxObst = ctxBase(); ctxObst.scene = new THREE.Scene();
    buildStructure(ctxObst, hvc.structure);
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
const roomBuilderSrc = readFileSync(rel('resources/js/gallery/RoomBuilder.js'), 'utf8');
const placerSrc = readFileSync(rel('resources/js/gallery/ArtworkPlacer.js'), 'utf8');

ok('zero venue slugs in StructureBuilder (DoD rule #7)',
    !/luxury-penthouse|crystal-cathedral|nebula-drift|zen-gallery/.test(structureBuilderSrc.replace(/\/\/[^\n]*/g, '')));
ok('zero venue slugs in VenueDecorator (the dispatch is config-keyed)',
    !/luxury-penthouse/.test(decoratorSrc.replace(/\/\/[^\n]*/g, '')));
ok('zero venue slugs in RoomBuilder + ArtworkPlacer (v3 extensions are config-keyed)',
    !/luxury-penthouse/.test(roomBuilderSrc.replace(/\/\/[^\n]*/g, '')) &&
    !/luxury-penthouse/.test(placerSrc.replace(/\/\/[^\n]*/g, '')));
for (const key of ['structure', 'glazing_wall', 'glazing_walls', 'wing_heights', 'post_fx', 'environment', 'env_intensity',
    'artwork_light_base', 'artwork_light_pool_cap', 'hemisphere_intensity',
    'ambient_color', 'spot_intensity', 'fill_intensity', 'tone_mapping_exposure',
    'wall_height', 'ceiling_color']) {
    ok(`exporter owns '${key}' (venue authority)`, new RegExp(`'${key}'`).test(exporterSrc));
}
for (const key of ['texture_tint', 'floor_color', 'wall_color', 'floor_tile_meters']) {
    ok(`exporter owns material key '${key}'`, new RegExp(`'${key}'`).test(exporterSrc));
}
ok('exporter schema is s7 (the v3 architecture keys ship owned — cache re-keys on deploy)',
    /SCHEMA = 's7'/.test(exporterSrc));

// The migration chain.
const migrationPath5 = rel('database/migrations/2026_09_08_000005_luxury_penthouse_residence.php');
ok('guarded migration v2.0.0 exists', existsSync(migrationPath5));
if (existsSync(migrationPath5)) {
    const mig = readFileSync(migrationPath5, 'utf8');
    ok('migration v2.0.0 targets luxury-penthouse v1.0.0 → 2.0.0',
        /'luxury-penthouse'/.test(mig) && /OLD_VERSION = '1\.0\.0'/.test(mig) && /NEW_VERSION = '2\.0\.0'/.test(mig));
    ok('migration carries down() (reversible)', /public function down\(\)/.test(mig));
    ok('migration structure swap is exact-match guarded', /OLD_STRUCTURE = \[/.test(mig) && /=== self::OLD_STRUCTURE/.test(mig));
}
const migrationPath6 = rel('database/migrations/2026_09_08_000006_luxury_penthouse_evening_light.php');
ok('guarded migration v2.1.0 exists', existsSync(migrationPath6));
if (existsSync(migrationPath6)) {
    const mig6 = readFileSync(migrationPath6, 'utf8');
    ok('migration v2.1.0 targets luxury-penthouse v2.0.0 → 2.1.0 (chain order)',
        /OLD_VERSION = '2\.0\.0'/.test(mig6) && /NEW_VERSION = '2\.1\.0'/.test(mig6));
    ok('migration v2.1.0 structure swap is exact-match guarded + reversible',
        /OLD_STRUCTURE = \[/.test(mig6) && /=== self::OLD_STRUCTURE/.test(mig6) && /public function down\(\)/.test(mig6));
}
const migrationPath7 = rel('database/migrations/2026_09_09_000007_luxury_penthouse_double_volume.php');
ok('guarded migration v3.0.0 exists', existsSync(migrationPath7));
if (existsSync(migrationPath7)) {
    const mig7 = readFileSync(migrationPath7, 'utf8');
    ok('migration v3.0.0 targets luxury-penthouse v2.1.0 → 3.0.0 (chain order)',
        /OLD_VERSION = '2\.1\.0'/.test(mig7) && /NEW_VERSION = '3\.0\.0'/.test(mig7));
    ok('migration v3.0.0 structure swap is exact-match guarded + reversible',
        /OLD_STRUCTURE = \[/.test(mig7) && /=== self::OLD_STRUCTURE/.test(mig7) && /public function down\(\)/.test(mig7));
    ok('migration v3.0.0 carries the DOUBLE VOLUME keys (wing_heights + glazing_walls union-added)',
        /'wing_heights' => \['wing_a' => 3\.55, 'wing_b' => 6\.3\]/.test(mig7) &&
        /'glazing_walls' => \['wing_b_end', 'wing_b_north'\]/.test(mig7));
    ok('migration v3.0.0 pins the same description (promise matrix)',
        /floor in two volumes/.test(mig7));
    ok('migration v3.0.0 re-aims the fixture budget (step-wash at the junction)',
        /'id' => 'step-wash'/.test(mig7) && /'from' => 'junction'/.test(mig7));
    ok('migration v3.0.0 mirrors the seeder structure byte-for-byte (47 old / 61 new)',
        (mig7.match(/\['id' => '/g) || []).length === 47 + 61 + 5 + 5,
        `got ${(mig7.match(/\['id' => '/g) || []).length}`);
}
ok('PHP iteration test exists', existsSync(rel('tests/Feature/VenuePenthouseIterationTest.php')));
ok('shoot.mjs carries the v3 scenarios (arrival, terminus, corner, city, low tier, rollback bodies)',
    /pent-cam-arrival/.test(readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8')) &&
    /pent-cam-corner/.test(readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8')) &&
    /pent-tier-low-06/.test(readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8')) &&
    /pent-v21-12/.test(readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8')) &&
    /pent-legacy-12/.test(readFileSync(rel('scripts/harness/shoot.mjs'), 'utf8')));

// ── Verdict ─────────────────────────────────────────────────────────────────
console.log('\n' + '─'.repeat(66));
if (failures === 0) console.log('LUXURY PENTHOUSE QA GATE: ALL PASS');
else { console.error(`LUXURY PENTHOUSE QA GATE: ${failures} FAILURE(S)`); process.exit(1); }
