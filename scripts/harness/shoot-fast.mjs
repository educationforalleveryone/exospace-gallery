// shoot-fast.mjs — rapid single-page capture for DESIGN ITERATION (not the
// formal evidence matrix): minimal waits, 960x540, N scenarios from argv.
//   node scripts/harness/shoot-fast.mjs lake-cam-walk "venue=mirror-lake&..." ...
// Scenarios are given as: id:query[:px,py,pz:tx,ty,tz]  (cam optional)
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = process.env.OUT || 'shots-fast';
const PORT = 4177;
mkdirSync(resolve(rootDir, OUT), { recursive: true });

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.jpg': 'image/jpeg', '.glb': 'model/gltf-binary', '.hdr': 'application/octet-stream', '.webp': 'image/webp' };
const server = createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]);
    if (p === '/') p = '/harness/harness.html';
    const file = join(rootDir, 'public', p);
    if (existsSync(file)) {
        res.writeHead(200, { 'Content-Type': MIME[extname(file)] || 'application/octet-stream' });
        res.end(readFileSync(file));
    } else { res.writeHead(404); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const specs = process.argv.slice(2);
if (!specs.length) { console.error('no scenarios'); process.exit(1); }

const browser = await chromium.launch({ args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-lcd-text',
    '--disable-gpu-vsync', '--disable-frame-rate-limit',
    '--disable-renderer-backgrounding', '--disable-background-timer-throttling',
    '--disable-backgrounding-occluded-windows'] });

// Tier override: high (same as shoot.mjs tierInit.high — navigator + the
// performance.now stretch that keeps the deferred FPS benchmark from firing
// mid-capture; heavy SwiftShader frames otherwise retro-downgrade the tier).
const TIER_INIT = `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
const _d = Date.now.bind(Date); Date.now = () => _d() * 250;
const __origNow = performance.now.bind(performance);
const __t0 = __origNow();
performance.now = () => __t0 + (__origNow() - __t0) * 0.004;`;

for (const spec of specs) {
    const [id, query, camP, camT] = spec.split(':').reduce((acc, part, i) => {
        // query contains ':'? No — our queries use & and =. cam coords use commas.
        return [...acc, part];
    }, []);
    const url = `http://127.0.0.1:${PORT}/harness/harness.html?${query}`;
    const page = await browser.newPage({ viewport: { width: 960, height: 540 } });
    await page.addInitScript(TIER_INIT);
    const errs = [];
    page.on('pageerror', e => errs.push(String(e).slice(0, 160)));
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await page.waitForSelector('#enter-btn', { timeout: 60000 }).catch(() => {});
    // DOM click — the curtain overlay can intercept pointer events during
    // its fade; a JS click is what the scene's own flow listens for.
    await page.$eval('#enter-btn', el => el.click()).catch(() => {});
    await page.waitForFunction(() => {
        const s = window.__exospace?.scene;
        return !s || (s._gardenAssetsSettled !== false && s._lakeAssetsSettled !== false);
    }, { timeout: 60000 }).catch(() => errs.push('assets gate'));
    await page.waitForTimeout(9000);
    if (camP && camT) {
        const p = camP.split(',').map(Number), t = camT.split(',').map(Number);
        await page.evaluate(([p, t]) => {
            const s = window.__exospace?.scene;
            if (!s?.camera) return;
            s.arrivalActive = false;
            s.camera.position.set(p[0], p[1], p[2]);
            s.camera.lookAt(t[0], t[1], t[2]);
        }, [p, t]);
        await page.waitForTimeout(2500);
    }
    await page.addStyleTag({ content: '#crosshair,#ui-layer,#controls-hint{display:none!important}' }).catch(() => {});
    const tier = await page.evaluate(() => {
        const s = window.__exospace?.scene;
        if (!s) return null;
        let gpu = '';
        try {
            const gl = s.renderer?.getContext();
            const d = gl?.getExtension('WEBGL_debug_renderer_info');
            gpu = d ? (gl.getParameter(d.UNMASKED_RENDERER_WEBGL) || '') : 'no-debug-info';
        } catch (e) { gpu = 'err'; }
        return { low: s.isLowEnd, mobile: s._isMobileTier, gpu };
    });
    try {
        await page.screenshot({ path: resolve(rootDir, OUT, `${id}.png`), timeout: 240000 });
        console.log(`[fast] ${id} ok tier=${JSON.stringify(tier)}${errs.length ? ' errors=' + JSON.stringify(errs.slice(0, 3)) : ''}`);
    } catch (e) {
        console.log(`[fast] ${id} CAPTURE FAILED: ${String(e).slice(0, 120)}`);
    }
    await page.close();
}
await browser.close();
server.close();
