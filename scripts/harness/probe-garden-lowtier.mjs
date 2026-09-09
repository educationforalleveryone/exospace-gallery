// Probe: LOW tier scene inventory — find the white disc
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';

const PORT = 4217;
const rootDir = '/home/z/my-project/exospace';
const publicDir = path.join(rootDir, 'public');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg' };
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
const browser = await chromium.launch({ args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader'] });
const ctx = await browser.newContext({ viewport: { width: 900, height: 540 } });
await ctx.addInitScript(`
    Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>2});
    Object.defineProperty(navigator,'deviceMemory',{get:()=>2});
    Object.defineProperty(navigator,'maxTouchPoints',{get:()=>0});
    const origGetExtension = WebGL2RenderingContext.prototype.getExtension;
    WebGL2RenderingContext.prototype.getExtension = function(name) {
        if (name === 'WEBGL_debug_renderer_info') return null;
        return origGetExtension.call(this, name);
    };
`);
const page = await ctx.newPage();
page.on('pageerror', e => console.log('[pageerror]', String(e).slice(0, 200)));
await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?venue=sculpture-garden&count=12`, { waitUntil: 'load', timeout: 90000 });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
await page.click('#enter-btn');
await page.waitForTimeout(5000);

const inv = await page.evaluate(() => {
    const s = window.__exospace.scene;
    const out = { far: s.camera.far, lowEnd: s.isLowEnd, mobile: s.isMobile, fog: s.scene.fog ? { near: s.scene.fog.near, far: s.scene.fog.far } : null, objects: [] };
    s.scene.traverse((o) => {
        if (!o.isMesh) return;
        const p = o.position;
        let r = 0;
        if (o.geometry?.boundingSphere) r = o.geometry.boundingSphere.radius * (o.scale.x || 1);
        out.objects.push({
            type: o.geometry?.type,
            mat: o.material?.type,
            color: o.material?.color ? '#' + o.material.color.getHexString() : null,
            pos: [+p.x.toFixed(1), +p.y.toFixed(1), +p.z.toFixed(1)],
            r: +r.toFixed(1),
            visible: o.visible,
            name: o.name || null,
        });
    });
    return out;
});
console.log(JSON.stringify(inv, null, 1));
await browser.close();
server.close();
process.exit(0);
