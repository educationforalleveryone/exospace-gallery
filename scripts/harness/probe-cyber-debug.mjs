// Minimal probe: load the harness page, dump console errors + page errors.
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = 4199;
const rootDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicDir = path.join(rootDir, 'public');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.jpg': 'image/jpeg', '.png': 'image/png' };
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
        let p = decodeURIComponent(url.pathname);
        if (p.endsWith('/')) p += 'index.html';
        const fp = path.join(publicDir, p);
        if (!fp.startsWith(publicDir) || !existsSync(fp)) { res.writeHead(404); res.end('nf: ' + p); return; }
        const data = await readFile(fp);
        res.writeHead(200, { 'Content-Type': MIME[path.extname(fp)] || 'application/octet-stream' });
        res.end(data);
    } catch { res.writeHead(500); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const browser = await chromium.launch({ args: ['--enable-unsafe-swiftshader', '--use-angle=swiftshader'] });
const ctx = await browser.newContext({ viewport: { width: 640, height: 360 } });
const page = await ctx.newPage();
page.on('pageerror', e => console.log('PAGEERROR:', String(e).slice(0, 500)));
page.on('console', m => {
    const t = m.text();
    if (t.includes('Shader Error') || t.includes('FRAGMENT') || t.includes('ERROR')) {
        console.log('=== SHADER MSG ===');
        console.log(t);
        console.log('=== END ===');
    } else if (m.type() === 'error' || m.type() === 'warning') {
        console.log('CONSOLE', m.type(), t.slice(0, 200));
    }
});
const q = process.argv[2] || 'venue=cyber-gallery&count=8';
await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?${q}`, { waitUntil: 'load' });
await page.waitForTimeout(8000);
const state = await page.evaluate(() => ({
    ready: !!window.__HARNESS_READY,
    hasData: !!window.GALLERY_DATA,
    venueSlug: window.GALLERY_DATA?.venue_slug,
    qaMotion: window.EXOSPACE_QA_MOTION,
    enterBtn: !!document.getElementById('enter-btn'),
    canvas: !!document.querySelector('#canvas-container canvas'),
}));
console.log('STATE:', JSON.stringify(state));
await browser.close();
server.close();
process.exit(0);
