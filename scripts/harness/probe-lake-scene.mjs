// probe-lake-scene.mjs — dump the live scene state of the Mirror Lake build:
// camera, lights, sky, water, moon screen-projection, artwork count.
// Run: node scripts/harness/probe-lake-scene.mjs [count]
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4173;
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

const { completed } = await import('node:process');
const browser = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
const page = await browser.newPage({ viewport: { width: 1280, height: 720 } });
const errors = [];
page.on('pageerror', e => errors.push(String(e)));
page.on('console', m => { if (m.type() === 'error' && !m.text().includes('404')) errors.push(m.text()); });

await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=mirror-lake&reflect=0&count=${count}`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
// The curtain can dismiss itself as assets finish (the button detaches) —
// click if it is still there, otherwise the scene is already entered.
await page.click('#enter-btn', { force: true, timeout: 8000 }).catch(() => {});
await page.waitForTimeout(14000);

const state = await page.evaluate(() => {
    const s = window.__exospace?.scene;
    if (!s?.scene) return { error: 'no scene' };
    const cam = s.camera;
    const lights = [];
    let sky = 0, water = 0, moon = 0, stars = 0, mist = 0, trees = 0;
    const v = new (Object.getPrototypeOf(cam.position).constructor)();
    cam.updateMatrixWorld();
    const proj = (pos) => {
        v.set(pos.x, pos.y, pos.z).project(cam);
        return { x: +v.x.toFixed(2), y: +v.y.toFixed(2), z: +v.z.toFixed(2) };
    };
    let moonWorld = null, pavLight = 0;
    s.scene.traverse(o => {
        if (o.isLight) {
            lights.push({
                type: o.type, color: '#' + o.color.getHexString(),
                intensity: +o.intensity.toFixed(3),
                pos: o.position.toArray().map(n => +n.toFixed(1)),
            });
            if (o.type === 'PointLight' && o.color.getHexString() === 'ffd2a0') pavLight++;
        }
        const name = o.name || '';
        if (o.material?.uniforms?.topColor) sky++;
        if (o.material?.uniforms?.tDiffuse) water++;
        if (o.geometry?.type === 'SphereGeometry' && o.material?.color?.getHexString() === 'e3ebfa') { moon++; moonWorld = proj(o.getWorldPosition(new (Object.getPrototypeOf(cam.position).constructor)())); }
        if (o.isPoints) mist++;
        if (o.isInstancedMesh) trees++;
    });
    const fog = s.scene.fog;
    return {
        camera: { pos: cam.position.toArray().map(n => +n.toFixed(2)), far: cam.far, fov: cam.fov },
        lightCount: lights.length, lights, sky, water, moon, moonProj: moonWorld, stars: !!s.scene.getObjectByProperty('isPoints', true),
        mistClouds: mist, instancedMeshes: trees,
        fog: fog ? { color: '#' + fog.color.getHexString(), near: fog.near, far: fog.far } : null,
        envIntensity: s.scene.environmentIntensity,
        hasEnv: !!s.scene.environment,
        artworks: s.artworks?.length,
        arrivalActive: !!s.arrivalActive,
        tier: { low: s.isLowEnd, mobile: s._isMobileTier },
        pavLight,
        berth0: s._lakePlan ? { role: s._lakePlan.courts[0]?.role, pos: [s._lakePlan.courts[0]?.x, s._lakePlan.courts[0]?.z] } : null,
        spawn: s._lakePlan?.spawn,
    };
});
console.log(JSON.stringify(state, null, 1));
console.log('page errors:', errors.slice(0, 8));
await browser.close();
server.close();
completed && 0;
