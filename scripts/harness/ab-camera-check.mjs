/**
 * A/B camera-behaviour probe — runs the identical movement/focus/tour checks
 * against whichever harness root it is pointed at, so pre-change vs
 * post-change behaviour can be compared honestly.
 *
 * Run:  node scripts/harness/ab-camera-check.mjs <port> <rootDir>
 */
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const argRoot = process.argv[3] || resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const rootDir = resolve(argRoot);
const PORT = Number(process.argv[2] || 4193);
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.jpg': 'image/jpeg', '.glb': 'model/gltf-binary', '.hdr': 'application/octet-stream', '.webp': 'image/webp' };
const server = createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]);
    if (p === '/') p = '/harness/harness.html';
    const file = join(rootDir, 'public', p);
    if (existsSync(file)) { res.writeHead(200, { 'Content-Type': MIME[extname(file)] || 'application/octet-stream' }); res.end(readFileSync(file)); }
    else { res.writeHead(404); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const browser = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
const page = await browser.newPage({ viewport: { width: 800, height: 450 } });
const errors = [];
page.on('pageerror', e => errors.push(String(e).slice(0, 200)));
page.on('console', m => {
    if (m.type() !== 'error') return;
    const t = m.text();
    if (t.includes('404') || t.includes('Failed to load resource')) return;
    errors.push(t.slice(0, 200));
});
await page.addInitScript(() => {
    Object.defineProperty(navigator, 'hardwareConcurrency', { get: () => 8 });
    Object.defineProperty(navigator, 'deviceMemory', { get: () => 8 });
    try { localStorage.clear(); } catch (e) { /* private mode */ }
});

await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=crystal-cathedral&count=12&tier=high`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 90000 });
await page.$eval('#enter-btn', el => el.click());
const arrivalDone = await page.waitForFunction(() => {
    const s = window.__exospace?.scene;
    return s && !!s.artworks?.length && !s.arrivalActive;
}, { timeout: 45000 }).then(() => true).catch(() => false);
console.log('arrivalDone:', arrivalDone);
await page.waitForTimeout(2000);

const result = await page.evaluate(async () => {
    const s = window.__exospace.scene;
    s.controls.isLocked = true;
    let maxDisp = 0;
    for (const dir of ['forward', 'backward', 'left', 'right']) {
        const start = s.camera.position.clone();
        s.moveState[dir] = true;
        await new Promise(r => setTimeout(r, 1200));
        s.moveState[dir] = false;
        await new Promise(r => setTimeout(r, 3500));
        maxDisp = Math.max(maxDisp, s.camera.position.distanceTo(start));
    }
    // focus on the farthest artwork
    let target = s.artworks[0];
    let bestDist = -1;
    for (const a of s.artworks) {
        const d = s.camera.position.distanceTo(a.position);
        if (d > bestDist) { bestDist = d; target = a; }
    }
    const from = s.camera.position.clone();
    s.focusedArtwork = target;
    s.toggleArtworkInfo();
    await new Promise(r => setTimeout(r, 2500));
    const focusMoved = s.camera.position.distanceTo(from);
    const inspecting = s.isInspecting === true;
    s.toggleArtworkInfo();
    await new Promise(r => setTimeout(r, 2500));
    const exited = s.isInspecting === false;

    const tourFrom = s.camera.position.clone();
    if (typeof window.startGuidedTour === 'function') window.startGuidedTour();
    await new Promise(r => setTimeout(r, 7000));
    const tourActive = window.__exospace.tour?.active === true;
    const tourMoved = s.camera.position.distanceTo(tourFrom);
    window.__exospace.tour?.stop?.();

    return {
        maxDisp: Math.round(maxDisp * 100) / 100,
        focusMoved: Math.round(focusMoved * 100) / 100,
        inspecting, exited,
        tourActive, tourMoved: Math.round(tourMoved * 100) / 100,
        boundsRadius: s._circularBoundsRadius ?? null,
        pos: s.camera.position.toArray().map(v => Math.round(v * 100) / 100),
    };
});
console.log('CAMERA:', JSON.stringify(result));
console.log('ERRORS:', errors.length ? errors.slice(0, 4) : 'none');

await browser.close();
server.close();
process.exit(0);
