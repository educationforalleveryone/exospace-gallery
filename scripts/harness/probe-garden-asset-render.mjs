// probe-garden-asset-render.mjs — minimal standalone render of the garden's
// ASSET LAYER: verifies GLB decode (DRACO+WebP), height normalization,
// instancing placement, foliage alpha cutout and per-role scale — decoupled
// from the full venue scene (which SwiftShader cannot rasterize in QA time).
//   node scripts/harness/probe-garden-asset-render.mjs
import { createServer } from 'node:http';
import { readFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = 4239;
const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.json': 'application/json',
    '.glb': 'model/gltf-binary', '.wasm': 'application/wasm', '.webp': 'image/webp',
    '.jpg': 'image/jpeg', '.png': 'image/png' };

const vendor = {
    '/probe-vendor/three.module.js': 'node_modules/three/build/three.module.js',
    '/probe-vendor/three.core.js': 'node_modules/three/build/three.core.js',
    '/probe-vendor/GLTFLoader.js': 'node_modules/three/examples/jsm/loaders/GLTFLoader.js',
    '/probe-vendor/DRACOLoader.js': 'node_modules/three/examples/jsm/loaders/DRACOLoader.js',
    '/utils/BufferGeometryUtils.js': 'node_modules/three/examples/jsm/utils/BufferGeometryUtils.js',
    '/probe-vendor/GardenLayout.js': 'resources/js/gallery/GardenLayout.js',
    '/probe-vendor/Rng.js': 'resources/js/gallery/Rng.js',
};
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
        const p = decodeURIComponent(url.pathname);
        if (p === '/probe.html') { res.writeHead(200, { 'Content-Type': 'text/html' }); res.end(pageHtml); return; }
        const rel = vendor[p];
        if (rel && existsSync(path.join(rootDir, rel))) {
            res.writeHead(200, { 'Content-Type': 'text/javascript' });
            res.end(await readFile(path.join(rootDir, rel)));
            return;
        }
        const fp = path.join(publicDir, p);
        if (!fp.startsWith(publicDir) || !existsSync(fp)) { console.log('[404]', p); res.writeHead(404); res.end(); return; }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(fp)] || 'application/octet-stream' });
        res.end(await readFile(fp));
    } catch { res.writeHead(500); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const pageHtmlUnused = `<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{margin:0}</style></head>
<body><script type="module">
import * as THREE from 'three';
import { GLTFLoader } from '/harness/assets/three/addons loaders placeholder.js';
</script></body></html>`;

// The harness bundle exposes three via importmap-less bundling; simplest is a
// CDN-free page importing the installed three from the dev server — but we
// serve only public/. So: build the probe page around the ALREADY BUNDLED
// three chunk shipped in public/build (it's an ES module with imports we can
// reuse via a tiny import map).
const pageHtml = `<!DOCTYPE html><html><head><meta charset="utf-8">
<style>body{margin:0;overflow:hidden}canvas{display:block}</style>
<script type="importmap">
{ "imports": {
    "three": "/probe-vendor/three.module.js",
    "three/addons/loaders/GLTFLoader.js": "/probe-vendor/GLTFLoader.js",
    "three/addons/loaders/DRACOLoader.js": "/probe-vendor/DRACOLoader.js"
} }
</script>
</head><body>
<script type="module">
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader } from 'three/addons/loaders/DRACOLoader.js';

const result = { loaded: [], failed: [], boxes: {} };
window.__probe = result;

const scene = new THREE.Scene();
scene.background = new THREE.Color(0xdfe2d1);

const camera = new THREE.PerspectiveCamera(55, 640 / 360, 0.1, 200);
if (window.__closeup) { camera.position.set(-3.4, 3.4, 1.2); camera.lookAt(-5.6, 4.6, -2.6); }
else if (window.__planMode) { camera.position.set(0, 1.6, 9.2); camera.lookAt(0, 1.8, -4); }
else { camera.position.set(11, 3.2, 13); camera.lookAt(0, 2.2, 0); }

const hemi = new THREE.HemisphereLight(0xbfd9ee, 0x51663c, 0.9);
scene.add(hemi);
const sun = new THREE.DirectionalLight(0xffeecb, 1.6);
sun.position.set(-8, 12, 4);
scene.add(sun);

// a simple lawn
const lawn = new THREE.Mesh(new THREE.CircleGeometry(16, 48), new THREE.MeshLambertMaterial({ color: 0x5e7a46 }));
lawn.rotation.x = -Math.PI / 2;
scene.add(lawn);

const draco = new DRACOLoader();
draco.setDecoderPath('/decoders/');
const loader = new GLTFLoader();
loader.setDRACOLoader(draco);

// one anchor per role, arranged so every asset faces the camera
let roles;
if (window.__planMode) {
    // PLAN MODE — the real GardenLayout composition (harness seed, count=12)
    // with every vegetation anchor filled by its real asset.
    const planMod = await import('/probe-vendor/GardenLayout.js');
    const rngMod = await import('/probe-vendor/Rng.js');
    const plan = planMod.buildGardenPlan({
        radius: 14, count: 12,
        rng: rngMod.createVenueRng('sculpture-garden:harness'),
        config: {},
    });
    const height = plan.terrain.height;
    window.__gardenHeight = height;
    // the lawn
    const lawnGeo = new THREE.CircleGeometry(14, 72);
    const lp = lawnGeo.attributes.position;
    for (let i = 0; i < lp.count; i++) lp.setZ(i, 0);
    const lawn = new THREE.Mesh(lawnGeo, new THREE.MeshLambertMaterial({ color: 0x54713f }));
    lawn.rotation.x = -Math.PI / 2;
    scene.add(lawn);
    // gravel-ish courts (flat colour stand-ins — the real gravel is verified
    // in the full captures; this frame is about the VEGETATION COMPOSITION)
    const court = new THREE.Mesh(new THREE.CircleGeometry(3, 40), new THREE.MeshLambertMaterial({ color: 0xb3a98f }));
    court.rotation.x = -Math.PI / 2; court.position.y = 0.02; scene.add(court);
    const plaza = court.clone(); plaza.position.set(plan.spawn.x, 0.02, plan.spawn.z); scene.add(plaza);
    // hero stand-in
    const drum = new THREE.Mesh(new THREE.CylinderGeometry(0.98, 1.08, 0.55, 24), new THREE.MeshLambertMaterial({ color: 0xbdb5a2 }));
    drum.position.set(0, 0.28, 0); scene.add(drum);
    roles = [];
    const byRole = {};
    for (const t of plan.trees) (byRole[t.role] = byRole[t.role] || []).push([t.x, t.scale, t.z, t.rot, height]);
    for (const sh of plan.shrubs) (byRole[sh.role] = byRole[sh.role] || []).push([sh.x, sh.scale, sh.z, sh.rot, height]);
    for (const b of plan.boulders) (byRole['boulder'] = byRole['boulder'] || []).push([b.x, b.scale, b.z, b.rot, height]);
    const files = { tree_large: ['tree_large_01.glb', 8.5], tree_medium: ['tree_medium_01.glb', 6.5], tree_accent: ['tree_medium_02.glb', 5.0], shrub: ['shrub_01.glb', 1.5], grass: ['grass_clump_01.glb', 0.7], boulder: ['boulder_01.glb', 0.9] };
    for (const [role, anchors] of Object.entries(byRole)) {
        if (!files[role]) continue;
        roles.push([role, files[role][0], files[role][1], anchors]);
    }
    console.log('PLAN anchors: ' + JSON.stringify(Object.fromEntries(Object.entries(byRole).map(([k, v]) => [k, v.length]))));
} else {
    roles = [
        ['tree_large',  'tree_large_01.glb',  8.5, [-6.5, 0, -3]],
        ['tree_medium', 'tree_medium_01.glb', 6.5, [-2.0, 0, -6]],
        ['tree_accent', 'tree_medium_02.glb', 5.0, [3.5, 0, -5]],
        ['shrub',       'shrub_01.glb',       1.5, [6.0, 0, -1.5]],
        ['grass',       'grass_clump_01.glb', 0.7, [4.2, 0, 1.5]],
        ['boulder',     'boulder_01.glb',     0.9, [1.5, 0, 2.5]],
    ];
}

const renderer = new THREE.WebGLRenderer({ antialias: false, preserveDrawingBuffer: true });
renderer.setSize(640, 360);
document.body.appendChild(renderer.domElement);

Promise.all(roles.map(([role, file, targetH, anchors]) =>
    new Promise((resolve) => {
        loader.load('/assets/venues/sculpture-garden/' + file, (gltf) => {
            const proto = gltf.scene;
            const box = new THREE.Box3().setFromObject(proto);
            const size = new THREE.Vector3(); box.getSize(size);
            const nrm = targetH / (size.y || 1);
            const list = (window.__planMode && Array.isArray(anchors[0]) && anchors[0].length === 5)
                ? anchors : [anchors];
            for (const a of list) {
                const obj = window.__planMode && Array.isArray(a) && a.length === 5
                    ? proto.clone()
                    : proto;
                const [x, sc, z, yaw, hfn] = (window.__planMode && Array.isArray(a) && a.length === 5)
                    ? a : [a[0], 1, a[2], 0.6, null];
                obj.scale.setScalar(nrm * sc);
                obj.position.set(x, hfn ? hfn(x, z) - 0.05 * nrm * sc : -0.05, z);
                obj.rotation.y = yaw;
                obj.traverse(o => {
                    if (o.isMesh) {
                        const mats = Array.isArray(o.material) ? o.material : [o.material];
                        for (const m of mats) {
                            if (m.transparent && m.alphaTest === 0) m.alphaTest = 0.35;
                            if (m.metalness !== undefined && m.metalness > 0.05) m.metalness = 0.05;
                            if (m.vertexColors) { m.vertexColors = false; m.needsUpdate = true; }
                        }
                        o.castShadow = false; o.receiveShadow = false;
                    }
                });
                scene.add(obj);
            }
            result.loaded.push({ role, sourceH: +size.y.toFixed(2), normalize: +nrm.toFixed(3), instances: list.length });
            resolve();
        }, undefined, (err) => { result.failed.push({ role, error: String(err).slice(0, 200) }); resolve(); });
    })
)).then(() => {
    let n = 0;
    const tick = () => {
        renderer.render(scene, camera);
        if (++n < 8) requestAnimationFrame(tick);
        else {
            // read back REAL framebuffer pixels at two canopy points + lawn
            const gl = renderer.getContext();
            const w = gl.drawingBufferWidth, h = gl.drawingBufferHeight;
            const px = new Uint8Array(4);
            const read = (x, y) => { gl.readPixels(x, y, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, px); return [px[0], px[1], px[2], px[3]]; };
            result.pixels = {
                canopyLeft: read(Math.round(w * 0.38), Math.round(h * 0.62)),
                canopyRight: read(Math.round(w * 0.63), Math.round(h * 0.60)),
                lawn: read(Math.round(w * 0.5), Math.round(h * 0.15)),
            };
            result.meshCount = (() => { let c = 0; scene.traverse(o => { if (o.isMesh) c++; }); return c; })();
            result.done = true;
            console.log('PROBE_DONE ' + JSON.stringify(result));
        }
    };
    tick();
});
</script></body></html>`;

const { chromium } = await import('playwright');
const browser = await chromium.launch({ args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader'] });
const page2 = await browser.newPage({ viewport: { width: 640, height: 360 } });
const logs = [];
page2.on('console', m => logs.push(m.text()));
page2.on('pageerror', e => console.log('[pageerror]', String(e).slice(0, 400)));
const STRIP = process.env.STRIP_MAPS === '1';
const RED = process.env.RED_TEST === '1';
const CLOSEUP = process.env.CLOSEUP === '1';
if (STRIP) await page2.addInitScript(() => { window.__stripMaps = true; });
if (RED) await page2.addInitScript(() => { window.__redTest = true; });
if (CLOSEUP) await page2.addInitScript(() => { window.__closeup = true; });
const PLAN = process.env.PLAN_MODE === '1';
if (PLAN) {
    await page2.addInitScript(() => { window.__planMode = true; });
    await page2.setViewportSize({ width: 960, height: 540 });
}
await page2.goto(`http://127.0.0.1:${PORT}/probe.html`, { waitUntil: 'load' });

let done = false;
for (let i = 0; i < 30; i++) {
    await page2.waitForTimeout(3000);
    if (logs.some(l => l.includes('PROBE_DONE'))) { done = true; break; }
}
const payload = logs.find(l => l.includes('PROBE_DONE'));
if (payload) {
    const data = JSON.parse(payload.replace('PROBE_DONE ', ''));
    console.log('loaded:', JSON.stringify(data.loaded));
    console.log('failed:', JSON.stringify(data.failed));
    const shot = await page2.locator('canvas').screenshot().catch(() => null);
    if (shot) await writeFile(path.join(rootDir, 'shots-garden-v4/asset-render-probe.png'), shot);
    console.log('screenshot:', shot ? 'saved shots-garden-v4/asset-render-probe.png' : 'FAILED');
} else {
    console.log('PROBE NEVER FINISHED');
}
await browser.close();
server.close();
process.exit(done ? 0 : 1);
