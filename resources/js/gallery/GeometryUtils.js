// ─────────────────────────────────────────────────────────────────────────────
// GeometryUtils — build-time geometry merging helper
//
// PERF-D21 (3D audit F21): three.js issues one draw call per Mesh. Repeated
// props that share a material (frame pieces, easels, hedges, beams, skirting,
// path stones) were each 4-24 separate Meshes — identical material, static
// transforms. Merging them into a single BufferGeometry collapses the whole
// group to ONE draw call with zero visual change.
//
// A 30-artwork gallery previously paid 30 × 4 = 120 draw calls for frames
// alone (plus 120 for the canvases). After merging: 30 + 30. On mobile GPUs,
// draw-call overhead (CPU-side state changes feeding the GPU) is often the
// frame-time bottleneck — this is the cheapest large win available.
//
// mergeParts(parts) — parts: Array<{
//   geo:  THREE.BufferGeometry  (shared source — cloned internally, not mutated)
//   pos?: [x, y, z]             default [0,0,0]
//   rot?: [rx, ry, rz]          radians, XYZ order, default [0,0,0]
//   scale?: number              uniform scale, default 1
// }>
// Returns a new merged geometry. Callers own (and should dispose) the source
// geometries after merging.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';

export function mergeParts(parts) {
    const geos = parts.map(p => {
        const g = p.geo.clone();
        const m = new THREE.Matrix4().compose(
            new THREE.Vector3(p.pos?.[0] || 0, p.pos?.[1] || 0, p.pos?.[2] || 0),
            new THREE.Quaternion().setFromEuler(new THREE.Euler(
                p.rot?.[0] || 0, p.rot?.[1] || 0, p.rot?.[2] || 0
            )),
            new THREE.Vector3(p.scale ?? 1, p.scale ?? 1, p.scale ?? 1)
        );
        g.applyMatrix4(m);
        return g;
    });

    const merged = mergeGeometries(geos);
    geos.forEach(g => g.dispose()); // free the intermediate clones
    return merged;
}
