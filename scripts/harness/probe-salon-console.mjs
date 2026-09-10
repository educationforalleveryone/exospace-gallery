// probe-salon-console.mjs — high-tier boot: capture ALL console traffic,
// assert the RGBELoader deprecation warning is gone and the ao.jpg 404s are
// gone (the two console defects from the deployed field report).
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4205;
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.jpg': 'image/jpeg', '.glb': 'model/gltf-binary', '.hdr': 'application/octet-stream' };
const server = createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]);
    if (p === '/') p = '/harness/harness.html';
    const file = join(rootDir, 'public', p);
    if (existsSync(file)) { res.writeHead(200, { 'Content-Type': MIME[extname(file)] || 'application/octet-stream' }); res.end(readFileSync(file)); }
    else { res.writeHead(404); res.end(); }
});
await new Promise(r => server.listen(PORT, r));

const browser = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
const page = await browser.newPage({ viewport: { width: 480, height: 270 } });
const consoleLines = [];
const failed404 = [];
page.on('console', m => consoleLines.push(`[${m.type()}] ${m.text()}`));
page.on('response', r => { if (r.status() === 404) failed404.push(r.url()); });
page.on('pageerror', e => consoleLines.push(`[pageerror] ${String(e)}`));

await page.addInitScript(`Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
Object.defineProperty(navigator,'deviceMemory',{get:()=>8});`);
await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=the-salon&count=12&tier=high`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
for (let i = 0; i < 5; i++) {
    await page.evaluate(() => { const b = document.getElementById('enter-btn'); if (b) b.click(); });
    await page.waitForTimeout(2200);
    if (await page.evaluate(() => !!window.__exospace?.scene?.scene)) break;
}
await page.waitForTimeout(2500);

const deprecat = consoleLines.filter(l => /deprecat/i.test(l));
const ao404 = failed404.filter(u => /ao\.jpg/.test(u));
console.log('deprecation warnings:', deprecat.length ? deprecat : 'NONE ✓');
console.log('ao.jpg 404s:', ao404.length ? ao404 : 'NONE ✓');
console.log('404s total:', failed404.length ? failed404 : 'none');
console.log('page errors:', consoleLines.filter(l => l.startsWith('[pageerror]')).length ? 'YES' : 'none');
const envOk = await page.evaluate(() => {
    const s = window.__exospace.scene;
    return { hasEnv: !!s.scene.environment, envType: s.scene.environment?.type || null };
});
console.log('HDRI environment:', JSON.stringify(envOk));
await browser.close(); server.close(); process.exit(0);
