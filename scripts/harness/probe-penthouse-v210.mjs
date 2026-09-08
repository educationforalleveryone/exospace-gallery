#!/usr/bin/env node
// Probe the LIVE penthouse scene: dump ceiling/floor material colors, the
// light roster, and sample the rendered ceiling luminance.
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.jpg': 'image/jpeg', '.png': 'image/png' };
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, 'http://x');
        let p = path.join(publicDir, decodeURIComponent(url.pathname));
        const data = await readFile(p);
        res.writeHead(200, { 'Content-Type': MIME[path.extname(p)] || 'application/octet-stream' });
        res.end(data);
    } catch { res.writeHead(404); res.end(); }
});
await new Promise(r => server.listen(4207, r));

const browser = await chromium.launch({ args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-lcd-text', '--disable-gpu-vsync', '--disable-frame-rate-limit', '--disable-renderer-backgrounding', '--disable-background-timer-throttling', '--disable-backgrounding-occluded-windows'] });
const ctx = await browser.newContext({ viewport: { width: 320, height: 180 } });
await ctx.addInitScript(`
    Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
    Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
`);
const page = await ctx.newPage();
page.on('pageerror', e => console.log('PAGEERROR', String(e).slice(0, 200)));
await page.goto('http://127.0.0.1:4207/harness/scripts/harness/harness.html?venue=luxury-penthouse&count=12', { waitUntil: 'load' });
await page.waitForFunction(() => {
    const b = document.getElementById('enter-btn');
    return b && b.style.pointerEvents === 'auto';
}, { timeout: 45000 });
await page.$eval('#enter-btn', el => el.click());
await page.waitForTimeout(16000);

const info = await page.evaluate(() => {
    const s = window.__exospace?.scene;
    if (!s?.scene) return { err: 'no scene' };
    const out = { named: [], lights: 0 };
    s.scene.traverse(o => {
        if (o.isLight) out.lights++;
        if (o.isMesh && /structure:/.test(o.name || '')) {
            const p = o.position;
            const r = o.rotation;
            out.named.push({
                n: (o.name || '').replace('structure:', ''),
                p: [+p.x.toFixed(1), +p.y.toFixed(1), +p.z.toFixed(1)],
                ry: +r.y.toFixed(2),
                emissive: o.material.emissive ? o.material.emissive.getHexString() + '@' + o.material.emissiveIntensity : null,
                visible: o.visible,
            });
        }
    });
    out.named = out.named.filter(m => /sky|city|horizon|haze/.test(m.n));
    return out;
});
console.log(JSON.stringify(info, null, 1));

await browser.close();
server.close();
