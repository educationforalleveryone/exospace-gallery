#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// zen-transitions.mjs — state-isolation evidence (brief §15).
//
// Walks the REAL harness boot path in sequence — zen → white-cube → zen and
// zen → dark-museum → zen — one fresh page load per venue (exactly how the
// production app transitions between venues: a full scene boot, never an
// in-place mutation). After each boot, snapshot the venue-identity state
// from the live scene. The two zen snapshots in each sequence must be
// IDENTICAL: no fog/lighting/exposure/geometry state may survive a page
// boundary, and nothing from the intermediate venue may ride along.
//
//   node scripts/harness/zen-transitions.mjs
// ─────────────────────────────────────────────────────────────────────────────
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = Number(process.argv[2] || 4213);
const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.jpg': 'image/jpeg',
    '.png': 'image/png', '.hdr': 'application/octet-stream', '.wasm': 'application/wasm' };

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

const bootInit = `
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
    const __origNow = performance.now.bind(performance);
    const __t0 = __origNow();
    performance.now = () => __t0 + (__origNow() - __t0) * 0.25;
`;

async function bootScene(browser, query) {
    const ctx = await browser.newContext({ viewport: { width: 320, height: 180 } });
    await ctx.addInitScript(bootInit);
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?${query}`, { waitUntil: 'load' });
    try {
        await page.waitForFunction(() => {
            const b = document.getElementById('enter-btn');
            return b && b.style.pointerEvents === 'auto';
        }, { timeout: 60000 });
    } catch { errors.push('enter-btn never unlocked'); }
    await page.$eval('#enter-btn', el => el.click());
    await page.waitForTimeout(7000);
    await page.setViewportSize({ width: 640, height: 360 });
    await page.waitForTimeout(5000);

    const snap = await Promise.race([
        page.evaluate(() => {
            const s = window.__exospace?.scene;
            if (!s?.scene) return null;
            const lights = s.scene.children.filter(o => o.isLight);
            const meshes = s.scene.children.filter(o => o.isMesh);
            return {
                venue: s._venueSlug,
                layout: s._layoutMeta?.type,
                fog: s.scene.fog ? { c: '#' + s.scene.fog.color.getHexString(), n: s.scene.fog.near, f: s.scene.fog.far } : null,
                background: s.scene.background ? '#' + s.scene.background.getHexString() : null,
                exposure: s.renderer.toneMappingExposure,
                envMap: !!s.scene.environment,
                lightCount: lights.length,
                meshCount: meshes.length,
                artworkCount: s.artworks?.length ?? 0,
                obstacleCount: s._obstacles?.length ?? -1,
                venueKeys: Object.keys(s._venueVisualConfig || {}).sort().join(','),
                ambient: (() => { const a = lights.find(l => l.isAmbientLight); return a ? { c: '#' + a.color.getHexString(), i: +a.intensity.toFixed(3) } : null; })(),
                hemisphere: (() => { const h = lights.find(l => l.isHemisphereLight); return h ? +h.intensity.toFixed(3) : null; })(),
                artworkBase: s._venueArtworkLightBase ?? null,
                poolCap: s._venueArtworkLightPoolCap ?? null,
                frameStyle: s._venueFrameStyleOverride ?? null,
                placementMode: s._venuePlacementMode ?? null,
                postFx: s._venuePostFx ? JSON.stringify(s._venuePostFx) : null,
                envIntensity: s._venueEnvIntensity ?? null,
                bayMeshes: meshes.filter(m => String(m.name).startsWith('bays-')).length,
                foreignMeshNames: meshes.map(m => m.name).filter(n =>
                    /museum-picture|shoji|alcove|salon-|neon|void-|skyline|lounge|glazing/.test(String(n))).sort(),
            };
        }),
        new Promise(resolve => setTimeout(() => resolve(null), 20000)),
    ]);
    await ctx.close();
    return { snap, errors };
}

const same = (a, b, keys) => {
    const diffs = [];
    for (const k of keys) {
        const av = JSON.stringify(a?.[k]), bv = JSON.stringify(b?.[k]);
        if (av !== bv) diffs.push(`${k}: ${av} !== ${bv}`);
    }
    return diffs;
};

let failures = 0;
const ok = (name, cond, detail = '') => {
    if (cond) console.log(`  ✓ ${name}`);
    else { failures++; console.error(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`); }
};
const section = (name) => console.log(`\n── ${name} ${'─'.repeat(Math.max(1, 62 - name.length))}`);

const browser = await chromium.launch({
    args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-lcd-text',
           '--disable-gpu-vsync', '--disable-frame-rate-limit',
           '--disable-renderer-backgrounding', '--disable-background-timer-throttling',
           '--disable-backgrounding-occluded-windows'],
});

const IDENTITY_KEYS = ['venue', 'layout', 'fog', 'background', 'exposure', 'envMap',
    'lightCount', 'meshCount', 'artworkCount', 'venueKeys', 'ambient', 'hemisphere',
    'artworkBase', 'poolCap', 'frameStyle', 'placementMode', 'postFx', 'envIntensity',
    'bayMeshes', 'foreignMeshNames'];

for (const [name, seq] of [
    ['zen → white-cube → zen', ['venue=zen-gallery&count=8', 'count=8', 'venue=zen-gallery&count=8']],
    ['zen → dark-museum → zen', ['venue=zen-gallery&count=8', 'venue=dark-museum&count=8', 'venue=zen-gallery&count=8']],
]) {
    section(`Transition: ${name}`);
    const runs = [];
    for (const q of seq) {
        // Sandbox SwiftShader sometimes starves the stats evaluate — the
        // boot itself is what must not fail; retry a starved snapshot once.
        let { snap, errors } = await bootScene(browser, q);
        if (!snap) { ({ snap, errors } = await bootScene(browser, q)); }
        runs.push(snap);
        ok(`boot [${q.split('&')[0]}] completed without page errors`, errors.length === 0, errors[0] ?? '');
    }
    const [first, middle, back] = runs;
    ok('all three boots produced scene state', !!first && !!middle && !!back);
    if (!first || !back) continue;

    const diffs = same(first, back, IDENTITY_KEYS);
    ok('zen state after the round-trip is IDENTICAL to zen before (no leak, full determinism)',
        diffs.length === 0, diffs.join(' | '));

    // The intermediate venue must have been a genuinely different venue.
    ok(`intermediate venue rendered (${middle?.venue})`, !!middle && first.venue !== middle.venue);

    // Foreign-structure scan on the zen boots.
    ok('zen scene carries no foreign venue structure (museum/salon/neon/shoji/void)',
        (first.foreignMeshNames ?? []).length === 0, (first.foreignMeshNames ?? []).join(','));

    // Zen-specific identity sanity on the live scene.
    ok('zen declares NO scene environment (envMap false — nothing can reflect a sky)',
        first.envMap === false);
    ok('zen bay architecture present on the live scene', (first.bayMeshes ?? 0) >= 4);
    console.log(`    [zen identity] fog=${first.fog?.c} exposure=${first.exposure} lights=${first.lightCount} meshes=${first.meshCount} bays=${first.bayMeshes}`);
}

await browser.close();
server.close();
console.log('\n' + '─'.repeat(66));
if (failures === 0) console.log('✅ zen-transitions: STATE ISOLATION PROVEN');
else { console.error(`❌ zen-transitions: ${failures} check(s) FAILED`); process.exit(1); }
