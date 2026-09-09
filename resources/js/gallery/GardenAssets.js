// ─────────────────────────────────────────────────────────────────────────────
// GardenAssets — the asset-driven environment layer for the Outdoor Sculpture
// Garden (v4.0.0 "The Sculpture Park").
//
// WHY THIS EXISTS
//   v2/v3 built every environmental object from THREE primitives — icosahedron
//   canopies, cone conifers, cylinder stepping stones, box hedges. The result
//   read instantly as "procedural game level", which is precisely what a
//   premium sculpture garden must never do (user verdict on v3 + screenshots).
//   This module inverts the model: the environment is DESIGNED as a set of
//   curated anchor positions (GardenLayout plan), and every anchor consumes a
//   NAMED EXTERNAL ASSET (GLB) supplied by the gallery owner. The code no
//   longer manufactures nature — it composes supplied assets into a plan.
//
// DESIGN CONTRACT
//   • DB-declared manifest: visual_config.garden.assets maps semantic ROLES
//     (tree_large, shrub, bench…) → filenames under garden.assets_base. The
//     DB stays authoritative (§24); the filenames are the interface the owner
//     fills with their own GLBs.
//   • GRACEFUL: a missing file never crashes and never spawns an ugly
//     placeholder — the role is skipped, a single console.info fires, and the
//     rest of the garden renders. The base environment (terrain, gravel
//     walks, panel stands, hero court, sky) is designed to stand alone.
//   • NORMALIZED: an asset is auto-scaled so its bounding-box height matches
//     the role's target height (real-world metres), so assets from any
//     source/units land at believable scale with per-anchor jitter.
//   • BATCHED: all instances of one role share the loaded geometry/materials
//     and render as ONE InstancedMesh per (role × child mesh) — a fully
//     planted garden costs ~1-2 dozen draw calls regardless of anchor count.
//   • DETERMINISTIC: anchors and their jitters come from the seeded plan;
//     asset loading is async but consumes no rng — identical galleries
//     compose identically on every load.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { loadGlb } from './AssetLoader.js';

// ── Role manifest ────────────────────────────────────────────────────────────
// targetHeight is in metres and is the NORMALIZATION target (bounding-box
// height of the loaded asset is scaled to match). solid roles register an
// invisible trunk/body collision proxy per anchor. castShadow is per role
// (high tier only): trees shade the walks, small planting does not.
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

// ── Resolution ───────────────────────────────────────────────────────────────
// visual_config.garden.assets (role → filename) overrides the manifest's
// default filenames; unknown roles in config are ignored, declared-but-null
// roles opt out explicitly. Returns [{ role, url, def }].
// assets_base is NORMALIZED ROOT-RELATIVE (the platform convention —
// public/ is the web root, cf. TEXTURE_PATHS): a base without a leading
// slash resolves against the PAGE URL, which breaks on every nested route
// (/gallery/x, /admin/…/preview, the QA harness). Absolute URLs pass through.
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

// ── Loading ──────────────────────────────────────────────────────────────────
// Parallel loads with per-role fault isolation. A missing/failed asset
// resolves to null — the caller skips the role. ONE console.info per missing
// role per build (diagnostic, not spam): the missing layer is by design and
// the base environment carries the garden without it.
//
// Each prepared entry:
//   { role, def, items: [{ geometry, material, matrix }], normalizeScale,
//     sourceHeight }
// `items` are the asset's mesh children flattened with their LOCAL matrices
// relative to the asset root (the root itself is identity — loadGlb returns a
// fresh scene). Instancing composes anchor × item matrices at build time.
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
                    // Foliage cards ship alpha-blended; without a cutout the
                    // depth pass renders SOLID quads (blob shadows + halo).
                    // alphaTest gives correct cutout rendering AND shadows.
                    const mats = Array.isArray(node.material) ? node.material : [node.material];
                    for (const m of mats) {
                        if (m.transparent && m.alphaTest === 0) m.alphaTest = 0.35;
                        // The asset vocabulary (foliage, wood, stone, garden
                        // furniture) is DIELECTRIC. glTF metallicRoughness
                        // textures occasionally carry a non-zero metallic
                        // channel (roughness maps packed grayscale-as-ARM);
                        // metallic foliage then renders near-black without
                        // an IBL and mirror-dark with one — the probe-caught
                        // defect class. Clamp the whole layer to dielectric.
                        if (m.metalness !== undefined && m.metalness > 0.05) m.metalness = 0.05;
                        // Baked VERTEX COLORS (Blender AO/dirt passes ship as
                        // COLOR_0) multiply the base color — under a daylight
                        // rig they double-darken every canopy to a near-black
                        // silhouette (the probe-caught defect class). Our
                        // lighting is real; the bake is not wanted.
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

// ── Instanced placement ─────────────────────────────────────────────────────
// Places one prepared asset at every anchor of its role.
//
//   anchors: Array<{ x, z, yaw, scale, shadow? }>   (from the garden plan)
//   heightFn: (x, z) → terrain y                    (from the plan's field)
//
// Per anchor: root matrix = T(x, height − sink, z) · R(yaw) · S(normalizeScale
// × jitter). Each item then composes root × item.matrix into its role-item's
// InstancedMesh. All anchors of one role share ~len(items) draw calls.
//
// opts.tierLow: low tier skips shadows entirely and caps anchor count from
// the END of the array (the plan orders anchors by compositional importance —
// nearest/most-purposed first).
//
// Returns the created InstancedMeshes (scene-owned; disposed with the scene).
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

        // Collision proxies — one invisible box per solid anchor (the merged
        // mesh's AABB would span the whole grove). Same pattern as v3 trees.
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

// ── Anchor list helper ───────────────────────────────────────────────────────
// The plan stores one flat vegetation list with role tags; this groups them
// per role for buildGardenAssetInstances.
export function groupAnchorsByRole(anchors) {
    const byRole = {};
    for (const a of anchors) {
        if (!a?.role) continue;
        (byRole[a.role] = byRole[a.role] || []).push(a);
    }
    return byRole;
}
