// probe-salon-user.mjs — post-deploy user report forensics (screenshot 1/2/3):
//   S1: "wall or curtain?" — a full-width tan surface with a vertical seam;
//       the visitor walks THROUGH it.
//   S2: door narrow / needs better design.
//   S3: far view — general polish.
//
// The probe boots the REAL salon build, captures reproduction shots, and
// (a) raycasts through screen-space grids to NAME the surface under the
//     camera (merged geometry keeps names: 'merged:<group>'),
// (b) drives the REAL movement pipeline (velocity → moveForward/moveRight →
//     enforceRoomBounds → obstacle push-out) into the suspect surface and
//     reports whether the visitor passes.
//
//   node scripts/harness/probe-salon-user.mjs [count] [tier]
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4199;
const count = Number(process.argv[2] || 15);
const tier = process.argv[3] || 'high';
const outDir = join(rootDir, 'shots-salon-user');
mkdirSync(outDir, { recursive: true });
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
const page = await browser.newPage({ viewport: { width: 1280, height: 880 } });
const errors = [];
page.on('pageerror', e => errors.push(String(e)));
page.on('console', m => { if (m.type() === 'error' && !m.text().includes('404')) errors.push(m.text()); });

const tierInit = tier === 'low'
    ? `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>2});
       Object.defineProperty(navigator,'deviceMemory',{get:()=>0.5});`
    : `Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
       Object.defineProperty(navigator,'deviceMemory',{get:()=>8});`;
await page.addInitScript(tierInit);
await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=the-salon&count=${count}&tier=${tier}`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
for (let i = 0; i < 5; i++) {
    await page.evaluate(() => {
        const b = document.getElementById('enter-btn');
        if (b) b.click();
        const curtain = document.getElementById('curtain');
        if (curtain) { curtain.style.transition = 'opacity 0.2s'; curtain.style.opacity = '0'; curtain.style.pointerEvents = 'none'; }
    });
    await page.waitForTimeout(2500);
    const gone = await page.evaluate(() => {
        const s = window.__exospace?.scene;
        return !!(s?.scene && s.camera);
    });
    if (gone) break;
}
await page.waitForTimeout(1500);

// ── Scene report ─────────────────────────────────────────────────────────────
const report = await page.evaluate(() => {
    const s = window.__exospace.scene;
    const b = s.roomBounds;
    const meta = s._layoutMeta;
    // manual world AABB (THREE classes are bundled, not global)
    const aabbOf = (root) => {
        root.updateWorldMatrix(true, true);
        let minX = Infinity, minY = Infinity, minZ = Infinity, maxX = -Infinity, maxY = -Infinity, maxZ = -Infinity;
        root.traverse((o) => {
            if (!o.isMesh || !o.geometry?.attributes?.position) return;
            const pos = o.geometry.attributes.position;
            const m = o.matrixWorld;
            for (let i = 0; i < pos.count; i++) {
                const x = pos.getX(i), y = pos.getY(i), z = pos.getZ(i);
                const wx = m.elements[0] * x + m.elements[4] * y + m.elements[8] * z + m.elements[12];
                const wy = m.elements[1] * x + m.elements[5] * y + m.elements[9] * z + m.elements[13];
                const wz = m.elements[2] * x + m.elements[6] * y + m.elements[10] * z + m.elements[14];
                if (wx < minX) minX = wx; if (wx > maxX) maxX = wx;
                if (wy < minY) minY = wy; if (wy > maxY) maxY = wy;
                if (wz < minZ) minZ = wz; if (wz > maxZ) maxZ = wz;
            }
        });
        return { minX, minY, minZ, maxX, maxY, maxZ };
    };
    // structure mesh inventory (merged groups keep 'merged:<group>' names)
    const structs = [];
    s.scene.traverse(o => {
        if (o.isMesh && (String(o.name).startsWith('structure:') || String(o.name).startsWith('merged:'))) {
            const box = aabbOf(o);
            structs.push({ name: o.name, min: [+box.minX.toFixed(2), +box.minY.toFixed(2), +box.minZ.toFixed(2)], max: [+box.maxX.toFixed(2), +box.maxY.toFixed(2), +box.maxZ.toFixed(2)] });
        }
    });
    return { bounds: b, meta, wallLength: meta?.wallLength, structs, obstacles: (s._obstacles || []).length };
});
console.log('room:', JSON.stringify({ bounds: report.bounds, wallLength: report.wallLength, obstacles: report.obstacles }));
for (const st of report.structs) console.log('  struct', st.name, JSON.stringify(st.min), '→', JSON.stringify(st.max));

const cdp = await page.context().newCDPSession(page);
async function capture(id) {
    const shot = await cdp.send('Page.captureScreenshot', { format: 'png' });
    writeFileSync(join(outDir, `${id}.png`), Buffer.from(shot.data, 'base64'));
    console.log('captured', id);
}

// ── Reproduction poses ┐
const POSES = [
    { id: 's3-far-view',   p: null, t: null },          // filled below: spawn default view
    { id: 's2-door-close', p: null, t: null },
    { id: 's1-mystery',    p: null, t: null },
];
await page.evaluate(() => {
    const s = window.__exospace.scene;
    const L = s._layoutMeta?.wallLength || 12.6;
    window.__L = L;
});
const L = await page.evaluate(() => window.__L);
console.log('wallLength =', L);

// s3: far view — stand in the far corner looking across the room (user's shot 3)
await page.evaluate(() => {
    const s = window.__exospace.scene;
    if (s._rafId) cancelAnimationFrame(s._rafId);
    s._rafActive = false;
    const L = window.__L;
    s.camera.position.set(-L / 2 + 1.2, 1.6, L / 2 - 1.2);
    s.camera.lookAt(L / 2 - 0.5, 1.5, -L / 2 + 0.5);
    s.camera.updateMatrixWorld();
    s.updateProximityLighting && s.updateProximityLighting();
    if (s._postFx && !s.isLowEnd) s._postFx.render(); else s.renderer.render(s.scene, s.camera);
});
await capture('s3-far-view');

// raycast identification helper — cast through screen points, name hits
async function raycastFromPose(poseName, px, py, points) {
    return page.evaluate(({ px, py, points }) => {
        const s = window.__exospace.scene;
        const cam = s.camera;
        cam.position.set(...px); cam.lookAt(...py); cam.updateMatrixWorld();
        const ray = s.raycaster || new (Object.getPrototypeOf(cam).constructor).prototype.raycaster;
        ray.near = 0.05; ray.far = 100;
        const out = [];
        for (const [nx, ny] of points) {
            ray.setFromCamera({ x: nx, y: ny }, cam);
            const hits = ray.intersectObjects(s.scene.children, true).filter(h => h.object.isMesh && String(h.object.name) !== '');
            const named = hits.slice(0, 3).map(h => ({
                name: String(h.object.name).slice(0, 60),
                dist: +h.distance.toFixed(2),
                pt: [+h.point.x.toFixed(2), +h.point.y.toFixed(2), +h.point.z.toFixed(2)],
            }));
            out.push({ nx, ny, named });
        }
        return out;
    }, { px, py, points });
}

// ── S1 reproduction sweep: stand at several z depths facing the BACK wall ──
for (const z of [L / 2 - 1.0, L / 2 - 2.0, L / 2 - 3.5, 0, -(L / 2 - 3.0)]) {
    const id = `s1-back-z${z.toFixed(1)}`.replace(/\./g, '_').replace(/-/g, 'm');
    await page.evaluate((z) => {
        const s = window.__exospace.scene;
        if (s._rafId) cancelAnimationFrame(s._rafId);
        s._rafActive = false;
        s.camera.position.set(0, 1.6, z);
        s.camera.lookAt(0, 1.5, s._layoutMeta.wallLength / 2);
        s.camera.updateMatrixWorld();
        s.updateProximityLighting && s.updateProximityLighting();
        if (s._postFx && !s.isLowEnd) s._postFx.render(); else s.renderer.render(s.scene, s.camera);
    }, z);
    await capture(id);
    // raycast: centre column + around the seam (door zone) + off-centre wall
    const hits = await raycastFromPose(id, [0, 1.6, z], [0, 1.5, L / 2],
        [[0, 0.1], [0, 0.0], [0, -0.25], [0, -0.45], [0.03, 0], [-0.03, 0], [0.35, 0], [-0.35, 0], [0, 0.6]]);
    for (const h of hits) console.log(`  ray z=${z} ndc(${h.nx},${h.ny}) →`, h.named.map(n => `${n.name}@${n.dist}`).join(' | '));
}

// ── S1 alternative: facing the FRONT wall from the back half ────────────────
for (const z of [L / 2 - 2.5, 0]) {
    const id = `s1-front-z${z.toFixed(1)}`.replace(/\./g, '_').replace(/-/g, 'm');
    await page.evaluate((z) => {
        const s = window.__exospace.scene;
        s.camera.position.set(0, 1.6, z);
        s.camera.lookAt(0, 1.5, -s._layoutMeta.wallLength / 2);
        s.camera.updateMatrixWorld();
        s.updateProximityLighting && s.updateProximityLighting();
        if (s._postFx && !s.isLowEnd) s._postFx.render(); else s.renderer.render(s.scene, s.camera);
    }, z);
    await capture(id);
    const hits = await raycastFromPose(id, [0, 1.6, z], [0, 1.5, -L / 2],
        [[0, 0], [0, -0.2], [0, 0.5], [0.03, 0]]);
    for (const h of hits) console.log(`  ray FRONT z=${z} ndc(${h.nx},${h.ny}) →`, h.named.map(n => `${n.name}@${n.dist}`).join(' | '));
}

// ── S2: door close-up reproduction ──────────────────────────────────────────
await page.evaluate(() => {
    const s = window.__exospace.scene;
    const L = window.__L;
    s.camera.position.set(0, 1.6, L / 2 - 2.6);
    s.camera.lookAt(0, 1.45, L / 2);
    s.camera.updateMatrixWorld();
    s.updateProximityLighting && s.updateProximityLighting();
    if (s._postFx && !s.isLowEnd) s._postFx.render(); else s.renderer.render(s.scene, s.camera);
});
await capture('s2-door-close');

// ── Movement pass-through tests — the REAL pipeline ─────────────────────────
// Drive from mid-room toward the back wall at several x offsets; report the
// resting z. If the resting z is BEYOND the room bound (or beyond the inner
// face), we have pass-through.
const walkTests = await page.evaluate(() => {
    const s = window.__exospace.scene;
    const L = s._layoutMeta.wallLength;
    const results = [];
    const targets = [
        { label: 'back-wall-centre(door)', from: [0, 1.6, 0], dir: [0, 0, 1] },
        { label: 'back-wall-x+1.5',        from: [1.5, 1.6, 0], dir: [0, 0, 1] },
        { label: 'back-wall-x-1.5',        from: [-1.5, 1.6, 0], dir: [0, 0, 1] },
        { label: 'front-wall-centre',      from: [0, 1.6, 0], dir: [0, 0, -1] },
        { label: 'left-wall-centre',       from: [0, 1.6, 0], dir: [-1, 0, 0] },
        { label: 'right-wall-centre',      from: [0, 1.6, 0], dir: [1, 0, 0] },
    ];
    for (const t of targets) {
        // deterministic walk: integrate velocity for N frames like the real loop
        s.camera.position.set(...t.from);
        s.velocity?.set?.(0, 0, 0);
        // face the direction
        s.camera.lookAt(t.from[0] + t.dir[0] * 5, 1.6, t.from[2] + t.dir[2] * 5);
        s.camera.updateMatrixWorld(true);
        const delta = 1 / 60;
        for (let i = 0; i < 900; i++) {
            // emulate the real input → velocity path (forward held)
            const mult = 1;
            s.velocity.z += -1 * (window.__CONFIG__?.camera?.acceleration ?? 40) * delta * mult;
            const maxSpeed = (window.__CONFIG__?.camera?.maxSpeed ?? 3);
            const sp = Math.hypot(s.velocity.x, s.velocity.z);
            if (sp > maxSpeed) { s.velocity.x *= maxSpeed / sp; s.velocity.z *= maxSpeed / sp; }
            s.controls.moveRight(s.velocity.x * delta);
            s.controls.moveForward(-s.velocity.z * delta);
            s.enforceRoomBounds();
            s.camera.position.y = (window.__CONFIG__?.camera?.height ?? 1.6);
        }
        results.push({ label: t.label, rest: [+s.camera.position.x.toFixed(3), +s.camera.position.z.toFixed(3)], boundZ: +L.toFixed(3) });
    }
    return results;
});
console.log('walk tests (rest positions):');
for (const w of walkTests) console.log('  ', w.label, '→', JSON.stringify(w.rest));

console.log('page errors:', errors.length ? errors : 'none');
await browser.close(); server.close(); process.exit(0);
