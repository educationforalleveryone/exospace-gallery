#!/usr/bin/env node
// forensic_shots.mjs — targeted forensic matrix for the Luxury Penthouse
// investigation: venue body (v3 / v2.1 / legacy) × tier (high / low) × pose.
// Reuses the shoot.mjs boot recipe (SwiftShader + tier pinning + rAF hop).
import { createServer } from 'node:http';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = Number(process.env.PORT || 4201);
const OUT = process.env.OUT || 'shots-forensic';
const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript',
    '.css': 'text/css', '.json': 'application/json', '.jpg': 'image/jpeg', '.png': 'image/png',
    '.hdr': 'application/octet-stream', '.glb': 'model/gltf-binary', '.wasm': 'application/wasm' };

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
await mkdir(path.resolve(rootDir, OUT), { recursive: true });

// venue body: v3 | v21 | legacy  → harness venue keys
const BODY = { v3: 'luxury-penthouse', v21: 'luxury-penthouse-v21', legacy: 'luxury-penthouse-legacy' };

// poses: [id, px,py,pz, tx,ty,tz] — matched to the production screenshots
const POSES = [
    ['arrival',  3, 1.6, -6.5,  3, 1.9, 6],    // spawn sightline down wing A
    ['corridor', 3, 1.6, -2,    3, 1.7, 12],   // deeper into the procession
    ['city',     21, 1.6, 5.75, 45, 3.0, 5.75],// east glass → the skyline
    ['seam',     3, 1.6, -2.5,  3, 2.4, 6],    // the step
    ['corner',   7.5, 1.6, 1.5, 16, 2.6, 7.5], // double-height glass corner
    ['wide',     14, 2.4, 0,    6, 1.4, -8],   // overview from wing B
];

const tierInit = {
    high: `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
           Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
           const __origNow = performance.now.bind(performance);
           const __t0 = __origNow();
           performance.now = () => __t0 + (__origNow() - __t0) * 0.004;`,
    low:  `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>2});
           Object.defineProperty(navigator,'deviceMemory',{get:()=>2});
           Object.defineProperty(navigator,'maxTouchPoints',{get:()=>0});`,
};

const BODY_SEL = process.env.BODIES || 'v3,legacy';
const TIER_SEL = process.env.TIERS || 'high';
const POSE_SEL = process.env.POSES_ || 'arrival,corridor,city,seam,corner,wide';

const matrix = [];
for (const b of BODY_SEL.split(',')) for (const t of TIER_SEL.split(',')) for (const p of POSE_SEL.split(',')) {
    matrix.push({ body: b, tier: t, pose: p });
}

async function run() {
    const browser = await chromium.launch({
        args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-lcd-text',
               '--disable-gpu-vsync', '--disable-frame-rate-limit',
               '--disable-renderer-backgrounding', '--disable-background-timer-throttling',
               '--disable-backgrounding-occluded-windows'],
    });
    for (const m of matrix) {
        const id = `${m.body}-${m.pose}-${m.tier}`;
        const ctx = await browser.newContext({ viewport: { width: 320, height: 180 }, deviceScaleFactor: 1 });
        await ctx.addInitScript(`
            ${tierInit[m.tier]}
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
        page.on('pageerror', e => errors.push(String(e).slice(0, 200)));
        page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text().slice(0, 120)); });

        const q = `venue=${BODY[m.body]}&count=12`;
        await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?${q}`, { waitUntil: 'load' });
        try {
            await page.waitForFunction(() => {
                const b = document.getElementById('enter-btn');
                return b && b.style.pointerEvents === 'auto';
            }, { timeout: 45000 });
        } catch { errors.push('enter-btn never unlocked'); }

        try {
            await page.$eval('#enter-btn', el => el.click());
        } catch { errors.push('enter-btn click failed'); }
        await page.waitForTimeout(10000);
        await page.setViewportSize({ width: 640, height: 360 });
        await page.waitForTimeout(9000);
        await page.addStyleTag({ content: '#crosshair,#ui-layer,#controls-hint{display:none!important}' }).catch(() => {});

        const pose = POSES.find(p => p[0] === m.pose);
        if (pose) {
            await page.evaluate((arr) => {
                const s = window.__exospace?.scene;
                if (!s?.camera) return;
                s.camera.position.set(arr[1], arr[2], arr[3]);
                s.camera.lookAt(arr[4], arr[5], arr[6]);
                s.camera.updateMatrixWorld();
            }, pose);
            await page.waitForTimeout(2000);
        }

        let pngB64 = null;
        try {
            const cdp = await ctx.newCDPSession(page);
            const shotData = await cdp.send('Page.captureScreenshot', { format: 'png' });
            pngB64 = shotData.data;
        } catch (e) { errors.push(`capture failed: ${String(e).slice(0, 120)}`); }
        if (pngB64) await writeFile(path.resolve(rootDir, OUT, `${id}.png`), Buffer.from(pngB64, 'base64'));

        // one-line status
        let line = `${id}: ${pngB64 ? 'OK' : 'NO SHOT'}`;
        const realErrors = errors.filter(e => !e.includes('Failed to load resource'));
        if (realErrors.length) line += ` | errors: ${realErrors.slice(0, 2).join(' ;; ')}`;
        console.log(line);
        await ctx.close();
    }
    await browser.close();
    server.close();
}
await run();
