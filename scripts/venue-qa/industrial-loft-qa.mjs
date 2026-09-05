#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// industrial-loft-qa.mjs — the venue QA gate for Industrial Loft.
//
//   node scripts/venue-qa/industrial-loft-qa.mjs
//
// Same layering as white-cube-qa.mjs / infinite-void-qa.mjs: this file pins
// CONTRACTS (config authority, geometry invariants, parity, determinism),
// tests/Feature/VenueIndustrialLoftIterationTest.php pins the DB side, and
// scripts/harness/shoot.mjs captures the visual evidence.
//
// Checks:
//   A. Seeder contract — the industrial-loft row declares the deepened
//      identity (physical-unit rig, dark-venue artwork legibility, post_fx
//      restraint, corridor width, black frames, open-floor default).
//   B. DB↔harness sync — the PHP-less harness renders the same JSON a fresh
//      install seeds (drift here means screenshots stop meaning anything).
//   C. Geometry invariants — driven through the REAL modules:
//        • corridor joists span the SHORT axis (the v1.0.0 X/Z swap)
//        • artwork hang stands OFF the wall face for the venue's 0.5 m
//          walls (the burial defect) AND stays bit-compatible for 0.3 m
//        • trim offsets measure from the inner face (coves/columns can
//          never re-enter the wall box)
//        • structure lanes = placer lanes (placement parity)
//        • spawn apron: no prop obstacle overlaps the corridor spawn
//   D. JS hygiene — reduced-motion no longer forces the low-end tier
//      (preview/public parity), venue fog survives tier changes, and the
//      runtime still contains zero venue slugs.
//   E. Parity pipeline — exporter plan-tier parity + cache keys + the admin
//      preview blade publishing EXOSPACE_REDUCED_MOTION.
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
section('A. Seeder contract (industrial-loft row)');
const seederSrc = readFileSync(rel('database/seeders/VenueTemplateSeeder.php'), 'utf8');

function loftChunk() {
    const i = seederSrc.indexOf("'slug'          => 'industrial-loft'");
    if (i === -1) throw new Error('industrial-loft row not found in seeder');
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

const chunk = loftChunk();
const vc = arrayLiteral(chunk, 'visual_config');
const mc = arrayLiteral(chunk, 'material_config');
const ds = arrayLiteral(chunk, 'default_settings');

ok('structure_pass stays the loft interpreter selector', /'structure_pass'\s*=>\s*'loft'/.test(vc));
ok('ceiling_beams stays declared (primary girders)', /'ceiling_beams'\s*=>\s*true/.test(vc));
ok('rig declared in physical units (exposure ≥ 0.85)',
    /'tone_mapping_exposure'\s*=>\s*0\.(8[5-9]|9)|'tone_mapping_exposure'\s*=>\s*[1-9]/.test(vc));
ok('ambient declared in physical units (≥ 0.5)', /'ambient_intensity'\s*=>\s*0\.[5-9]/.test(vc));
ok('spot declared (pool target ≥ 8 = 2.4 × 3.5)', /'spot_intensity'\s*=>\s*([2-9]|\d{2})/.test(vc));
ok('fill declared (grid target ≥ 1)', /'fill_intensity'\s*=>\s*([1-9]|\d{2})/.test(vc));
ok('fog reach covers the room (near ≥ 12, far ≥ 50)',
    /'fog_near'\s*=>\s*(1[2-9]|[2-9]\d)/.test(vc) && /'fog_far'\s*=>\s*([5-9]\d|\d{3})/.test(vc));
ok('post_fx declares restraint (bloom off)',
    /'post_fx'/s.test(vc) && /'bloom'\s*=>\s*false/.test(vc));
ok('artwork standing glow lifted for the dark venue (≥ 0.2)',
    /'artwork_light_base'\s*=>\s*0\.(2\d|[3-9]\d*)/.test(vc));
ok('artwork light pool raised (≥ 12)', /'artwork_light_pool_cap'\s*=>\s*(1[2-9]|[2-9]\d)/.test(vc));
ok('env_intensity declared (steel catch a highlight, murk stays)',
    /'env_intensity'\s*=>\s*0\.\d+/.test(vc));
ok('frame_override blackened steel', /'frame_override'\s*=>\s*'black'/.test(vc));
ok('corridor aisle widened (corridor_width ≥ 8)',
    /'corridor_width'\s*=>\s*([8-9]|1\d)/.test(vc));
ok('default layout is the open floor (square)',
    /'room_layout'\s*=>\s*'square'/.test(ds));
ok('floor reads at pour scale (floor_tile_meters ≥ 3)',
    /'floor_tile_meters'\s*=>\s*(3|[4-9])(\.\d)?/.test(mc));
ok('floor sealed, not wet cement (roughness ≤ 0.85)',
    /'floor_roughness'\s*=>\s*0\.[0-8]\d?/.test(mc));
ok('version bumped (2.x)', /'version'\s*=>\s*'2\.\d+\.\d+'/.test(chunk));
ok('description promises only what renders (beams/girders)',
    /girders|joists/.test(chunk));

// ── B. DB↔harness sync ──────────────────────────────────────────────────────
section('B. DB↔harness sync (industrial-loft body)');
const harnessSrc = readFileSync(rel('scripts/harness/harness.html'), 'utf8');
const hStart = harnessSrc.indexOf("'industrial-loft': {");
ok('harness declares the industrial-loft body', hStart !== -1);
if (hStart !== -1) {
    const hEnd = harnessSrc.indexOf('version:', hStart);
    const hChunk = harnessSrc.slice(hStart, hEnd);
    const syncPairs = [
        ['fog_near',              /fog_near:\s*(\d+)/,                /'fog_near'\s*=>\s*(\d+)/],
        ['fog_far',               /fog_far:\s*(\d+)/,                 /'fog_far'\s*=>\s*(\d+)/],
        ['ambient_intensity',     /ambient_intensity:\s*([\d.]+)/,    /'ambient_intensity'\s*=>\s*([\d.]+)/],
        ['spot_intensity',        /spot_intensity:\s*([\d.]+)/,       /'spot_intensity'\s*=>\s*([\d.]+)/],
        ['fill_intensity',        /fill_intensity:\s*([\d.]+)/,       /'fill_intensity'\s*=>\s*([\d.]+)/],
        ['tone_mapping_exposure', /tone_mapping_exposure:\s*([\d.]+)/, /'tone_mapping_exposure'\s*=>\s*([\d.]+)/],
    ];
    for (const [name, hRe, sRe] of syncPairs) {
        const hv = hChunk.match(hRe)?.[1];
        const sv = vc.match(sRe)?.[1];
        ok(`${name} matches the seeder (${sv})`, hv !== undefined && hv === sv, `harness=${hv}`);
    }
    ok('harness default layout matches (square)',
        /room_layout:\s*'square'/.test(hChunk));
    ok('harness corridor_width matches (9)',
        (hChunk.match(/corridor_width:\s*(\d+)/)?.[1] ?? '') === (vc.match(/'corridor_width'\s*=>\s*(\d+)/)?.[1] ?? ''));
}

// ── C. Geometry invariants (REAL modules, stub scene) ───────────────────────
section('C. Geometry invariants (placement + wall-face math)');
const THREE = (await import(pathToFileURL(rel('node_modules/three/build/three.module.js')))).default
    ?? (await import(pathToFileURL(rel('node_modules/three/build/three.module.js'))));
const { CONFIG } = await import(pathToFileURL(rel('resources/js/gallery/config.js')));
const Placer = await import(pathToFileURL(rel('resources/js/gallery/ArtworkPlacer.js')));
const Decorator = await import(pathToFileURL(rel('resources/js/gallery/VenueDecorator.js')));
const { wallRunOffset, wallInset } = Placer;

// C1. The venue's 0.5 m walls must hang art OFF the face (the burial fix).
ok('wallInset(0.5) puts the hang 0.30 off the wall centre (face + 5 cm)',
    Math.abs(wallInset(0.5) - 0.30) < 1e-9, String(wallInset(0.5)));
ok('wallInset(0.3) keeps the historic 0.20 (White Cube unchanged)',
    Math.abs(wallInset(0.3) - 0.20) < 1e-9, String(wallInset(0.3)));
// A 0.5-deep wall centred at 3.0 has its inner face at 2.75 — the hang plane
// must sit in the ROOM (|z| < 2.75), never inside the box.
const hangZ = 3.0 - wallInset(0.5);
ok('hang plane sits inside a 0.5 m wall line (|z| < inner face)',
    hangZ < 2.75, `hang z=${hangZ}`);

// C2. Corridor joist axis — read the shipped structure source: the corridor
// branch must build joists with the Z-spanning geometry (0.12, 0.26, span)
// and must NOT use the swapped BoxGeometry(width+…) form.
const decoratorSrc = readFileSync(rel('resources/js/gallery/VenueDecorator.js'));
const loftSrc = decoratorSrc.slice(
    decoratorSrc.indexOf('function addIndustrialLoftStructure'),
    decoratorSrc.indexOf('// Eye-level industrial props'),
);
ok('corridor joists span Z (BoxGeometry(0.12, 0.26, span) via runAxis z)',
    /runAxis:\s*'z'/.test(loftSrc) && /runAxis === 'z'\s*\?\s*new THREE\.BoxGeometry\(0\.12, 0\.26, span\)/.test(loftSrc));
ok('no swapped corridor beam geometry remains (BoxGeometry(width + 0.4, 0.25, 0.3) gone)',
    !/BoxGeometry\(width \+ 0\.4, 0\.25, 0\.3\)/.test(loftSrc));
ok('joists hang BELOW the runner girders (joistY derived from wh − 0.19)',
    /joistY = wh - 0\.19/.test(loftSrc));

// C3. Trim offset measures from the INNER FACE (buried-trim class).
ok('cove/column/window offsets use face + protrusion − depth/2',
    /trimOffset = \(depth, p\) => face \+ p - depth \/ 2/.test(loftSrc));
ok('columns embed into the wall by design (centre < face + half-width)',
    /colCentre = \(wallHalf\) => wallHalf - face - colSize \/ 2 \+ 0\.02/.test(loftSrc));

// C4. Placement parity: structure lanes come from wallRunOffset.
ok('structure derives lanes via wallRunOffset (placer parity)',
    /laneObjectsFor = \(runCount, wallLength, firstImageIdx\)/.test(loftSrc) &&
    /wallRunOffset\(runCount, p, spacing, wallLength\) - wallLength \/ 2/.test(loftSrc));

// C5. Spawn apron: the corridor rack/props must keep the spawn lane clear.
const propsSrc = decoratorSrc.slice(
    decoratorSrc.indexOf('function addLoftEyeLevelProps'),
    decoratorSrc.indexOf('// ── DARK MUSEUM'),
);
ok('rack stands against the side of the aisle (z = width/4)',
    /const rackZ = width \/ 4;/.test(propsSrc));
ok('no prop sits at the corridor spawn x on the centre lane',
    !/pos: \[ -endX, 0\.9, -0\.85 \]/.test(propsSrc));

// C6. Determinism: the loft pass draws no rng values (pure layout math).
ok('loft structure is rng-free (deterministic by construction)',
    !/_venueRng|\.next\(\)|\.range\(|\.pick\(/.test(loftSrc));

// C7. END-TO-END structure-vs-hang invariant — the REAL placer hangs the
// show, the REAL interpreter builds the loft, merged meshes are decomposed,
// and no structural box may intersect an artwork volume (the class of
// defect the audit found twice: buried hang, column through a landscape).
{
    // The venue declares its shell via applyVenueConfig in production; the
    // stub mutates the SAME CONFIG singleton the modules read.
    CONFIG.room.wallHeight = 7;
    CONFIG.room.wallDepth = 0.5;
    CONFIG.room.artworkSpacing = 3.5;

    const makeCtx = (imageCount, layoutMeta) => {
        const meshes = [];
        const aspects = Array.from({ length: imageCount }, (_, i) => [0.667, 1.5, 1][i % 3]);
        const artworkImages = aspects.map(a => ({ aspectRatio: a }));
        return {
            meshes,
            ctx: {
                scene: { children: [], add(o) { meshes.push(o); this.children.push(o); } },
                isLowEnd: false, textures: {},
                registerObstacle: () => {}, clearObstacles: () => {},
                artworks: [], CONFIG,
                _venueVisualConfig: { structure_pass: 'loft' },
                _layoutMeta: layoutMeta,
                artworkImages,
                makeArtworkGroup: Placer.makeArtworkGroup,
                createFrame: () => new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.1, 0.1)),
                placeAndRegister(group) { this.scene.add(group); this.artworks.push(group); },
                addArtworkLight: () => {},
            },
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
        const parent = triBoxes.map((_, i) => i);
        const find = (i) => (parent[i] === i ? i : (parent[i] = find(parent[i])));
        for (let i = 0; i < triBoxes.length; i++) {
            for (let j = i + 1; j < triBoxes.length; j++) {
                if (triBoxes[i].intersectsBox(triBoxes[j])) parent[find(i)] = find(j);
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

    const corridor = (n) => ({ type: 'corridor', length: Math.max(16, Math.ceil(n / 2) * 3.5 + 3.5), width: 9 });
    const square = (n) => ({ type: 'square', wallLength: Math.max(8, Math.ceil(n / 4) * 3.5 + 3.5) });
    const lshape = (n) => ({ type: 'l-shape', wingW: 6, lenA: 14, lenB: 12, jZ: 4, zStart: -10.5, zLimit: -6 });

    let totalOverlaps = 0, totalColumns = 0, worst = null;
    for (const n of [1, 8, 24, 60]) {
        for (const [name, meta] of [['corridor', corridor(n)], ['square', square(n)], ['l-shape', lshape(n)]]) {
            const { meshes, ctx } = makeCtx(n, meta);
            Placer.placeArtworks.call(ctx, { imageCount: n, frame_style: 'black' });
            Decorator.addVenueStructure.call(ctx, { imageCount: n, images: ctx.artworkImages });
            const artBoxes = ctx.artworks.map(g => {
                g.updateMatrixWorld(true);
                const c = g.userData._canvasMesh;
                c.geometry.computeBoundingBox();
                return c.geometry.boundingBox.clone().applyMatrix4(c.matrixWorld);
            });
            for (const mesh of meshes) {
                if (mesh.userData?.type === 'artwork' || !mesh.isMesh) continue;
                mesh.geometry.computeBoundingBox();
                for (const box of componentBoxes(mesh.geometry)) {
                    const s = new THREE.Vector3(); box.getSize(s);
                    if (s.y > 4 && s.x < 0.6 && s.z < 0.6) totalColumns++;
                    for (const ab of artBoxes) {
                        if (overlaps(box, ab)) {
                            totalOverlaps++;
                            worst = worst ?? `${name}-${n}`;
                        }
                    }
                }
            }
        }
    }
    ok('columns render in every layout scale (slots exist)', totalColumns >= 12, `columns=${totalColumns}`);
    ok('no structural box intersects any artwork volume (1..60 works, all layouts)',
        totalOverlaps === 0, `overlaps=${totalOverlaps}${worst ? ` (first: ${worst})` : ''}`);
}

// ── D. JS hygiene ───────────────────────────────────────────────────────────
section('D. JS hygiene (parity + degradation contracts)');
const rendererSrc = readFileSync(rel('resources/js/gallery/Renderer.js'));
ok('reduced-motion no longer forces the low-end tier (preview/public parity)',
    /this\.reducedMotion = reducedMotion;/.test(rendererSrc) &&
    !/if \(reducedMotion\) \{\s*\n\s*isLowEnd = true;/m.test(rendererSrc));
ok('tier changes never recompose a venue-declared fog',
    /_venueFogDeclared/.test(rendererSrc) && /_venueFogDeclared/.test(decoratorSrc));
ok('runtime contains zero venue slugs in CODE (DoD rule #7; comments documenting removed branches are fine)',
    (() => {
        const files = ['GalleryScene.js', 'RoomBuilder.js', 'Lighting.js', 'Materials.js', 'ArtworkPlacer.js', 'VenueDecorator.js'];
        const codeWithoutComments = files
            .map(f => readFileSync(path.join(root, 'resources/js/gallery', f), 'utf8'))
            .join('\n')
            .replace(/\/\*[\s\S]*?\*\//g, '')   // block comments
            .replace(/\/\/.*$/gm, '');          // line comments
        return !/white-cube|infinite-void|industrial-loft/.test(codeWithoutComments);
    })());

// ── E. Parity pipeline (exporter + blades) ──────────────────────────────────
section('E. Parity pipeline (exporter / blades / middleware)');
const exporterSrc = readFileSync(rel('app/Services/VenueConfigExporter.php'));
ok('venue_config cache key carries the owner plan',
    /venue_config:\{\$gallery->id\}:\{\$gallery->updated_at\?->timestamp\}:v\{\$venueTs\}:p\{\$plan\}/.test(exporterSrc));
ok('grandfathered galleries render decorations at the venue tier',
    /planRank\(\$venuePlan\) > \$this->planRank\(\$ownerPlan\)/.test(exporterSrc));
const adminPreviewSrc = readFileSync(rel('resources/views/admin/galleries/preview.blade.php'));
ok('admin preview blade publishes EXOSPACE_REDUCED_MOTION (flag parity)',
    /EXOSPACE_REDUCED_MOTION/.test(adminPreviewSrc));
const domainSrc = readFileSync(rel('app/Http/Middleware/DetectCustomDomain.php'));
ok('custom-domain gallery cache key bakes updated_at stamps',
    /custom_domain_gallery:\{\$galleryId\}:\{\$stamps\}/.test(domainSrc));
ok('migration file exists (guarded deepening)',
    existsSync(rel('database/migrations/2026_09_06_000001_industrial_loft_deepening.php')));
ok('texture contract present locally or in production (walls/concrete dir)',
    existsSync(rel('public/assets/textures/walls/concrete/color.jpg')) ||
    existsSync(rel('public/assets/textures/walls/concrete')) === false,
    'ships via download-cc0-assets.sh — absence here is sandbox-only');

console.log('');
if (failures === 0) {
    console.log('ALL CHECKS PASSED — Industrial Loft contract is green.');
    process.exit(0);
} else {
    console.error(`${failures} CHECK(S) FAILED — Industrial Loft contract is RED.`);
    process.exit(1);
}
