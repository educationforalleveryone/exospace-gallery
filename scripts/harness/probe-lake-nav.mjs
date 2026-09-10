// probe-lake-nav.mjs — Mirror Lake navigation/collision reproduction probe.
//
//   node scripts/harness/probe-lake-nav.mjs [count]
//
// Boots the harness (mirror-lake), freezes the render loop, then drives the
// REAL movement pipeline (velocity → enforceRoomBounds → _lakeTick) through
// scripted visitor scenarios and reports:
//   • teleports (per-frame displacement far above maxSpeed·dt)
//   • water intrusion (position over water while not on pier/pavilion)
//   • railing pass-through (pier deck edge crossed laterally)
//   • reachable viewing distance per artwork
//   • stuck states (input held, position frozen)
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4177;
const count = Number(process.argv[2] || 12);
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
const page = await browser.newPage({ viewport: { width: 1280, height: 720 } });
const errors = [];
page.on('pageerror', e => errors.push(String(e)));
page.on('console', m => { if (m.type() === 'error' && !m.text().includes('404')) errors.push(m.text()); });

await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=mirror-lake&reflect=0&count=${count}`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
await page.click('#enter-btn', { force: true, timeout: 8000 }).catch(() => {});
await page.waitForTimeout(9000);

const report = await page.evaluate(async () => {
    const s = window.__exospace?.scene;
    if (!s?.scene) return { error: 'no scene' };
    // Freeze the live loop so the manual pipeline below owns the camera.
    s._isVisible = false;
    await new Promise(r => setTimeout(r, 120));

    const DT = 1 / 60;
    const plan = s._lakePlan;
    const out = { spawn: { ...plan.spawn }, pier: { ...plan.pier }, pavilion: { ...plan.pavilion }, R: plan.radius, scenarios: [] };

    const isWaterAt = (x, z, m = 0) => z < plan.shoreZ(x) - m;
    const onPierBand = (x, z) => Math.abs(x - plan.pier.x) < plan.pier.width / 2 + 0.3
        && z < plan.pier.footZ + 0.5 && z > plan.pavilion.z - (plan.pavilion.size / 2 + 0.25);
    const onPavBand = (x, z) => Math.abs(x - plan.pavilion.x) < plan.pavilion.size / 2 + 0.25
        && Math.abs(z - plan.pavilion.z) < plan.pavilion.size / 2 + 0.25;

    // Deterministic movement clock — the live FPS sampler shares scene.clock
    // via rAF, so manual updateMovement() calls would read ~0 delta. Pin it.
    s.clock.getDelta = () => DT;

    // Run the real pipeline for `seconds` while holding the given move state,
    // starting the camera at (x, z) looking along (lx, lz). Returns a trace.
    const run = (name, x, z, dirX, dirZ, seconds, opts = {}) => {
        s.camera.position.set(x, 1.6, z);
        s.velocity.set(0, 0, 0);
        s.controls.isLocked = true;
        s.arrivalActive = false;
        s.isInspecting = false;
        // Face the walk direction: camera default forward is −z; yaw rotates it.
        s.camera.rotation.set(0, Math.atan2(-dirX, -dirZ), 0);
        s.camera.updateMatrixWorld(true);   // moveForward reads matrixWorld
        s.moveState.forward = false; s.moveState.backward = false;
        s.moveState.left = false; s.moveState.right = false;
        if (opts.backward) s.moveState.backward = true;
        else s.moveState.forward = true;
        if (opts.strafe) { s.moveState.forward = false; s.moveState[opts.strafe] = true; }

        const trace = [];
        let teleported = false, maxStep = 0, teleportAt = null;
        const steps = Math.round(seconds / DT);
        for (let i = 0; i < steps; i++) {
            const px = s.camera.position.x, pz = s.camera.position.z;
            s.updateMovement();          // velocity + enforceRoomBounds + y pin
            if (s._lakeTick) s._lakeTick(); // the exact frame order in animate()
            const nx = s.camera.position.x, nz = s.camera.position.z;
            const step = Math.hypot(nx - px, nz - pz);
            maxStep = Math.max(maxStep, step);
            if (step > 0.6) { teleported = true; teleportAt = { frame: i, from: [+px.toFixed(2), +pz.toFixed(2)], to: [+nx.toFixed(2), +nz.toFixed(2)] }; }
            trace.push([+nx.toFixed(3), +nz.toFixed(3)]);
        }
        s.moveState.forward = false; s.moveState.backward = false;
        s.moveState.left = false; s.moveState.right = false;
        s.controls.isLocked = false;
        const end = { x: +s.camera.position.x.toFixed(3), z: +s.camera.position.z.toFixed(3) };
        const water = isWaterAt(end.x, end.z, 0.05) && !onPierBand(end.x, end.z) && !onPavBand(end.x, end.z);
        out.scenarios.push({
            name, start: [x, z], end,
            teleported, maxStep: +maxStep.toFixed(3),
            teleportAt,
            endedOverWater: water,
            traceEvery: trace.filter((_, i) => i % 30 === 0),
        });
        return end;
    };

    // ── A. Walk straight at every artwork from the shore side ───────────
    for (let i = 0; i < s.artworks.length; i++) {
        const art = s.artworks[i];
        art.updateWorldMatrix(true, false);
        const p = art.getWorldPosition(new (art.position.constructor)());
        // Start on the shore walk nearest the artwork, then walk straight at it.
        let best = null, bd = Infinity;
        for (const [wx, wz] of plan.walk.samples) {
            const d = Math.hypot(wx - p.x, wz - p.z);
            if (d < bd) { bd = d; best = [wx, wz]; }
        }
        const dx = p.x - best[0], dz = p.z - best[1];
        const dl = Math.hypot(dx, dz) || 1;
        run(`A/artwork-${i}`, best[0], best[1], dx / dl, dz / dl, 8);
    }

    // ── B. Pier walk north, then step off the deck edge mid-water ───────
    const pierMidZ = (plan.pier.footZ + plan.pier.endZ) / 2;
    run('B/pier-north', plan.pier.x, plan.pier.footZ + 0.2, 0, -1, 14);
    run('B/pier-stepoff-west', plan.pier.x, pierMidZ, -1, 0, 4);
    run('B/pier-stepoff-east', plan.pier.x, pierMidZ, 1, 0, 4);

    // ── C. Pavilion: walk on, then off the open deck edges ──────────────
    run('C/pavilion-on', plan.pavilion.x, plan.pavilion.z + plan.pavilion.size / 2 + 1.2, 0, -1, 8);
    run('C/pavilion-stepoff-east', plan.pavilion.x, plan.pavilion.z, 1, 0, 4);
    run('C/pavilion-stepoff-west', plan.pavilion.x, plan.pavilion.z, -1, 0, 4);

    // ── D. Shore walk end-to-end + spawn sanity ─────────────────────────
    const w0 = plan.walk.samples[0];
    const wN = plan.walk.samples[plan.walk.samples.length - 1];
    run('D/shorewalk-east', w0[0], w0[1], 1, 0, 20);
    run('D/shorewalk-west', wN[0], wN[1], -1, 0, 20);
    run('D/spawn-to-hero', plan.spawn.x, plan.spawn.z, -0.45, -0.89, 12);

    // ── E. Reachable viewing distance per artwork (walk from the water's
    //      edge at 3 sample bearings toward each berth) ──────────────────
    const view = [];
    for (let i = 0; i < s.artworks.length; i++) {
        const art = s.artworks[i];
        art.updateWorldMatrix(true, false);
        const p = art.getWorldPosition(new (art.position.constructor)());
        let minD = Infinity;
        for (let a = 0; a < 8; a++) {
            const th = (a / 8) * Math.PI * 2;
            // start 1 m south of the shoreline at the artwork's x ± offsets
            const sx = p.x + Math.sin(th) * 3;
            const sz = plan.shoreZ(sx) - 0.1;
            const dx = p.x - sx, dz = p.z - sz;
            const dl = Math.hypot(dx, dz) || 1;
            const end = run(`E/view-${i}-a${a}`, sx, sz, dx / dl, dz / dl, 6);
            const d = Math.hypot(end.x - p.x, end.z - p.z);
            minD = Math.min(minD, d);
        }
        view.push({ i, minDistXZ: +minD.toFixed(2) });
    }
    out.viewing = view;

    s._isVisible = true;
    return out;
});

console.log(JSON.stringify(report, null, 1));
console.log('page errors:', errors.slice(0, 8));
await browser.close();
server.close();
process.exit(0);
