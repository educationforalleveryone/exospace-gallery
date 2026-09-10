// probe-lake-tour.mjs — verifies the scripted-camera contract on Mirror Lake:
//   1. starting the guided tour must NOT fight the shore clamp (camera
//      reaches the over-water tour poses smoothly, no yank)
//   2. stopping the tour must LAND the camera on legal ground (no inherited
//      over-water position, no snap afterwards)
//   3. focus mode (E) over a water-side artwork tweens in and back cleanly
//   4. walking afterwards produces no teleport (region state is consistent)
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4191;
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
const page = await browser.newPage({ viewport: { width: 800, height: 500 } });
await page.addInitScript(`Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
Object.defineProperty(navigator,'deviceMemory',{get:()=>8});`);
await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=mirror-lake&reflect=0&count=8&tier=high`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
await page.$eval('#enter-btn', el => el.click()).catch(() => {});
await page.waitForTimeout(9000);

const report = await page.evaluate(async () => {
    const s = window.__exospace.scene;
    const out = { steps: [] };
    const legal = () => {
        const plan = s._lakePlan;
        const p = s.camera.position;
        if (Math.abs(p.x - plan.pier.x) < 0.9 && p.z < plan.pier.footZ + 0.5 && p.z > plan.pavilion.z - plan.pavilion.size / 2 - 0.15) return 'pier';
        const dx = p.x - plan.pavilion.x, dz = p.z - plan.pavilion.z;
        const c = Math.cos(plan.pavilion.yaw), sn = Math.sin(plan.pavilion.yaw);
        const lx = dx * c - dz * sn, lz = dx * sn + dz * c;
        if (Math.abs(lx) < plan.pavilion.size / 2 + 0.25 && Math.abs(lz) < plan.pavilion.size / 2 + 0.25) return 'pavilion';
        return p.z >= plan.shoreZ(p.x) - 0.16 ? 'land' : 'WATER';
    };

    // 1. start the tour (same path as the T key / tour button)
    window.startGuidedTour();
    await new Promise(r => setTimeout(r, 4500));   // first hop tween (~2s) + settle
    out.steps.push({ step: 'tour first hop', pos: s.camera.position.toArray().map(n => +n.toFixed(2)), region: legal(), scripted: !!s._cameraScripted });
    // advance twice (the GuidedTour instance's own next())
    const tour = window.__exospace?.tour;
    for (let k = 0; k < 2; k++) {
        tour?.next();
        await new Promise(r => setTimeout(r, 3500));
    }
    out.steps.push({ step: 'tour after 3 hops', pos: s.camera.position.toArray().map(n => +n.toFixed(2)), region: legal(), scripted: !!s._cameraScripted });

    // 2. stop the tour → settle must land the camera legally
    tour?.stop();
    await new Promise(r => setTimeout(r, 1200));   // settle glide
    out.steps.push({ step: 'tour stopped + settled', pos: s.camera.position.toArray().map(n => +n.toFixed(2)), region: legal(), scripted: !!s._cameraScripted });

    // 3. focus mode from the settled position: focus nearest artwork (Enter path)
    s.controls.isLocked = true;
    s.focusNearestArtwork();
    await new Promise(r => setTimeout(r, 400));
    const enterState = {
        hasTween: !!s.focusTween,
        progress: s.focusTween ? +s.focusTween.progress().toFixed(3) : null,
        pos: s.camera.position.toArray().map(n => +n.toFixed(2)),
    };
    await new Promise(r => setTimeout(r, 2500));   // enter tween 1.5s + margin
    out.steps.push({ step: 'focus entered', ...enterState, posAfter: s.camera.position.toArray().map(n => +n.toFixed(2)), region: legal(), inspecting: !!s.isInspecting });
    s.toggleArtworkInfo();                          // exit
    // SwiftShader note: gsap's lagSmoothing caps tween progress per rAF
    // tick, so a 1.2 s exit tween can take ~20 s of wall time on the software
    // rasterizer. Real devices run 60 fps and complete on schedule. Poll long.
    for (let k = 0; k < 120 && s.isInspecting; k++) await new Promise(r => setTimeout(r, 250));
    out.steps.push({ step: 'focus exited', pos: s.camera.position.toArray().map(n => +n.toFixed(2)), region: legal(), inspecting: !!s.isInspecting });

    // 4. walk 3 s — no teleport allowed afterwards
    const DT = 1 / 60;
    s.clock.getDelta = () => DT;
    s.moveState.forward = true;
    let maxStep = 0;
    for (let i = 0; i < 180; i++) {
        const px = s.camera.position.x, pz = s.camera.position.z;
        s.updateMovement();
        if (s._lakeTick) s._lakeTick();
        maxStep = Math.max(maxStep, Math.hypot(s.camera.position.x - px, s.camera.position.z - pz));
    }
    s.moveState.forward = false;
    out.steps.push({ step: 'walked 3s after focus exit', pos: s.camera.position.toArray().map(n => +n.toFixed(2)), region: legal(), maxStep: +maxStep.toFixed(3) });
    return out;
});
console.log(JSON.stringify(report, null, 1));
await browser.close();
server.close();
process.exit(0);
