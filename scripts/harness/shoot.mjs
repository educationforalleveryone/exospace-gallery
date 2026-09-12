#!/usr/bin/env node
import { createServer } from 'node:http';
import { readFile, writeFile } from 'node:fs/promises';
import { existsSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const args = process.argv.slice(2);
function arg(name, fallback) {
    const i = args.indexOf(`--${name}`);
    if (i === -1) return fallback;
    const v = args[i + 1];
    return v && !v.startsWith('--') ? v : true;
}

const OUT      = String(arg('out', 'shots'));
const ONLY     = [].concat(arg('scenario', []) || []).map(String);
const STATS    = arg('stats', false) === true;
const SETTLE   = Number(arg('settle', 1));   // multiply the post-enter waits — heavy scenarios stream textures in the background; SwiftShader decodes them slowly
const PORT     = Number(arg('port', 4199));

const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript',
    '.css': 'text/css', '.json': 'application/json', '.jpg': 'image/jpeg', '.png': 'image/png',
    '.hdr': 'application/octet-stream', '.glb': 'model/gltf-binary', '.wasm': 'application/wasm' };

// Minimal static server rooted at public/
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
mkdirSync(path.resolve(rootDir, OUT), { recursive: true });

// ── Scenarios (Modern White Cube QA matrix + venue-specific sweeps) ─────────
const SCENARIOS = [
    { id: 'square-01',            q: 'count=1' },
    { id: 'square-08-mixed',      q: 'count=8' },
    { id: 'square-12-mixed',      q: 'count=12' },
    { id: 'square-30-mixed',      q: 'count=30' },
    { id: 'square-60-mixed',      q: 'count=60' },
    { id: 'square-08-portrait',   q: 'count=8&orient=portrait' },
    { id: 'square-08-landscape',  q: 'count=8&orient=landscape' },
    { id: 'square-08-square',     q: 'count=8&orient=square' },
    { id: 'square-08-extreme',    q: 'count=8&orient=extreme' },
    { id: 'corridor-08',          q: 'count=8&layout=corridor' },
    { id: 'lshape-08',           q: 'count=8&layout=l-shape' },
    { id: 'rotunda-08',           q: 'count=8&layout=rotunda' },
    { id: 'tier-low-08',          q: 'count=8', tier: 'low' },
    // Infinite Void — count scaling (radius + placement stress)
    { id: 'void-01',              q: 'venue=infinite-void&count=1' },
    { id: 'void-06',              q: 'venue=infinite-void&count=6' },
    { id: 'void-12-mixed',        q: 'venue=infinite-void&count=12' },
    { id: 'void-30-mixed',        q: 'venue=infinite-void&count=30' },
    { id: 'void-60-mixed',        q: 'venue=infinite-void&count=60' },
    // Infinite Void — orientation stress
    { id: 'void-08-portrait',     q: 'venue=infinite-void&count=8&orient=portrait' },
    { id: 'void-08-landscape',    q: 'venue=infinite-void&count=8&orient=landscape' },
    { id: 'void-08-extreme',      q: 'venue=infinite-void&count=8&orient=extreme' },
    // Infinite Void — tier degradation
    { id: 'void-tier-low-06',     q: 'venue=infinite-void&count=6', tier: 'low' },
    { id: 'nebula-06',            q: 'venue=nebula-drift&count=6' },
    { id: 'nebula-12-mixed',      q: 'venue=nebula-drift&count=12' },
    { id: 'nebula-40-mixed',      q: 'venue=nebula-drift&count=40' },
    { id: 'nebula-cam-rim',       q: 'venue=nebula-drift&count=12',
      cam: { p: [0, 1.6, 9],   t: [0, 2.0, 0] } },
    { id: 'nebula-cam-up',        q: 'venue=nebula-drift&count=12',
      cam: { p: [0, 1.6, 0],   t: [0, 7.2, 11] } },
    { id: 'nebula-cam-crown',     q: 'venue=nebula-drift&count=12',
      cam: { p: [0, 1.6, 0],   t: [5, 10, -8] } },
    { id: 'nebula-tier-low-06',   q: 'venue=nebula-drift&count=6', tier: 'low' },
    // Rollback chain: the v1.0.0 starfield body must still render by config.
    { id: 'nebula-legacy-12',     q: 'venue=nebula-drift-legacy&count=12' },
    { id: 'pent-06',              q: 'venue=luxury-penthouse&count=6' },
    { id: 'pent-12-mixed',        q: 'venue=luxury-penthouse&count=12' },
    { id: 'pent-40-mixed',        q: 'venue=luxury-penthouse&count=40' },
    { id: 'pent-cam-arrival',     q: 'venue=luxury-penthouse&count=12',
      cam: { p: [3, 1.6, -7.25], t: [3, 1.9, 4] } },
    { id: 'pent-cam-seam',        q: 'venue=luxury-penthouse&count=12',
      cam: { p: [3, 1.6, -2.5],  t: [3, 2.4, 6] } },
    { id: 'pent-cam-fireplace',   q: 'venue=luxury-penthouse&count=12',
      cam: { p: [3, 1.6, 3.5],   t: [3, 2.6, 8.75] } },
    { id: 'pent-cam-corner',      q: 'venue=luxury-penthouse&count=12',
      cam: { p: [7.5, 1.6, 1.5], t: [16, 2.6, 7.5] } },
    { id: 'pent-cam-corridor-40', q: 'venue=luxury-penthouse&count=40',
      cam: { p: [3, 1.6, -14],   t: [3, 1.7, 10] } },
    { id: 'pent-cam-lounge',      q: 'venue=luxury-penthouse&count=12',
      cam: { p: [10, 1.6, 5.75],  t: [26.5, 1.7, 5.75] } },
    { id: 'pent-cam-view',        q: 'venue=luxury-penthouse&count=12',
      cam: { p: [12.5, 1.6, 5.75], t: [20, 2.7, 5.75] } },
    { id: 'pent-cam-city',        q: 'venue=luxury-penthouse&count=12',
      cam: { p: [21, 1.6, 5.75], t: [45, 3.0, 5.75] } },
    { id: 'pent-cam-above',       q: 'venue=luxury-penthouse&count=12',
      cam: { p: [3, 3.1, -6.5],  t: [6, 1.4, 7] } },
    // Tier degradation: the residence must read on Lambert (low).
    { id: 'pent-tier-low-06',     q: 'venue=luxury-penthouse&count=6', tier: 'low' },
    { id: 'pent-tier-low-12',     q: 'venue=luxury-penthouse&count=12', tier: 'low' },
    { id: 'pent-v21-12',          q: 'venue=luxury-penthouse-v21&count=12' },
    // Rollback chain: the v1.0.0 "Rooms" body must still render by config.
    { id: 'pent-legacy-12',       q: 'venue=luxury-penthouse-legacy&count=12' },
    { id: 'cathedral-05',         q: 'venue=crystal-cathedral&count=5' },
    { id: 'cathedral-12-mixed',   q: 'venue=crystal-cathedral&count=12' },
    { id: 'cathedral-30-mixed',   q: 'venue=crystal-cathedral&count=30' },
    { id: 'cathedral-40-mixed',   q: 'venue=crystal-cathedral&count=40' },
    // Orientation stress on the default hang
    { id: 'cathedral-08-portrait',  q: 'venue=crystal-cathedral&count=8&orient=portrait' },
    { id: 'cathedral-08-landscape', q: 'venue=crystal-cathedral&count=8&orient=landscape' },
    { id: 'cathedral-08-extreme',   q: 'venue=crystal-cathedral&count=8&orient=extreme' },
    { id: 'cathedral-tier-low-06',  q: 'venue=crystal-cathedral&count=6', tier: 'low' },
    { id: 'cathedral-cam-up',     q: 'venue=crystal-cathedral&count=12',
      cam: { p: [0, 1.6, 0],   t: [0, 18.8, 0] } },
    { id: 'cathedral-cam-wall',   q: 'venue=crystal-cathedral&count=12',
      cam: { p: [0, 1.6, 6],   t: [0, 1.8, 14] } },
    { id: 'cathedral-cam-rim',    q: 'venue=crystal-cathedral&count=12',
      cam: { p: [0, 1.6, 13],  t: [0, 2.2, 0] } },
    // Rollback chain: the IT2 colonnade body must still render by config.
    { id: 'cathedral-legacy-12',  q: 'venue=crystal-cathedral-legacy&count=12' },
    { id: 'void-overridden-12',   q: 'venue=infinite-void-overridden&count=12' },
    // Industrial Loft — default corridor layout + declared alternatives
    { id: 'loft-corridor-08',     q: 'venue=industrial-loft&count=8' },
    { id: 'loft-corridor-16',     q: 'venue=industrial-loft&count=16' },
    { id: 'loft-corridor-24',     q: 'venue=industrial-loft&count=24' },
    { id: 'loft-corridor-01',     q: 'venue=industrial-loft&count=1' },
    { id: 'loft-corridor-60',     q: 'venue=industrial-loft&count=60' },
    { id: 'loft-square-12',       q: 'venue=industrial-loft&count=12&layout=square' },
    { id: 'loft-square-30',       q: 'venue=industrial-loft&count=30&layout=square' },
    { id: 'loft-lshape-08',       q: 'venue=industrial-loft&count=8&layout=l-shape' },
    // Industrial Loft — orientation stress on the default corridor
    { id: 'loft-corridor-portrait',  q: 'venue=industrial-loft&count=8&orient=portrait' },
    { id: 'loft-corridor-landscape', q: 'venue=industrial-loft&count=8&orient=landscape' },
    { id: 'loft-corridor-extreme',   q: 'venue=industrial-loft&count=8&orient=extreme' },
    // Industrial Loft — tier degradation
    { id: 'loft-tier-low-08',     q: 'venue=industrial-loft&count=8', tier: 'low' },
    // Japanese Zen Gallery — "The Quiet Procession" (v2.0.0) matrix
    { id: 'zen-square-01',          q: 'venue=zen-gallery&count=1' },
    { id: 'zen-square-08',          q: 'venue=zen-gallery&count=8' },
    { id: 'zen-square-12-mixed',    q: 'venue=zen-gallery&count=12' },
    { id: 'zen-square-30-mixed',    q: 'venue=zen-gallery&count=30' },
    { id: 'zen-square-60-mixed',    q: 'venue=zen-gallery&count=60' },
    { id: 'zen-square-portrait',    q: 'venue=zen-gallery&count=8&orient=portrait' },
    { id: 'zen-square-landscape',   q: 'venue=zen-gallery&count=8&orient=landscape' },
    { id: 'zen-square-extreme',     q: 'venue=zen-gallery&count=8&orient=extreme' },
    { id: 'zen-corridor-08',        q: 'venue=zen-gallery&count=8&layout=corridor' },
    { id: 'zen-corridor-16',        q: 'venue=zen-gallery&count=16&layout=corridor' },
    { id: 'zen-lshape-08',          q: 'venue=zen-gallery&count=8&layout=l-shape' },
    { id: 'zen-tier-low-08',        q: 'venue=zen-gallery&count=8', tier: 'low' },
    { id: 'zen-live-wide-30',
      q: 'venue=zen-gallery&count=30',
      cam: { p: [-13, 2.1, 13], t: [6, 1.3, -10] } },
    // Dark Museum — FORENSIC BEFORE (v1.0.0 forensic body; never update it)
    { id: 'museum-before-square-08',  q: 'venue=dark-museum-v1&count=8' },
    { id: 'museum-before-rotunda-08', q: 'venue=dark-museum-v1&count=8&layout=rotunda' },
    // Dark Museum — diagnostic (all dynamic lights off)
    { id: 'museum-diag-dark',         q: 'venue=dm-dark&count=8' },
    // Dark Museum — diagnostic (ambient 6 — is ambient wiring alive?)
    { id: 'museum-diag-bright',       q: 'venue=dm-bright&count=8' },
    { id: 'museum-diag-tint55',       q: 'venue=dm-tint55&count=8' },
    { id: 'museum-diag-untint',       q: 'venue=dm-untint&count=8' },
    // Dark Museum — v2 deepening matrix
    { id: 'museum-square-01',         q: 'venue=dark-museum&count=1' },
    { id: 'museum-square-08',         q: 'venue=dark-museum&count=8' },
    { id: 'museum-square-12-mixed',   q: 'venue=dark-museum&count=12' },
    { id: 'museum-square-30-mixed',   q: 'venue=dark-museum&count=30' },
    { id: 'museum-square-60-mixed',   q: 'venue=dark-museum&count=60' },
    { id: 'museum-square-portrait',   q: 'venue=dark-museum&count=8&orient=portrait' },
    { id: 'museum-square-landscape',  q: 'venue=dark-museum&count=8&orient=landscape' },
    { id: 'museum-square-extreme',    q: 'venue=dark-museum&count=8&orient=extreme' },
    { id: 'museum-rotunda-08',        q: 'venue=dark-museum&count=8&layout=rotunda' },
    { id: 'museum-rotunda-16',        q: 'venue=dark-museum&count=16&layout=rotunda' },
    { id: 'museum-tier-low-08',       q: 'venue=dark-museum&count=8', tier: 'low' },
    { id: 'museum-overridden-08',     q: 'venue=dark-museum-overridden&count=8' },
    { id: 'museum-fogonly-08',        q: 'venue=dark-museum-fogonly&count=8' },
    { id: 'museum-live-wide-30',
      q: 'venue=dark-museum&count=30',
      cam: { p: [-13, 2.1, 13], t: [6, 1.3, -10] } },
    { id: 'museum-residual-wide-30',
      q: 'venue=dark-museum-residual&count=30',
      cam: { p: [-13, 2.1, 13], t: [6, 1.3, -10] } },
    { id: 'cyber-corridor-08',      q: 'venue=cyber-gallery&count=8' },
    { id: 'cyber-corridor-16',      q: 'venue=cyber-gallery&count=16' },
    { id: 'cyber-square-08',        q: 'venue=cyber-gallery&count=8&layout=square' },
    { id: 'cyber-square-30',        q: 'venue=cyber-gallery&count=30&layout=square' },
    { id: 'cyber-square-01',        q: 'venue=cyber-gallery&count=1&layout=square' },
    { id: 'cyber-corridor-portrait',  q: 'venue=cyber-gallery&count=8&orient=portrait' },
    { id: 'cyber-corridor-landscape', q: 'venue=cyber-gallery&count=8&orient=landscape' },
    { id: 'cyber-corridor-extreme',   q: 'venue=cyber-gallery&count=8&orient=extreme' },
    { id: 'cyber-motion-slow',      q: 'venue=cyber-gallery&count=8&motion=slow' },
    { id: 'cyber-motion-walk',      q: 'venue=cyber-gallery&count=8&motion=walk' },
    { id: 'cyber-motion-fast',      q: 'venue=cyber-gallery&count=8&motion=fast' },
    { id: 'cyber-motion-fast-sq',   q: 'venue=cyber-gallery&count=12&layout=square&motion=fast' },
    // Eye-level close reading — artwork readability at inspection distance.
    { id: 'cyber-cam-close',        q: 'venue=cyber-gallery&count=8&motion=walk',
      cam: { p: [1.75, 1.6, 0.95], t: [1.75, 1.6, 2.75] } },
    { id: 'garden-05',            q: 'venue=sculpture-garden&shadows=0&assets=0&count=5' },
    { id: 'garden-12-mixed',      q: 'venue=sculpture-garden&shadows=0&assets=0&count=12' },
    { id: 'garden-30-mixed',      q: 'venue=sculpture-garden&shadows=0&assets=0&count=30' },
    { id: 'garden-cam-arrival',   q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [0, 1.6, 8.4],   t: [0, 1.7, -4] } },
    { id: 'garden-cam-promenade', q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [0.9, 1.62, 5.2], t: [0, 1.7, -2] } },
    { id: 'garden-cam-court',     q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [0, 1.62, 3.4],  t: [0, 1.9, 0] } },
    { id: 'garden-cam-close',     q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [0, 1.62, -4.2],  t: [0, 1.65, -7.4] } },
    { id: 'garden-cam-distance',  q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [0, 1.6, 7.5],   t: [0, 1.9, -6.5] } },
    { id: 'garden-cam-boundary',  q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [-4.5, 1.6, 0.5],   t: [-14, 2.6, -10] } },
    { id: 'garden-cam-horizon',   q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [4, 1.6, -1],   t: [0.5, 4.2, -20] } },
    { id: 'garden-tier-low-12',   q: 'venue=sculpture-garden&shadows=0&assets=0&count=12', tier: 'low' },
    { id: 'garden-cam-front',     q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [0.1, 1.62, -2.9],  t: [0.1, 1.6, -5.0] } },
    { id: 'garden-cam-oblique',   q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [-2.4, 1.62, -3.6], t: [0.1, 1.6, -5.0] } },
    { id: 'garden-cam-backside',  q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [0.1, 1.62, -7.6],  t: [0.1, 1.6, -5.0] } },
    { id: 'garden-cam-backside3q',q: 'venue=sculpture-garden&shadows=0&assets=0&count=12',
      cam: { p: [-1.9, 1.62, -7.1], t: [0.1, 1.6, -5.0] } },
    { id: 'void-cam-behind',      q: 'venue=infinite-void&count=1',
      cam: { behind: 0, behindDist: 2.6 } },
    { id: 'garden-cam-assets',    q: 'venue=sculpture-garden&shadows=0&count=12',
      cam: { p: [0, 1.6, 8.4],   t: [0, 1.9, -5] } },
    { id: 'cyber-tier-low-08',      q: 'venue=cyber-gallery&count=8', tier: 'low' },
    // ── MIRROR LAKE — v1.0.0 FORENSIC BEFORE (never update; regression ref) ──
    { id: 'lake-v1-08',           q: 'venue=mirror-lake-v1&count=8' },
    { id: 'lake-v1-tier-low-08',  q: 'venue=mirror-lake-v1&count=8', tier: 'low' },
    { id: 'lake-05',              q: 'venue=mirror-lake&tier=high&reflect=0&assets=0&count=5' },
    { id: 'lake-12-mixed',        q: 'venue=mirror-lake&tier=high&reflect=0&assets=0&count=12' },
    { id: 'lake-40-mixed',        q: 'venue=mirror-lake&tier=high&reflect=0&assets=0&count=40' },
    { id: 'lake-cam-arrival',     q: 'venue=mirror-lake&tier=high&reflect=0&assets=0&count=12',
      cam: { p: [0, 1.6, 12.24],  t: [0, 1.7, -6] } },
    { id: 'lake-cam-walk',        q: 'venue=mirror-lake&tier=high&reflect=0&assets=0&count=12',
      cam: { p: [-6, 1.62, 7.2],  t: [-8.5, 1.55, -5] } },
    { id: 'lake-cam-pier',        q: 'venue=mirror-lake&tier=high&reflect=0&assets=0&count=12',
      cam: { p: [8.5, 1.62, 0.5], t: [1.5, 1.7, -9] } },
    { id: 'lake-cam-pavilion',    q: 'venue=mirror-lake&tier=high&reflect=0&assets=0&count=12',
      cam: { p: [8.5, 1.75, -10.2], t: [0, 1.7, 12] } },
    { id: 'lake-cam-close',       q: 'venue=mirror-lake&tier=high&reflect=0&assets=0&count=12',
      cam: { p: [-3.9, 1.62, -3.5], t: [-4.4, 1.6, -6.5] } },
    { id: 'lake-cam-horizon',     q: 'venue=mirror-lake&tier=high&reflect=0&assets=0&count=12',
      cam: { p: [0, 1.62, 6.5],   t: [0, 2.6, -20] } },
    { id: 'lake-cam-backside',    q: 'venue=mirror-lake&tier=high&reflect=0&assets=0&count=12',
      cam: { p: [-4.4, 1.62, -9.4], t: [-4.4, 1.6, -6.5] } },
    { id: 'lake-tier-low-12',     q: 'venue=mirror-lake&tier=low&reflect=0&assets=0&count=12', tier: 'low' },
    { id: 'salon-01',             q: 'venue=the-salon&count=1' },
    { id: 'salon-08-mixed',       q: 'venue=the-salon&count=8' },
    { id: 'salon-12-mixed',       q: 'venue=the-salon&count=12' },
    { id: 'salon-30-mixed',       q: 'venue=the-salon&count=30' },
    { id: 'salon-08-portrait',    q: 'venue=the-salon&count=8&orient=portrait' },
    { id: 'salon-tier-low-08',    q: 'venue=the-salon&count=8', tier: 'low' },
    { id: 'salon-cam-spawn',      q: 'venue=the-salon&count=12' },
    { id: 'salon-cam-corner',     q: 'venue=the-salon&count=12',
      cam: { p: [-3.1, 1.62, 3.1], t: [1.5, 1.5, -1.5] } },
    { id: 'salon-cam-door',       q: 'venue=the-salon&count=12',
      cam: { p: [0, 1.62, -2.6],  t: [0, 1.5, 4.2] } },
    { id: 'salon-cam-close',      q: 'venue=the-salon&count=12',
      cam: { p: [-1.4, 1.62, -2.2], t: [-1.4, 1.55, -4.2] } },
    { id: 'salon-cam-high',       q: 'venue=the-salon&count=12',
      cam: { p: [0, 3.0, 3.4],    t: [0, 1.2, -3] } },
    { id: 'salon-cam-front',      q: 'venue=the-salon&count=12',
      cam: { p: [0, 1.62, 3.2],   t: [0, 1.5, -4.2] } },
    { id: 'salon-cam-left',       q: 'venue=the-salon&count=12',
      cam: { p: [2.6, 1.62, 0],   t: [-4.2, 1.5, 0] } },
];

const tierInit = {
    high: `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
           Object.defineProperty(navigator,'deviceMemory',{get:()=>8});
           // Heavy scenarios (30-60 works, ~20 lights) can dip under the
           // 35fps downgrade threshold under SwiftShader even at boot size,
           // retro-downgrading the tier mid-capture. Stretch the monotonic
           // clock 250x so the deferred FPS benchmark's 2 s warmup needs
           // ~8 REAL minutes before it can even sample — a capture session
           // (~15 s) can never reach a verdict, so the static high-tier
           // detection is authoritative for every still. (Measured on the
           // cathedral: transmission + Reflector + bloom renders at ~1-4 fps
           // REAL under SwiftShader, so a mere 4-8x stretch still let the
           // benchmark fire "1.1 fps < 35" mid-capture — the tier race that
           // made some review stills render as Lambert haze. Real GPUs hold
           // 60 fps; this only pins the QA harness, never the product.)
           const __origNow = performance.now.bind(performance);
           const __t0 = __origNow();
           performance.now = () => __t0 + (__origNow() - __t0) * 0.004;`,
    low:  `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>2});
           Object.defineProperty(navigator,'deviceMemory',{get:()=>2});
           Object.defineProperty(navigator,'maxTouchPoints',{get:()=>0});`,
};

const BOOT_VIEWPORT = { width: 320, height: 180 };
const SHOT_VIEWPORT = { width: 640, height: 360 };

async function run() {
    const browser = await chromium.launch({
        args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--disable-lcd-text',
               '--disable-gpu-vsync', '--disable-frame-rate-limit',
               '--disable-renderer-backgrounding', '--disable-background-timer-throttling',
               '--disable-backgrounding-occluded-windows'],
    });
    const report = [];
    for (const sc of SCENARIOS) {
        if (ONLY.length && !ONLY.includes(sc.id)) continue;
        const tier = sc.tier || 'high';
        const ctx = await browser.newContext({
            viewport: BOOT_VIEWPORT,
            deviceScaleFactor: 1,
        });
        await ctx.addInitScript(`
            ${tierInit[tier]}
            // Hide the GPU string so detectLowEnd's software-renderer regex
            // cannot see SwiftShader (keeps the requested tier authoritative).
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
        const errors = [];
        page.on('pageerror', e => errors.push(String(e)));
        page.on('crash', () => errors.push('PAGE CRASHED'));
        page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });

        console.error(`[shoot] ${sc.id}: page loading…`);
        await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?${sc.q}`, { waitUntil: 'load' });
        console.error(`[shoot] ${sc.id}: loaded, waiting for enter…`);

        // Wait for the viewer to unlock Enter (enterReady or 100%)
        try {
            await page.waitForFunction(() => {
                const b = document.getElementById('enter-btn');
                return b && b.style.pointerEvents === 'auto';
            }, { timeout: 45000 });
        } catch { errors.push('enter-btn never unlocked'); }
        console.error(`[shoot] ${sc.id}: entering…`);

        // Enter, then let the arrival choreography finish (1.5 s dolly + margin)
        await page.$eval('#enter-btn', el => el.click()).catch(e => errors.push(`enter click: ${e}`));
        await page.waitForFunction(() => {
            const s = window.__exospace?.scene;
            return !s || (s._gardenAssetsSettled !== false && s._lakeAssetsSettled !== false);
        }, { timeout: 90000 }).catch(() => errors.push('venue assets never settled'));
        console.error(`[shoot] ${sc.id}: assets gate passed`);
        await page.waitForTimeout(Math.round(10000 * SETTLE));       // FPS-benchmark window closes
        await page.setViewportSize(SHOT_VIEWPORT);    // capture resolution
        await page.waitForTimeout(Math.round(9000 * SETTLE));        // frames at capture res + background texture stream
        if ((sc.q || '').includes('sculpture-garden') || (sc.q || '').includes('mirror-lake')) {
            await page.waitForTimeout(Math.round(15000 * SETTLE));
        }
        console.error(`[shoot] ${sc.id}: capturing…`);

        // Hide HUD chrome for clean venue captures (crosshair, buttons, hint)
        await page.addStyleTag({ content: '#crosshair,#ui-layer,#controls-hint{display:none!important}' }).catch(() => {});

        if (sc.cam) {
            await page.evaluate((cam) => {
                const s = window.__exospace?.scene;
                if (!s?.camera) return;
                if (cam.behind !== undefined) {
                    const art = s.artworks?.[cam.behind];
                    if (!art) return;
                    art.updateMatrixWorld(true);
                    const V3 = s.camera.position.constructor;
                    const Q  = s.camera.quaternion.constructor;
                    const pos = new V3();
                    art.getWorldPosition(pos);
                    const q = new Q();
                    art.getWorldQuaternion(q);
                    const back = new V3(0, 0, -1).applyQuaternion(q);
                    const eye = pos.clone().add(back.multiplyScalar(cam.behindDist || 2.4));
                    eye.y = pos.y + (cam.behindLift ?? 0.04);
                    s.camera.position.copy(eye);
                    s.camera.lookAt(pos);
                    s.camera.updateMatrixWorld();
                    return;
                }
                s.camera.position.set(...cam.p);
                s.camera.lookAt(...cam.t);
                s.camera.updateMatrixWorld();
            }, sc.cam);
            await page.waitForTimeout(2000);   // let a full render loop pass
        }

        let pngB64 = null;
        try {
            const cdp = await ctx.newCDPSession(page);
            pngB64 = await Promise.race([
                cdp.send('Page.captureScreenshot', { format: 'png' }, { timeout: 310000 })
                    .then(d => d.data),
                new Promise(resolve => setTimeout(() => resolve(null), 300000)),
            ]);
            if (!pngB64) errors.push('capture timed out (scene too heavy for the software rasterizer)');
        } catch (e) {
            errors.push(`capture failed: ${String(e).slice(0, 120)}`);
        }
        const shot = path.resolve(rootDir, OUT, `${sc.id}.png`);
        if (pngB64) {
            await writeFile(shot, Buffer.from(pngB64, 'base64'));
        }

        let stats = null;
        if (STATS) {
            try {
                stats = await Promise.race([
                    page.evaluate(() => {
                        const s = window.__exospace?.scene;
                        if (!s?.renderer) return null;
                        const i = s.renderer.info;
                        return {
                            drawCalls: i.render.calls, triangles: i.render.triangles,
                            geometries: i.memory.geometries, textures: i.memory.textures,
                            sceneObjects: s.scene ? s.scene.children.length : null,
                            lights: s.scene ? s.scene.children.filter(o => o.isLight).length : null,
                            camera: s.camera ? { x: +s.camera.position.x.toFixed(2), y: +s.camera.position.y.toFixed(2), z: +s.camera.position.z.toFixed(2) } : null,
                            artworks: s.artworks?.length ?? null,
                            roomBounds: s.roomBounds, layout: s._layoutMeta?.type,
                            tier: { lowEnd: !!s.isLowEnd, mobile: !!s.isMobile, mobileTier: !!s._isMobileTier },
                            exposure: s.renderer.toneMappingExposure,
                            fog: s.scene.fog ? { near: s.scene.fog.near, far: s.scene.fog.far, color: '#' + s.scene.fog.color.getHexString() } : null,
                        };
                    }),
                    new Promise(resolve => setTimeout(() => resolve(null), 15000)),
                ]);
            } catch { stats = null; }   // shot already captured — keep going
        }
        report.push({ id: sc.id, tier, shot: path.basename(shot), errors, stats });
        await ctx.close();
    }
    await browser.close();
    server.close();
    console.log(JSON.stringify(report, null, 2));
}
await run();
