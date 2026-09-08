#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// zen-gallery-qa.mjs — the venue QA gate for Japanese Zen Gallery v2.
//
//   node scripts/venue-qa/zen-gallery-qa.mjs
//
// Same layering as white-cube-qa.mjs / infinite-void-qa.mjs /
// industrial-loft-qa.mjs / dark-museum-qa.mjs: this file pins CONTRACTS
// (config authority, geometry invariants, parity, determinism),
// tests/Feature/VenueZenIterationTest.php pins the DB side, and
// scripts/harness/shoot.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the zen-gallery row declares the v2 "Quiet
//      Procession" identity: structure_pass 'bays' + proportions, the
//      warm-paper atmosphere, the procession rig, texture_tint authority,
//      the DECLARED-ABSENT environment ('none' + env_intensity 0 — no sky
//      can leak in), artwork legibility, placement curation, post-fx
//      restraint, linear-only supported_layouts, sumi-ink frames.
//   B. DB↔harness sync — the PHP-less harness renders the same JSON a
//      fresh install seeds (same pairs, equal values).
//   C. Geometry invariants — driven through the REAL modules:
//        • THE SIGNATURE GUARANTEE: every artwork sits CENTRED between its
//          two flanking fins (±2 cm) on every supported layout, at any
//          count — the bay architecture derives from the same run plan the
//          placer consumes (squareRunPlan / lshapeRowPlan).
//        • No timber/recess geometry ever intersects a canvas (clipping).
//        • Every artwork fits its bay with margin (no pierce of fins).
//        • The clerestory band, display steps and (square) rafters exist;
//          the whole architecture costs ≤ 6 draw calls (merged).
//        • Low-end tier: same silhouettes in Lambert (degradation parity).
//        • Determinism: rebuilding twice produces identical geometry.
//   D. JS hygiene — zero venue slugs in runtime code; the 'bays' pass is
//      selected by config only.
//   E. Authority/parity — 'bays' + 'structure' are venue-owned exporter
//      keys (a stale gallery override cannot reshape the architecture),
//      the venue request vocabulary admits 'bays', the guarded migration
//      exists and pins the same v2 description, and the runtime patch
//      guard consumes the SHIPPED lists (no JS-side second copy).
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
section('A. Seeder contract (zen-gallery row)');
const seederSrc = readFileSync(rel('database/seeders/VenueTemplateSeeder.php'), 'utf8');

function zenChunk() {
    const i = seederSrc.indexOf("'slug'          => 'zen-gallery'");
    if (i === -1) throw new Error('zen-gallery row not found in seeder');
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

const chunk = zenChunk();
const vc = arrayLiteral(chunk, 'visual_config');
const mc = arrayLiteral(chunk, 'material_config');

ok('structure_pass is the bays interpreter selector', /'structure_pass'\s*=>\s*'bays'/.test(vc));
ok('bays proportion block declared (fin_top 3.12 clears the focal hero)',
    /'fin_top'\s*=>\s*3\.12/.test(vc) && /'clerestory_height'\s*=>\s*0\.24/.test(vc));
ok('environment is DECLARED ABSENCE (none) — no sky can leak in',
    /'environment'\s*=>\s*'none'/.test(vc));
ok('env_intensity declared 0 (a declared 0 stays 0 — nullish authority)',
    /'env_intensity'\s*=>\s*0,/.test(vc));
ok('texture_tint declared (declared colours are authoritative)',
    /'texture_tint'\s*=>\s*true/.test(mc));
ok('wall tint declared (warm limewash 0xe6dfcf)', /'wall_color'\s*=>\s*'0xe6dfcf'/.test(mc));
ok('floor tint declared (pale cedar 0xa98d64)', /'floor_color'\s*=>\s*'0xa98d64'/.test(mc));
ok('floor reads at tatami scale (floor_tile_meters 1.8)', /'floor_tile_meters'\s*=>\s*1\.8/.test(mc));
ok('rig declared procession-bright (exposure 0.95, ambient 0.5)',
    /'tone_mapping_exposure'\s*=>\s*0\.95/.test(vc) && /'ambient_intensity'\s*=>\s*0\.5/.test(vc));
ok('warm ambient separated from neutral artwork pool (ambient 0xfff2dd)',
    /'ambient_color'\s*=>\s*'0xfff2dd'/.test(vc));
ok('fog dissolves into warm light (near 18, far 60)',
    /'fog_near'\s*=>\s*18/.test(vc) && /'fog_far'\s*=>\s*60/.test(vc));
ok('artwork standing glow declared (0.25)', /'artwork_light_base'\s*=>\s*0\.25/.test(vc));
ok('artwork light pool raised (10)', /'artwork_light_pool_cap'\s*=>\s*10/.test(vc));
ok('hemisphere softened (0.1)', /'hemisphere_intensity'\s*=>\s*0\.1/.test(vc));
ok('placement curation declared (generous + focal front + pairing)',
    /'density'\s*=>\s*'generous'/.test(vc) && /'focal_wall'\s*=>\s*'front'/.test(vc) && /'pair_orientation'\s*=>\s*true/.test(vc));
ok('post_fx declares restraint (bloom off)',
    /'post_fx'/s.test(vc) && /'bloom'\s*=>\s*false/.test(vc));
ok('frame_override sumi ink (black)', /'frame_override'\s*=>\s*'black'/.test(vc));
ok('rotunda DROPPED from supported_layouts (linear procession)',
    /'supported_layouts'\s*=>\s*\['square',\s*'corridor',\s*'l-shape'\]/.test(chunk));
ok('no absolute-coordinate props remain (the v1 structure array is gone)',
    !/shoji-a-top/.test(chunk) && !/\'structure\'/.test(chunk));
ok('version bumped (2.x)', /'version'\s*=>\s*'2\.\d+\.\d+'/.test(chunk));
ok('description promises only what renders (framed bays / paper band / cedar)',
    /framed bays/.test(chunk) && /paper band/.test(chunk) && /cedar/.test(chunk));

// ── B. DB↔harness sync ──────────────────────────────────────────────────────
section('B. DB↔harness sync (zen-gallery body)');
const harnessSrc = readFileSync(rel('scripts/harness/harness.html'), 'utf8');
const hStart = harnessSrc.indexOf("'zen-gallery': {");
ok('harness declares the zen-gallery body', hStart !== -1);
if (hStart !== -1) {
    const hEnd = harnessSrc.indexOf('version:', hStart);
    const hChunk = harnessSrc.slice(hStart, hEnd);
    const syncPairs = [
        ['wall_height',           /wall_height:\s*([\d.]+)/,           /'wall_height'\s*=>\s*([\d.]+)/],
        ['background_color',      /background_color:\s*'(0x[0-9a-f]+)'/, /'background_color'\s*=>\s*'(0x[0-9a-f]+)'/],
        ['fog_near',              /fog_near:\s*(\d+)/,                 /'fog_near'\s*=>\s*(\d+)/],
        ['fog_far',               /fog_far:\s*(\d+)/,                  /'fog_far'\s*=>\s*(\d+)/],
        ['ambient_intensity',     /ambient_intensity:\s*([\d.]+)/,     /'ambient_intensity'\s*=>\s*([\d.]+)/],
        ['spot_intensity',        /spot_intensity:\s*([\d.]+)/,        /'spot_intensity'\s*=>\s*([\d.]+)/],
        ['tone_mapping_exposure', /tone_mapping_exposure:\s*([\d.]+)/, /'tone_mapping_exposure'\s*=>\s*([\d.]+)/],
        ['environment',           /environment:\s*'(\w+)'/,            /'environment'\s*=>\s*'(\w+)'/],
        ['structure_pass',        /structure_pass:\s*'(\w+)'/,         /'structure_pass'\s*=>\s*'(\w+)'/],
        ['wall_color',            /wall_color:\s*'(0x[0-9a-f]+)'/,     /'wall_color'\s*=>\s*'(0x[0-9a-f]+)'/],
        ['floor_color',           /floor_color:\s*'(0x[0-9a-f]+)'/,    /'floor_color'\s*=>\s*'(0x[0-9a-f]+)'/],
        ['floor_tile_meters',     /floor_tile_meters:\s*([\d.]+)/,     /'floor_tile_meters'\s*=>\s*([\d.]+)/],
        ['artwork_light_base',    /artwork_light_base:\s*([\d.]+)/,    /'artwork_light_base'\s*=>\s*([\d.]+)/],
    ];
    for (const [name, hRe, sRe] of syncPairs) {
        const h = hChunk.match(hRe)?.[1];
        const s = chunk.match(sRe)?.[1];
        ok(`sync ${name} (${h} === ${s})`, h !== undefined && h === s);
    }
    ok('harness drops rotunda too',
        !/rotunda/.test(hChunk.match(/supported_layouts:[^\n]+/)?.[0] ?? 'rotunda'));
}

// ── C. Geometry invariants (REAL modules) ───────────────────────────────────
section('C. Geometry invariants (real placer + real bays pass)');
const THREE = (await import(pathToFileURL(rel('node_modules/three/build/three.module.js')))).default
    ?? (await import(pathToFileURL(rel('node_modules/three/build/three.module.js'))));
const { CONFIG } = await import(pathToFileURL(rel('resources/js/gallery/config.js')));
const Placer = await import(pathToFileURL(rel('resources/js/gallery/ArtworkPlacer.js')));
const Decorator = await import(pathToFileURL(rel('resources/js/gallery/VenueDecorator.js')));

const decoratorSrc = readFileSync(rel('resources/js/gallery/VenueDecorator.js'));

ok("dispatcher selects 'bays' by config (zero slug knowledge)",
    /pass === 'bays'/.test(decoratorSrc) && /addBaysStructure/.test(decoratorSrc));
ok('the pass consumes the shared square run plan',
    /squareRunPlan\(/.test(decoratorSrc));
ok('the pass consumes the shared l-shape row plan',
    /lshapeRowPlan\(/.test(decoratorSrc));

const FIN_W = 0.16, FIN_D = 0.14, FIN_TOP = 3.12;

const makeZenCtx = (imageCount, layoutMeta, isLowEnd = false) => {
    const meshes = [];
    const aspects = Array.from({ length: imageCount }, (_, i) => [0.667, 1.5, 1][i % 3]);
    const artworkImages = aspects.map(a => ({ aspectRatio: a }));
    return {
        ctx: {
            scene: { children: [], add(o) { meshes.push(o); this.children.push(o); } },
            isLowEnd, textures: {},
            registerObstacle: () => {}, clearObstacles: () => {},
            artworks: [], CONFIG,
            _venueVisualConfig: {
                structure_pass: 'bays',
                bays: { fin_width: FIN_W, fin_depth: FIN_D, fin_top: FIN_TOP, header_height: 0.20,
                        recess_lift: 0.012, step_height: 0.08, step_depth: 0.36,
                        clerestory_gap: 0.05, clerestory_height: 0.24 },
            },
            _venueSlug: 'zen-gallery',
            _layoutMeta: layoutMeta,
            artworkImages,
            makeArtworkGroup: Placer.makeArtworkGroup,
            createFrame: () => new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.1, 0.1)),
            placeAndRegister(group) { this.scene.add(group); this.artworks.push(group); },
            addArtworkLight: () => {},
        },
        meshes, artworkImages,
    };
};

// (componentBoxes retained for future cluster-level assertions)
const componentBoxes = (geometry) => {
    const pos = geometry.attributes.position;
    const index = geometry.index;
    const triCount = (index ? index.count : pos.count) / 3;
    const triBoxes = [];
    for (let t = 0; t < triCount; t++) {
        const box = new THREE.Box3();
        for (let k = 0; k < 3; k++) {
            const vi = index ? index.getX(t * 3 + k) : t * 3 + k;
            box.expandByPoint(new THREE.Vector3(pos.getX(vi), pos.getY(vi), pos.getZ(vi)));
        }
        triBoxes.push(box);
    }
    const EPS = 0.01;
    const grown = triBoxes.map(b => b.clone().expandByScalar(EPS));
    const parent = triBoxes.map((_, i) => i);
    const find = (i) => (parent[i] === i ? i : (parent[i] = find(parent[i])));
    for (let i = 0; i < grown.length; i++) {
        for (let j = i + 1; j < grown.length; j++) {
            if (grown[i].intersectsBox(grown[j])) parent[find(i)] = find(j);
        }
    }
    const clusters = new Map();
    triBoxes.forEach((b, i) => {
        const r = find(i);
        if (!clusters.has(r)) clusters.set(r, b.clone());
        else clusters.get(r).union(b);
    });
    return [...clusters.values()];
};
// Decompose merged geometry into per-TRIANGLE AABBs. A box side-face's
// triangle spans the box's full extent in the two face axes, so fin faces
// are identifiable by their y-span (fins are full-height; headers are not)
// — no clustering needed: mergeParts never welds touching boxes, but their
// unioned AABB would be the whole wall (useless for both fin-finding and
// clipping checks).
const triangleBoxes = (geometry) => {
    const pos = geometry.attributes.position;
    const index = geometry.index;
    const triCount = (index ? index.count : pos.count) / 3;
    const triBoxes = [];
    for (let t = 0; t < triCount; t++) {
        const box = new THREE.Box3();
        for (let k = 0; k < 3; k++) {
            const vi = index ? index.getX(t * 3 + k) : t * 3 + k;
            box.expandByPoint(new THREE.Vector3(pos.getX(vi), pos.getY(vi), pos.getZ(vi)));
        }
        triBoxes.push(box);
    }
    return triBoxes;
};
const overlaps = (a, b, tol = 0.005) =>
    a.min.x <= b.max.x - tol && a.max.x >= b.min.x + tol &&
    a.min.y <= b.max.y - tol && a.max.y >= b.min.y + tol &&
    a.min.z <= b.max.z - tol && a.max.z >= b.min.z + tol;

const S = 4.5; // generous — the venue declares it (PlacementCuration preset)
const FACE0 = CONFIG.room.wallDepth / 2 + FIN_D / 2; // fin centre depth off the wall centre plane

// Identify which bayed face an artwork hangs on + its tangent coordinate.
// Mirrors each layout's placer walk exactly (wallId on square; face planes
// on corridor / l-shape). Returns { wall, t, planeFn, tanFn } or null.
function artFace(g, meta) {
    const x = g.position.x, z = g.position.z;
    const wd = CONFIG.room.wallDepth;
    const faceIn = wd / 2 + 0.05; // the placer's wallInset at depth 0.3
    if (meta.type === 'square') {
        const id = g.userData.wallId;
        const L = meta.wallLength;
        const defs = {
            front: { plane: -(L / 2) + FACE0, axis: 'z', tan: x },
            back:  { plane: (L / 2) - FACE0,  axis: 'z', tan: -x },
            left:  { plane: -(L / 2) + FACE0, axis: 'x', tan: -z },
            right: { plane: (L / 2) - FACE0,  axis: 'x', tan: z },
        };
        return id && defs[id] ? { wall: id, ...defs[id] } : null;
    }
    if (meta.type === 'corridor') {
        const { length: Lg, width: W } = meta;
        if (Math.abs(z - (-(W / 2) + faceIn)) < 0.2) return { wall: 'front', plane: -(W / 2) + FACE0, axis: 'z', tan: x };
        if (Math.abs(z - ((W / 2) - faceIn)) < 0.2)  return { wall: 'back',  plane: (W / 2) - FACE0,  axis: 'z', tan: -x };
        return null;
    }
    if (meta.type === 'l-shape') {
        const { wingW, lenA, jZ, lenB } = meta;
        if (Math.abs(x - faceIn) < 0.2)                       return { wall: 'A0', plane: FACE0, axis: 'x', tan: z };
        if (Math.abs(x - (wingW - faceIn)) < 0.2)             return { wall: 'A1', plane: wingW - FACE0, axis: 'x', tan: z };
        if (Math.abs(z - (jZ + faceIn)) < 0.2)                return { wall: 'B0', plane: jZ + FACE0, axis: 'z', tan: x };
        if (Math.abs(z - (lenA / 2 - faceIn)) < 0.2)          return { wall: 'B1', plane: lenA / 2 - FACE0, axis: 'z', tan: x };
        return null;
    }
    return null;
}

const squareMeta = (n) => ({ type: 'square', wallLength: Math.max(8, Math.ceil(n / 4) * S + S) });
const corridorMeta = (n) => {
    const length = Math.max(16, Math.ceil(n / 2) * S + S);
    return { type: 'corridor', length, width: 6 };
};
const lshapeMeta = (n) => {
    const spacing = S;
    const estCountA = Math.ceil(n * 0.6);
    const lenA = Math.max(12, (Math.ceil(estCountA / 2) * spacing) + spacing);
    const jZ = lenA / 2 - 6;
    const zStart = -lenA / 2 + spacing;
    const zLimit = jZ - spacing / 2;
    let spillFrom = n, sideA = 0, rowA = 0;
    for (let i = 0; i < n; i++) {
        if (zStart + rowA * spacing >= zLimit) { spillFrom = i; break; }
        sideA = 1 - sideA;
        if (sideA === 0) rowA++;
    }
    const actualCountB = n - spillFrom;
    const lenB = Math.max(12, (Math.ceil(actualCountB / 2) * spacing) + spacing * 2);
    return { type: 'l-shape', wingW: 6, lenA, lenB, jZ, zStart, zLimit };
};

let total = 0, centred = 0, clipped = 0, cramped = 0, onBayedFaces = 0;
for (const n of [1, 8, 24, 60]) {
    for (const [name, meta] of [['square', squareMeta(n)], ['corridor', corridorMeta(n)], ['l-shape', lshapeMeta(n)]]) {
        const { ctx, meshes } = makeZenCtx(n, meta);
        Placer.placeArtworks.call(ctx, { imageCount: n, frame_style: 'black' });
        Decorator.addVenueStructure.call(ctx, { imageCount: n });

        const timber = meshes.find(m => m.name === 'bays-timber');
        const paper  = meshes.find(m => m.name === 'bays-paper');
        const step   = meshes.find(m => m.name === 'bays-step');
        const recess = meshes.find(m => m.name === 'bays-recess');
        ok(`${name} n=${n}: bay meshes present (timber/recess/paper/step)`,
            !!timber && !!recess && !!paper && !!step);
        if (!timber) continue;

        const bayMeshes = meshes.filter(m => String(m.name).startsWith('bays-'));
        ok(`${name} n=${n}: architecture costs ≤ 6 draw calls (${bayMeshes.length})`,
            bayMeshes.length <= 6, `got ${bayMeshes.length}`);

        timber.updateMatrixWorld(true); recess.updateMatrixWorld(true);
        const timberTris = triangleBoxes(timber.geometry);
        const recessTris = triangleBoxes(recess.geometry);
        // Fin faces: full-height triangles (headers are 0.2 m bands).
        const finTris = timberTris.filter(b => (b.max.y - b.min.y) > FIN_TOP - 0.3);
        // Dedupe fin positions per face (one tangential coordinate per fin).
        const finCoords = (face) => {
            const coords = [];
            for (const b of finTris) {
                const plane = face.axis === 'z' ? (b.min.z + b.max.z) / 2 : (b.min.x + b.max.x) / 2;
                if (Math.abs(plane - face.plane) >= 0.05) continue;
                const tan = face.axis === 'z' ? (b.min.x + b.max.x) / 2 : (b.min.z + b.max.z) / 2;
                if (!coords.some(c => Math.abs(c - tan) < 0.05)) coords.push(tan);
            }
            return coords.sort((a, b) => a - b);
        };

        for (const g of ctx.artworks) {
            total++;
            g.updateMatrixWorld(true);
            const face = artFace(g, meta);
            if (!face) continue;
            onBayedFaces++;
            const canvas = g.userData._canvasMesh;
            canvas.geometry.computeBoundingBox();
            const canvasBox = canvas.geometry.boundingBox.clone().applyMatrix4(canvas.matrixWorld);

            // Fins on THIS face (analytic positions from the fin triangles).
            const coords = finCoords(face);
            const left  = [...coords].filter(c => c < face.tan - 0.05).pop();
            const right = coords.find(c => c > face.tan + 0.05);

            if (left !== undefined && right !== undefined) {
                const gapL = face.tan - left, gapR = right - face.tan;
                if (Math.abs(gapL - gapR) < 0.02) centred++;
                const bayWidth = (gapL + gapR) - FIN_W;
                const alongX = face.axis === 'z';
                const artSpan = alongX
                    ? (canvasBox.max.x - canvasBox.min.x)
                    : (canvasBox.max.z - canvasBox.min.z);
                if (artSpan < bayWidth - 0.05) { /* fits with margin */ }
                else cramped++;
            }
            // Clipping: NO timber or recess triangle may enter the canvas
            // box (the canvas is a zero-depth plane — only geometry that
            // spans it registers).
            const hitTimber = timberTris.some(b => overlaps(b, canvasBox));
            const hitRecess = recessTris.some(b => overlaps(b, canvasBox));
            if (hitTimber || hitRecess) clipped++;
        }
    }
}
ok(`every hang sits on a bayed face (${onBayedFaces}/${total})`, onBayedFaces === total);
ok(`THE SIGNATURE: every artwork centred between its fins (${centred}/${onBayedFaces} within ±2 cm)`,
    centred === onBayedFaces, `${onBayedFaces - centred} off-centre`);
ok(`no architecture clips any artwork (${total - clipped}/${total} clean)`,
    clipped === 0, `${clipped} intersecting`);
ok(`every artwork fits its bay with margin (${total - cramped}/${total})`,
    cramped === 0, `${cramped} cramped`);

// ── Determinism: rebuild twice → identical timber geometry ──────────────────
{
    const meta = squareMeta(8);
    const a = makeZenCtx(8, meta);
    Placer.placeArtworks.call(a.ctx, { imageCount: 8, frame_style: 'black' });
    Decorator.addVenueStructure.call(a.ctx, { imageCount: 8 });
    const b = makeZenCtx(8, meta);
    Placer.placeArtworks.call(b.ctx, { imageCount: 8, frame_style: 'black' });
    Decorator.addVenueStructure.call(b.ctx, { imageCount: 8 });
    const ga = a.meshes.find(m => m.name === 'bays-timber').geometry;
    const gb = b.meshes.find(m => m.name === 'bays-timber').geometry;
    const pa = Array.from(ga.attributes.position.array), pb = Array.from(gb.attributes.position.array);
    ok('determinism: two rebuilds produce identical bay geometry',
        pa.length === pb.length && pa.every((v, i) => v === pb[i]));
}

// ── Low-end degradation parity ──────────────────────────────────────────────
{
    const meta = squareMeta(8);
    const { ctx, meshes } = makeZenCtx(8, meta, true);
    Placer.placeArtworks.call(ctx, { imageCount: 8, frame_style: 'black' });
    Decorator.addVenueStructure.call(ctx, { imageCount: 8 });
    const timber = meshes.find(m => m.name === 'bays-timber');
    ok('low-end tier: the same bay silhouettes exist (Lambert)',
        !!timber && timber.material.isMeshLambertMaterial === true);
    const paper = meshes.find(m => m.name === 'bays-paper');
    ok('low-end tier: the clerestory glow survives (the venue light identity)',
        !!paper && (paper.material.emissiveIntensity ?? 0) > 0);
}

// ── D. JS hygiene ───────────────────────────────────────────────────────────
section('D. JS hygiene');
{
    const runtimeFiles = [
        'resources/js/gallery/VenueDecorator.js',
        'resources/js/gallery/ArtworkPlacer.js',
        'resources/js/gallery/StructureBuilder.js',
        'resources/js/gallery/RoomBuilder.js',
        'resources/js/gallery/Lighting.js',
        'resources/js/gallery/Materials.js',
        'resources/js/gallery/config.js',
        'resources/js/gallery/GalleryScene.js',
    ];
    for (const f of runtimeFiles) {
        const src = readFileSync(rel(f), 'utf8');
        ok(`${path.basename(f)} stays slug-free ('zen-gallery' absent)`,
            !src.includes("'zen-gallery'") && !src.includes('"zen-gallery"'));
    }
}

// ── E. Authority + parity ───────────────────────────────────────────────────
section('E. Authority + parity');
const exporterSrc = readFileSync(rel('app/Services/VenueConfigExporter.php'), 'utf8');
ok("'bays' is a venue-owned exporter key (architecture cannot be overridden)",
    /'structure',\s*'bays'/.test(exporterSrc));
ok("exporter SCHEMA bumped to s6 (s5 zen pass + s6 nebula palette; cached payloads re-key on deploy)",
    /public const SCHEMA = 's6'/.test(exporterSrc));
const requestSrc = readFileSync(rel('app/Http/Requests/SuperAdmin/VenueTemplateRequest.php'), 'utf8');
ok("venue request vocabulary admits 'bays'",
    /'rooms',\s*'cube',\s*'loft',\s*'museum',\s*'bays',\s*'garden',\s*'phenomena'/.test(requestSrc));
const migrationPath = 'database/migrations/2026_09_07_000002_zen_gallery_deepening.php';
ok('the guarded deepening migration exists', existsSync(rel(migrationPath)));
const migrationSrc = readFileSync(rel(migrationPath), 'utf8');
ok('migration writes the same v2 description as the seeder', (() => {
    const desc = chunk.match(/'description'\s*=>\s*'([^']+)'/)?.[1];
    return !!desc && migrationSrc.includes(desc);
})());
ok('migration rewrites the environment to none (guarded from studio)',
    /'environment'\s*=>\s*\['from' => 'studio',\s*'to' => 'none'/.test(migrationSrc) &&
    /'env_intensity'\s*=>\s*0,/.test(migrationSrc));
ok('migration drops rotunda from supported_layouts (guarded)',
    /\['square',\s*'rotunda',\s*'l-shape'\]/.test(migrationSrc));
const sceneSrc = readFileSync(rel('resources/js/gallery/GalleryScene.js'), 'utf8');
ok('runtime patch guard consumes the SHIPPED owned lists (no JS copy to drift)',
    /venue_owned_visual/.test(sceneSrc) && /venue_owned_material/.test(sceneSrc));

// ── Report ──────────────────────────────────────────────────────────────────
console.log('\n' + '─'.repeat(66));
if (failures === 0) console.log('✅ zen-gallery-qa: ALL CHECKS PASSED');
else { console.error(`❌ zen-gallery-qa: ${failures} check(s) FAILED`); process.exit(1); }
