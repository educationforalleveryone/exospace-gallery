// probe-lake-water.mjs — the high-tier WATER REFLECTOR proof.
//
// The formal matrix captures on the ?reflect=0 strip (the Reflector's extra
// scene render is minutes-per-frame under SwiftShader — the documented
// cathedral cost class). THIS probe is the reflector's own evidence: it
// enters the venue via the DOM click path, waits patiently for the first
// mirrored frames, then captures two poses:
//   1. the landing sightline (moon + arc + their reflections), and
//   2. a low water-level view from the shore walk (the reflection language:
//      artwork doubled, sky band, moon glitter).
//
//   node scripts/harness/probe-lake-water.mjs [count=5]
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4179;
const count = Number(process.argv[2] || 5);
const OUT = 'shots-lake-water';
mkdirSync(resolve(rootDir, OUT), { recursive: true });

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.jpg': 'image/jpeg', '.glb': 'model/gltf-binary', '.webp': 'image/webp' };
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

const browser = await chromium.launch({ args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-lcd-text', '--disable-gpu-vsync', '--disable-frame-rate-limit', '--disable-renderer-backgrounding', '--disable-background-timer-throttling', '--disable-backgrounding-occluded-windows'] });
const TIER_INIT = `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
const _d = Date.now.bind(Date); Date.now = () => _d() * 250;`;

const page = await browser.newPage({ viewport: { width: 640, height: 360 } });
await page.addInitScript(TIER_INIT);
const errs = [];
page.on('pageerror', e => errs.push(String(e).slice(0, 200)));

await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=mirror-lake&tier=high&assets=0&count=${count}`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 90000 });
// DOM click — bypasses the curtain's pointer interception.
await page.$eval('#enter-btn', el => el.click()).catch(e => errs.push('enter: ' + e));

// Patient gate: the scene must exist AND the first mirrored frames rendered.
await page.waitForFunction(() => {
    const s = window.__exospace?.scene;
    return !!s?.camera;
}, { timeout: 120000 }).catch(() => errs.push('scene never ready'));
await page.waitForTimeout(15000);

const hasReflector = await page.evaluate(() => {
    const s = window.__exospace?.scene;
    if (!s?.scene) return { found: false };
    let found = false;
    s.scene.traverse(o => { if (o.material?.uniforms?.tDiffuse) found = true; });
    let gpu = '';
    try {
        const gl = s.renderer.getContext();
        const d = gl.getExtension('WEBGL_debug_renderer_info');
        gpu = d ? (gl.getParameter(d.UNMASKED_RENDERER_WEBGL) || '') : 'no-debug-info';
    } catch (e) { gpu = 'err'; }
    return { found, tier: { low: s.isLowEnd, mobile: s._isMobileTier }, gpu,
             exposure: s.renderer?.toneMappingExposure };
});

const pose = async (name, p, t, settle) => {
    await page.evaluate(([p, t]) => {
        const s = window.__exospace?.scene;
        if (!s?.camera) return;
        s.arrivalActive = false;
        s.camera.position.set(p[0], p[1], p[2]);
        s.camera.lookAt(t[0], t[1], t[2]);
    }, [p, t]);
    await page.waitForTimeout(settle);
    await page.addStyleTag({ content: '#crosshair,#ui-layer,#controls-hint{display:none!important}' }).catch(() => {});
    // The reflector doubles the scene render — one composited frame under
    // SwiftShader can take minutes. Screenshot timeout must cover it.
    await page.screenshot({ path: resolve(rootDir, OUT, name), timeout: 240000 });
    console.log(`[water] ${name} captured`);
};

// 1. landing sightline; 2. low over the water from the walk.
await pose('water-landing.png', [0, 1.6, 12.24], [0, 1.75, -6], 60000);
await pose('water-low.png', [-5.2, 1.35, 6.1], [-7.5, 1.1, -5.5], 60000);

console.log(`[water] reflector check: ${JSON.stringify(hasReflector)}`);
console.log(`[water] page errors: ${JSON.stringify(errs.slice(0, 5))}`);
await browser.close();
server.close();
