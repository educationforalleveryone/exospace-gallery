// Dump artwork world positions + camera state from the live harness scene
// (framing aid for the eye-level close-reading shot).
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = 4199;
const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.jpg': 'image/jpeg', '.png': 'image/png', '.css': 'text/css', '.json': 'application/json' };
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
        let p = decodeURIComponent(url.pathname);
        if (p.endsWith('/')) p += 'index.html';
        const fp = path.join(publicDir, p);
        if (!fp.startsWith(publicDir) || !existsSync(fp)) { res.writeHead(404); res.end(); return; }
        res.writeHead(200, { 'Content-Type': MIME[path.extname(fp)] || 'application/octet-stream' });
        res.end(await readFile(fp));
    } catch { res.writeHead(500); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const browser = await chromium.launch({ args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader'] });
const ctx = await browser.newContext({ viewport: { width: 320, height: 180 } });
await ctx.addInitScript(`
    Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
    Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
    const o = WebGL2RenderingContext.prototype.getExtension;
    WebGL2RenderingContext.prototype.getExtension = function(n){ return n==='WEBGL_debug_renderer_info' ? null : o.call(this,n); };
    let _r = 0; window.requestAnimationFrame = (cb) => setTimeout(() => cb(performance.now()), 8);
    window.cancelAnimationFrame = (id) => clearTimeout(id);
    window.EXOSPACE_QA_MOTION = 3.0;
`);
const page = await ctx.newPage();
await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?venue=cyber-gallery&count=8`, { waitUntil: 'load' });
await page.waitForFunction(() => {
    const b = document.getElementById('enter-btn');
    return b && b.style.pointerEvents === 'auto';
}, { timeout: 45000 });
await page.$eval('#enter-btn', el => el.click());
await page.waitForTimeout(12000);
const info = await page.evaluate(() => {
    const s = window.__exospace?.scene;
    if (!s) return null;
    const v = new (window.__exospace.THREE?.Vector3 || Object.getPrototypeOf(s.camera.position).constructor)();
    const arts = (s.artworks || []).map((a) => {
        const wp = a.getWorldPosition(v.clone ? v.clone() : v);
        const bb = a.userData?.bounds ?? null;
        return { x: +wp.x.toFixed(2), y: +wp.y.toFixed(2), z: +wp.z.toFixed(2), w: a.userData?.width ?? a.userData?.artWidth ?? null, h: a.userData?.height ?? null };
    });
    return {
        camera: { x: +s.camera.position.x.toFixed(2), y: +s.camera.position.y.toFixed(2), z: +s.camera.position.z.toFixed(2) },
        roomBounds: s.roomBounds,
        layout: s._layoutMeta,
        artworks: arts,
    };
});
console.log(JSON.stringify(info, null, 1));
await browser.close();
server.close();
process.exit(0);
