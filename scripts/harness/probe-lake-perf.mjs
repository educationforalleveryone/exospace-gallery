// probe-lake-perf.mjs — console/network hygiene + renderer stats for Mirror
// Lake on both water paths. 404s for stripped assets (GLBs/HDRIs/audio in the
// QA sandbox) are expected and reported separately from runtime errors.
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4195;
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

const browser = await chromium.launch({ args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
const results = {};

for (const reflect of ['0', '1']) {
    const page = await browser.newPage({ viewport: { width: 800, height: 450 } });
    const consoleErrors = [];
    const notFound = [];
    page.on('pageerror', e => consoleErrors.push('PAGEERROR: ' + String(e).slice(0, 200)));
    page.on('console', m => {
        if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200));
        if (m.type() === 'warning') consoleErrors.push('WARN: ' + m.text().slice(0, 200));
    });
    page.on('response', r => { if (r.status() === 404) notFound.push(r.url().split('/').pop()); });

    await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=mirror-lake&reflect=${reflect}&count=12&tier=high`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#enter-btn', { timeout: 60000 });
    await page.$eval('#enter-btn', el => el.click()).catch(() => {});
    await page.waitForTimeout(reflect === '0' ? 12000 : 20000);

    // walk a little ( exercise movement + collision + clamp paths )
    await page.evaluate(() => {
        const s = window.__exospace.scene;
        s.controls.isLocked = true;
        s.arrivalActive = false;
        s.moveState.forward = true;
    });
    await page.waitForTimeout(reflect === '0' ? 4000 : 9000);
    await page.evaluate(() => {
        const s = window.__exospace.scene;
        s.moveState.forward = false;
    });

    results[`reflect_${reflect}`] = await page.evaluate(() => {
        const s = window.__exospace.scene;
        const i = s.renderer?.info;
        const mem = s.renderer?.info?.memory;
        const programs = s.renderer?.info?.programs?.length;
        return {
            tier: { low: s.isLowEnd, mobile: s._isMobileTier },
            drawCalls: i?.render?.calls,
            triangles: i?.render?.triangles,
            geometries: mem?.geometries,
            textures: mem?.textures,
            programs,
            artworks: s.artworks?.length,
            obstacles: s._obstacles?.length,
        };
    });
    results[`reflect_${reflect}`].consoleErrors = consoleErrors.filter(e => !e.startsWith('WARN:'));
    results[`reflect_${reflect}`].consoleWarnings = consoleErrors.filter(e => e.startsWith('WARN:')).slice(0, 5);
    results[`reflect_${reflect}`].notFound = [...new Set(notFound)];
    await page.close();
}
console.log(JSON.stringify(results, null, 1));
await browser.close();
server.close();
process.exit(0);
