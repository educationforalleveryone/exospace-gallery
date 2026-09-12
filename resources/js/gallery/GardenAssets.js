import * as THREE from 'three';
import { loadGlb } from './AssetLoader.js';

export const GARDEN_ASSET_MANIFEST = Object.freeze({
    tree_large:  { file: 'tree_large_01.glb',  targetHeight: 8.5, solid: true,  castShadow: true,  proxy: [0.6, 4.0, 0.6] },
    tree_medium: { file: 'tree_medium_01.glb', targetHeight: 6.5, solid: true,  castShadow: true,  proxy: [0.5, 3.0, 0.5] },
    tree_accent: { file: 'tree_medium_02.glb', targetHeight: 5.0, solid: true,  castShadow: true,  proxy: [0.4, 2.4, 0.4] },
    shrub:       { file: 'shrub_01.glb',       targetHeight: 1.5, solid: false, castShadow: false },
    grass:       { file: 'grass_clump_01.glb', targetHeight: 0.7, solid: false, castShadow: false },
    boulder:     { file: 'boulder_01.glb',     targetHeight: 0.9, solid: true,  castShadow: true,  proxy: [0.8, 0.8, 0.8] },
    bench:       { file: 'bench_01.glb',       targetHeight: 0.85, solid: true, castShadow: true,  proxy: [1.8, 0.9, 0.65] },
});

const ASSET_BASE_DEFAULT = 'assets/venues/sculpture-garden/';

export function resolveGardenAssetRequests(gardenCfg) {
    const cfg = gardenCfg || {};
    let base = String(cfg.assets_base || ASSET_BASE_DEFAULT);
    if (!/^(https?:)?\/\//.test(base) && !base.startsWith('/')) base = '/' + base;
    base = base.replace(/\/?$/, '/');
    const declared = (cfg.assets && typeof cfg.assets === 'object') ? cfg.assets : {};
    const requests = [];
    for (const [role, def] of Object.entries(GARDEN_ASSET_MANIFEST)) {
        const file = Object.prototype.hasOwnProperty.call(declared, role)
            ? declared[role]
            : def.file;
        if (!file) continue;               // explicit null → role opted out
        requests.push({ role, url: base + file, def });
    }
    return requests;
}

export async function loadGardenAssets(requests, ctx) {
    const entries = new Map();
    const missing = [];

    await Promise.all(requests.map(async (req) => {
        try {
            const scene = await loadGlb.call(ctx, req.url);
            const items = [];
            const box = new THREE.Box3();
            scene.updateMatrixWorld(true);
            scene.traverse((node) => {
                if (!node.isMesh || !node.geometry) return;
                if (node.material) {
                    const mats = Array.isArray(node.material) ? node.material : [node.material];
                    for (const m of mats) {
                        if (m.transparent && m.alphaTest === 0) m.alphaTest = 0.35;
                        if (m.metalness !== undefined && m.metalness > 0.05) m.metalness = 0.05;
                        if (m.vertexColors) { m.vertexColors = false; m.needsUpdate = true; }
                    }
                }
                box.expandByObject(node);
                items.push({
                    geometry: node.geometry,
                    material: node.material,
                    matrix: node.matrixWorld.clone(),
                });
            });
            if (!items.length) throw new Error('asset contains no meshes');
            const size = new THREE.Vector3();
            box.getSize(size);
            const sourceHeight = size.y || 1;
            entries.set(req.role, {
                role: req.role,
                def: req.def,
                items,
                sourceHeight,
                normalizeScale: req.def.targetHeight / sourceHeight,
            });
        } catch (err) {
            missing.push(req.role);
            console.info(
                `[garden] asset for role "${req.role}" not available (${req.url}) — ` +
                `skipping this layer. Provide the GLB to complete the landscape. ` +
                `[${err?.message || err}]`
            );
        }
    }));

    return { entries, missing };
}

export function buildGardenAssetInstances(ctx, entries, anchorsByRole, heightFn, opts = {}) {
    const meshes = [];
    const low = !!opts.tierLow;
    const cap = opts.anchorCap || Infinity;

    for (const [role, anchorsAll] of Object.entries(anchorsByRole)) {
        const entry = entries.get(role);
        if (!entry || !anchorsAll?.length) continue;
        const anchors = anchorsAll.slice(0, low ? Math.min(cap, anchorsAll.length) : anchorsAll.length);
        if (!anchors.length) continue;

        const shadows = !low && entry.def.castShadow && ctx.renderer?.shadowMap?.enabled !== false;

        const anchorRoot = new THREE.Matrix4();
        const rot = new THREE.Matrix4();
        const scaleV = new THREE.Vector3();
        const posV = new THREE.Vector3();
        const quat = new THREE.Quaternion();
        const euler = new THREE.Euler();

        for (const item of entry.items) {
            const inst = new THREE.InstancedMesh(item.geometry, item.material, anchors.length);
            inst.frustumCulled = false;  // instances span the field; cull per-scene, not per-root
            inst.castShadow = shadows;
            inst.receiveShadow = !low;
            let n = 0;
            for (const a of anchors) {
                const s = entry.normalizeScale * (a.scale ?? 1);
                posV.set(a.x, heightFn(a.x, a.z) - (opts.sink ?? 0.04) * s, a.z);
                euler.set(0, a.yaw ?? 0, 0);
                quat.setFromEuler(euler);
                scaleV.set(s, s, s);
                anchorRoot.compose(posV, quat, scaleV);
                inst.setMatrixAt(n, anchorRoot.clone().multiply(item.matrix));
                n++;
            }
            inst.count = n;
            inst.instanceMatrix.needsUpdate = true;
            inst.matrixAutoUpdate = false;
            ctx.scene.add(inst);
            meshes.push(inst);
        }

        if (entry.def.solid && entry.def.proxy && !opts.skipProxies) {
            const proxyGeo = new THREE.BoxGeometry(...entry.def.proxy);
            const proxyMat = new THREE.MeshBasicMaterial({ visible: false });
            for (const a of anchors) {
                const s = entry.normalizeScale * (a.scale ?? 1);
                const proxy = new THREE.Mesh(proxyGeo, proxyMat);
                proxy.position.set(a.x, heightFn(a.x, a.z) + entry.def.proxy[1] * 0.5 * s - 0.06, a.z);
                proxy.scale.setScalar(s);
                proxy.visible = false;
                ctx.scene.add(proxy);
                ctx.registerObstacle(proxy, 0.1);
                meshes.push(proxy);
            }
        }
    }
    return meshes;
}

export function groupAnchorsByRole(anchors) {
    const byRole = {};
    for (const a of anchors) {
        if (!a?.role) continue;
        (byRole[a.role] = byRole[a.role] || []).push(a);
    }
    return byRole;
}
