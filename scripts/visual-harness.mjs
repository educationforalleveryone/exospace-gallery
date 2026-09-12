#!/usr/bin/env node

const args = process.argv.slice(2);
function arg(name, fallback) {
    const i = args.indexOf(`--${name}`);
    if (i === -1) return fallback;
    const v = args[i + 1];
    return v && !v.startsWith('--') ? v : true;
}

const BASE_URL   = String(arg('base-url', 'http://127.0.0.1:8000')).replace(/\/$/, '');
const OUT_DIR    = String(arg('out', 'baselines'));
const UPDATE     = arg('update', false) === true;
const ONLY       = [].concat(arg('venue', []) || []).map(String);
const THRESHOLD  = Number(arg('threshold', 0.1));   // per-pixel YIQ delta
const MAX_DRIFT  = Number(arg('max-drift', 0.01));  // 1% of pixels may differ

const fs   = await import('node:fs');
const path = await import('node:path');

let chromium, puppeteerErr = null;
try {
    chromium = (await import('playwright')).chromium;
} catch (e) {
    puppeteerErr = e;
}
if (!chromium) {
    console.error(
        '\n[visual-harness] playwright is not installed.\n' +
        '  Run:  npm i -D playwright pixelmatch pngjs && npx playwright install chromium\n' +
        `  (${puppeteerErr?.message ?? 'import failed'})\n`
    );
    process.exit(2);
}

async function venueSlugs(page) {
    if (ONLY.length) return ONLY;
    await page.goto(`${BASE_URL}/venues`, { waitUntil: 'domcontentloaded' });
    return page.$$eval('a[href*="/venues/"]', as =>
        [...new Set(as.map(a => a.getAttribute('href')))]
            .map(h => h.split('/venues/')[1]?.split(/[?#]/)[0])
            .filter(Boolean)
    );
}

async function capture(page, slug) {
    const url = `${BASE_URL}/venues/${slug}/preview`;
    await page.goto(url, { waitUntil: 'domcontentloaded' });

    const canvas = page.locator('#canvas-container canvas').first();
    await canvas.waitFor({ state: 'visible', timeout: 30_000 });

    const enter = page.locator('#enter-btn').first();
    if (await enter.isVisible().catch(() => false)) {
        await expectEnabled(page);
        await enter.click();
    }
    await page.waitForTimeout(1_500);

    const buf = await canvas.screenshot();
    return buf;
}

async function expectEnabled(page) {
    try {
        await page.waitForFunction(
            () => {
                const b = document.getElementById('enter-btn');
                return !!b && getComputedStyle(b).pointerEvents === 'auto';
            },
            { timeout: 60_000 },
        );
    } catch {
        const skip = page.locator('#skip-intro-link').first();
        if (await skip.isVisible().catch(() => false)) {
            await skip.click();
            return;
        }
        throw new Error('enter control never enabled — textures did not finish loading');
    }
}

function compare(aBuf, bBuf) {
    return (async () => {
        const { PNG } = await import('pngjs');
        const pixelmatch = (await import('pixelmatch')).default ?? (await import('pixelmatch'));
        const a = PNG.sync.read(aBuf), b = PNG.sync.read(bBuf);
        if (a.width !== b.width || a.height !== b.height) {
            return { drift: 1, note: `dimensions changed ${a.width}x${a.height} → ${b.width}x${b.height}` };
        }
        const diff = new PNG({ width: a.width, height: a.height });
        const n = pixelmatch(a.data, b.data, diff.data, a.width, a.height, { threshold: THRESHOLD });
        return { drift: n / (a.width * a.height), diffPng: diff };
    })();
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
    viewport: { width: 1280, height: 720 },
    reducedMotion: 'reduce',
    deviceScaleFactor: 1,
});
const page = await context.newPage();

fs.mkdirSync(OUT_DIR, { recursive: true });
const slugs = await venueSlugs(page);
if (!slugs.length) {
    console.error('[visual-harness] no venue slugs found — pass --venue=slug or check --base-url.');
    process.exit(2);
}
console.log(`[visual-harness] sweep: ${slugs.join(', ')}\n`);

let failures = 0;
for (const slug of slugs) {
    const file = path.join(OUT_DIR, `${slug}.png`);
    try {
        const shot = await capture(page, slug);
        if (UPDATE || !fs.existsSync(file)) {
            fs.writeFileSync(file, shot);
            console.log(`  ${UPDATE ? 'baseline updated' : 'baseline created'}  ${slug}`);
            continue;
        }
        const { drift, diffPng, note } = await compare(fs.readFileSync(file), shot);
        if (drift <= MAX_DRIFT) {
            console.log(`  ok (${(drift * 100).toFixed(2)}% drift)  ${slug}`);
        } else {
            failures++;
            const diffFile = path.join(OUT_DIR, `${slug}.diff.png`);
            if (diffPng) fs.writeFileSync(diffFile, PNG.sync.write(diffPng));
            console.log(`  DRIFT ${(drift * 100).toFixed(2)}%  ${slug}${note ? ` — ${note}` : ''}${diffPng ? `  (diff: ${diffFile})` : ''}`);
        }
    } catch (e) {
        failures++;
        console.log(`  ERROR  ${slug} — ${e.message.split('\n')[0]}`);
    }
}

await browser.close();
console.log(failures ? `\n${failures} venue(s) drifted or failed — see above.` : '\nAll venues match their baselines.');
process.exit(failures ? 1 : 0);
