#!/usr/bin/env node
// probe_v31_shots.mjs — AFTER shots for the v3.1.0 Media Wall iteration.
// One FRESH BROWSER per pose (SwiftShader in this sandbox OOM-kills long-lived
// pages); v31 only — the v3.0.0 "before" is evidenced by the production
// screenshots + shots-forensic/v3-*.png.
import { createServer } from 'node:http';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = 4221;
const OUT = 'shots-v31';
const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css',
    '.json': 'application/json', '.jpg': 'image/jpeg', '.png': 'image/png', '.hdr': 'application/octet-stream' };
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
        let p = decodeURIComponent(url.pathname);
        if (p.endsWith('/')) p += 'index.html';
        const fp = path.join(publicDir, p);
        if (!fp.startsWith(publicDir) || !existsSync(fp)) { res.writeHead(404); res.end('nf'); return; }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(fp)] || 'application/octet-stream' });
        res.end(await readFile(fp));
    } catch { res.writeHead(500); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const { chromium } = await import('playwright');
await mkdir(path.resolve(rootDir, OUT), { recursive: true });

// Auto-aim poses: computed IN-PAGE from the actual furniture positions —
// the room scales with artwork count, so absolute poses lie.
const ALL = {
    'lounge-arrival': 'auto',
    'media-close': 'auto',
    'artwall': 'auto',
    'sculpture': 'auto',
    'city': 'auto',
};
const sel = (process.env.POSES_ || 'lounge-arrival,media-close,artwall,sculpture').split(',');
const POSES = sel.filter(k => ALL[k]).map(k => [k, 0, 0, 0, 0, 0, 0]);

for (const [id, px, py, pz, tx, ty, tz] of POSES) {
    const out = path.join(rootDir, OUT, `${id}.png`);
    if (existsSync(out)) { console.log(`${id}: already done, skip`); continue; }
    let browser = null;
    try {
        browser = await chromium.launch({
            args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-lcd-text',
                   '--disable-gpu-vsync', '--disable-frame-rate-limit', '--disable-renderer-backgrounding',
                   '--disable-background-timer-throttling', '--disable-backgrounding-occluded-windows',
                   '--disable-dev-shm-usage'],
        });
        const ctx = await browser.newContext({ viewport: { width: 480, height: 270 }, deviceScaleFactor: 1 });
        await ctx.addInitScript(`
            Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>2});
            Object.defineProperty(navigator,'deviceMemory',{get:()=>2});
            const orig = WebGL2RenderingContext.prototype.getExtension;
            WebGL2RenderingContext.prototype.getExtension = function(name) {
                if (name === 'WEBGL_debug_renderer_info') return null;
                return orig.call(this, name);
            };
            window.requestAnimationFrame = (cb) => setTimeout(() => cb(performance.now()), 4);
            window.cancelAnimationFrame = (id) => clearTimeout(id);
        `);
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(String(e).slice(0, 200)));
        page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text().slice(0, 140)); });

        process.stdout.write(`${id}: booting v31... `);
        await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?venue=luxury-penthouse-v31&count=8`, { waitUntil: 'load', timeout: 90000 });
        await page.waitForFunction(() => {
            const b = document.getElementById('enter-btn');
            return b && b.style.pointerEvents === 'auto';
        }, { timeout: 90000 });
        await page.$eval('#enter-btn', el => el.click());
        await page.waitForTimeout(11000);
        await page.addStyleTag({ content: '#crosshair,#ui-layer,#controls-hint{display:none!important}' }).catch(() => {});

        if (id === 'lounge-arrival') {
            const probe = await page.evaluate(() => {
                const s = window.__exospace?.scene;
                if (!s?.scene) return { error: 'no scene' };
                const get = n => s.scene.getObjectByName(n);
                const bezel = get('structure:media-bezel');
                const screen = bezel?.children?.find(c => c.isMesh);
                return {
                    console: !!get('structure:media-console'),
                    bezel: !!bezel,
                    screen: !!screen,
                    mat: screen?.material?.type,
                    lamp: (() => { const l = get('structure:lamp-pole'); return l ? [l.position.x, l.position.z] : null; })(),
                    knotY: (() => { const k = get('structure:sculpture-knot'); return k ? +k.position.y.toFixed(2) : null; })(),
                    artBar: !!get('structure:art-wall-light-bar'),
                };
            });
            console.log(`probe ${JSON.stringify(probe)}`);
        }

        const aimed = await page.evaluate((pid) => {
            const s = window.__exospace?.scene;
            if (!s?.camera) return null;
            const get = n => s.scene.getObjectByName(n);
            const P = o => o ? { x: o.position.x, y: o.position.y, z: o.position.z } : null;
            const console_ = P(get('structure:media-console'));
            const bezel = P(get('structure:media-bezel'));
            const sofa = P(get('structure:sofa-base'));
            const bar = P(get('structure:art-wall-light-bar'));
            const plinth = P(get('structure:plinth'));
            let cam = null, tgt = null;
            if (pid === 'lounge-arrival' && console_) {
                // arrival sightline: west-south-west of the console, the walk
                // from wing A — the screen's NW face + the sofa in frame.
                tgt = { x: console_.x + 0.4, y: 0.85, z: console_.z + 0.5 };
                cam = { x: console_.x - 6.4, y: 1.8, z: console_.z + 1.2 };
            } else if (pid === 'media-close' && bezel) {
                cam = { x: bezel.x - 3.2, y: 1.55, z: bezel.z + 2.6 };
                tgt = { x: bezel.x, y: bezel.y, z: bezel.z };
            } else if (pid === 'artwall' && bar) {
                cam = { x: bar.x + 4.2, y: bar.y - 0.55, z: bar.z };
                tgt = { x: bar.x, y: bar.y - 0.75, z: bar.z };
            } else if (pid === 'sculpture' && plinth) {
                cam = { x: plinth.x, y: 1.6, z: plinth.z - 4.2 };
                tgt = { x: plinth.x, y: 1.05, z: plinth.z };
            }
            if (!cam) return null;
            s.camera.position.set(cam.x, cam.y, cam.z);
            s.camera.lookAt(tgt.x, tgt.y, tgt.z);
            s.camera.updateMatrixWorld();
            return { cam, tgt };
        }, id);
        if (!aimed) console.log(`  (${id}: auto-aim failed — object missing)`);
        await page.waitForTimeout(2500);
        const cdp = await ctx.newCDPSession(page);
        const shot = await cdp.send('Page.captureScreenshot', { format: 'png' });
        await writeFile(out, Buffer.from(shot.data, 'base64'));
        console.log(`saved (${errors.length} console errors${errors.length ? ': ' + errors.slice(0, 2).join(' | ') : ''})`);
    } catch (e) {
        console.log(`FAILED: ${String(e).slice(0, 160)}`);
    } finally {
        if (browser) await browser.close().catch(() => {});
    }
}
server.close();
process.exit(0);
