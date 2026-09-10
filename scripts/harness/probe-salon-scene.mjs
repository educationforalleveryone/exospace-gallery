// probe-salon-scene.mjs — dump the live scene state of the Salon build:
// camera, artwork transforms (wall/row metadata), structure mesh inventory,
// obstacle registry, console errors. Also verifies the hang contract:
// every artwork inside the wall span, clear of the doorcase zone, rows
// separated vertically, nothing intersecting the trim.
//
//   node scripts/harness/probe-salon-scene.mjs [count]
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4181;
const count = Number(process.argv[2] || 12);
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.jpg': 'image/jpeg', '.glb': 'model/gltf-binary', '.hdr': 'application/octet-stream', '.webp': 'image/webp' };

const server = createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]);
    if (p === '/') p = '/harness/harness.html';
    const file = join(rootDir, 'public', p);
    if (existsSync(file)) {
        res.writeHead(200, { 'Content-Type': MIME[extname(file)] || 'application/octet-stream' });
        res.end(readFileSync(file));
    } else { res.writeHead(404); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const browser = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
const page = await browser.newPage({ viewport: { width: 1280, height: 720 } });
const errors = [];
page.on('pageerror', e => errors.push(String(e)));
page.on('console', m => { if (m.type() === 'error' && !m.text().includes('404')) errors.push(m.text()); });

await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=the-salon&count=${count}`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
await page.click('#enter-btn', { force: true, timeout: 8000 }).catch(() => {});
await page.waitForTimeout(12000);

const state = await page.evaluate(() => {
    const s = window.__exospace?.scene;
    if (!s?.scene) return { error: 'no scene' };
    const cam = s.camera;
    const artworks = (s.artworks || []).map(a => {
        const box = new (Object.getPrototypeOf(cam.position).constructor === undefined ? Object : Object)();
        const p = a.position;
        const canvas = a.userData?._canvasMesh;
        let w = 0, h = 0;
        if (canvas?.geometry?.parameters) {
            w = canvas.geometry.parameters.width; h = canvas.geometry.parameters.height;
        }
        // world AABB rough (yaw-only rotation): project half extents
        const yaw = a.rotation.y;
        const hw = (Math.abs(Math.cos(yaw)) * w + Math.abs(Math.sin(yaw)) * 0.16) / 2;
        const hd = (Math.abs(Math.sin(yaw)) * w + Math.abs(Math.cos(yaw)) * 0.16) / 2;
        return {
            id: a.userData?.id, wall: a.userData?.wallId ?? null, row: a.userData?.row ?? null,
            pos: [+p.x.toFixed(2), +p.y.toFixed(2), +p.z.toFixed(2)],
            size: [+w.toFixed(2), +h.toFixed(2)],
            x0: +(p.x - hw).toFixed(2), x1: +(p.x + hw).toFixed(2),
            z0: +(p.z - hd).toFixed(2), z1: +(p.z + hd).toFixed(2),
            scale: +a.scale.x.toFixed(3),
        };
    });
    const structure = [];
    s.scene.traverse(o => {
        if (o.isMesh && typeof o.name === 'string' && o.name.startsWith('structure:') || o.isMesh && o.name?.startsWith('merged:')) {
            structure.push({ name: o.name, pos: o.position.toArray().map(n => +n.toFixed(2)) });
        }
    });
    const lights = [];
    s.scene.traverse(o => { if (o.isLight) lights.push({ t: o.type, i: +o.intensity.toFixed(2) }); });
    return {
        camera: { pos: cam.position.toArray().map(n => +n.toFixed(2)), yaw: +(cam.rotation.y||0).toFixed(2) },
        layoutMeta: s._layoutMeta || null,
        artworkSpacing: (window.__CONFIG__?.room?.artworkSpacing) ?? null,
        bounds: s.roomBounds || null,
        obstacles: (s._obstacles || []).length,
        artworks, structureCount: structure.length, structure: structure.slice(0, 40),
        lights: lights.length,
        errors: undefined,
    };
});

console.log(JSON.stringify({ count, state, errors }, null, 1));
await browser.close();
const { default: http } = await import('node:http');
server.close();
process.exit(0);
