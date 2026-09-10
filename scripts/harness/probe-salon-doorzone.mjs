// probe-salon-doorzone.mjs — check artwork AABBs against the doorcase
// assembly volumes (casing + leaves + panels). The keep_clear drops the
// centre SLOT and guarantees the nearest remaining canvas CENTRE is at
// ±spacing/2 — but a max-cap canvas's EDGE can still reach back into the
// doorzone (the v2.1 door close-up shows a wide eye-row landscape covering
// the jamb). Numbers, not eyeballs.
//   node scripts/harness/probe-salon-doorzone.mjs [count]
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4203;
const count = Number(process.argv[2] || 15);
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.png': 'image/png', '.jpg': 'image/jpeg' };
const server = createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]);
    if (p === '/') p = '/harness/harness.html';
    const file = join(rootDir, 'public', p);
    if (existsSync(file)) { res.writeHead(200, { 'Content-Type': MIME[extname(file)] || 'application/octet-stream' }); res.end(readFileSync(file)); }
    else { res.writeHead(404); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const browser = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
const page = await browser.newPage({ viewport: { width: 320, height: 180 } });
await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=the-salon&count=${count}&tier=high`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
for (let i = 0; i < 5; i++) {
    await page.evaluate(() => {
        const b = document.getElementById('enter-btn');
        if (b) b.click();
        const curtain = document.getElementById('curtain');
        if (curtain) { curtain.style.opacity = '0'; curtain.style.pointerEvents = 'none'; }
    });
    await page.waitForTimeout(2200);
    if (await page.evaluate(() => !!window.__exospace?.scene?.scene)) break;
}
await page.waitForTimeout(800);

const res = await page.evaluate(() => {
    const s = window.__exospace.scene;
    const L = s._layoutMeta.wallLength;
    const inner = L / 2 - 0.075; // wall inner face (wallDepth 0.15)
    // doorcase volumes (world, x/y/z) — from the v2.1 payload geometry
    const assembly = {
        casing:  { x0: -0.83, x1: 0.83, y0: 0, y1: 2.67, z0: inner - 0.036, z1: inner + 0.02 },
        leaves:  { x0: -0.74, x1: 0.74, y0: 0, y1: 2.52, z0: inner - 0.055, z1: inner - 0.015 },
        panels:  { x0: -0.60, x1: 0.60, y0: 0.16, y1: 2.36, z0: inner - 0.067, z1: inner - 0.055 },
    };
    const aabbOf = (root) => {
        root.updateWorldMatrix(true, true);
        let minX = Infinity, minY = Infinity, minZ = Infinity, maxX = -Infinity, maxY = -Infinity, maxZ = -Infinity;
        root.traverse((o) => {
            if (!o.isMesh || !o.geometry?.attributes?.position) return;
            const pos = o.geometry.attributes.position;
            const m = o.matrixWorld;
            for (let i = 0; i < pos.count; i++) {
                const x = pos.getX(i), y = pos.getY(i), z = pos.getZ(i);
                const wx = m.elements[0] * x + m.elements[4] * y + m.elements[8] * z + m.elements[12];
                const wy = m.elements[1] * x + m.elements[5] * y + m.elements[9] * z + m.elements[13];
                const wz = m.elements[2] * x + m.elements[6] * y + m.elements[10] * z + m.elements[14];
                if (wx < minX) minX = wx; if (wx > maxX) maxX = wx;
                if (wy < minY) minY = wy; if (wy > maxY) maxY = wy;
                if (wz < minZ) minZ = wz; if (wz > maxZ) maxZ = wz;
            }
        });
        return { minX, minY, minZ, maxX, maxY, maxZ };
    };
    const overlaps = (a, b) =>
        a.minX < b.x1 && a.maxX > b.x0 && a.minY < b.y1 && a.maxY > b.y0 && a.minZ < b.z1 && a.maxZ > b.z0;
    const out = [];
    for (const art of s.artworks) {
        const bb = aabbOf(art);
        const wall = art.userData?.wallId ?? null;
        if (wall !== 'back') continue;
        const hits = Object.entries(assembly).filter(([, vol]) => overlaps(bb, vol)).map(([k]) => k);
        out.push({
            id: art.userData?.id, row: art.userData?.row ?? null,
            cx: +((bb.minX + bb.maxX) / 2).toFixed(2),
            x0: +bb.minX.toFixed(2), x1: +bb.maxX.toFixed(2),
            y0: +bb.minY.toFixed(2), y1: +bb.maxY.toFixed(2),
            z0: +bb.minZ.toFixed(3), z1: +bb.maxZ.toFixed(3),
            w: +(bb.maxX - bb.minX).toFixed(2),
            overlaps: hits,
        });
    }
    return { L, inner, artworks: out };
});
console.log('wallLength', res.L, 'inner face', res.inner);
let bad = 0;
for (const a of res.artworks) {
    const flag = a.overlaps.length ? '  ✗ OVERLAPS ' + a.overlaps.join('+') : '';
    if (a.overlaps.length) bad++;
    console.log(`  wall=back row=${a.row} x[${a.x0},${a.x1}] y[${a.y0},${a.y1}] z[${a.z0},${a.z1}] w=${a.w}${flag}`);
}
console.log(bad === 0 ? 'DOORZONE CLEAR' : `${bad} artwork(s) overlap the doorcase`);
await browser.close(); server.close(); process.exit(0);
