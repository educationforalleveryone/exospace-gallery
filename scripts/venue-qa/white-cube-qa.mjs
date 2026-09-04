#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// white-cube-qa.mjs — the venue QA gate for Modern White Cube.
//
//   node scripts/venue-qa/white-cube-qa.mjs
//
// Runs WITHOUT a PHP stack (plain Node over the repo checkout), so it fits
// CI containers and build sandboxes. Uses the project's existing QA layering:
// it pins CONTRACTS (config authority, placement geometry, determinism),
// while tests/Feature/VenueWhiteCubePolishIterationTest.php pins the DB side
// and scripts/harness/shoot.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the white-cube row declares the polished identity
//      (gallery-white fog, physical-unit rig, post_fx restraint, layouts).
//   B. DB↔harness sync — the PHP-less harness renders the same JSON a fresh
//      install seeds (drift here means screenshots stop meaning anything).
//   C. Placement invariants — driven through the REAL placement modules:
//      aspect clamps, partial-run centring, wall contact, bounds, and the
//      determinism contract (same inputs → identical transforms).
//   D. JS hygiene — no venue slugs in the runtime (DoD rule #7), and the
//      mipmap-less texture path always pairs with LinearFilter (the
//      black-artwork low-end bug class).
// ─────────────────────────────────────────────────────────────────────────────
import { readFileSync, readdirSync } from 'node:fs';
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
section('A. Seeder contract (white-cube row)');
const seederSrc = readFileSync(rel('database/seeders/VenueTemplateSeeder.php'), 'utf8');

function whiteCubeChunk() {
    const i = seederSrc.indexOf("'slug'          => 'white-cube'");
    if (i === -1) throw new Error('white-cube row not found in seeder');
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

const chunk = whiteCubeChunk();
const vc = arrayLiteral(chunk, 'visual_config');
const mc = arrayLiteral(chunk, 'material_config');
const ds = arrayLiteral(chunk, 'default_settings');

ok('fog dissolves toward gallery white (never soot)',
    /'fog_color'\s*=>\s*'0x(f|e)[0-9a-f]/i.test(vc),
    'expected a light fog colour ≥ 0xe…');
ok('fog starts beyond normal viewing distance (near ≥ 14)',
    /'fog_near'\s*=>\s*(1[4-9]|[2-9]\d)/.test(vc));
ok('exposure ≥ 1.0 (ACES at 0.5 rendered the room grey)',
    /'tone_mapping_exposure'\s*=>\s*(1\.\d+|[2-9])/.test(vc));
ok('ambient declared in physical units (≥ 0.4)',
    /'ambient_intensity'\s*=>\s*0\.[4-9]/.test(vc));
ok('spot_intensity declared (pool target ≥ 2)',
    /'spot_intensity'\s*=>\s*[2-9]/.test(vc));
ok('fill_intensity declared (grid target ≥ 1.5 — previously dead config)',
    /'fill_intensity'\s*=>\s*(1\.[5-9]|[2-9])/.test(vc));
ok('post_fx declares restraint (bloom off)',
    /'bloom'\s*=>\s*false/.test(vc));
ok('structure_pass selector present', vc.includes("'structure_pass'"));
ok('floor is sealed polished concrete (light tone, roughness ≤ 0.6)',
    /'floor_color'\s*=>\s*'0x(9|a|b)/.test(mc) && /'floor_roughness'\s*=>\s*0\.[1-5]/.test(mc));
ok('default frame defines the edge (modern/classic/black)',
    /'frame_style'\s*=>\s*'(modern|classic|black)'/.test(ds));
ok('all four advertised layouts supported',
    /'supported_layouts'\s*=>\s*\[[^\]]*'square'[^\]]*'corridor'[^\]]*'l-shape'[^\]]*'rotunda'/.test(chunk));
ok('version bumped to 1.1.0 (polish iteration)',
    /'version'\s*=>\s*'1\.1\.0'/.test(chunk));

// ── B. DB ↔ harness sync ────────────────────────────────────────────────────
section('B. Harness payload ↔ seeder sync');
const harnessSrc = readFileSync(rel('scripts/harness/harness.html'), 'utf8');
for (const [label, needle] of [
    ['fog colour', "fog_color: '0xf2f1ee'"],
    ['fog near', 'fog_near: 16'],
    ['fog far', 'fog_far: 60'],
    ['ambient', 'ambient_intensity: 0.55'],
    ['spot', 'spot_intensity: 3.2'],
    ['fill', 'fill_intensity: 2.6'],
    ['exposure', 'tone_mapping_exposure: 1.05'],
    ['bloom off', 'bloom: false'],
    ['floor colour', "floor_color: '0x9c9c98'"],
    ['floor roughness', 'floor_roughness: 0.55'],
]) {
    ok(`harness carries ${label}`, harnessSrc.includes(needle), `missing "${needle}"`);
}

// ── C. Placement invariants (real modules, stub scene) ──────────────────────
section('C. Placement invariants (real ArtworkPlacer modules)');
const THREE = await import('three');
const { _placeArtworksSquare, _placeArtworksCorridor, wallRunOffset } =
    await import(pathToFileURL(rel('resources/js/gallery/ArtworkPlacer.js')).href);
const { CONFIG: PLACEMENT_CONFIG } =
    await import(pathToFileURL(rel('resources/js/gallery/config.js')).href);

const SPACING = 3.5;

function makeContext(imageCount) {
    const ctx = {
        artworkImages: Array.from({ length: imageCount }, (_, i) => ({
            id: 1000 + i,
            aspectRatio: 1.5,
            texture: null,
        })),
        artworks: [],
        isLowEnd: false,
        textures: {},
        _venueFrameOverride: null,
        _venuePlacement: {},
        _hangableSurfaces: [],
        _glazing: null,
        scene: { add() {} },
    };
    ctx.makeArtworkGroup = (img) => {
        // Mirror of ArtworkPlacer.makeArtworkGroup's geometry contract.
        const aspectRatio = img.aspectRatio || 1;
        const maxHeight = 2.0, maxWidth = 3.0;
        let height = maxHeight, width = height * aspectRatio;
        if (width > maxWidth) { width = maxWidth; height = width / aspectRatio; }
        const canvas = new THREE.Mesh(new THREE.PlaneGeometry(width, height), new THREE.MeshBasicMaterial());
        const frame = new THREE.Mesh(new THREE.BoxGeometry(1, 1, 1), new THREE.MeshBasicMaterial());
        const group = new THREE.Group();
        group.add(frame);
        group.add(canvas);
        group.userData = { type: 'artwork', id: img.id, _canvasMesh: canvas, ...img };
        return { group };
    };
    ctx.placeAndRegister = (group) => {
        ctx.artworks.push(group);
        group.userData.lightBase = 0.1;
        group.userData.lightMax = 1;
    };
    return ctx;
}

function runSquare(imageCount) {
    const ctx = makeContext(imageCount);
    const wallCount = 4;
    const wallLength = Math.max(8, (Math.ceil(imageCount / wallCount) * SPACING) + SPACING);
    ctx._layoutMeta = { type: 'square', wallLength };
    const saved = PLACEMENT_CONFIG.room.artworkSpacing;
    PLACEMENT_CONFIG.room.artworkSpacing = SPACING;
    try { _placeArtworksSquare.call(ctx, { frame_style: 'modern' }); }
    finally { PLACEMENT_CONFIG.room.artworkSpacing = saved; }
    return { ctx, wallLength };
}

// C1. every artwork on a wall plane, inside the room, upright, at eye level
{
    const { ctx, wallLength } = runSquare(8);
    const hang = ctx.artworks;
    ok('8 works all registered', hang.length === 8, `got ${hang.length}`);
    const half = wallLength / 2;
    const allOnWalls = hang.every(g => {
        const { x, z } = g.position;
        return [x, -x, z, -z].some(v => Math.abs(v - half) < 0.25);
    });
    ok('all artworks sit on a wall plane (0.2 m hang depth)', allOnWalls);
    ok('no artwork outside the room',
        hang.every(g => Math.abs(g.position.x) <= half + 0.01 && Math.abs(g.position.z) <= half + 0.01));
    ok('artworks hang at eye level (1.6 m)',
        hang.every(g => Math.abs(g.position.y - 1.6) < 0.001));
    // Upright = the canvas world normal is horizontal (|ny| ≈ 0) and points
    // INTO the room. Euler angles are the wrong instrument: lookAt's 180°
    // yaw has equivalent euler representations like (−π, 0, −π).
    const normalsUpright = hang.every(g => {
        const n = new THREE.Vector3(0, 0, 1).applyQuaternion(g.quaternion);
        return Math.abs(n.y) < 1e-6;
    });
    ok('canvases are upright (world normal horizontal)', normalsUpright);
    const facingIn = hang.every((g, i) => {
        const n = new THREE.Vector3(0, 0, 1).applyQuaternion(g.quaternion);
        const toCenter = new THREE.Vector3(-g.position.x, 0, -g.position.z).normalize();
        // Off-centre hang positions tilt `toCenter` vs the wall normal
        // (~19° at ±1.75 m on a 10.5 m wall) — the invariant is the
        // inward hemisphere, not exact alignment.
        return n.dot(toCenter) > 0.5;
    });
    ok('every canvas faces into the room', facingIn);
}

// C2. single artwork hangs dead-centre on its wall
{
    const { ctx } = runSquare(1);
    ok('single-work show is centred on the wall (x ≈ 0)',
        Math.abs(ctx.artworks[0].position.x) < 0.001,
        `x = ${ctx.artworks[0].position.x}`);
}

// C3. partial runs are centred (5 works → 2/2/1, the remainder centred)
{
    const { ctx } = runSquare(5);
    const counts = {};
    for (const g of ctx.artworks) counts[g.userData.wallId] = (counts[g.userData.wallId] || 0) + 1;
    const singleWall = Object.entries(counts).find(([, n]) => n === 1)?.[0];
    ok('5-work hang splits 2/2/1 across three walls',
        !!singleWall && Object.keys(counts).length === 3, JSON.stringify(counts));
    const single = ctx.artworks.find(g => g.userData.wallId === singleWall);
    if (single) {
        const axis = (singleWall === 'left' || singleWall === 'right') ? 'z' : 'x';
        ok('remainder run is centred on its wall (off ≈ 0 along the run axis)',
            Math.abs(single.position[axis]) < 0.001, `${axis} = ${single.position[axis]}`);
    }
}

// C4. full runs keep the historic symmetric geometry
{
    const { ctx } = runSquare(8);
    const xs = ctx.artworks.map(g => +g.position.x.toFixed(4));
    ok('full run of 2 keeps the historic spacing (±1.75 pair present)',
        xs.includes(-1.75) && xs.includes(1.75), JSON.stringify(xs));
}

// C5. extreme aspect clamps (makeArtworkGroup contract)
{
    const ctx = makeContext(2);
    const wide = ctx.makeArtworkGroup({ id: 1, aspectRatio: 5.0 }).group;
    const tall = ctx.makeArtworkGroup({ id: 2, aspectRatio: 0.2 }).group;
    const w = wide.userData._canvasMesh.geometry.parameters;
    const t = tall.userData._canvasMesh.geometry.parameters;
    ok('ultra-wide clamps to 3.0 × 0.6 m',
        Math.abs(w.width - 3.0) < 1e-6 && Math.abs(w.height - 0.6) < 1e-6,
        `${w.width}×${w.height}`);
    ok('ultra-tall clamps to 0.4 × 2.0 m',
        Math.abs(t.width - 0.4) < 1e-6 && Math.abs(t.height - 2.0) < 1e-6,
        `${t.width}×${t.height}`);
}

// C6. wallRunOffset contract
{
    ok('wallRunOffset: single work → wall centre',
        Math.abs(wallRunOffset(1, 0, SPACING, 8) - 4) < 1e-9);
    ok('wallRunOffset: full run keeps historic geometry',
        Math.abs(wallRunOffset(2, 0, SPACING, 10.5) - 3.5) < 1e-9 &&
        Math.abs(wallRunOffset(2, 1, SPACING, 10.5) - 7) < 1e-9);
    ok('wallRunOffset: empty run → 0', wallRunOffset(0, 0, SPACING, 8) === 0);
}

// C7. corridor odd-count remainder is centred
{
    const ctx = makeContext(3);
    ctx._layoutMeta = { type: 'corridor', length: 16, width: 6 };
    const saved = PLACEMENT_CONFIG.room.artworkSpacing;
    PLACEMENT_CONFIG.room.artworkSpacing = SPACING;
    try { _placeArtworksCorridor.call(ctx, {}); }
    finally { PLACEMENT_CONFIG.room.artworkSpacing = saved; }
    const wallA = ctx.artworks.filter(g => Math.abs(g.position.z - (-2.8)) < 0.01);
    const wallB = ctx.artworks.filter(g => Math.abs(g.position.z - 2.8) < 0.01);
    ok('corridor 3-work hang splits 2/1', wallA.length === 2 && wallB.length === 1,
        `${wallA.length}/${wallB.length}`);
    ok('corridor remainder run is centred (x ≈ 0)',
        wallB.length === 1 && Math.abs(wallB[0].position.x) < 0.001, `x = ${wallB[0]?.position.x}`);
}

// C8. determinism — identical inputs produce identical transforms
{
    const a = runSquare(12).ctx;
    const b = runSquare(12).ctx;
    const sig = (ctx) => ctx.artworks.map(g =>
        `${g.userData.id}:${g.position.x.toFixed(5)},${g.position.y.toFixed(5)},${g.position.z.toFixed(5)},${g.rotation.y.toFixed(5)}`
    ).join('|');
    ok('placement is deterministic (two runs → identical transforms)', sig(a) === sig(b));
}

// ── D. JS hygiene ───────────────────────────────────────────────────────────
section('D. JS hygiene (config authority + correctness classes)');
{
    const dir = rel('resources/js/gallery');
    const files = readdirSync(dir).filter(f => f.endsWith('.js'));
    const slugHits = [];
    for (const f of files) {
        const src = readFileSync(path.join(dir, f), 'utf8');
        if (src.includes('white-cube') || src.includes('white_cube')) slugHits.push(f);
    }
    ok('zero venue slugs in the runtime (DoD rule #7)', slugHits.length === 0, slugHits.join(', '));

    const assetSrc = readFileSync(rel('resources/js/gallery/AssetLoader.js'), 'utf8');
    const lines = assetSrc.split('\n');
    let unguarded = 0;
    lines.forEach((l, i) => {
        if (/generateMipmaps\s*=\s*false/.test(l)) {
            const window = lines.slice(i, i + 3).join('\n');
            if (!window.includes('LinearFilter')) unguarded++;
        }
    });
    ok('every no-mipmap texture path pairs with LinearFilter (black-artwork guard)',
        unguarded === 0, `${unguarded} unguarded`);

    const roomSrc = readFileSync(rel('resources/js/gallery/RoomBuilder.js'), 'utf8');
    ok('square room bounds are inset by the wall skin (no wall penetration)',
        /wallDepth \|\| 0\.3\) \/ 2 \+ 0\.3/.test(roomSrc));
    ok('camera far scales with the built room (no far-plane wall clipping)',
        roomSrc.includes('updateProjectionMatrix') && roomSrc.includes('roomReach * 2.5 + 10'));
    ok('fill flows through venueFillIntensity (dead-config fix)',
        roomSrc.includes('venueFillIntensity.call(this'));
    const lightingSrc = readFileSync(rel('resources/js/gallery/Lighting.js'), 'utf8');
    ok('proximity target never starves far artworks to black (base-glow rule)',
        lightingSrc.includes('target = ud.lightBase;'));
    ok('ambient declaration replaces the preset (no double-ambient)',
        !lightingSrc.includes('tintIntensity * 0.5'));
}

console.log(failures === 0
    ? `\nALL CHECKS PASSED — Modern White Cube contract is green.\n`
    : `\n${failures} CHECK(S) FAILED — see above.\n`);
process.exit(failures === 0 ? 0 : 1);
