/**
 * Governor before/after measurement probe — Playwright + SwiftShader +
 * Chrome DevTools Protocol CPU throttling, following the existing
 * scripts/harness/probe-*.mjs conventions.
 *
 * Measures RENDERED-frame cadence (dt between frames that actually drew)
 * with the adaptive governor disabled vs enabled, under CPU throttle, and
 * reports median/p95 plus the governor's final applied step.
 *
 * Run:  node scripts/harness/probe-governor.mjs
 */
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4197;
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.jpg': 'image/jpeg', '.glb': 'model/gltf-binary', '.hdr': 'application/octet-stream', '.webp': 'image/webp' };
const server = createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]);
    if (p === '/') p = '/harness/harness.html';
    const file = join(rootDir, 'public', p);
    if (existsSync(file)) { res.writeHead(200, { 'Content-Type': MIME[extname(file)] || 'application/octet-stream' }); res.end(readFileSync(file)); }
    else { res.writeHead(404); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const SCENARIOS = [
    { venue: 'crystal-cathedral', throttle: 4, gov: false },
    { venue: 'crystal-cathedral', throttle: 4, gov: true },
    { venue: 'crystal-cathedral', throttle: 6, gov: false },
    { venue: 'crystal-cathedral', throttle: 6, gov: true },
    { venue: 'the-salon',         throttle: 4, gov: false },
    { venue: 'the-salon',         throttle: 4, gov: true },
    { venue: 'crystal-cathedral', throttle: 1, gov: true },  // sanity: no downgrade when fast
];

const browser = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });

function stats(dts) {
    const s = [...dts].sort((a, b) => a - b);
    if (!s.length) return null;
    const pick = p => s[Math.min(s.length - 1, Math.round((p / 100) * (s.length - 1)))];
    const avg = s.reduce((a, b) => a + b, 0) / s.length;
    return { median: pick(50), p95: pick(95), avg, n: s.length };
}

for (const sc of SCENARIOS) {
    const page = await browser.newPage({ viewport: { width: 640, height: 360 } });
    const errors = [];
    page.on('pageerror', e => errors.push('PAGEERROR: ' + String(e).slice(0, 160)));
    page.on('console', m => {
        if (m.type() === 'error' && !m.text().includes('404') && !m.text().includes('Failed to load resource')) errors.push(m.text().slice(0, 160));
    });

    await page.addInitScript(gov => {
        // Pin the static tier high (same convention as shoot.mjs tierInit):
        // this container reports 2 cores, which the static low-end check
        // would otherwise latch before the governor ever runs.
        Object.defineProperty(navigator, 'hardwareConcurrency', { get: () => 8 });
        Object.defineProperty(navigator, 'deviceMemory', { get: () => 8 });
        if (!gov) window.__EXOSPACE_GOVERNOR_OFF = true;
        try { localStorage.clear(); } catch (e) { /* private mode */ }
    }, sc.gov);

    const session = await page.context().newCDPSession(page);
    await session.send('Emulation.setCPUThrottlingRate', { rate: sc.throttle });

    await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=${sc.venue}&count=12&tier=high`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#enter-btn', { timeout: 90000 });
    await page.$eval('#enter-btn', el => el.click()).catch(() => {});

    // Settle: entrance + arrival dolly + governor calibration window.
    await page.waitForTimeout(9000);

    // Walk to exercise movement + collision paths and defeat idle-skip.
    await page.evaluate(() => {
        const s = window.__exospace.scene;
        s.controls.isLocked = true;
        s.arrivalActive = false;
        s.moveState.forward = true;
    });

    // Measure rendered-frame cadence from inside the loop.
    await page.evaluate(() => {
        const s = window.__exospace.scene;
        const orig = s.animate.bind(s);
        window.__probeFrames = [];
        s.__lastRenderT0 = null;
        s.animate = () => {
            const t0 = performance.now();
            orig();
            const info = s.renderer?.info;
            if (info && info.render.calls > 0) {
                if (s.__lastRenderT0 != null) {
                    window.__probeFrames.push(t0 - s.__lastRenderT0);
                }
                s.__lastRenderT0 = t0;
            }
        };
    });

    // ~55 s of sampling (under throttle this yields 100-400 rendered frames
    // and enough governor-evaluation cycles for the ladder to descend).
    await page.waitForTimeout(sc.throttle > 1 ? 55000 : 28000);

    await page.evaluate(() => {
        const s = window.__exospace.scene;
        s.moveState.forward = false;
    });

    const result = await page.evaluate(() => {
        const s = window.__exospace.scene;
        const pc = s._perfControls;
        const gov = pc?._gov;
        const dts = (window.__probeFrames || []).slice(8); // drop warmup
        return {
            frames: dts.length,
            pr: Math.round(s.renderer.getPixelRatio() * 100) / 100,
            bloom: s._postFx?.bloomPass ? s._postFx.bloomPass.enabled : null,
            lights: s._maxActiveLights ?? null,
            hdri: !!s.scene.environment,
            draws: s.renderer.info.render.calls,
            trisK: Math.round(s.renderer.info.render.triangles / 1000),
            step: gov ? gov.stepIndex() : null,
            atFloor: gov ? gov.atFloor() : null,
            govOverride: gov ? gov.override() : null,
        };
    });
    const st = await page.evaluate(() => {
        const dts = (window.__probeFrames || []).slice(8);
        const s = [...dts].sort((a, b) => a - b);
        if (!s.length) return null;
        const pick = p => s[Math.min(s.length - 1, Math.round((p / 100) * (s.length - 1)))];
        return {
            median: Math.round(pick(50) * 10) / 10,
            p95: Math.round(pick(95) * 10) / 10,
            n: s.length,
        };
    });

    console.log(JSON.stringify({
        venue: sc.venue, cpu: `x${sc.throttle}`, governor: sc.gov ? 'on' : 'off',
        ...st, ...result,
        jsErrors: errors.length ? errors.slice(0, 3) : 'none',
    }));

    await page.close();
}

await browser.close();
server.close();
process.exit(0);
