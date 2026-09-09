// Probe: load the harness garden page and dump console errors
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';

const PORT = 4211;
const rootDir = '/home/z/my-project/exospace';
const publicDir = path.join(rootDir, 'public');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.mjs': 'text/javascript', '.json': 'application/json', '.jpg': 'image/jpeg', '.png': 'image/png', '.hdr': 'application/octet-stream', '.glb': 'model/gltf-binary', '.wasm': 'application/wasm' };

const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, `http://127.0.0.1:${PORT}`);
        let p = decodeURIComponent(url.pathname);
        if (p.endsWith('/')) p += 'index.html';
        const fp = path.join(publicDir, p);
        if (!fp.startsWith(publicDir) || !existsSync(fp)) { res.writeHead(404); res.end('nf ' + p); return; }
        const data = await readFile(fp);
        res.writeHead(200, { 'Content-Type': MIME[path.extname(fp)] || 'application/octet-stream' });
        res.end(data);
    } catch { res.writeHead(500); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const { chromium } = await import('playwright');
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 720 } });
const page = await ctx.newPage();
page.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') console.log('[console]', m.type(), m.text().slice(0, 300)); });
page.on('pageerror', e => console.log('[pageerror]', String(e).slice(0, 500)));
page.on('response', r => { if (r.status() >= 400) console.log('[http]', r.status(), r.url().slice(-120)); });
await page.goto(`http://127.0.0.1:${PORT}/harness/scripts/harness/harness.html?venue=sculpture-garden&count=12`, { waitUntil: 'networkidle', timeout: 60000 }).catch(e => console.log('[goto]', String(e).slice(0, 200)));
await page.waitForTimeout(3000);
const btn = await page.$('#enter-btn');
console.log('enter-btn present:', !!btn);
const title = await page.title().catch(() => '?');
console.log('title:', title);
await browser.close();
server.close();
process.exit(0);
