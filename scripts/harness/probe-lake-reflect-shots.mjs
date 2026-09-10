// probe-lake-reflect-shots.mjs — key frames on the PLANAR water path (the
// product default). The Reflector re-renders the scene per frame, so this is
// limited to a few viewpoints under SwiftShader.
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = process.env.OUT || 'shots-lake-planar';
const PORT = 4187;
mkdirSync(resolve(rootDir, OUT), { recursive: true });

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

const browser = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--disable-lcd-text'] });
const page = await browser.newPage({ viewport: { width: 900, height: 500 } });
await page.addInitScript(`Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
const _d = Date.now.bind(Date); Date.now = () => _d() * 250;
const __origNow = performance.now.bind(performance);
const __t0 = __origNow();
performance.now = () => __t0 + (__origNow() - __t0) * 0.002;`);

await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=mirror-lake&count=12&tier=high`, { waitUntil: 'domcontentloaded', timeout: 45000 });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
await page.$eval('#enter-btn', el => el.click()).catch(() => {});
await page.waitForFunction(() => {
    const s = window.__exospace?.scene;
    return !s || (s._lakeAssetsSettled !== false);
}, { timeout: 90000 }).catch(() => {});
await page.waitForTimeout(6000);
await page.addStyleTag({ content: '#crosshair,#controls-hint{display:none!important}' }).catch(() => {});

const shots = await page.evaluate(() => {
    const s = window.__exospace.scene;
    const plan = s._lakePlan;
    const R = plan.radius;
    const pier = plan.pier, pav = plan.pavilion, spawn = plan.spawn;
    const hero = s.artworks[0]?.getWorldPosition(new (s.camera.position.constructor)()) || { x: -4, y: 1.6, z: 1 };
    return [
        ['r1-spawn-view',      [spawn.x, 1.6, spawn.z],                       [hero.x, 1.3, hero.z]],
        ['r2-hero-from-shore', [hero.x, 1.6, plan.shoreZ(hero.x) - 0.1],      [hero.x, 1.56, hero.z]],
        ['r3-pier-approach',   [pier.x, 1.6, plan.shoreZ(pier.x) + 2.2],      [pav.x, 0.8, pav.z]],
        ['r4-pavilion-west',   [pav.x - 4.4, 1.1, pav.z - 1.2],               [pav.x, 0.6, pav.z]],
    ];
});

for (const [name, p, t] of shots) {
    await page.evaluate(([p, t]) => {
        const s = window.__exospace.scene;
        s.arrivalActive = false;
        s._lakeTick && (s._lakeTick = () => {});
        s.camera.position.set(p[0], p[1], p[2]);
        s.camera.lookAt(t[0], t[1], t[2]);
        s.camera.updateMatrixWorld(true);
    }, [p, t]);
    await page.waitForTimeout(4500);
    await page.screenshot({ path: resolve(rootDir, OUT, `${name}.png`), timeout: 240000 });
}
console.log('planar shots written to', OUT);
await browser.close();
server.close();
process.exit(0);
