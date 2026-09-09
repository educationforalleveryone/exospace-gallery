#!/usr/bin/env node
// probe_media_wall_shots.mjs — before/after evidence for the v3.1.0 Media Wall
// iteration. Boots the harness ONCE per venue (fixed 640x360 viewport — the
// SwiftShader resize stall), shoots matched poses, and sanity-probes the new
// scene objects (media bezel + screen child, picture bar, lamp, knot).
import { createServer } from 'node:http';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = 4219;
const OUT = process.env.OUT || 'shots-media-wall';
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

// [id, px,py,pz, tx,ty,tz] — matched to the user's production screenshots
const POSES = [
    ['lounge-arrival', 27.0, 1.6, 6.5,  33.0, 1.5, 13.0],  // s1 vantage: walk toward lounge
    ['media-close',    30.2, 1.6, 12.2, 33.0, 1.05, 10.55],// screen head-on from the rug
    ['artwall',         2.6, 1.6, 12.75,  0.4, 2.6, 12.75], // statement wall + picture light
    ['sculpture',       3.0, 1.6, 7.2,   3.0, 1.15, 11.25], // knot rest check
    ['city',           21.0, 1.6, 5.75, 45.0, 3.0, 5.75],  // s2 vantage: glass + lamp corner
];

const browser = await chromium.launch({
    args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-lcd-text',
           '--disable-gpu-vsync', '--disable-frame-rate-limit', '--disable-renderer-backgrounding',
           '--disable-background-timer-throttling', '--disable-backgrounding-occluded-windows'],
});

const VENUES = [
    ['v30', 'luxury-penthouse'],
    ['v31', 'luxury-penthouse-v31'],
];

for (const [tag, venueKey] of VENUES) {
    const errors = [];

    // Sanity + shots run in ONE fresh context per pose (SwiftShader is
    // memory-fragile on long-lived pages — the original forensic_shots
    // convention). The sanity probe rides on the first pose's boot.
    for (let pi = 0; pi < POSES.length; pi++) {
        const [id, px, py, pz, tx, ty, tz] = POSES[pi];
        const ctx = await browser.newContext({ viewport: { width: 640, height: 360 }, deviceScaleFactor: 1 });
        await ctx.addInitScript(`
            Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
            Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
            const orig = WebGL2RenderingContext.prototype.getExtension;
            WebGL2RenderingContext.prototype.getExtension = function(name) {
                if (name === 'WEBGL_debug_renderer_info') return null;
                return orig.call(this, name);
            };
            window.requestAnimationFrame = (cb) => setTimeout(() => cb(performance.now()), 4);
            window.cancelAnimationFrame = (id) => clearTimeout(id);
        `);
        const page = await ctx.newPage();
        page.on('pageerror', e => errors.push('PAGEERROR: ' + String(e).slice(0, 250)));
        page.on('console', msg => { if (msg.type() === 'error') errors.push('CONSOLE: ' + msg.text().slice(0, 160)); });

        process.stdout.write(`[${tag}] ${id}: booting ${venueKey}... `);
        await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?venue=${venueKey}&count=24&orient=mixed`, { waitUntil: 'load', timeout: 90000 });
        try {
            await page.waitForFunction(() => {
                const b = document.getElementById('enter-btn');
                return b && b.style.pointerEvents === 'auto';
            }, { timeout: 90000 });
            await page.$eval('#enter-btn', el => el.click());
        } catch (e) { errors.push('enter flow failed: ' + String(e).slice(0, 120)); }
        await page.waitForTimeout(11000);
        await page.addStyleTag({ content: '#crosshair,#ui-layer,#controls-hint{display:none!important}' }).catch(() => {});

        if (pi === 0) {
            const probe = await page.evaluate(() => {
                const s = window.__exospace?.scene;
                if (!s?.scene) return { error: 'no scene' };
                const get = n => s.scene.getObjectByName(n);
                const bezel = get('structure:media-bezel');
                const screen = bezel?.children?.find(c => c.isMesh);
                const lamp = get('structure:lamp-pole');
                const knot = get('structure:sculpture-knot');
                return {
                    mediaConsole: !!get('structure:media-console'),
                    mediaBezel: !!bezel,
                    screenChild: !!screen,
                    screenMat: screen?.material?.type,
                    lampPos: lamp ? [lamp.position.x, lamp.position.z] : null,
                    knotY: knot ? +knot.position.y.toFixed(2) : null,
                    artBar: !!get('structure:art-wall-light-bar'),
                };
            });
            console.log(`probe: ${JSON.stringify(probe)}`);
        } else {
            console.log('booted');
        }

        await page.evaluate((arr) => {
            const s = window.__exospace?.scene;
            if (!s?.camera) return;
            s.camera.position.set(arr[1], arr[2], arr[3]);
            s.camera.lookAt(arr[4], arr[5], arr[6]);
            s.camera.updateMatrixWorld();
        }, [id, px, py, pz, tx, ty, tz]);
        await page.waitForTimeout(2200);
        const cdp = await ctx.newCDPSession(page);
        const shot = await cdp.send('Page.captureScreenshot', { format: 'png' });
        await writeFile(path.join(rootDir, OUT, `${tag}-${id}.png`), Buffer.from(shot.data, 'base64'));
        console.log(`  saved ${tag}-${id}.png`);
        await ctx.close();
    }

    if (errors.length) {
        console.log(`[${tag}] ERRORS:`);
        for (const e of errors.slice(0, 6)) console.log('   ', e);
    } else {
        console.log(`[${tag}] zero console/page errors`);
    }
}
await browser.close();
server.close();
process.exit(0);
