// Probe: inspect court 2's artwork from front vs back + dump transforms
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { writeFileSync } from 'node:fs';

const PORT = 4213;
const rootDir = '/home/z/my-project/exospace';
const publicDir = path.join(rootDir, 'public');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.hdr': 'application/octet-stream', '.glb': 'model/gltf-binary', '.wasm': 'application/wasm' };
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
const browser = await chromium.launch({
    args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-lcd-text',
           '--disable-gpu-vsync', '--disable-frame-rate-limit',
           '--disable-renderer-backgrounding', '--disable-background-timer-throttling',
           '--disable-backgrounding-occluded-windows'],
});
const ctx = await browser.newContext({ viewport: { width: 900, height: 540 } });
await ctx.addInitScript(`
    Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
    Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
    const origGetExtension = WebGL2RenderingContext.prototype.getExtension;
    WebGL2RenderingContext.prototype.getExtension = function(name) {
        if (name === 'WEBGL_debug_renderer_info') return null;
        return origGetExtension.call(this, name);
    };
    const __origNow = performance.now.bind(performance);
    const __t0 = __origNow();
    performance.now = () => __t0 + (__origNow() - __t0) * 0.004;
`);
const page = await ctx.newPage();
page.on('pageerror', e => console.log('[pageerror]', String(e).slice(0, 200)));
await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?venue=sculpture-garden&count=12`, { waitUntil: 'load', timeout: 60000 });
await page.waitForSelector('#enter-btn', { timeout: 30000 });
await page.click('#enter-btn');
await page.waitForTimeout(4000);

// Dump artwork transforms
const arts = await page.evaluate(() => {
    const s = window.__exospace?.scene;
    return (s?.artworks || []).map((a, i) => ({
        i,
        pos: a.position ? { x: +a.position.x.toFixed(2), y: +a.position.y.toFixed(2), z: +a.position.z.toFixed(2) } : null,
        rotY: a.rotation ? +(a.rotation.y * 180 / Math.PI).toFixed(0) : null,
        scale: a.scale ? +a.scale.x.toFixed(2) : null,
        canvas: a.userData?._canvasMesh ? { vis: a.userData._canvasMesh.visible, mat: a.userData._canvasMesh.material?.type, map: !!a.userData._canvasMesh.material?.map, side: a.userData._canvasMesh.material?.side } : null,
    }));
});
console.log(JSON.stringify(arts, null, 1));

// Front view of court index 2 (facing dir)
async function shot(name, px, pz, tx, tz) {
    await page.evaluate(({ px, pz, tx, tz }) => {
        const s = window.__exospace.scene;
        s.camera.position.set(px, 1.62, pz);
        s.camera.lookAt(tx, 1.65, tz);
    }, { px, pz, tx, tz });
    await page.waitForTimeout(800);
    const cdp = await ctx.newCDPSession(page);
    const d = await cdp.send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(`/home/z/my-project/exospace/shots-garden-v3/probe-${name}.png`, Buffer.from(d.data, 'base64'));
    console.log('shot', name);
}
const c2 = arts[2];
const fx = Math.sin(c2.rotY * Math.PI / 180), fz = Math.cos(c2.rotY * Math.PI / 180);
await shot('front', c2.pos.x + fx * 2.2, c2.pos.z + fz * 2.2, c2.pos.x, c2.pos.z);
await shot('back', c2.pos.x - fx * 2.2, c2.pos.z - fz * 2.2, c2.pos.x, c2.pos.z);
await browser.close();
server.close();
process.exit(0);
