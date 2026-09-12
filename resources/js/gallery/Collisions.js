import * as THREE from 'three';

export function registerObstacle(mesh, padding = 0.3) {
    if (!mesh) return;
    const box = new THREE.Box3().setFromObject(mesh);
    // Expand by player-radius padding so we don't clip into the mesh
    box.min.x -= padding; box.max.x += padding;
    box.min.z -= padding; box.max.z += padding;
    // Only care about XZ plane (player can't move vertically)
    this._obstacles.push({
        box,
        skipY: true,
    });
}

export function clearObstacles() {
    this._obstacles.length = 0;
}

export function enforceRoomBounds() {
    const pos = this.camera.position;
    const prevX = pos.x, prevZ = pos.z;

    // ── 1. Layout-shape collision (L-shape, rotunda, square, corridor) ──────
    if (this._lShapeBounds) {
        const { a, b } = this._lShapeBounds;
        const inA = pos.x >= a.minX && pos.x <= a.maxX && pos.z >= a.minZ && pos.z <= a.maxZ;
        const inB = pos.x >= b.minX && pos.x <= b.maxX && pos.z >= b.minZ && pos.z <= b.maxZ;

        if (!inA && !inB) {
            // Push to nearest valid point in either wing
            const cAx = Math.max(a.minX, Math.min(a.maxX, pos.x));
            const cAz = Math.max(a.minZ, Math.min(a.maxZ, pos.z));
            const dA  = (pos.x - cAx) ** 2 + (pos.z - cAz) ** 2;

            const cBx = Math.max(b.minX, Math.min(b.maxX, pos.x));
            const cBz = Math.max(b.minZ, Math.min(b.maxZ, pos.z));
            const dB  = (pos.x - cBx) ** 2 + (pos.z - cBz) ** 2;

            if (dA <= dB) { pos.x = cAx; pos.z = cAz; }
            else          { pos.x = cBx; pos.z = cBz; }
        }
    } else if (this._rotundaRadius) {
        const r = this._rotundaRadius - 0.5;
        const d = Math.sqrt(pos.x * pos.x + pos.z * pos.z);
        if (d > r) {
            pos.x = (pos.x / d) * r;
            pos.z = (pos.z / d) * r;
        }
    } else if (this._circularBoundsRadius) {
        const r = this._circularBoundsRadius;
        const d = Math.sqrt(pos.x * pos.x + pos.z * pos.z);
        if (d > r) {
            pos.x = (pos.x / d) * r;
            pos.z = (pos.z / d) * r;
        }
    } else if (this.roomBounds) {
        const b = this.roomBounds;
        pos.x = Math.max(b.minX, Math.min(b.maxX, pos.x));
        pos.z = Math.max(b.minZ, Math.min(b.maxZ, pos.z));
    }

    if (this._obstacles && this._obstacles.length > 0) {
        for (const obs of this._obstacles) {
            const box = obs.box;
            if (pos.x > box.min.x && pos.x < box.max.x &&
                pos.z > box.min.z && pos.z < box.max.z) {
                const penLeft   = pos.x - box.min.x; // distance to left face
                const penRight  = box.max.x - pos.x;
                const penFront  = pos.z - box.min.z;
                const penBack   = box.max.z - pos.z;

                const minPen = Math.min(penLeft, penRight, penFront, penBack);
                if      (minPen === penLeft)  pos.x = box.min.x;
                else if (minPen === penRight) pos.x = box.max.x;
                else if (minPen === penFront) pos.z = box.min.z;
                else                          pos.z = box.max.z;
            }
        }
    }

    // ── 3. Zero velocity on axes where we were pushed back ─────────────────
    if (pos.x !== prevX) this.velocity.x = 0;
    if (pos.z !== prevZ) this.velocity.z = 0;
}
