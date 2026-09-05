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
];

const tierInit = {
    high: `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
           Object.defineProperty(navigator,'deviceMemory',{get:()=>8});`,
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
        await page.waitForTimeout(7000);              // FPS-benchmark window closes
        await page.setViewportSize(SHOT_VIEWPORT);    // capture resolution
        await page.waitForTimeout(6000);              // frames at capture res

        // Hide HUD chrome for clean venue captures (crosshair, buttons, hint)
        await page.addStyleTag({ content: '#crosshair,#ui-layer,#controls-hint{display:none!important}' }).catch(() => {});

        // CDP screenshot — Playwright's own screenshot path waits for
        // compositor stability that never settles while the scene renders
        // continuously under SwiftShader. Page.captureScreenshot must run
        // WHILE the loop renders (halting invalidates the WebGL drawing
        // buffer and captures a blank frame).
        const cdp = await ctx.newCDPSession(page);
        const { data: pngB64 } = await cdp.send('Page.captureScreenshot', { format: 'png' });
        const shot = path.resolve(rootDir, OUT, `${sc.id}.png`);
        await writeFile(shot, Buffer.from(pngB64, 'base64'));

        // Pull render stats from the scene (draw calls, triangles, lights)
        let stats = null;
        if (STATS) {
            stats = await page.evaluate(() => {
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
            });
        }
        report.push({ id: sc.id, tier, shot: path.basename(shot), errors, stats });
        await ctx.close();
    }
    await browser.close();
    server.close();
    console.log(JSON.stringify(report, null, 2));
}
await run();
