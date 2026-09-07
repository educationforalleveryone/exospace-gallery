#!/usr/bin/env node
// Probe: capture the full stack of the page error on a venue preview boot.
import { chromium } from 'playwright';

const BASE = process.argv[2] || 'http://127.0.0.1:8090';
const slug = process.argv[3] || 'white-cube';
const browser = await chromium.launch({ headless: true });
const page = await (await browser.newContext({
    viewport: { width: 1280, height: 720 },
    reducedMotion: 'reduce',
    deviceScaleFactor: 1,
})).newPage();
page.on('pageerror', e => {
    console.log('PAGEERROR:', e.message);
    console.log('STACK:', (e.stack || '').split('\n').slice(0, 12).join('\n'));
});
page.on('console', m => { if (m.type() === 'error') console.log('CONSOLE:', m.text().slice(0, 300)); });
await page.goto(`${BASE}/venues/${slug}/preview`, { waitUntil: 'domcontentloaded', timeout: 60000 });
const canvas = page.locator('#canvas-container canvas').first();
await canvas.waitFor({ state: 'visible', timeout: 60000 });
const enter = page.locator('#enter-btn').first();
if (await enter.isVisible().catch(() => false)) {
    await page.waitForFunction(() => {
        const b = document.getElementById('enter-btn');
        return !!b && getComputedStyle(b).pointerEvents === 'auto';
    }, { timeout: 90000 });
    await enter.click();
}
await page.waitForTimeout(10000);
await browser.close();
console.log('probe done');
