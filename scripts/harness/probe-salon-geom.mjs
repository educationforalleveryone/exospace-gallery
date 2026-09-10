// probe-salon-geom.mjs — dump world-space bounding boxes of every merged
// structure group + unmerged structure mesh: where did the baked parts land?
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4189;
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
page.on('pageerror', e => console.error('pageerror:', String(e)));
await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=the-salon&count=12`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
await page.click('#enter-btn', { force: true, timeout: 8000 }).catch(() => {});
await page.waitForTimeout(9000);

const boxes = await page.evaluate(() => {
    const s = window.__exospace.scene;
    const out = [];
    s.scene.traverse(o => {
        if (o.isMesh && o.geometry && o.name && (o.name.startsWith('merged:') || o.name.startsWith('structure:'))) {
            o.geometry.computeBoundingBox();
            const bb = o.geometry.boundingBox;
            // apply matrixWorld (identity for merged groups, transform for singles)
            const m = o.matrixWorld;
            const p = (x, y, z) => {
                const v = new o.position.constructor(x, y, z).applyMatrix4(m);
                return [+v.x.toFixed(2), +v.y.toFixed(2), +v.z.toFixed(2)];
            };
            const mn = p(bb.min.x, bb.min.y, bb.min.z);
            const mx = p(bb.max.x, bb.max.y, bb.max.z);
            out.push({ name: o.name, min: mn, max: mx, verts: o.geometry.attributes.position.count });
        }
    });
    return out;
});
for (const b of boxes) console.log(b.name.padEnd(34), 'min', JSON.stringify(b.min), 'max', JSON.stringify(b.max), 'verts', b.verts);
await browser.close(); server.close(); process.exit(0);
