import * as THREE from 'three';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';

export function mergeParts(parts) {
    const geos = parts.map(p => {
        const g = p.geo.clone();
        const s = Array.isArray(p.scale) ? p.scale : (p.scale ?? 1);
        const m = new THREE.Matrix4().compose(
            new THREE.Vector3(p.pos?.[0] || 0, p.pos?.[1] || 0, p.pos?.[2] || 0),
            new THREE.Quaternion().setFromEuler(new THREE.Euler(
                p.rot?.[0] || 0, p.rot?.[1] || 0, p.rot?.[2] || 0
            )),
            new THREE.Vector3(
                Array.isArray(s) ? (s[0] ?? 1) : s,
                Array.isArray(s) ? (s[1] ?? 1) : s,
                Array.isArray(s) ? (s[2] ?? 1) : s
            )
        );
        g.applyMatrix4(m);
        return g;
    });

    const merged = mergeGeometries(geos);
    geos.forEach(g => g.dispose()); // free the intermediate clones
    return merged;
}
