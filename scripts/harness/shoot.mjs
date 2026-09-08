#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// shoot.mjs — drive the static viewer harness (public/harness/harness.html)
// with headless Chromium and capture deterministic screenshots per scenario.
//
//   node scripts/harness/shoot.mjs --out shots [--scenarios default] [--stats]
//
// Requirements: `vite build --config scripts/harness/vite.harness.config.mjs`
// and a static server rooted at public/ (script starts one itself).
//
// Tier control WITHOUT touching app code: addInitScript overrides the
// navigator signals the viewer's own detectors read (hardwareConcurrency,
// deviceMemory, WEBGL_debug_renderer_info availability, prefers-reduced-motion).
// high → full quality; low → software-renderer-shaped environment.
// ─────────────────────────────────────────────────────────────────────────────
import { createServer } from 'node:http';
import { readFile, writeFile } from 'node:fs/promises';
import { existsSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const args = process.argv.slice(2);
function arg(name, fallback) {
    const i = args.indexOf(`--${name}`);
    if (i === -1) return fallback;
    const v = args[i + 1];
    return v && !v.startsWith('--') ? v : true;
}

const OUT      = String(arg('out', 'shots'));
const ONLY     = [].concat(arg('scenario', []) || []).map(String);
const STATS    = arg('stats', false) === true;
const SETTLE   = Number(arg('settle', 1));   // multiply the post-enter waits — heavy scenarios stream textures in the background; SwiftShader decodes them slowly
const PORT     = Number(arg('port', 4199));

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript',
    '.css': 'text/css', '.json': 'application/json', '.jpg': 'image/jpeg', '.png': 'image/png',
    '.hdr': 'application/octet-stream', '.glb': 'model/gltf-binary', '.wasm': 'application/wasm' };

// Minimal static server rooted at public/
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
        let p = decodeURIComponent(url.pathname);
        if (p.endsWith('/')) p += 'index.html';
        const fp = path.join(publicDir, p);
        if (!fp.startsWith(publicDir) || !existsSync(fp)) { res.writeHead(404); res.end('nf'); return; }
        const data = await readFile(fp);
        res.writeHead(200, { 'Content-Type': MIME[path.extname(fp)] || 'application/octet-stream' });
        res.end(data);
    } catch { res.writeHead(500); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const { chromium } = await import('playwright');
mkdirSync(path.resolve(rootDir, OUT), { recursive: true });

// ── Scenarios (Modern White Cube QA matrix + venue-specific sweeps) ─────────
const SCENARIOS = [
    { id: 'square-01',            q: 'count=1' },
    { id: 'square-08-mixed',      q: 'count=8' },
    { id: 'square-12-mixed',      q: 'count=12' },
    { id: 'square-30-mixed',      q: 'count=30' },
    { id: 'square-60-mixed',      q: 'count=60' },
    { id: 'square-08-portrait',   q: 'count=8&orient=portrait' },
    { id: 'square-08-landscape',  q: 'count=8&orient=landscape' },
    { id: 'square-08-square',     q: 'count=8&orient=square' },
    { id: 'square-08-extreme',    q: 'count=8&orient=extreme' },
    { id: 'corridor-08',          q: 'count=8&layout=corridor' },
    { id: 'lshape-08',           q: 'count=8&layout=l-shape' },
    { id: 'rotunda-08',           q: 'count=8&layout=rotunda' },
    { id: 'tier-low-08',          q: 'count=8', tier: 'low' },
    // Infinite Void — count scaling (radius + placement stress)
    { id: 'void-01',              q: 'venue=infinite-void&count=1' },
    { id: 'void-06',              q: 'venue=infinite-void&count=6' },
    { id: 'void-12-mixed',        q: 'venue=infinite-void&count=12' },
    { id: 'void-30-mixed',        q: 'venue=infinite-void&count=30' },
    { id: 'void-60-mixed',        q: 'venue=infinite-void&count=60' },
    // Infinite Void — orientation stress
    { id: 'void-08-portrait',     q: 'venue=infinite-void&count=8&orient=portrait' },
    { id: 'void-08-landscape',    q: 'venue=infinite-void&count=8&orient=landscape' },
    { id: 'void-08-extreme',      q: 'venue=infinite-void&count=8&orient=extreme' },
    // Infinite Void — tier degradation
    { id: 'void-tier-low-06',     q: 'venue=infinite-void&count=6', tier: 'low' },
    // Nebula Drift — "The Deep Field" (2026-09-08 audit pass, v2.0.0).
    // Count scaling: 6 (capacity floor), 12 (depth-band threshold), 40
    // (capacity ceiling — the two-band hang at full radius), plus the
    // walk-through poses: spawn arrival, eye-level at the rim looking
    // across the pools, and the look-up (band + meridian ring).
    { id: 'nebula-06',            q: 'venue=nebula-drift&count=6' },
    { id: 'nebula-12-mixed',      q: 'venue=nebula-drift&count=12' },
    { id: 'nebula-40-mixed',      q: 'venue=nebula-drift&count=40' },
    { id: 'nebula-cam-rim',       q: 'venue=nebula-drift&count=12',
      cam: { p: [0, 1.6, 9],   t: [0, 2.0, 0] } },
    { id: 'nebula-cam-up',        q: 'venue=nebula-drift&count=12',
      cam: { p: [0, 1.6, 0],   t: [0, 7.2, 11] } },
    // v2.2.0 identity pose — the arch CROWN. bandYaw is seeded per venue;
    // for the harness seed the crown sits toward -Z + X, so this forensic
    // pose frames the luminous core (the composition's hierarchy evidence).
    { id: 'nebula-cam-crown',     q: 'venue=nebula-drift&count=12',
      cam: { p: [0, 1.6, 0],   t: [5, 10, -8] } },
    // Tier degradation: the Deep Field must read on Lambert (low) —
    // composition carries the identity, motion does not.
    { id: 'nebula-tier-low-06',   q: 'venue=nebula-drift&count=6', tier: 'low' },
    // Rollback chain: the v1.0.0 starfield body must still render by config.
    { id: 'nebula-legacy-12',     q: 'venue=nebula-drift-legacy&count=12' },
    // Luxury Penthouse — "The Double Volume" (2026-09-09 full redesign,
    // v3.0.0). Count scaling: 6 (capacity floor), 12 (default hang), 40
    // (capacity ceiling — the full two-wing gallery). Walk-through poses
    // follow the new sequence: arrival down the LOW coved procession (the
    // sculpture + the lit seam ahead), the fireplace TERMINUS from the
    // seam, the double-height CORNER (glass wrapping two faces + terrace),
    // the seam shot (procession → volume lift), the lounge at the glass,
    // and the city depth through the north face.
    { id: 'pent-06',              q: 'venue=luxury-penthouse&count=6' },
    { id: 'pent-12-mixed',        q: 'venue=luxury-penthouse&count=12' },
    { id: 'pent-40-mixed',        q: 'venue=luxury-penthouse&count=40' },
    { id: 'pent-cam-arrival',     q: 'venue=luxury-penthouse&count=12',
      cam: { p: [3, 1.6, -7.25], t: [3, 1.9, 4] } },
    { id: 'pent-cam-seam',        q: 'venue=luxury-penthouse&count=12',
      cam: { p: [3, 1.6, -2.5],  t: [3, 2.4, 6] } },
    { id: 'pent-cam-fireplace',   q: 'venue=luxury-penthouse&count=12',
      cam: { p: [3, 1.6, 3.5],   t: [3, 2.6, 8.75] } },
    { id: 'pent-cam-corner',      q: 'venue=luxury-penthouse&count=12',
      cam: { p: [7.5, 1.6, 1.5], t: [16, 2.6, 7.5] } },
    { id: 'pent-cam-corridor-40', q: 'venue=luxury-penthouse&count=40',
      cam: { p: [3, 1.6, -14],   t: [3, 1.7, 10] } },
    { id: 'pent-cam-lounge',      q: 'venue=luxury-penthouse&count=12',
      cam: { p: [10, 1.6, 5.75],  t: [26.5, 1.7, 5.75] } },
    { id: 'pent-cam-view',        q: 'venue=luxury-penthouse&count=12',
      cam: { p: [12.5, 1.6, 5.75], t: [20, 2.7, 5.75] } },
    { id: 'pent-cam-city',        q: 'venue=luxury-penthouse&count=12',
      cam: { p: [21, 1.6, 5.75], t: [45, 3.0, 5.75] } },
    { id: 'pent-cam-above',       q: 'venue=luxury-penthouse&count=12',
      cam: { p: [3, 3.1, -6.5],  t: [6, 1.4, 7] } },
    // Tier degradation: the residence must read on Lambert (low).
    { id: 'pent-tier-low-06',     q: 'venue=luxury-penthouse&count=6', tier: 'low' },
    { id: 'pent-tier-low-12',     q: 'venue=luxury-penthouse&count=12', tier: 'low' },
    // Rollback chain: the v2.1.0 "Evening Light" body must still render by
    // config (the guarded migration's down() target).
    { id: 'pent-v21-12',          q: 'venue=luxury-penthouse-v21&count=12' },
    // Rollback chain: the v1.0.0 "Rooms" body must still render by config.
    { id: 'pent-legacy-12',       q: 'venue=luxury-penthouse-legacy&count=12' },
    // Crystal Cathedral — "The Luminous Arcade" (2026-09-07 audit).
    // Count scaling: 5 (capacity floor), 12 (depth-band threshold), 30 and
    // 40 (capacity ceiling — arcade bay plan + two-ring hang at scale).
    { id: 'cathedral-05',         q: 'venue=crystal-cathedral&count=5' },
    { id: 'cathedral-12-mixed',   q: 'venue=crystal-cathedral&count=12' },
    { id: 'cathedral-30-mixed',   q: 'venue=crystal-cathedral&count=30' },
    { id: 'cathedral-40-mixed',   q: 'venue=crystal-cathedral&count=40' },
    // Orientation stress on the default hang
    { id: 'cathedral-08-portrait',  q: 'venue=crystal-cathedral&count=8&orient=portrait' },
    { id: 'cathedral-08-landscape', q: 'venue=crystal-cathedral&count=8&orient=landscape' },
    { id: 'cathedral-08-extreme',   q: 'venue=crystal-cathedral&count=8&orient=extreme' },
    // Tier degradation: the arcade must read on Lambert + gloss floor (low),
    // and the walkable interior must read at eye level on every tier.
    { id: 'cathedral-tier-low-06',  q: 'venue=crystal-cathedral&count=6', tier: 'low' },
    // Forensic poses: (a) the look-up shot — vault, boss ring, oculus;
    // (b) eye-level down the art-bay wall; (c) the crossing from the rim.
    { id: 'cathedral-cam-up',     q: 'venue=crystal-cathedral&count=12',
      cam: { p: [0, 1.6, 0],   t: [0, 18.8, 0] } },
    { id: 'cathedral-cam-wall',   q: 'venue=crystal-cathedral&count=12',
      cam: { p: [0, 1.6, 6],   t: [0, 1.8, 14] } },
    { id: 'cathedral-cam-rim',    q: 'venue=crystal-cathedral&count=12',
      cam: { p: [0, 1.6, 13],  t: [0, 2.2, 0] } },
    // Rollback chain: the IT2 colonnade body must still render by config.
    { id: 'cathedral-legacy-12',  q: 'venue=crystal-cathedral-legacy&count=12' },
    // Deployed-screenshot incident regression: the exact overridden-gallery
    // config (purple background + dim rig) vs the restored venue defaults.
    { id: 'void-overridden-12',   q: 'venue=infinite-void-overridden&count=12' },
    // Industrial Loft — default corridor layout + declared alternatives
    { id: 'loft-corridor-08',     q: 'venue=industrial-loft&count=8' },
    { id: 'loft-corridor-16',     q: 'venue=industrial-loft&count=16' },
    { id: 'loft-corridor-24',     q: 'venue=industrial-loft&count=24' },
    { id: 'loft-corridor-01',     q: 'venue=industrial-loft&count=1' },
    { id: 'loft-corridor-60',     q: 'venue=industrial-loft&count=60' },
    { id: 'loft-square-12',       q: 'venue=industrial-loft&count=12&layout=square' },
    { id: 'loft-square-30',       q: 'venue=industrial-loft&count=30&layout=square' },
    { id: 'loft-lshape-08',       q: 'venue=industrial-loft&count=8&layout=l-shape' },
    // Industrial Loft — orientation stress on the default corridor
    { id: 'loft-corridor-portrait',  q: 'venue=industrial-loft&count=8&orient=portrait' },
    { id: 'loft-corridor-landscape', q: 'venue=industrial-loft&count=8&orient=landscape' },
    { id: 'loft-corridor-extreme',   q: 'venue=industrial-loft&count=8&orient=extreme' },
    // Industrial Loft — tier degradation
    { id: 'loft-tier-low-08',     q: 'venue=industrial-loft&count=8', tier: 'low' },
    // Japanese Zen Gallery — "The Quiet Procession" (v2.0.0) matrix
    { id: 'zen-square-01',          q: 'venue=zen-gallery&count=1' },
    { id: 'zen-square-08',          q: 'venue=zen-gallery&count=8' },
    { id: 'zen-square-12-mixed',    q: 'venue=zen-gallery&count=12' },
    { id: 'zen-square-30-mixed',    q: 'venue=zen-gallery&count=30' },
    { id: 'zen-square-60-mixed',    q: 'venue=zen-gallery&count=60' },
    { id: 'zen-square-portrait',    q: 'venue=zen-gallery&count=8&orient=portrait' },
    { id: 'zen-square-landscape',   q: 'venue=zen-gallery&count=8&orient=landscape' },
    { id: 'zen-square-extreme',     q: 'venue=zen-gallery&count=8&orient=extreme' },
    { id: 'zen-corridor-08',        q: 'venue=zen-gallery&count=8&layout=corridor' },
    { id: 'zen-corridor-16',        q: 'venue=zen-gallery&count=16&layout=corridor' },
    { id: 'zen-lshape-08',          q: 'venue=zen-gallery&count=8&layout=l-shape' },
    { id: 'zen-tier-low-08',        q: 'venue=zen-gallery&count=8', tier: 'low' },
    // Zen — elevated corner view across the room (procession read: fins,
    // bays, clerestory and rafter rhythm in one frame).
    { id: 'zen-live-wide-30',
      q: 'venue=zen-gallery&count=30',
      cam: { p: [-13, 2.1, 13], t: [6, 1.3, -10] } },
    // Dark Museum — FORENSIC BEFORE (v1.0.0 forensic body; never update it)
    { id: 'museum-before-square-08',  q: 'venue=dark-museum-v1&count=8' },
    { id: 'museum-before-rotunda-08', q: 'venue=dark-museum-v1&count=8&layout=rotunda' },
    // Dark Museum — diagnostic (all dynamic lights off)
    { id: 'museum-diag-dark',         q: 'venue=dm-dark&count=8' },
    // Dark Museum — diagnostic (ambient 6 — is ambient wiring alive?)
    { id: 'museum-diag-bright',       q: 'venue=dm-bright&count=8' },
    { id: 'museum-diag-tint55',       q: 'venue=dm-tint55&count=8' },
    { id: 'museum-diag-untint',       q: 'venue=dm-untint&count=8' },
    // Dark Museum — v2 deepening matrix
    { id: 'museum-square-01',         q: 'venue=dark-museum&count=1' },
    { id: 'museum-square-08',         q: 'venue=dark-museum&count=8' },
    { id: 'museum-square-12-mixed',   q: 'venue=dark-museum&count=12' },
    { id: 'museum-square-30-mixed',   q: 'venue=dark-museum&count=30' },
    { id: 'museum-square-60-mixed',   q: 'venue=dark-museum&count=60' },
    { id: 'museum-square-portrait',   q: 'venue=dark-museum&count=8&orient=portrait' },
    { id: 'museum-square-landscape',  q: 'venue=dark-museum&count=8&orient=landscape' },
    { id: 'museum-square-extreme',    q: 'venue=dark-museum&count=8&orient=extreme' },
    { id: 'museum-rotunda-08',        q: 'venue=dark-museum&count=8&layout=rotunda' },
    { id: 'museum-rotunda-16',        q: 'venue=dark-museum&count=16&layout=rotunda' },
    { id: 'museum-tier-low-08',       q: 'venue=dark-museum&count=8', tier: 'low' },
    // Dark Museum — FORENSIC REPRO of the deployed override incident
    // (v2 venue + stale gallery override layer: violet fog, dim rig,
    //  open_air, planar floor) + isolation variant without open_air.
    { id: 'museum-overridden-08',     q: 'venue=dark-museum-overridden&count=8' },
    { id: 'museum-fogonly-08',        q: 'venue=dark-museum-fogonly&count=8' },
    // Dark Museum — live-deployed framing parity: elevated corner view across
    // the room (matches the user's post-hotfix screenshot) so the polished
    // stone floor can be compared at the same grazing angle as production.
    { id: 'museum-live-wide-30',
      q: 'venue=dark-museum&count=30',
      cam: { p: [-13, 2.1, 13], t: [6, 1.3, -10] } },
    // Dark Museum — post-hotfix RESIDUAL repro: healed owned keys + surviving
    // material/post_fx layer, framed like the user's second screenshot.
    { id: 'museum-residual-wide-30',
      q: 'venue=dark-museum-residual&count=30',
      cam: { p: [-13, 2.1, 13], t: [6, 1.3, -10] } },
];

const tierInit = {
    high: `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
           Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
           // Heavy scenarios (30-60 works, ~20 lights) can dip under the
           // 35fps downgrade threshold under SwiftShader even at boot size,
           // retro-downgrading the tier mid-capture. Stretch the monotonic
           // clock 250x so the deferred FPS benchmark's 2 s warmup needs
           // ~8 REAL minutes before it can even sample — a capture session
           // (~15 s) can never reach a verdict, so the static high-tier
           // detection is authoritative for every still. (Measured on the
           // cathedral: transmission + Reflector + bloom renders at ~1-4 fps
           // REAL under SwiftShader, so a mere 4-8x stretch still let the
           // benchmark fire "1.1 fps < 35" mid-capture — the tier race that
           // made some review stills render as Lambert haze. Real GPUs hold
           // 60 fps; this only pins the QA harness, never the product.)
           const __origNow = performance.now.bind(performance);
           const __t0 = __origNow();
           performance.now = () => __t0 + (__origNow() - __t0) * 0.004;`,
    low:  `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>2});
           Object.defineProperty(navigator,'deviceMemory',{get:()=>2});
           Object.defineProperty(navigator,'maxTouchPoints',{get:()=>0});`,
};

// Headless compositing throttles real rAF (~17fps) during page load, which
// trips the viewer's own 35fps FPS-benchmark and retroactively downgrades
// the tier — and SwiftShader at 720p genuinely renders ~4fps. So: boot at a
// small viewport with a timer-driven rAF (4 ms hop) so the benchmark measures
// true per-frame cost instead of compositor stalls, THEN resize to the
// capture resolution once the benchmark window has passed.
const BOOT_VIEWPORT = { width: 320, height: 180 };
const SHOT_VIEWPORT = { width: 640, height: 360 };

async function run() {
    const browser = await chromium.launch({
        args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-lcd-text',
               '--disable-gpu-vsync', '--disable-frame-rate-limit',
               '--disable-renderer-backgrounding', '--disable-background-timer-throttling',
               '--disable-backgrounding-occluded-windows'],
    });
    const report = [];
    for (const sc of SCENARIOS) {
        if (ONLY.length && !ONLY.includes(sc.id)) continue;
        const tier = sc.tier || 'high';
        const ctx = await browser.newContext({
            viewport: BOOT_VIEWPORT,
            deviceScaleFactor: 1,
        });
        await ctx.addInitScript(`
            ${tierInit[tier]}
            // Hide the GPU string so detectLowEnd's software-renderer regex
            // cannot see SwiftShader (keeps the requested tier authoritative).
            const origGetExtension = WebGL2RenderingContext.prototype.getExtension;
            WebGL2RenderingContext.prototype.getExtension = function(name) {
                if (name === 'WEBGL_debug_renderer_info') return null;
                return origGetExtension.call(this, name);
            };
            let _rafId = 0;
            window.requestAnimationFrame = (cb) => setTimeout(() => cb(performance.now()), 4);
            window.cancelAnimationFrame = (id) => clearTimeout(id);
        `);
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(String(e)));
        page.on('crash', () => errors.push('PAGE CRASHED'));
        page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

        await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?${sc.q}`, { waitUntil: 'load' });

        // Wait for the viewer to unlock Enter (enterReady or 100%)
        try {
            await page.waitForFunction(() => {
                const b = document.getElementById('enter-btn');
                return b && b.style.pointerEvents === 'auto';
            }, { timeout: 45000 });
        } catch { errors.push('enter-btn never unlocked'); }

        // Enter, then let the arrival choreography finish (1.5 s dolly + margin)
        await page.$eval('#enter-btn', el => el.click());
        await page.waitForTimeout(Math.round(10000 * SETTLE));       // FPS-benchmark window closes
        await page.setViewportSize(SHOT_VIEWPORT);    // capture resolution
        await page.waitForTimeout(Math.round(9000 * SETTLE));        // frames at capture res + background texture stream

        // Hide HUD chrome for clean venue captures (crosshair, buttons, hint)
        await page.addStyleTag({ content: '#crosshair,#ui-layer,#controls-hint{display:none!important}' }).catch(() => {});

        // Optional scripted camera (forensic framing parity with a live shot).
        // PointerLockControls only mutates rotation on real mousemove events,
        // so a direct position.set + lookAt persists for the capture window.
        if (sc.cam) {
            await page.evaluate((cam) => {
                const s = window.__exospace?.scene;
                if (!s?.camera) return;
                s.camera.position.set(...cam.p);
                s.camera.lookAt(...cam.t);
                s.camera.updateMatrixWorld();
            }, sc.cam);
            await page.waitForTimeout(2000);   // let a full render loop pass
        }

        // CDP screenshot — Playwright's own screenshot path waits for
        // compositor stability that never settles while the scene renders
        // continuously under SwiftShader. Page.captureScreenshot must run
        // WHILE the loop renders (halting invalidates the WebGL drawing
        // buffer and captures a blank frame).
        //
        // CATHEDRAL AUDIT GUARD: a capture (or session) failure on ONE
        // scenario used to reject out of run() and kill the WHOLE run —
        // every later scenario lost its shot because scenario N's
        // SwiftShader compositor died. The heavy multi-pass venues (the
        // cathedral's transmission + planar reflection are the first
        // combination in the catalog) make that a routine event under
        // software rasterization, so the capture is now best-effort per
        // scenario: a dead capture is logged into the report and the run
        // continues with the remaining scenarios.
        let pngB64 = null;
        try {
            const cdp = await ctx.newCDPSession(page);
            const shotData = await cdp.send('Page.captureScreenshot', { format: 'png' });
            pngB64 = shotData.data;
        } catch (e) {
            errors.push(`capture failed: ${String(e).slice(0, 120)}`);
        }
        const shot = path.resolve(rootDir, OUT, `${sc.id}.png`);
        if (pngB64) {
            await writeFile(shot, Buffer.from(pngB64, 'base64'));
        }

        // Pull render stats from the scene (draw calls, triangles, lights).
        // Non-fatal: under SwiftShader the page occasionally dies between
        // the CDP capture and this evaluate — the SHOT is the deliverable,
        // a stats crash must not discard it.
        let stats = null;
        if (STATS) {
            try {
                stats = await Promise.race([
                    page.evaluate(() => {
                        const s = window.__exospace?.scene;
                        if (!s?.renderer) return null;
                        const i = s.renderer.info;
                        return {
                            drawCalls: i.render.calls, triangles: i.render.triangles,
                            geometries: i.memory.geometries, textures: i.memory.textures,
                            sceneObjects: s.scene ? s.scene.children.length : null,
                            lights: s.scene ? s.scene.children.filter(o => o.isLight).length : null,
                            camera: s.camera ? { x: +s.camera.position.x.toFixed(2), y: +s.camera.position.y.toFixed(2), z: +s.camera.position.z.toFixed(2) } : null,
                            artworks: s.artworks?.length ?? null,
                            roomBounds: s.roomBounds, layout: s._layoutMeta?.type,
                            tier: { lowEnd: !!s.isLowEnd, mobile: !!s.isMobile, mobileTier: !!s._isMobileTier },
                            exposure: s.renderer.toneMappingExposure,
                            fog: s.scene.fog ? { near: s.scene.fog.near, far: s.scene.fog.far, color: '#' + s.scene.fog.color.getHexString() } : null,
                        };
                    }),
                    new Promise(resolve => setTimeout(() => resolve(null), 15000)),
                ]);
            } catch { stats = null; }   // shot already captured — keep going
        }
        report.push({ id: sc.id, tier, shot: path.basename(shot), errors, stats });
        await ctx.close();
    }
    await browser.close();
    server.close();
    console.log(JSON.stringify(report, null, 2));
}
await run();
