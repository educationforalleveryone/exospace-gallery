// probe-lake-shots.mjs — single-session Mirror Lake capture: boots the venue
// once, then teleports the camera through named viewpoints and screenshots
// each. Fast enough for SwiftShader QA loops.
//
//   OUT=shots-lake-before node scripts/harness/probe-lake-shots.mjs [count]
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = process.env.OUT || 'shots-lake';
const PORT = 4181;
const count = Number(process.argv[2] || 12);
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
const page = await browser.newPage({ viewport: { width: 1100, height: 620 } });
const TIER_INIT = `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
const _d = Date.now.bind(Date); Date.now = () => _d() * 250;
const __origNow = performance.now.bind(performance);
const __t0 = __origNow();
performance.now = () => __t0 + (__origNow() - __t0) * 0.004;`;
await page.addInitScript(TIER_INIT);
const errors = [];
page.on('pageerror', e => errors.push(String(e)));

const TIER = process.env.TIER ? `&tier=${process.env.TIER}` : '&tier=high';
await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=mirror-lake&reflect=${process.env.REFLECT ?? 1}&count=${count}${TIER}`, { waitUntil: 'domcontentloaded', timeout: 45000 });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
await page.$eval('#enter-btn', el => el.click()).catch(() => {});
await page.waitForFunction(() => {
    const s = window.__exospace?.scene;
    return !s || (s._lakeAssetsSettled !== false);
}, { timeout: 60000 }).catch(() => errors.push('assets gate'));
await page.waitForTimeout(8000);
await page.addStyleTag({ content: '#crosshair,#controls-hint{display:none!important}' }).catch(() => {});

// Named viewpoints computed from the LIVE plan (no hardcoded coordinates).
const shots = await page.evaluate(() => {
    const s = window.__exospace.scene;
    const plan = s._lakePlan;
    const R = plan.radius;
    const pier = plan.pier, pav = plan.pavilion, spawn = plan.spawn;
    const hero = s.artworks[0]?.getWorldPosition(new (s.camera.position.constructor)()) || { x: -4, y: 1.6, z: -6 };
    const walkZAt = (x) => plan.shoreZ(x) + 1.7;
    const shoreZ = (x) => plan.shoreZ(x);
    const midPierZ = (pier.footZ + pav.z) / 2;
    return [
        ['01-spawn-view',        [spawn.x, 1.6, spawn.z],                    [spawn.x, 1.0, -2]],
        ['02-walk-east',         [spawn.x + 3, 1.6, walkZAt(spawn.x + 3)],   [pier.x, 1.2, pier.footZ]],
        ['03-pier-approach',     [pier.x, 1.6, shoreZ(pier.x) + 2.2],        [pav.x, 0.8, pav.z]],
        ['04-on-pier-mid',       [pier.x, 1.6, midPierZ],                    [pav.x, 0.7, pav.z]],
        ['05-on-pier-lookback',  [pier.x, 1.6, midPierZ],                    [0, 1.4, spawn.z]],
        ['06-pavilion-south',    [pav.x, 1.6, pav.z + 5.2],                  [pav.x, 0.7, pav.z]],
        ['07-pavilion-west-low', [pav.x - 4.4, 0.75, pav.z - 1.2],           [pav.x, 0.5, pav.z]],
        ['08-pavilion-north-low',[pav.x, 0.5, pav.z - 4.6],                  [pav.x, 0.6, pav.z + 1]],
        ['09-pavilion-deck',     [pav.x, 1.7, pav.z + 1.6],                  [pav.x - 2.6, 0.6, pav.z - 1.6]],
        ['10-hero-from-shore',   [hero.x, 1.6, shoreZ(hero.x) - 0.1],        [hero.x, 1.56, hero.z]],
        ['11-west-shore-pan',    [-R * 0.58, 1.6, shoreZ(-R * 0.58) + 1.2],  [0, 0.6, -R * 0.3]],
        ['12-farshore-north',    [pier.x, 1.6, pier.endZ - 3.5],             [0, 1.2, -R]],
        ['13-water-level-pier',  [pier.x + 2.6, 0.4, midPierZ - 1],          [pier.x, 0.3, pav.z]],
        ['14-arc-east-from-pier',[pier.x - 0.4, 1.6, pier.endZ + 2],         [-R * 0.4, 1.5, -R * 0.55]],
        ['15-plaza-lookback',    [0, 1.6, spawn.z + 2.2],                    [0, 1.5, spawn.z - 4]],
    ];
});

for (const [name, p, t] of shots) {
    await page.evaluate(([p, t]) => {
        const s = window.__exospace.scene;
        s.arrivalActive = false;
        s._lakeTick && (s._lakeTick = () => {});   // free camera for QA shots
        s.camera.position.set(p[0], p[1], p[2]);
        s.camera.lookAt(t[0], t[1], t[2]);
        s.camera.updateMatrixWorld(true);
    }, [p, t]);
    await page.waitForTimeout(process.env.REFLECT === '0' ? 1200 : 3000);
    await page.screenshot({ path: resolve(rootDir, OUT, `${name}.png`), timeout: 150000 });
}

console.log('shots written to', OUT);
console.log('page errors:', errors.slice(0, 6));
await browser.close();
server.close();
process.exit(0);
