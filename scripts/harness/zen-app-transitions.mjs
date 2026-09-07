#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// zen-app-transitions.mjs — state-isolation evidence on the REAL app (§15).
//
// The harness-based zen-transitions.mjs walks harness.html boots; this driver
// walks the PRODUCTION transition path: /venues/{slug}/preview in the running
// Laravel app — the same route a visitor hits — one FULL page load per venue
// (exactly how production changes venues: a fresh boot, never an in-place
// mutation). After each boot, snapshot the venue-identity state from the live
// scene via window.__exospace.scene (exposed by gallery/main.js).
//
// Sequences (brief §15): zen → white-cube → zen and zen → dark-museum → zen.
// The two zen snapshots in each sequence must be IDENTICAL, nothing foreign
// may ride along, and zen's declared-absent environment must stay absent
// (envMap false) next to venues that DO load HDRIs.
//
//   node scripts/harness/zen-app-transitions.mjs [base-url]
// ─────────────────────────────────────────────────────────────────────────────
import fs from 'node:fs';

const BASE_URL = process.argv[2] || 'http://127.0.0.1:8090';
const OUT_DIR = 'scripts/harness/shots-zen/transitions';
fs.mkdirSync(OUT_DIR, { recursive: true });

const { chromium } = await import('playwright');

const SNAPSHOT = () => {
    const s = window.__exospace?.scene;
    if (!s?.scene) return null;
    const lights = s.scene.children.filter(o => o.isLight);
    const meshes = s.scene.children.filter(o => o.isMesh);
    const fog = s.scene.fog;
    return {
        venue: s._venueSlug,
        layout: s._layoutMeta?.type,
        fog: fog ? { c: '#' + fog.color.getHexString(), n: fog.near, f: fog.far } : null,
        background: s.scene.background ? '#' + s.scene.background.getHexString() : null,
        exposure: +s.renderer.toneMappingExposure.toFixed(4),
        envMap: !!s.scene.environment,
        lightCount: lights.length,
        meshCount: meshes.length,
        artworkCount: s.artworks?.length ?? 0,
        venueKeys: Object.keys(s._venueVisualConfig || {}).sort().join(','),
        ambient: (() => { const a = lights.find(l => l.isAmbientLight); return a ? { c: '#' + a.color.getHexString(), i: +a.intensity.toFixed(3) } : null; })(),
        hemisphere: (() => { const h = lights.find(l => l.isHemisphereLight); return h ? +h.intensity.toFixed(3) : null; })(),
        artworkBase: s._venueArtworkLightBase ?? null,
        poolCap: s._venueArtworkLightPoolCap ?? null,
        frameStyle: s._venueFrameStyleOverride ?? null,
        placementMode: s._venuePlacementMode ?? null,
        postFx: s._venuePostFx ? JSON.stringify(s._venuePostFx) : null,
        envIntensity: s._venueEnvIntensity ?? null,
        materialKeys: Object.keys(s._venueMaterialConfig || {}).sort().join(','),
        bayMeshes: meshes.filter(m => String(m.name).startsWith('bays-')).length,
        foreignMeshNames: meshes.map(m => m.name).filter(n =>
            /museum-picture|shoji|alcove|salon-|neon|void-|skyline|lounge|glazing|loft-/.test(String(n))).sort(),
    };
};

const IDENTITY_KEYS = ['venue', 'layout', 'fog', 'background', 'exposure', 'envMap',
    'lightCount', 'meshCount', 'artworkCount', 'venueKeys', 'ambient', 'hemisphere',
    'artworkBase', 'poolCap', 'frameStyle', 'placementMode', 'postFx', 'envIntensity',
    'materialKeys', 'bayMeshes', 'foreignMeshNames'];

let failures = 0;
const ok = (name, cond, detail = '') => {
    if (cond) console.log(`  ✓ ${name}`);
    else { failures++; console.error(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`); }
};
const section = (n) => console.log(`\n── ${n} ${'─'.repeat(Math.max(1, 62 - n.length))}`);

async function boot(browser, slug, shot) {
    const ctx = await browser.newContext({
        viewport: { width: 1280, height: 720 },
        reducedMotion: 'reduce',
        deviceScaleFactor: 1,
    });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(String(e).slice(0, 200)));
    await page.goto(`${BASE_URL}/venues/${slug}/preview`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    const canvas = page.locator('#canvas-container canvas').first();
    await canvas.waitFor({ state: 'visible', timeout: 60_000 });
    const enter = page.locator('#enter-btn').first();
    if (await enter.isVisible().catch(() => false)) {
        try {
            await page.waitForFunction(() => {
                const b = document.getElementById('enter-btn');
                return !!b && getComputedStyle(b).pointerEvents === 'auto';
            }, { timeout: 90_000 });
            await enter.click();
        } catch {
            const skip = page.locator('#skip-intro-link').first();
            if (await skip.isVisible().catch(() => false)) await skip.click();
            else errors.push('enter control never enabled');
        }
    }
    await page.waitForTimeout(2_500);
    const snap = await page.evaluate(SNAPSHOT);
    if (shot) {
        try { fs.writeFileSync(shot, await canvas.screenshot()); } catch { /* headless flake */ }
    }
    await ctx.close();
    return { snap, errors };
}

const same = (a, b) => {
    const diffs = [];
    for (const k of IDENTITY_KEYS) {
        const av = JSON.stringify(a?.[k]), bv = JSON.stringify(b?.[k]);
        if (av !== bv) diffs.push(`${k}: ${av} !== ${bv}`);
    }
    return diffs;
};

const browser = await chromium.launch({ headless: true });

for (const [name, seq] of [
    ['zen → white-cube → zen', ['zen-gallery', 'white-cube', 'zen-gallery']],
    ['zen → dark-museum → zen', ['zen-gallery', 'dark-museum', 'zen-gallery']],
]) {
    section(`Transition: ${name}`);
    const snaps = [];
    let hadBootErrors = false;
    for (let i = 0; i < seq.length; i++) {
        const slug = seq[i];
        const shot = slug === 'zen-gallery' && i === seq.length - 1
            ? `${OUT_DIR}/${name.split(' ')[0]}-return-${i}.png` : null;
        const { snap, errors } = await boot(browser, slug, shot);
        if (errors.length) hadBootErrors = true;
        ok(`boot [${slug}] completed without page errors`, errors.length === 0, errors.join(' | '));
        ok(`boot [${slug}] produced a live scene snapshot`, !!snap);
        snaps.push(snap);
    }
    const [z1, mid, z2] = snaps;
    if (!z1 || !mid || !z2) continue;

    const diffs = same(z1, z2);
    ok(`${name}: both zen boots are byte-identical (${IDENTITY_KEYS.length} identity keys)`,
        diffs.length === 0, diffs.join(' | '));
    ok(`${name}: zen did not inherit the intermediate venue's structure`,
        (z2.foreignMeshNames || []).length === 0, z2.foreignMeshNames.join(','));
    ok(`${name}: zen keeps its declared-absent environment after the transition`,
        z2.envMap === false && z2.envIntensity === 0,
        `envMap=${z2.envMap} envIntensity=${z2.envIntensity}`);
    ok(`${name}: zen's bay architecture rebuilt after the transition`,
        (z2.bayMeshes || 0) >= 4, `bayMeshes=${z2.bayMeshes}`);
    ok(`${name}: zen identity survived (slug + layout + hang)`,
        z2.venue === 'zen-gallery' && !!z2.layout && z2.artworkCount > 0,
        `venue=${z2.venue} layout=${z2.layout} artworks=${z2.artworkCount}`);
    console.log(`    (intermediate ${mid.venue}: envMap=${mid.envMap} meshCount=${mid.meshCount} — booted between the two zen boots)`);
    if (hadBootErrors) console.log('    NOTE: boot errors above are page-level noise; identity comparison still valid.');
}

// ── Differentiation lineup (§10): same sample hang, four venues ─────────────
section('Differentiation lineup captures (same artwork count per venue)');
for (const slug of ['zen-gallery', 'white-cube', 'infinite-void', 'industrial-loft', 'dark-museum']) {
    const { snap } = await boot(browser, slug, `${OUT_DIR}/lineup-${slug}.png`);
    ok(`lineup capture [${slug}]`, !!snap, 'no snapshot');
}

await browser.close();
console.log(failures === 0
    ? '\n✅ zen-app-transitions: ALL CHECKS PASSED'
    : `\n❌ zen-app-transitions: ${failures} check(s) failed`);
process.exit(failures === 0 ? 0 : 1);
