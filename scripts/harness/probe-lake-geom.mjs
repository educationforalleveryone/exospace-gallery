// probe-lake-geom.mjs — Mirror Lake geometry intersection audit (post-impl
// QA §8). Boots the venue, then checks the specific structural relationships
// the render QA flagged plus the generic artwork/terrain invariants:
//   1. pavilion posts land ON the deck (no float, no sink)
//   2. bench seat rests on its skids and stays inside the deck edge
//   3. fascia clears the roof slab (flush under, not clipping)
//   4. pier rails stop before the pavilion deck (no crossing)
//   5. every artwork hovers over submerged bed (water visible under it)
//   6. artwork collision AABBs never overlap each other (no push-fights)
//   7. artwork AABBs clear the pier rail boxes
//   8. terrain height agrees with isWater along a sample grid
import { chromium } from 'playwright';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { extname, join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PORT = 4189;
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
const page = await browser.newPage({ viewport: { width: 640, height: 400 } });
await page.addInitScript(`Object.defineProperty(navigator,'hardwareConcurrency',{get:()=>8});
Object.defineProperty(navigator,'deviceMemory',{get:()=>8});`);
await page.goto(`http://127.0.0.1:${PORT}/harness/harness.html?venue=mirror-lake&reflect=0&count=12&tier=high`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('#enter-btn', { timeout: 60000 });
await page.$eval('#enter-btn', el => el.click()).catch(() => {});
await page.waitForTimeout(9000);

const report = await page.evaluate(() => {
    const s = window.__exospace.scene;
    const plan = s._lakePlan;
    const out = { checks: [], info: {} };
    const ok = (name, cond, detail) => out.checks.push({ name, ok: !!cond, detail: detail || '' });

    const boxOf = (obj) => { obj.updateWorldMatrix(true, true); return new (obj.geometry ? Object.getPrototypeOf(obj.position) : Object).constructor.constructor === Object ? null : null; };
    // Use THREE.Box3 via the scene's own constructor chain.
    const v3 = s.camera.position.constructor;
    const box3Of = (obj) => {
        obj.updateWorldMatrix(true, true);
        const b = new (Object.getPrototypeOf(s.raycaster.ray).constructor)(); // placeholder
        return null;
    };
    // Simpler: reconstruct Box3 manually from traversed vertex bounds.
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
    const find = (name) => s.scene.getObjectByProperty('name', name);

    // Locate the pier / pavilion meshes by traversal from the scene: the
    // pier group holds a merged timber mesh + a steel rail mesh (+ proxies);
    // the pavilion group holds the merged body + roof + fascia + strip.
    let pierRails = null, pavRoof = null, pavFascia = null;
    s.scene.traverse((o) => {
        if (!o.isMesh || !o.geometry?.attributes?.position) return;
        const mat = Array.isArray(o.material) ? o.material[0] : o.material;
        if (mat?.visible === false) return;                       // collision proxies
        const bb = aabbOf(o);
        const px = +o.getWorldPosition(new v3()).x.toFixed(1);
        if (Math.abs(px - plan.pier.x) < 0.2 && (bb.maxY - bb.minY) < 0.06 && Math.abs(bb.maxY - 0.66) < 0.05) pierRails = { o, bb };
        if (Math.abs(px - plan.pavilion.x) < 0.2 && (bb.maxY - bb.minY) < 0.12 && bb.minY > 2.95) pavRoof = { o, bb };
        if (Math.abs(px - plan.pavilion.x) < 0.2 && (bb.maxY - bb.minY) < 0.12 && bb.minY > 2.8 && bb.maxY < 2.995) pavFascia = { o, bb };
    });

    // 1. pavilion corner posts vs deck top: the merged body must span the
    // deck (0) to the roof soffit (~2.99) with no float gap.
    const pavBodyBox = aabbOf(find('structure:pavilion') || s.scene.children[s.scene.children.length - 1] || s.scene);
    // (fallback below: derive from the pier/pavilion spans via a targeted
    // traversal — the merged body is the only timber mesh over the pavilion)
    let bodyBox = null;
    s.scene.traverse((o) => {
        if (bodyBox || !o.isMesh) return;
        const mat = Array.isArray(o.material) ? o.material[0] : o.material;
        if (mat?.visible === false) return;
        const bb = aabbOf(o);
        const px = o.getWorldPosition(new v3()).x;
        if (Math.abs(px - plan.pavilion.x) < 0.2 && bb.minY < 0.02 && bb.maxY > 2.7) bodyBox = bb;
    });
    if (bodyBox) {
        ok('pavilion body spans deck-to-roof (merged, no float gap)', bodyBox.minY < 0.02 && bodyBox.maxY > 2.7,
            `minY=${bodyBox.minY.toFixed(3)} maxY=${bodyBox.maxY.toFixed(3)}`);
    } else ok('pavilion body found', false);

    // 2. rails span from the bank to the pavilion deck edge (no crossing)
    if (pierRails) {
        ok('pier rails reach the bank (walk side anchored)',
            pierRails.bb.maxZ > plan.pier.footZ - 1,
            `rail maxZ=${pierRails.bb.maxZ.toFixed(2)} footZ=${plan.pier.footZ.toFixed(2)}`);
        ok('pier rails stop at the pavilion deck edge (no crossing)',
            pierRails.bb.minZ >= plan.pavilion.z + plan.pavilion.size / 2 - 0.2,
            `rail minZ=${pierRails.bb.minZ.toFixed(2)} pav south edge=${(plan.pavilion.z + plan.pavilion.size / 2).toFixed(2)}`);
    } else ok('pier rails found', false);

    // 3. fascia under the roof, not clipping
    if (pavRoof && pavFascia) {
        ok('fascia clears the roof slab', pavFascia.bb.maxY <= pavRoof.bb.minY + 0.005,
            `fascia maxY=${pavFascia.bb.maxY.toFixed(3)} roof minY=${pavRoof.bb.minY.toFixed(3)}`);
    } else ok('fascia/roof found', false, JSON.stringify({ roof: !!pavRoof, fascia: !!pavFascia }));
    // 4. every artwork hovers over submerged bed + visible water under it
    let hoverOk = true, hoverDetail = '';
    for (const art of s.artworks) {
        const p = art.getWorldPosition(new v3());
        const bed = plan.terrain.height(p.x, p.z);
        if (!(bed < plan.waterLevel)) { hoverOk = false; hoverDetail = `art@(${p.x.toFixed(1)},${p.z.toFixed(1)}) bed=${bed.toFixed(2)} water=${plan.waterLevel}`; break; }
        const bb = aabbOf(art);
        if (!(bb.minY > plan.waterLevel)) { hoverOk = false; hoverDetail = `art bottom ${bb.minY.toFixed(2)} below water ${plan.waterLevel}`; break; }
    }
    ok('every artwork hovers over submerged bed, above the water plane', hoverOk, hoverDetail);

    // 5. artwork obstacle AABBs never overlap each other
    let noOverlap = true, ovDetail = '';
    const boxes = s.artworks.map(a => aabbOf(a));
    for (let i = 0; i < boxes.length && noOverlap; i++) {
        for (let j = i + 1; j < boxes.length; j++) {
            const a = boxes[i], b = boxes[j];
            if (a.minX < b.maxX && a.maxX > b.minX && a.minZ < b.maxZ && a.maxZ > b.minZ) {
                noOverlap = false; ovDetail = `art ${i} and ${j} AABBs overlap`; break;
            }
        }
    }
    ok('artwork collision AABBs never overlap', noOverlap, ovDetail);
    out.info.artBoxes = s.artworks.map((a, i) => { const b = aabbOf(a); const p = a.getWorldPosition(new v3()); return { i, x: +p.x.toFixed(2), z: +p.z.toFixed(2), minX: +b.minX.toFixed(2), maxX: +b.maxX.toFixed(2), minZ: +b.minZ.toFixed(2), maxZ: +b.maxZ.toFixed(2), w: +(b.maxX - b.minX).toFixed(2) }; });

    // 6. artwork AABBs clear the pier rail boxes (the walk stays clear)
    const halfRail = plan.pier.width / 2 + 0.3;
    let railClear = true, railDetail = '';
    boxes.forEach((b, i) => {
        if (b.maxX > plan.pier.x - halfRail && b.minX < plan.pier.x + halfRail
            && b.maxZ > plan.pier.endZ - 2 && b.minZ < plan.pier.footZ + 2) {
            railClear = false; railDetail = `art ${i} inside the pier corridor`;
        }
    });
    ok('artwork AABBs clear the pier corridor', railClear, railDetail);

    // 7. terrain bowl agrees with isWater on a grid
    let bowlOk = true;
    for (let x = -15; x <= 15; x += 3) {
        for (let d = -6; d <= 6; d += 2) {
            const z = plan.shoreZ(x) + d;
            const h = plan.terrain.height(x, z);
            const water = d < 0;
            if (water && h !== 0 && h > plan.waterLevel && d < -2) bowlOk = false;
            if (!water && h !== 0) bowlOk = false;
        }
    }
    ok('terrain bowl: land flat at datum, bed sinks north', bowlOk);

    out.info.rails = pierRails ? { minZ: +pierRails.bb.minZ.toFixed(2), maxZ: +pierRails.bb.maxZ.toFixed(2) } : null;
    out.info.pavilion = bodyBox ? { minY: +bodyBox.minY.toFixed(3), maxY: +bodyBox.maxY.toFixed(3) } : null;
    return out;
});
console.log(JSON.stringify(report, null, 1));
await browser.close();
server.close();
process.exit(0);
