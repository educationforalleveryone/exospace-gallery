// probe-salon-fieldgeom.mjs — dump the EXACT world geometry of every merged
// structure mesh (position attribute bounds in LOCAL space + matrixWorld +
// world AABB) so the phantom-surface question is answered from vertices,
// not from AABB guesses.
//   node scripts/harness/probe-salon-fieldgeom.mjs [count]
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4201;
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
await page.waitForTimeout(1000);

const dump = await page.evaluate(() => {
    const s = window.__exospace.scene;
    const out = [];
    s.scene.traverse((o) => {
        if (!o.isMesh) return;
        const name = String(o.name);
        if (!name.startsWith('structure:') && !name.startsWith('merged:')) return;
        const geo = o.geometry;
        geo.computeBoundingBox();
        const bb = geo.boundingBox;
        const m = o.matrixWorld.elements;
        out.push({
            name,
            meshPos: [+o.position.x.toFixed(3), +o.position.y.toFixed(3), +o.position.z.toFixed(3)],
            meshRotY: +(o.rotation.y || 0).toFixed(4),
            localBB: {
                min: [+bb.min.x.toFixed(3), +bb.min.y.toFixed(3), +bb.min.z.toFixed(3)],
                max: [+bb.max.x.toFixed(3), +bb.max.y.toFixed(3), +bb.max.z.toFixed(3)],
            },
            worldOfLocalMin: [
                +(m[0] * bb.min.x + m[4] * bb.min.y + m[8] * bb.min.z + m[12]).toFixed(3),
                +(m[1] * bb.min.x + m[5] * bb.min.y + m[9] * bb.min.z + m[13]).toFixed(3),
                +(m[2] * bb.min.x + m[6] * bb.min.y + m[10] * bb.min.z + m[14]).toFixed(3),
            ],
            worldOfLocalMax: [
                +(m[0] * bb.max.x + m[4] * bb.max.y + m[8] * bb.max.z + m[12]).toFixed(3),
                +(m[1] * bb.max.x + m[5] * bb.max.y + m[9] * bb.max.z + m[13]).toFixed(3),
                +(m[2] * bb.max.x + m[6] * bb.max.y + m[10] * bb.max.z + m[14]).toFixed(3),
            ],
        });
    });
    return { L: s._layoutMeta?.wallLength, meshes: out };
});
console.log('wallLength =', dump.L);
for (const m of dump.meshes) {
    console.log(`\n${m.name}`);
    console.log('  meshPos', JSON.stringify(m.meshPos), 'rotY', m.meshRotY);
    console.log('  localBB', JSON.stringify(m.localBB.min), '→', JSON.stringify(m.localBB.max));
    console.log('  world(localMin)', JSON.stringify(m.worldOfLocalMin));
    console.log('  world(localMax)', JSON.stringify(m.worldOfLocalMax));
}
await browser.close(); server.close(); process.exit(0);
