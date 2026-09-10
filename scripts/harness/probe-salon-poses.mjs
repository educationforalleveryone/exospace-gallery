// probe-salon-poses.mjs — DETERMINISTIC forensic captures: stop the RAF
// chain, set the pose on the owned camera, render ONE frame synchronously,
// capture. No loop interference, no stale presentations.
//
//   node scripts/harness/probe-salon-poses.mjs [count]
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4197;
const count = Number(process.argv[2] || 12);
const outDir = join(rootDir, `shots-salon-poses-${count}`);
mkdirSync(outDir, { recursive: true });
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.png': 'image/png', '.jpg': 'image/jpeg' };
const server = createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]);
    if (p === '/') p = '/harness/harness.html';
    const file = join(rootDir, 'public', p);
    if (existsSync(file)) { res.writeHead(200, { 'Content-Type': MIME[extname(file)] || 'application/octet-stream' }); res.end(readFileSync(file)); }
    else { res.writeHead(404); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

// v3 "two rooms" poses — the room spans ±L/2 (L 8.0–12.6 by count), the
// curtain stands at z=0 with its 2.4 m opening, the hero wall is at z=−L/2
// (front), the walnut double door on z=+L/2 (back). Poses use count-24
// geometry (L=11.2, half 5.6) unless noted; the camera-space checks in the
// walk probe cover the rest.
const POSES = [
    { id: 'spawn-view',    p: [0, 1.6, 4.9],      t: [0, 1.5, -5.4] },   // arrival: hero THROUGH the opening
    { id: 'opening',       p: [0, 1.58, 1.6],     t: [0, 1.5, -5.4] },   // the opening sightline
    { id: 'curtain-front', p: [0, 1.55, 1.9],     t: [0, 1.45, 0.0] },   // the fabric face-on (room A side)
    { id: 'curtain-tie',   p: [-2.2, 1.5, 1.4],   t: [-1.15, 1.15, 0.0] }, // the tie band + gathered waist
    { id: 'room-a-door',   p: [0, 1.6, 1.2],      t: [0, 1.5, 5.4] },    // the walnut double door
    { id: 'room-b-hero',   p: [0, 1.6, -1.4],     t: [0, 1.5, -5.35] },  // hero wall + bench
    { id: 'room-b-back',   p: [2.4, 1.6, -2.8],   t: [-1.2, 1.45, 0.6] },// looking back at the curtain from room B
    { id: 'room-a-corner', p: [-2.5, 1.62, 3.6],  t: [1.8, 1.4, 0.2] },  // conversation corner + curtain
    { id: 'along-left-b',  p: [-4.9, 1.62, -2.2], t: [3.0, 1.4, -4.9] }, // room B hang along the west wall
    { id: 'upper-row',     p: [0, 1.62, -1.2],    t: [-1.6, 2.9, -5.3] },// the upper salon line
];

const browser = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
const page = await browser.newPage({ viewport: { width: 480, height: 270 } });
page.on('pageerror', e => console.error('pageerror:', String(e)));
page.on('console', m => { if (m.text().includes('benchmark') || m.text().includes('low-end') || m.text().includes('tier')) console.log('[console]', m.text().slice(0, 160)); });
const tier = process.argv[4] || 'high';
const tierInit = tier === 'low'
    ? `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>2});
       Object.defineProperty(navigator,'deviceMemory',{get:()=>0.5});`
    : `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
       Object.defineProperty(navigator,'deviceMemory',{get:()=>8});`;
await page.addInitScript(tierInit);
await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=the-salon&count=${count}&tier=${tier}`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
for (let i = 0; i < 5; i++) {
    await page.evaluate(() => {
        const b = document.getElementById('enter-btn');
        if (b) b.click();
        const curtain = document.getElementById('curtain');
        if (curtain) { curtain.style.transition = 'opacity 0.2s'; curtain.style.opacity = '0'; curtain.style.pointerEvents = 'none'; }
    });
    await page.waitForTimeout(2500);
    const gone = await page.evaluate(() => {
        const s = window.__exospace?.scene;
        return !!(s?.scene && s.camera) && (document.getElementById('curtain')?.style.opacity === '0' || !document.getElementById('enter-btn'));
    });
    if (gone) break;
}
await page.waitForTimeout(1500); // capture BEFORE the fps benchmark downgrades

const cdp = await page.context().newCDPSession(page);
// Room-size the poses: they are authored against count-24 geometry
// (half-depth 5.9); scale the horizontal components so every count's
// camera stands INSIDE its room.
const scale = await page.evaluate(() => {
    const s = window.__exospace.scene;
    const L = s._layoutMeta?.wallLength || 11.8;
    return (L / 2) / 5.9;
});
for (const pose of POSES) {
    const sized = {
        id: pose.id,
        p: [pose.p[0] * scale, pose.p[1], pose.p[2] * scale],
        t: [pose.t[0] * scale, pose.t[1], pose.t[2] * scale],
    };
    await page.evaluate((pose) => {
        const s = window.__exospace.scene;
        // stop the RAF chain — the loop is the only thing that overwrites
        // the camera (movement/lean normalization). One manual render below.
        if (s._rafId) cancelAnimationFrame(s._rafId);
        s._rafActive = false;
        s.camera.position.set(...pose.p);
        s.camera.lookAt(...pose.t);
        s.camera.updateMatrixWorld();
        s.updateProximityLighting && s.updateProximityLighting();
        if (s._postFx && !s.isLowEnd) s._postFx.render();
        else s.renderer.render(s.scene, s.camera);
    }, sized);
    const shot = await cdp.send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(join(outDir, `${pose.id}.png`), Buffer.from(shot.data, 'base64'));
    if (pose.id === 'spawn') {
        const st = await page.evaluate(() => { const s = window.__exospace.scene; return { low: !!s.isLowEnd, lights: s.scene.children.filter(o=>o.isLight).map(l=>({t:l.type,i:+l.intensity.toFixed(2)})), exposure: s.renderer.toneMappingExposure }; });
        console.log('tier state:', JSON.stringify(st));
    }
    console.log('captured', pose.id);
}
await browser.close(); server.close(); process.exit(0);
