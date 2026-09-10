// probe-salon-perf.mjs — draw calls / triangles / lights / console errors
// for the salon at representative counts. Render loop owns the camera (spawn).
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4199;
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.png': 'image/png', '.jpg': 'image/jpeg' };
const server = createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]);
    if (p === '/') p = '/harness/harness.html';
    const file = join(rootDir, 'public', p);
    if (existsSync(file)) { res.writeHead(200, { 'Content-Type': MIME[extname(file)] || 'application/octet-stream' }); res.end(readFileSync(file)); }
    else { res.writeHead(404); res.end(); }
});
await new Promise(r => server.listen(PORT, r));
const browser = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });

for (const count of [8, 12, 30]) {
    const page = await browser.newPage({ viewport: { width: 640, height: 360 } });
    const errors = [];
    page.on('pageerror', e => errors.push('PAGEERROR: ' + String(e)));
    page.on('console', m => { if (m.type() === 'error' && !m.text().includes('404') && !m.text().includes('Failed to load resource')) errors.push(m.text()); });
    const failed = [];
    page.on('requestfailed', r => failed.push(r.url().split('/').pop()));
    await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=the-salon&count=${count}`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#enter-btn', { timeout: 60000 });
    await page.evaluate(() => { const b = document.getElementById('enter-btn'); if (b) b.click(); });
    await page.waitForTimeout(7000);
    const stats = await page.evaluate(() => {
        const s = window.__exospace.scene;
        const i = s.renderer.info;
        return {
            drawCalls: i.render.calls, triangles: i.render.triangles,
            programs: i.programs?.length ?? null, geometries: i.memory.geometries, textures: i.memory.textures,
            lights: s.scene.children.filter(o => o.isLight).length,
            artworks: s.artworks?.length, exposure: s.renderer.toneMappingExposure,
            fog: s.scene.fog ? `${s.scene.fog.near}/${s.scene.fog.far}` : null,
        };
    });
    console.log(`count=${count}`, JSON.stringify(stats), 'jsErrors:', errors.length ? errors : 'none', 'netFail:', failed.length ? failed : 'none');
    await page.close();
}
await browser.close(); server.close(); process.exit(0);
