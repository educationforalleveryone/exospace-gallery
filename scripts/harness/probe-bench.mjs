#!/usr/bin/env node
// probe-bench.mjs — standalone render of the sculpture garden's authored
// bench_01.glb (garden-iteration-5). Verifies: DRACO+WebP decode through the
// SAME decoder path the app uses (/decoders/draco/), height normalization to
// the manifest role target (0.85 m), material sanity under a daylight rig,
// and silhouette quality from the two angles a visitor actually sees.
//   node scripts/harness/probe-bench.mjs        → shots-bench/*.png
import { createServer } from 'node:http';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = 4244;
const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');
const OUT = process.argv[2] || 'shots-bench';
const MIME = { '.glb': 'model/gltf-binary', '.wasm': 'application/wasm', '.js': 'text/javascript' };

const vendor = {
    '/probe-vendor/three.module.js': 'node_modules/three/build/three.module.js',
    '/probe-vendor/three.core.js': 'node_modules/three/build/three.core.js',
    '/probe-vendor/GLTFLoader.js': 'node_modules/three/examples/jsm/loaders/GLTFLoader.js',
    '/probe-vendor/DRACOLoader.js': 'node_modules/three/examples/jsm/loaders/DRACOLoader.js',
    // GLTFLoader's internal relative import ('../utils/BufferGeometryUtils.js')
    // resolves against /probe-vendor/ → /utils/… — keep the graph alive.
    '/utils/BufferGeometryUtils.js': 'node_modules/three/examples/jsm/utils/BufferGeometryUtils.js',
    '/loaders/DRACOLoader.js': 'node_modules/three/examples/jsm/loaders/DRACOLoader.js',
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

const pageHtml = `<!DOCTYPE html><html><head><meta charset="utf-8">
<style>body{margin:0;overflow:hidden}canvas{display:block}</style>
<script type="importmap">
{ "imports": {
    "three": "/probe-vendor/three.module.js",
    "three/addons/loaders/GLTFLoader.js": "/probe-vendor/GLTFLoader.js",
    "three/addons/loaders/DRACOLoader.js": "/probe-vendor/DRACOLoader.js"
} }
</script></head><body>
<script type="module">
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader } from 'three/addons/loaders/DRACOLoader.js';

const result = { loaded: false, error: null, size: null, tris: 0, viewsRendered: 0 };
window.__probe = result;

const scene = new THREE.Scene();
scene.background = new THREE.Color(0xdfe2d1);

const camera = new THREE.PerspectiveCamera(45, 960 / 540, 0.05, 200);
const hemi = new THREE.HemisphereLight(0xbfd9ee, 0x51663c, 0.9);
scene.add(hemi);
const sun = new THREE.DirectionalLight(0xffeecb, 1.6);
sun.position.set(-8, 12, 4);
scene.add(sun);
const fill = new THREE.DirectionalLight(0xdfe8f0, 0.5);
fill.position.set(6, 4, 6);
scene.add(fill);

// gravel-ish ground + lawn hint
const lawn = new THREE.Mesh(new THREE.CircleGeometry(9, 48), new THREE.MeshLambertMaterial({ color: 0x5e7a46 }));
lawn.rotation.x = -Math.PI / 2; scene.add(lawn);
const court = new THREE.Mesh(new THREE.CircleGeometry(1.7, 40), new THREE.MeshLambertMaterial({ color: 0xb3a98f }));
court.rotation.x = -Math.PI / 2; court.position.y = 0.01; scene.add(court);

const draco = new DRACOLoader();
draco.setDecoderPath('/decoders/draco/');
const loader = new GLTFLoader();
loader.setDRACOLoader(draco);

const bench = await new Promise((resolve, reject) => {
    loader.load('/assets/venues/sculpture-garden/bench_01.glb',
        (g) => resolve(g.scene), undefined,
        (e) => { result.error = String(e); reject(e); });
});
result.loaded = true;

// Normalize EXACTLY like GardenAssets: bbox height → role target 0.85 m
const box = new THREE.Box3().setFromObject(bench);
const size = new THREE.Vector3(); box.getSize(size);
result.size = { x: +size.x.toFixed(3), y: +size.y.toFixed(3), z: +size.z.toFixed(3) };
const nrm = 0.85 / (size.y || 1);
bench.scale.setScalar(nrm);
bench.position.y = -0.04;
bench.traverse((o) => {
    if (o.isMesh) {
        const idx = o.geometry.getIndex();
        result.tris += (idx ? idx.count : o.geometry.attributes.position.count) / 3;
    }
});
scene.add(bench);

const renderer = new THREE.WebGLRenderer({ antialias: false, preserveDrawingBuffer: true });
renderer.setSize(960, 540);
document.body.appendChild(renderer.domElement);

// Views: front three-quarter (the approach), rear three-quarter, side profile,
// plus a 60 cm closeup that proves the wood grain actually ships in the GLB.
const views = [
    ['front3q',  [ 1.9, 1.05,  2.1]],
    ['rear3q',   [-1.9, 1.05, -2.1]],
    ['side',     [ 2.6, 0.85,  0.0]],
    ['closeup',  [ 0.7, 0.62,  0.9]],
];
window.__benchReady = false;
window.__benchShot = null;
let vi = 0;
// Views are DRIVER-ADVANCED (window.__benchAdvance()) — an interval would
// race the screenshot loop and label every frame one view behind.
window.__benchAdvance = function () {
    if (vi >= views.length) { window.__benchReady = true; return false; }
    const [name, p] = views[vi];
    camera.position.set(p[0], p[1], p[2]);
    camera.lookAt(0, name === 'closeup' ? 0.55 : 0.45, 0);
    camera.updateMatrixWorld();
    renderer.render(scene, camera);
    result.viewsRendered++;
    window.__benchShot = name;
    vi++;
    return true;
};
window.__benchAdvance();
</script></body></html>`;

// ── Drive with headless Chromium (SwiftShader, same as shoot.mjs) ────────────
const { chromium } = await import('playwright');
await mkdir(path.resolve(rootDir, OUT), { recursive: true });

const browser = await chromium.launch({
    args: ['--enable-unsafe-swiftshader', '--use-gl=swiftshader', '--disable-gpu-sandbox',
           '--disable-renderer-backgrounding'],
});
const page = await (await browser.newContext({ viewport: { width: 960, height: 540 } })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push('PAGEERROR: ' + String(e)));
page.on('console', (m) => {
    const t = m.type();
    if (t === 'error' || t === 'warning') errors.push(t.toUpperCase() + ': ' + m.text());
});

await page.goto(`http://127.0.0.1:${PORT}/probe.html`, { waitUntil: 'load' });
await page.waitForFunction(() => window.__probe?.loaded === true, null, { timeout: 30000 })
    .catch(() => errors.push('bench never decoded'));
const booted = await page.evaluate(() => typeof window.__probe !== 'undefined');

const taken = [];
for (let i = 0; i < 4; i++) {
    const name = await page.evaluate(() => window.__benchShot);
    await page.waitForTimeout(500);
    const shot = path.resolve(rootDir, OUT, `bench-${name || i}.png`);
    await page.screenshot({ path: shot });
    taken.push(shot);
    await page.evaluate(() => window.__benchAdvance());
}
const probe = booted ? await page.evaluate(() => JSON.parse(JSON.stringify(window.__probe))) : null;
await browser.close();
server.close();

console.log('probe result:', JSON.stringify(probe));
console.log('console errors:', errors.length ? errors : 'none');
console.log('shots:', taken.join(', '));
