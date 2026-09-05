#!/usr/bin/env node
// LOCAL-ONLY runtime probe: boots the harness for one scenario and evaluates
// the live scene — asserts no structural piece intersects an artwork volume.
// Usage: node scripts/harness/probe-loft.mjs [query]
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Usage: node scripts/harness/probe-loft.mjs "venue=industrial-loft&count=8&layout=corridor"
const q = process.argv[2] || 'venue=industrial-loft&count=8';
const PORT = 4213;
const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.jpg': 'image/jpeg', '.png': 'image/png', '.hdr': 'application/octet-stream' };
const server = createServer(async (req, res) => {
    try {
        let p = decodeURIComponent(new URL(req.url, `http://x:${PORT}`).pathname);
        if (p.endsWith('/')) p += 'index.html';
        const fp = path.join(publicDir, p);
        if (!fp.startsWith(publicDir) || !existsSync(fp)) { res.writeHead(404); res.end(); return; }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(fp)] || 'application/octet-stream' });
        res.end(await readFile(fp));
    } catch { res.writeHead(500); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const { chromium } = await import('playwright');
const browser = await chromium.launch({ args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-gpu-vsync', '--disable-frame-rate-limit'] });
const ctx = await browser.newContext({ viewport: { width: 480, height: 270 } });
await ctx.addInitScript(`
    Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
    Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
    const o = WebGL2RenderingContext.prototype.getExtension;
    WebGL2RenderingContext.prototype.getExtension = function(n){ return n==='WEBGL_debug_renderer_info'?null:o.call(this,n); };
    let id=0; window.requestAnimationFrame=(cb)=>setTimeout(()=>cb(performance.now()),4);
    window.cancelAnimationFrame=(x)=>clearTimeout(x);
`);
const page = await ctx.newPage();
page.on('pageerror', e => console.error('PAGEERROR:', String(e).slice(0, 300)));
await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?${q}`, { waitUntil: 'load' });
await page.waitForFunction(() => {
    const b = document.getElementById('enter-btn');
    return b && b.style.pointerEvents === 'auto';
}, { timeout: 60000 });
await page.$eval('#enter-btn', el => el.click());
await page.waitForTimeout(6500);

// Use the scene's own THREE via global constructor references
const report3 = await page.evaluate(() => {
    const s = window.__exospace?.scene;
    if (!s?.scene) return { error: 'no scene' };
    // Reconstruct an AABB helper with plain math from object matrices.
    const boxOf = (obj) => {
        obj.updateWorldMatrix(true, false);
        const g = obj.geometry;
        if (!g?.boundingBox) g.computeBoundingBox();
        const bb = g.boundingBox;
        const corners = [];
        for (const x of [bb.min.x, bb.max.x]) for (const y of [bb.min.y, bb.max.y]) for (const z of [bb.min.z, bb.max.z]) {
            corners.push([x, y, z]);
        }
        let minX = 1e9, minY = 1e9, minZ = 1e9, maxX = -1e9, maxY = -1e9, maxZ = -1e9;
        const e = obj.matrixWorld.elements;
        for (const [x, y, z] of corners) {
            const wx = e[0] * x + e[4] * y + e[8] * z + e[12];
            const wy = e[1] * x + e[5] * y + e[9] * z + e[13];
            const wz = e[2] * x + e[6] * y + e[10] * z + e[14];
            minX = Math.min(minX, wx); maxX = Math.max(maxX, wx);
            minY = Math.min(minY, wy); maxY = Math.max(maxY, wy);
            minZ = Math.min(minZ, wz); maxZ = Math.max(maxZ, wz);
        }
        return { minX, minY, minZ, maxX, maxY, maxZ };
    };
    const artworks = [];
    s.scene.traverse(o => { if (o.name === 'artwork-canvas') artworks.push(o); });
    // Columns are MERGED into one mesh (mergeParts → plain BufferGeometry,
    // no .parameters) — identify structural meshes by world-space AABB size:
    // tall (≥ 4 m), thin (≤ 0.5 m on both horizontal axes), near a wall.
    const structs = [];
    s.scene.traverse(o => {
        if (!o.isMesh || o.name === 'artwork-canvas' || o.name === 'artwork-frame') return;
        const b = boxOf(o);
        const sx = b.maxX - b.minX, sy = b.maxY - b.minY, sz = b.maxZ - b.minZ;
        if (sy >= 4 && sx <= 0.5 && sz <= 0.5 && (Math.abs(b.minY) < 0.2)) {
            structs.push({ kind: `struct(${sx.toFixed(2)}x${sy.toFixed(2)}x${sz.toFixed(2)})`, mesh: o, box: b });
        }
    });
        const dumpAll = [];
    s.scene.traverse(o => {
        if (!o.isMesh) return;
        const b = boxOf(o);
        dumpAll.push({ name: o.name || o.type, sx: +(b.maxX-b.minX).toFixed(2), sy: +(b.maxY-b.minY).toFixed(2), sz: +(b.maxZ-b.minZ).toFixed(2), y: +b.minY.toFixed(2), cx: +((b.minX+b.maxX)/2).toFixed(1), cz: +((b.minZ+b.maxZ)/2).toFixed(1) });
    });
    
const overlap = (a, b) => a.minX <= b.maxX && a.maxX >= b.minX && a.minY <= b.maxY && a.maxY >= b.minY && a.minZ <= b.maxZ && a.maxZ >= b.minZ;
    const hits = [];
    for (const st of structs) {
        const sb = st.box;
        for (const art of artworks) {
            const ab = boxOf(art);
            if (overlap(sb, ab)) hits.push({ struct: st.kind, art: art.parent.userData.title, at: [sb.minX.toFixed(2), sb.maxX.toFixed(2)], artZ: [ab.minZ.toFixed(2), ab.maxZ.toFixed(2)] });
        }
    }
    const obstacles = (s._obstacles || []).length;
    const cam = s.camera.position;
    const spawnBlocked = (s._obstacles || []).some(o => cam.x > o.box.min.x && cam.x < o.box.max.x && cam.z > o.box.min.z && cam.z < o.box.max.z);
    return {
        meshDump: dumpAll.slice(0, 40),
        layout: s._layoutMeta?.type, room: s._layoutMeta,
        artworks: artworks.length, columns: structs.length,
        structBoxes: structs.map(s => [s.box.minX.toFixed(1), s.box.maxX.toFixed(1), s.box.minZ.toFixed(1), s.box.maxZ.toFixed(1)]), obstacles,
        columnArtworkOverlaps: hits,
        spawnBlocked,
        camera: { x: +cam.x.toFixed(2), z: +cam.z.toFixed(2) },
        tier: { lowEnd: !!s.isLowEnd, mobile: !!s._isMobileTier },
        lights: s.scene.children.filter(o => o.isLight).length,
    };
});
console.log(JSON.stringify(report3, null, 2));
await browser.close();
server.close();
