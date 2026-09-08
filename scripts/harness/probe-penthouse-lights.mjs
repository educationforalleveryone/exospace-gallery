#!/usr/bin/env node
// Probe the LIVE harness scene: count lights, find fire-glow, sample the
// stone's lit luminance. Run: node scripts/harness/probe-penthouse-lights.mjs
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
        if (url.pathname === '/' || url.pathname === '/harness') p = path.join(publicDir, 'harness/harness.html');
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
page.on('console', m => console.log('[console]', m.type(), m.text().slice(0, 160)));
page.on('pageerror', e => console.log('[pageerror]', String(e).slice(0, 300)));
await page.goto('http://127.0.0.1:4207/harness/scripts/harness/harness.html?venue=luxury-penthouse&count=12', { waitUntil: 'domcontentloaded' });
await page.waitForFunction('window.__HARNESS_READY === true', null, { timeout: 15000 }).catch(async () => {
    console.log('[warn] __HARNESS_READY not set — dumping page state');
    console.log(await page.evaluate(() => ({ ready: window.__HARNESS_READY, url: location.href, title: document.title, body: document.body.innerText.slice(0, 120) })));
});
// enter past the curtain if present
await page.evaluate(() => { const b = document.getElementById('enter-btn'); if (b) b.click(); else document.querySelector('[data-enter], .curtain button')?.click(); });
await page.waitForTimeout(9000);
const report = await page.evaluate(() => {
    const scene = window.__exospace?.scene;
    if (!scene || !scene.scene) return { error: 'no scene' };
    const lights = [];
    scene.scene.traverse(o => {
        if (o.isLight) lights.push({ type: o.type, color: '#' + o.color.getHexString(), intensity: +o.intensity.toFixed(3), pos: o.position.toArray().map(v => +v.toFixed(2)), dist: o.distance });
    });
    const stone = scene.scene.getObjectByName('structure:fireplace-stone');
    return {
        lightCount: lights.length,
        lights,
        stoneMaterial: stone ? stone.material.color.getHexString() : null,
        tier: scene.isLowEnd ? 'low' : (scene._isMobileTier ? 'mobile' : 'high'),
        exposure: scene.renderer ? scene.renderer.toneMappingExposure : null,
    };
});
console.log(JSON.stringify(report, null, 1));
// fire-place framing screenshot at capture resolution
await page.setViewportSize({ width: 640, height: 360 });
await page.evaluate(() => {
    const scene = window.__exospace.scene;
    scene.camera.position.set(3, 1.6, -4);
    scene.camera.lookAt(3, 1.6, -8.75);
});
await page.waitForTimeout(2500);
await page.screenshot({ path: '/tmp/probe-fireplace.png' });
console.log('screenshot /tmp/probe-fireplace.png');
await browser.close();
server.close();
