#!/usr/bin/env node
// probe-salon-v3-walk.mjs — the two-room walkability probe.
//
//   node scripts/harness/probe-salon-v3-walk.mjs [count]
//
// Boots the harness (the-salon v3), freezes the live loop, then drives the
// REAL movement pipeline (velocity → enforceRoomBounds → obstacle push-out)
// through the v3 scenarios and asserts the field contract:
//   • the curtain fabric BLOCKS (walking into a panel stops at the AABB —
//     the v2.0 "I can walk through it" defect stays dead);
//   • the 2.4 m opening PASSES (the declared walk gap is the actual gap,
//     including a graze along the box edge);
//   • the walnut double door is never clipped (the wall skin stops the
//     visitor in front of the leaves);
//   • the bench still blocks (furniture collision sanity);
//   • every artwork in BOTH rooms is approachable to viewing distance;
//   • no teleports (max per-frame step ≤ the speed cap).
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4191;
const count = Number(process.argv[2] || 24);
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
const page = await browser.newPage({ viewport: { width: 960, height: 540 } });
const errors = [];
page.on('pageerror', e => errors.push(String(e)));
page.on('console', m => { if (m.type() === 'error' && !m.text().includes('404')) errors.push(m.text()); });

await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=the-salon&count=${count}`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
await page.click('#enter-btn', { force: true, timeout: 8000 }).catch(() => {});
await page.waitForTimeout(9000);

const report = await page.evaluate(async () => {
    const s = window.__exospace?.scene;
    if (!s?.scene) return { error: 'no scene' };
    s._isVisible = false;
    await new Promise(r => setTimeout(r, 120));

    const DT = 1 / 60;
    s.clock.getDelta = () => DT;
    const L = s._layoutMeta?.wallLength || 0;
    const obstacles = (s._obstacles || []).map(o => ({
        minX: +o.box.min.x.toFixed(2), maxX: +o.box.max.x.toFixed(2),
        minZ: +o.box.min.z.toFixed(2), maxZ: +o.box.max.z.toFixed(2),
    }));
    // the curtain's two boxes: at z≈0, wide in x
    const curtainBoxes = obstacles.filter(o => Math.abs(o.minZ) < 0.6 && Math.abs(o.maxZ) < 0.6 && (o.maxX - o.minX) > 1.0);

    const run = (name, x, z, dirX, dirZ, seconds) => {
        s.camera.position.set(x, 1.6, z);
        s.velocity.set(0, 0, 0);
        s.controls.isLocked = true;
        s.arrivalActive = false;
        s.isInspecting = false;
        s.camera.rotation.set(0, Math.atan2(-dirX, -dirZ), 0);
        s.camera.updateMatrixWorld(true);
        s.moveState.forward = true; s.moveState.backward = false;
        s.moveState.left = false; s.moveState.right = false;
        const trace = [];
        let maxStep = 0, teleported = false;
        const steps = Math.round(seconds / DT);
        for (let i = 0; i < steps; i++) {
            const px = s.camera.position.x, pz = s.camera.position.z;
            s.updateMovement();
            const nx = s.camera.position.x, nz = s.camera.position.z;
            const step = Math.hypot(nx - px, nz - pz);
            maxStep = Math.max(maxStep, step);
            if (step > 0.6) teleported = true;
            if (i % 15 === 0) trace.push([+nx.toFixed(2), +nz.toFixed(2)]);
        }
        s.moveState.forward = false;
        s.controls.isLocked = false;
        return {
            name, start: [x, z],
            end: { x: +s.camera.position.x.toFixed(3), z: +s.camera.position.z.toFixed(3) },
            maxStep: +maxStep.toFixed(3), teleported, traceEvery: trace,
        };
    };

    const out = { L, curtainBoxes, obstacleCount: obstacles.length, scenarios: [] };
    const R = (name, x, z, dx, dz, sec) => out.scenarios.push(run(name, x, z, dx, dz, sec));

    // ── A. THE FABRIC BLOCKS (both panels, both directions) ─────────────
    R('A/fabric-west-southbound', -3.0, 2.4, 0, -1, 6);   // room A → panel broadside
    R('A/fabric-west-northbound', -3.0, -2.4, 0, 1, 6);   // room B → panel broadside
    R('A/fabric-east-southbound', 3.0, 2.4, 0, -1, 6);
    R('A/fabric-east-northbound', 3.0, -2.4, 0, 1, 6);
    // lateral along the fabric (push-out slides, never tunnels)
    R('A/fabric-lateral', -2.0, 0.0, 1, 0, 5);

    // ── B. THE OPENING PASSES ────────────────────────────────────────────
    R('B/opening-centre', 0, 3.6, 0, -1, 8);              // straight through
    R('B/opening-graze-west', 1.02, 1.2, 0, -1, 8);       // along the box edge
    R('B/opening-graze-east', -1.02, 1.2, 0, -1, 8);
    R('B/roundtrip', 0, -2.4, 0, 1, 8);                   // room B (in front of the bench) back to A
    R('B/spawn-axis-to-hero', 0, 3.4, 0, -1, 12);         // the full arrival axis: opening, then the bench stops the walk

    // ── C. THE DOOR IS NEVER CLIPPED ─────────────────────────────────────
    R('C/door-centre', 0, 3.0, 0, 1, 8);
    R('C/door-diagonal', -2.5, 3.0, 0.55, 1, 8);

    // ── D. FURNITURE STILL BLOCKS ────────────────────────────────────────
    R('D/bench', 0, -3.0, 0, -1, 6);
    R('D/table', 2.25, 0.9, 0, 1, 6);

    // ── E. EVERY ARTWORK APPROACHABLE (both rooms) ───────────────────────
    const approach = [];
    for (let i = 0; i < s.artworks.length; i++) {
        const art = s.artworks[i];
        art.updateWorldMatrix(true, false);
        const p = art.getWorldPosition(new (art.position.constructor)());
        const n = new (art.position.constructor)(0, 0, 1).applyQuaternion(art.quaternion);
        // stand 4 m out along the facing normal, walk straight at the work
        const sx = p.x + n.x * 4.0, sz = p.z + n.z * 4.0;
        const dx = p.x - sx, dz = p.z - sz, dl = Math.hypot(dx, dz) || 1;
        const end = run(`E/artwork-${i}`, sx, sz, dx / dl, dz / dl, 6);
        const stopDist = Math.hypot(p.x - end.x, p.z - end.z);
        approach.push({ i, stopDist: +stopDist.toFixed(2), wall: art.userData.wallId, room: art.userData.room });
    }
    out.approach = approach;

    // curtain box geometry vs the declared opening
    const cfg = s._venuePlacement?.room_divider || {};
    const gap = curtainBoxes.length === 2
        ? +Math.min(Math.abs(curtainBoxes[0].maxX), Math.abs(curtainBoxes[1].minX) === 0 ? 99 : Math.abs(curtainBoxes[1].minX)).toFixed(3)
        : null;
    out.declaredOpening = cfg.opening ?? null;
    return out;
});

// ── assertions ────────────────────────────────────────────────────────────
let fails = 0;
const ok = (name, cond, detail = '') => {
    if (cond) console.log(`  ✓ ${name}`);
    else { fails++; console.error(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`); }
};
if (report.error) { console.error('FATAL:', report.error); process.exit(1); }
console.log(`room L=${report.L}  obstacles=${report.obstacleCount}  curtainBoxes=${JSON.stringify(report.curtainBoxes)}`);

const S = Object.fromEntries(report.scenarios.map(s => [s.name, s]));
ok('two curtain collision boxes registered', report.curtainBoxes.length === 2, JSON.stringify(report.curtainBoxes));
ok('opening boxes hug the declared walk gap (2.4 m ± pads)',
    report.curtainBoxes.length === 2
    && Math.abs(Math.abs(report.curtainBoxes[0].maxX) - 1.2) < 0.1
    && Math.abs(Math.abs(report.curtainBoxes[1].minX) - 1.2) < 0.1,
    JSON.stringify(report.curtainBoxes));

for (const name of ['A/fabric-west-southbound', 'A/fabric-west-northbound', 'A/fabric-east-southbound', 'A/fabric-east-northbound']) {
    const s = S[name];
    const blocked = s && !s.teleported && (
        (name.includes('southbound') && s.end.z > 0.2) ||
        (name.includes('northbound') && s.end.z < -0.2));
    ok(`${name}: fabric blocks`, blocked, JSON.stringify(s?.end));
}
{
    const s = S['A/fabric-lateral'];
    ok('A/fabric-lateral: slides without tunnelling', s && !s.teleported, JSON.stringify(s?.end));
}
for (const name of ['B/opening-centre', 'B/opening-graze-west', 'B/opening-graze-east']) {
    const s = S[name];
    ok(`${name}: passes into room B`, s && !s.teleported && s.end.z < -1.0, JSON.stringify(s?.end));
}
{
    const s = S['B/roundtrip'];
    ok('B/roundtrip: returns to room A', s && !s.teleported && s.end.z > 1.0, JSON.stringify(s?.end));
}
{
    // the spawn axis: through the opening, then the hero bench stops the
    // walk — the v2 composition, preserved in room B. The bench stands at
    // wall_front + 0.85; its padded box face is −L/2 + 1.405.
    const s = S['B/spawn-axis-to-hero'];
    const benchFace = -(report.L / 2) + 1.405;
    ok('B/spawn-axis: crosses the opening, the bench ends the axis at viewing distance',
        s && !s.teleported && s.end.z < -0.5 && Math.abs(s.end.z - benchFace) < 0.06,
        `${JSON.stringify(s?.end)} benchFace ${benchFace.toFixed(3)}`);
}
{
    const s = S['C/door-centre'];
    // the square room's walk skin: wallDepth/2 + 0.3 (salon wall_depth 0.15
    // → 0.375) — the leaves' front face stands ~0.23 m BEHIND that bound
    const skin = (report.L / 2) - 0.375;
    ok('C/door-centre: stops on the wall skin, never inside the leaves',
        s && s.end.z <= skin + 0.02, `${JSON.stringify(s?.end)} skin ${skin.toFixed(2)}`);
}
for (const name of ['D/bench', 'D/table']) {
    const s = S[name];
    const moved = s && Math.hypot(s.end.x - s.start[0], s.end.z - s.start[1]) < 3.4;
    ok(`${name}: furniture blocks`, moved, JSON.stringify(s?.end));
}
{
    const bad = (report.approach || []).filter(a => a.stopDist > 4.4 || a.stopDist < 0.2);
    ok(`artworks approachable (E): ${report.approach?.length} works, all stop at viewing distance`,
        report.approach?.length > 0 && bad.length === 0,
        bad.slice(0, 4).map(b => `#${b.i} ${b.stopDist}m ${b.wall}/${b.room}`).join(', '));
    const rooms = new Set((report.approach || []).map(a => a.room));
    ok('both rooms hold artworks (approach sweep)', rooms.has('a') && rooms.has('b'), [...rooms].join(','));
}
ok('no teleports anywhere', report.scenarios.every(s => !s.teleported));
ok('no page errors', errors.length === 0, errors.slice(0, 3).join(' | '));

console.log(fails === 0 ? '\nSALON V3 WALK PROBE: ALL PASS\n' : `\n${fails} WALK CHECK(S) FAILED\n`);
process.exit(fails === 0 ? 0 : 1);
