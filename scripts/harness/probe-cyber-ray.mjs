// Raycast from the close-read camera to identify what covers the artwork.
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
page.on('pageerror', e => console.log('PAGEERROR:', String(e).slice(0, 300)));
await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?venue=cyber-gallery&count=8&motion=walk`, { waitUntil: 'load' });
await page.waitForFunction(() => {
    const b = document.getElementById('enter-btn');
    return b && b.style.pointerEvents === 'auto';
}, { timeout: 45000 });
await page.$eval('#enter-btn', el => el.click());
await page.waitForTimeout(12000);
// second pass: list every object inside the corridor slab between camera and artwork
const info2 = await page.evaluate(() => {
    const s = window.__exospace?.scene;
    const out = { cam: { x: s.camera.position.x, y: s.camera.position.y, z: s.camera.position.z }, hits: [] };
    const V = s.scene.position.constructor;   // THREE.Vector3
    const tmp = new V();
    s.scene.traverse((o) => {
        if (!o.isMesh && !o.isSprite && !o.isPoints) return;
        o.getWorldPosition(tmp);
        const p = { x: tmp.x, y: tmp.y, z: tmp.z };
        if (p.x > -0.4 && p.x < 2.6 && p.y > 0.5 && p.y < 2.6 && p.z > 1.8 && p.z < 3.8) {
            out.hits.push({
                name: o.name || '(unnamed)',
                type: o.isMesh ? 'mesh' : o.isSprite ? 'sprite' : 'points',
                x: +p.x.toFixed(2), y: +p.y.toFixed(2), z: +p.z.toFixed(2),
                mat: o.material?.type ?? null,
                color: o.material?.color ? o.material.color.getHexString() : null,
                geom: o.geometry?.type ?? null,
                renderOrder: o.renderOrder,
                visible: o.visible,
            });
        }
    });
    return out;
});
console.log(JSON.stringify(info2, null, 1));
await browser.close();
server.close();
process.exit(0);
