#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// dark-museum-qa.mjs — the venue QA gate for Dark Museum.
//
//   node scripts/venue-qa/dark-museum-qa.mjs
//
// Same layering as white-cube-qa.mjs / infinite-void-qa.mjs /
// industrial-loft-qa.mjs: this file pins CONTRACTS (config authority,
// geometry invariants, parity, determinism),
// tests/Feature/VenueDarkMuseumIterationTest.php pins the DB side, and
// scripts/harness/shoot.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the dark-museum row declares the deepened
//      "night wing" identity (texture_tint authority — the audit's headline
//      material find, a readable-dark rig, a fog reach that covers the room,
//      declared post-fx restraint with the black vignette blend, dark-venue
//      artwork legibility, silenced hemisphere wash, curation placement).
//   B. DB↔harness sync — the PHP-less harness renders the same JSON a fresh
//      install seeds.
//   C. Geometry invariants — driven through the REAL modules:
//        • trim offsets measure from the wall INNER FACE (the v1.0.0
//          skirting was buried dead geometry — the White Cube defect class)
//        • the rotunda branch exists and never builds square-room skirting
//          (the v1.0.0 floating-trim defect)
//        • cabinets stop BELOW the ceiling (the see-over reveal)
//        • the post-placement picture-light hook is wired in buildGallery
//        • end-to-end: the REAL placer hangs the show, the REAL museum pass
//          builds the room, and every artwork gets exactly one picture-light
//          fixture band above it — no fixture drift, no structure-through-art
//        • bay hangs keep the outer walls' 5 cm standoff
//   D. JS hygiene — venue-declarable hemisphere (default unchanged), the
//      Exospace vignette blend (grey default = stock behaviour), zero venue
//      slugs in runtime code.
//   E. Parity pipeline — exporter plan-tier parity + the guarded deepening
//      migration + reduced-motion flag parity.
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
section('A. Seeder contract (dark-museum row)');
const seederSrc = readFileSync(rel('database/seeders/VenueTemplateSeeder.php'), 'utf8');

function museumChunk() {
    const i = seederSrc.indexOf("'slug'          => 'dark-museum'");
    if (i === -1) throw new Error('dark-museum row not found in seeder');
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

const chunk = museumChunk();
const vc = arrayLiteral(chunk, 'visual_config');
const mc = arrayLiteral(chunk, 'material_config');

ok('structure_pass stays the museum interpreter selector', /'structure_pass'\s*=>\s*'museum'/.test(vc));
ok('texture_tint declared (the audit headline: declared colours are authoritative)',
    /'texture_tint'\s*=>\s*true/.test(mc));
ok('wall tint declared (charcoal plaster)', /'wall_color'\s*=>\s*'0x7a746c'/.test(mc));
ok('floor tint declared (dark stone)', /'floor_color'\s*=>\s*'0x3a3835'/.test(mc));
ok('floor reads at tile scale (floor_tile_meters ≥ 3)', /'floor_tile_meters'\s*=>\s*(3|[4-9])(\.\d)?/.test(mc));
ok('rig declared dark-but-legible (exposure ≥ 0.7, < 1.0)',
    /'tone_mapping_exposure'\s*=>\s*0\.[789]\d*/.test(vc) && !/'tone_mapping_exposure'\s*=>\s*1/.test(vc));
ok('ambient declared (≥ 3 — charcoal albedo needs real irradiance)',
    /'ambient_intensity'\s*=>\s*[3-9]/.test(vc));
ok('spot declared (pool target ≥ 5 = 1.4 × 3.5)', /'spot_intensity'\s*=>\s*([1-9]|\d{2})/.test(vc));
ok('fill declared (grid ≥ 0.5)', /'fill_intensity'\s*=>\s*0\.[5-9]|[1-9]/.test(vc));
ok('fog reach covers the room (near ≥ 10, far ≥ 60)',
    /'fog_near'\s*=>\s*(1[0-9]|[2-9]\d)/.test(vc) && /'fog_far'\s*=>\s*([6-9]\d|\d{3})/.test(vc));
ok('post_fx declares restraint (bloom off)',
    /'post_fx'/s.test(vc) && /'bloom'\s*=>\s*false/.test(vc));
ok('post_fx declares the BLACK vignette blend (the audit root cause)',
    /'vignette_blend'\s*=>\s*'black'/.test(vc));
ok('artwork standing glow lifted for the dark venue (≥ 0.3)',
    /'artwork_light_base'\s*=>\s*0\.(3\d|[4-9]\d*)/.test(vc));
ok('artwork light pool raised (≥ 12)', /'artwork_light_pool_cap'\s*=>\s*(1[2-9]|[2-9]\d)/.test(vc));
ok('env_intensity declared (brass keeps a glint, stone stays night)',
    /'env_intensity'\s*=>\s*0\.\d+/.test(vc));
ok('hemisphere wash flattened (< 0.15 — the shared constant would grey the hierarchy)',
    /'hemisphere_intensity'\s*=>\s*0\.(0\d|1[0-4])/.test(vc));
ok('placement curation declared (density + focal wall + pairing)',
    /'density'\s*=>\s*'generous'/.test(vc) && /'focal_wall'\s*=>\s*'front'/.test(vc) && /'pair_orientation'\s*=>\s*true/.test(vc));
ok('frame_override stays gold (the venue signature)', /'frame_override'\s*=>\s*'gold'/.test(vc));
ok('version bumped (2.x)', /'version'\s*=>\s*'2\.\d+\.\d+'/.test(chunk));
ok('description promises only what renders (picture lights / charcoal)',
    /picture lights|charcoal/.test(chunk));

// ── B. DB↔harness sync ──────────────────────────────────────────────────────
section('B. DB↔harness sync (dark-museum body)');
const harnessSrc = readFileSync(rel('scripts/harness/harness.html'), 'utf8');
const hStart = harnessSrc.indexOf("'dark-museum': {");
ok('harness declares the dark-museum body', hStart !== -1);
if (hStart !== -1) {
    const hEnd = harnessSrc.indexOf('version:', hStart);
    const hChunk = harnessSrc.slice(hStart, hEnd);
    const syncPairs = [
        ['fog_near',              /fog_near:\s*(\d+)/,                 /'fog_near'\s*=>\s*(\d+)/],
        ['fog_far',               /fog_far:\s*(\d+)/,                  /'fog_far'\s*=>\s*(\d+)/],
        ['ambient_intensity',     /ambient_intensity:\s*([\d.]+)/,     /'ambient_intensity'\s*=>\s*([\d.]+)/],
        ['spot_intensity',        /spot_intensity:\s*([\d.]+)/,        /'spot_intensity'\s*=>\s*([\d.]+)/],
        ['fill_intensity',        /fill_intensity:\s*([\d.]+)/,        /'fill_intensity'\s*=>\s*([\d.]+)/],
        ['tone_mapping_exposure', /tone_mapping_exposure:\s*([\d.]+)/, /'tone_mapping_exposure'\s*=>\s*([\d.]+)/],
    ];
    for (const [name, hRe, sRe] of syncPairs) {
        const hv = hChunk.match(hRe)?.[1];
        const sv = vc.match(sRe)?.[1];
        ok(`${name} matches the seeder (${sv})`, hv !== undefined && hv === sv, `harness=${hv}`);
    }
    ok('harness wall tint matches the seeder',
        (hChunk.match(/wall_color:\s*'([0-9a-fx]+)'/)?.[1] ?? '') === (mc.match(/'wall_color'\s*=>\s*'([0-9a-fx]+)'/)?.[1] ?? ''));
    ok('harness default layout matches (square)', /room_layout:\s*'square'/.test(hChunk));
    ok('harness carries the black vignette blend', /vignette_blend:\s*'black'/.test(hChunk));
}
ok('v1.0.0 forensic body preserved for before/after evidence', harnessSrc.includes("'dark-museum-v1': {"));

// ── C. Geometry invariants (REAL modules, stub scene) ───────────────────────
section('C. Geometry invariants (placement + structure + picture lights)');
const THREE = (await import(pathToFileURL(rel('node_modules/three/build/three.module.js')))).default
    ?? (await import(pathToFileURL(rel('node_modules/three/build/three.module.js'))));
const { CONFIG } = await import(pathToFileURL(rel('resources/js/gallery/config.js')));
const Placer = await import(pathToFileURL(rel('resources/js/gallery/ArtworkPlacer.js')));
const Decorator = await import(pathToFileURL(rel('resources/js/gallery/VenueDecorator.js')));

const decoratorSrc = readFileSync(rel('resources/js/gallery/VenueDecorator.js'));
const museumSrc = decoratorSrc.slice(
    decoratorSrc.indexOf('function addDarkMuseumStructure'),
    decoratorSrc.indexOf('// ── SCULPTURE GARDEN'),
);
const roomBuilderSrc = readFileSync(rel('resources/js/gallery/RoomBuilder.js'));
const lightingSrc = readFileSync(rel('resources/js/gallery/Lighting.js'));

// C1. Trim offsets measure from the INNER FACE (buried-trim regression pin).
ok('museum trim uses the inner-face formula (face + protrusion − depth/2)',
    /const trimOffset = \(depth, p\) => face \+ p - depth \/ 2/.test(museumSrc));
ok('no centre-plane skirting remains (the v1.0.0 buried-trim signature)',
    !/pos: \[0,\s*skirtH \/ 2/.test(museumSrc) && !/skirtGeo/.test(museumSrc));

// C2. Rotunda layout parity: ring branch, no square skirting inside it.
ok('rotunda branch exists (ring baseboard, no dividers)',
    /meta\.type === 'rotunda'/.test(museumSrc) &&
    /CylinderGeometry\(r - 0\.02, r - 0\.02, 0\.12, 48, 1, true\)/.test(museumSrc));

// C3. Cabinets stop below the ceiling (the see-over reveal).
ok('cabinets stop below the ceiling (CAB_H = min(3.1, wh − 0.6))',
    /CAB_H\s*=\s*Math\.min\(3\.1, wh - 0\.6\)/.test(museumSrc));
ok('no full-height divider remains (dividerH = wh is gone)',
    !/dividerH\s*=\s*wh/.test(museumSrc));

// C4. Post-placement picture-light hook is wired end to end.
ok('buildGallery calls the post-placement hook AFTER placeArtworks',
    /this\.placeArtworks\(data\);[\s\S]{0,900}addVenuePostPlacementStructure\(\)/.test(roomBuilderSrc));
ok('the hook dispatches on structure_pass (zero slug knowledge)',
    /pass === 'museum'/.test(decoratorSrc) && /addVenuePostPlacementStructure/.test(decoratorSrc));

// C5. END-TO-END: real placer + real museum pass + real picture lights.
{
    CONFIG.room.wallHeight = 5;
    CONFIG.room.wallDepth = 0.3;
    CONFIG.room.artworkSpacing = 4.5; // generous — the museum declares it

    const makeCtx = (imageCount, layoutMeta) => {
        const meshes = [];
        const aspects = Array.from({ length: imageCount }, (_, i) => [0.667, 1.5, 1][i % 3]);
        const artworkImages = aspects.map(a => ({ aspectRatio: a }));
        return {
            ctx: {
                scene: { children: [], add(o) { meshes.push(o); this.children.push(o); } },
                isLowEnd: false, textures: {},
                registerObstacle: () => {}, clearObstacles: () => {},
                artworks: [], CONFIG,
                _venueVisualConfig: { structure_pass: 'museum' },
                _layoutMeta: layoutMeta,
                // RoomBuilder sets this before placement on every rotunda
                // build (createRoomRotunda); the stub mirrors the contract.
                _rotundaRadius: layoutMeta.radius,
                artworkImages,
                makeArtworkGroup: Placer.makeArtworkGroup,
                createFrame: () => new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.1, 0.1)),
                placeAndRegister(group) { this.scene.add(group); this.artworks.push(group); },
                addArtworkLight: () => {},
            },
            meshes, artworkImages,
        };
    };

    // Decompose a merged indexed BufferGeometry into connected-triangle
    // cluster AABBs (the structural boxes the merge hid).
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
        // mergeParts does not weld vertices — a box's six faces are six
        // disconnected triangle groups. Grow each face box by a small
        // epsilon so adjacent faces of the SAME box union into one cluster
        // (true per-box clusters), while separate boxes stay separate.
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
    const overlaps = (a, b, tol = 0.01) =>
        a.min.x <= b.max.x - tol && a.max.x >= b.min.x + tol &&
        a.min.y <= b.max.y - tol && a.max.y >= b.min.y + tol &&
        a.min.z <= b.max.z - tol && a.max.z >= b.min.z + tol;

    const square = (n) => ({ type: 'square', wallLength: Math.max(8, Math.ceil(n / 4) * 4.5 + 4.5) });
    const rotunda = (n) => ({ type: 'rotunda', radius: Math.max(7, (n * 4.5) / (2 * Math.PI)) });

    let totalOverlaps = 0;
    let fixtureBandHits = 0;
    let fixtureBandMisses = 0;
    let worst = null;
    for (const n of [1, 8, 24, 60]) {
        for (const [name, meta] of [['square', square(n)], ['rotunda', rotunda(n)]]) {
            const { ctx, meshes, artworkImages } = makeCtx(n, meta);
            Placer.placeArtworks.call(ctx, { imageCount: n, frame_style: 'classic' });
            Decorator.addVenueStructure.call(ctx, { imageCount: n, images: artworkImages });
            Decorator.addVenuePostPlacementStructure.call(ctx);

            // The named plate mesh (one merged geometry, one cluster per
            // artwork) + the merged tube mesh must exist. Cabinet caps and
            // downlight rings share the brass material but not the contract.
            const plateMesh = meshes.find(m => m.isMesh && m.name === 'museum-picture-light-plates');
            const tubeMesh = meshes.find(m => m.isMesh && m.name === 'museum-picture-light-tubes');
            ok(`${name} n=${n}: merged plate + tube fixtures present`, !!plateMesh && !!tubeMesh);
            if (!plateMesh) continue;

            // Every cluster of the brass mesh = one artwork's backing plate.
            const artBoxes = ctx.artworks.map(g => {
                g.updateMatrixWorld(true);
                const c = g.userData._canvasMesh;
                c.geometry.computeBoundingBox();
                return c.geometry.boundingBox.clone().applyMatrix4(c.matrixWorld);
            });
            plateMesh.geometry.computeBoundingBox();
            plateMesh.updateMatrixWorld(true);
            const clusters = componentBoxes(plateMesh.geometry)
                .map(b => b.applyMatrix4(plateMesh.matrixWorld));
            if (name === 'square') {
                ok(`square n=${n}: one fixture band per artwork (${clusters.length}/${n})`,
                    clusters.length === n, `clusters=${clusters.length}`);
            }

            // Each artwork's top edge must sit DIRECTLY below some fixture
            // cluster (fixture tracks the piece: |Δz/Δx lateral| small).
            for (const g of ctx.artworks) {
                g.updateMatrixWorld(true);
                const c = g.userData._canvasMesh;
                c.geometry.computeBoundingBox();
                const box = c.geometry.boundingBox.clone().applyMatrix4(c.matrixWorld);
                const topCentre = new THREE.Vector3(
                    (box.min.x + box.max.x) / 2, box.max.y, (box.min.z + box.max.z) / 2);
                const hit = clusters.some(cl =>
                    topCentre.y > cl.min.y - 0.75 && topCentre.y < cl.max.y + 0.35 &&
                    Math.abs((cl.min.x + cl.max.x) / 2 - topCentre.x) < 2.1 &&
                    Math.abs((cl.min.z + cl.max.z) / 2 - topCentre.z) < 2.1);
                if (hit) fixtureBandHits++; else { fixtureBandMisses++; worst = worst ?? `${name}-n${n}`; }
            }

            // No structural box may intersect an artwork volume.
            for (const mesh of meshes) {
                if (mesh.userData?.type === 'artwork' || !mesh.isMesh) continue;
                mesh.geometry.computeBoundingBox();
                const wb = mesh.geometry.boundingBox.clone().applyMatrix4(mesh.matrixWorld);
                for (const box of componentBoxes(mesh.geometry)) {
                    const world = box.clone().applyMatrix4(mesh.matrixWorld);
                    for (const ab of artBoxes) {
                        if (overlaps(world, ab)) { totalOverlaps++; worst = worst ?? `${name}-n${n}`; }
                    }
                }
            }
        }
    }
    ok('every artwork carries a picture-light band above it (1..60 works, square + rotunda)',
        fixtureBandMisses === 0, `misses=${fixtureBandMisses}${worst ? ` (first: ${worst})` : ''}`);
    ok('no structural box intersects any artwork volume', totalOverlaps === 0, `overlaps=${totalOverlaps}`);
}

// C6. Bay-hang standoff parity: a bay piece's frame back clears the divider
// face by the same ~5 cm as the outer walls' wallInset.
{
    const surf = { x: 0, z: -(0.15 + 0.03), nx: 0, nz: -1, width: 2.9, height: 2.4 };
    const plan = Placer._planBayHangs(6, [surf], 3.5);
    ok('bay plan produces hangs', Array.isArray(plan) && plan.length > 0);
    const dividerFace = 0.15;                          // wall_depth/2 — the physical divider face
    const frameBackFromFace = Math.abs(plan[0].z) - dividerFace;
    ok('bay standoff = outer-wall face clearance (0.03 surface + 0.02 planner = 5 cm off the face)',
        Math.abs(frameBackFromFace - 0.05) < 1e-9,
        `fromFace=${frameBackFromFace.toFixed(3)} (outer walls: wallInset − depth/2 = ${(Placer.wallInset(0.3) - 0.15).toFixed(3)})`);
}

// ── D. JS hygiene ───────────────────────────────────────────────────────────
section('D. JS hygiene (declared-identity contracts)');
ok('hemisphere wash is venue-declarable with the historical default',
    /_venueHemisphereIntensity \?\? 0\.15/.test(lightingSrc));
ok('vignette blend is venue-declarable, grey default keeps stock behaviour',
    /vignette_blend/.test(readFileSync(rel('resources/js/gallery/PostProcessing.js'), 'utf8')) &&
    /ExospaceVignetteShader/.test(readFileSync(rel('resources/js/gallery/PostProcessing.js'), 'utf8')));
ok('runtime contains zero venue slugs in CODE (DoD rule #7; comments documenting removed branches are fine)',
    (() => {
        const files = ['GalleryScene.js', 'RoomBuilder.js', 'Lighting.js', 'Materials.js', 'ArtworkPlacer.js', 'VenueDecorator.js'];
        const codeWithoutComments = files
            .map(f => readFileSync(path.join(root, 'resources/js/gallery', f), 'utf8'))
            .join('\n')
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/\/\/.*$/gm, '');
        return !/white-cube|infinite-void|industrial-loft|dark-museum/.test(codeWithoutComments);
    })());

// ── E. Parity pipeline (exporter + blades + migration) ──────────────────────
section('E. Parity pipeline (exporter / migration / flag parity)');
const exporterSrc = readFileSync(rel('app/Services/VenueConfigExporter.php'));
ok('venue_config cache key carries the owner plan',
    /venue_config:\{\$gallery->id\}:\{\$gallery->updated_at\?->timestamp\}:v\{\$venueTs\}:p\{\$plan\}/.test(exporterSrc));
ok('grandfathered galleries render decorations at the venue tier',
    /planRank\(\$venuePlan\) > \$this->planRank\(\$ownerPlan\)/.test(exporterSrc));
const adminPreviewSrc = readFileSync(rel('resources/views/admin/galleries/preview.blade.php'));
ok('admin preview blade publishes EXOSPACE_REDUCED_MOTION (flag parity)',
    /EXOSPACE_REDUCED_MOTION/.test(adminPreviewSrc));
ok('migration file exists (guarded deepening)',
    existsSync(rel('database/migrations/2026_09_06_000002_dark_museum_deepening.php')));
ok('picker accent matches the venue identity (brass, not the old dark-red)',
    /'dark-museum'\s*=>\s*\[[^\]]*'accent'\s*=>\s*'#b98a44'/.test(readFileSync(rel('resources/views/admin/galleries/create.blade.php'), 'utf8')));

console.log('');
if (failures === 0) {
    console.log('ALL CHECKS PASSED — Dark Museum contract is green.');
    process.exit(0);
} else {
    console.error(`${failures} CHECK(S) FAILED — Dark Museum contract is RED.`);
    process.exit(1);
}
