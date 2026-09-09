#!/usr/bin/env node
// probe-csp.mjs — proves the CSP `connect-src blob:` fix mechanism both ways
// (garden-iteration-5, user §15/§24: do not weaken CSP without understanding).
//
// The three.js GLTFLoader converts every GLB-EMBEDDED image into a blob:
// object URL and loads it through ImageBitmapLoader, which fetch()es the URL.
// fetch() is governed by connect-src. This probe loads the SAME GLB twice
// under two policies and captures the console both times:
//
//   run 1 — connect-src 'self'            → the production incident:
//                                           CSP violations + "Couldn't load
//                                           texture blob:…" + texture=null
//   run 2 — connect-src 'self' blob:      → the shipped fix:
//                                           zero violations, texture applied
//
//   node scripts/harness/probe-csp.mjs
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = 4246;
const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');
const MIME = { '.glb': 'model/gltf-binary', '.wasm': 'application/wasm', '.js': 'text/javascript' };

const vendor = {
    '/probe-vendor/three.module.js': 'node_modules/three/build/three.module.js',
    '/probe-vendor/three.core.js': 'node_modules/three/build/three.core.js',
    '/probe-vendor/GLTFLoader.js': 'node_modules/three/examples/jsm/loaders/GLTFLoader.js',
    '/probe-vendor/DRACOLoader.js': 'node_modules/three/examples/jsm/loaders/DRACOLoader.js',
    '/utils/BufferGeometryUtils.js': 'node_modules/three/examples/jsm/utils/BufferGeometryUtils.js',
};
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
        const p = decodeURIComponent(url.pathname);
        if (p === '/probe.html') {
            res.writeHead(200, {
                'Content-Type': 'text/html',
                // The policy under test arrives per-run via /policy
                'Content-Security-Policy': currentPolicy,
            });
            res.end(pageHtml);
            return;
        }
        const rel = vendor[p];
        if (rel && existsSync(path.join(rootDir, rel))) {
            res.writeHead(200, { 'Content-Type': 'text/javascript' });
            res.end(await readFile(path.join(rootDir, rel)));
            return;
        }
        const fp = path.join(publicDir, p);
        if (!fp.startsWith(publicDir) || !existsSync(fp)) { res.writeHead(404); res.end(); return; }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(fp)] || 'application/octet-stream' });
        res.end(await readFile(fp));
    } catch { res.writeHead(500); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

let currentPolicy = "";
const pageHtml = `<!DOCTYPE html><html><head><meta charset="utf-8">
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

const out = { textures: { total: 0, loaded: 0, failed: 0 } };
window.__cspProbe = out;
const draco = new DRACOLoader();
draco.setDecoderPath('/decoders/draco/');
const loader = new GLTFLoader();
loader.setDRACOLoader(draco);
try {
    const gltf = await loader.loadAsync('/assets/venues/sculpture-garden/bench_01.glb');
    gltf.scene.traverse((o) => {
        if (!o.isMesh) return;
        const mats = Array.isArray(o.material) ? o.material : [o.material];
        for (const m of mats) {
            const slots = ['map', 'normalMap', 'roughnessMap', 'metalnessMap', 'aoMap'];
            for (const s of slots) {
                if (m[s]) { out.textures.total++; if (m[s].image) out.textures.loaded++; else out.textures.failed++; }
            }
        }
    });
} catch (e) {
    out.error = String(e).slice(0, 160);
}
window.__cspDone = true;
</script></body></html>`;

const { chromium } = await import('playwright');
const browser = await chromium.launch({ args: ['--enable-unsafe-swiftshader'] });

async function runOnce(policy) {
    currentPolicy = policy;
    const page = await (await browser.newContext()).newPage();
    const consoleLines = [];
    page.on('console', (m) => {
        const t = m.text();
        if (m.type() === 'error' || t.includes('blob:') || t.includes('Content Security Policy') || t.includes('GLTFLoader')) {
            consoleLines.push(`[${m.type()}] ${t.slice(0, 140)}`);
        }
    });
    await page.goto(`http://127.0.0.1:${PORT}/probe.html`, { waitUntil: 'load' });
    await page.waitForFunction(() => window.__cspDone === true, null, { timeout: 30000 }).catch(() => {});
    const result = await page.evaluate(() => JSON.parse(JSON.stringify(window.__cspProbe))).catch(() => ({ textures: { total: -1, loaded: -1, failed: -1 } }));
    await page.close();
    return { result, consoleLines };
}

const blocked = await runOnce("connect-src 'self'");
const allowed = await runOnce("connect-src 'self' blob:");
await browser.close();
server.close();

console.log('── RUN 1  connect-src \'self\'  (the production incident)');
console.log('   textures:', JSON.stringify(blocked.result.textures), blocked.result.error ? `error: ${blocked.result.error}` : '');
console.log('   console:'); blocked.consoleLines.slice(0, 5).forEach(l => console.log('     ' + l));
console.log('── RUN 2  connect-src \'self\' blob:  (the shipped fix)');
console.log('   textures:', JSON.stringify(allowed.result.textures), allowed.result.error ? `error: ${allowed.result.error}` : '');
console.log('   console:'); allowed.consoleLines.slice(0, 5).forEach(l => console.log('     ' + l) || (allowed.consoleLines.length === 0 && console.log('     (clean — no CSP/texture errors)')));
if (allowed.consoleLines.length === 0) console.log('     (clean — no CSP/texture errors)');

// PASS = the incident reproduces under the old policy (CSP errors + no
// texture ever attaches → total 0) and the fix loads every texture cleanly.
const incidentReproduced = blocked.consoleLines.some(l => l.includes('Content Security Policy') || l.includes("Couldn't load texture"))
    && blocked.result.textures.total === 0;
const fixWorks = allowed.result.textures.total > 0
    && allowed.result.textures.loaded === allowed.result.textures.total
    && allowed.consoleLines.length === 0;
console.log(incidentReproduced && fixWorks
    ? '\nCSP PROBE PASS — blob: is required AND sufficient for GLB-embedded textures.'
    : '\nCSP PROBE INCONCLUSIVE — inspect above.');
process.exit(incidentReproduced && fixWorks ? 0 : 1);
