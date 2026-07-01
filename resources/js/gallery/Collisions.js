// ─────────────────────────────────────────────────────────────────────────────
// Collisions — room bounds + obstacle-aware pushback
//
// DARK MUSEUM FIX:
//   The old `_enforceRoomBounds` only clamped the player to the outer room
//   rectangle. Dark Museum adds two partial divider walls inside the room
//   (added by VenueDecorator.addVenueStructure), and the player could walk
//   straight through them. Now any mesh can be registered as an obstacle
//   via registerObstacle(mesh) — the collision system reads its bounding box
//   (in world space, axis-aligned) and pushes the player out.
//
// SCULPTURE GARDEN FIX:
//   Sculpture Garden uses a circular grass plane with no walls. The collision
//   is a circle bounds (radius) plus optional hedge obstacles. Same
//   registerObstacle() API — hedges register themselves when built.
//
// L-SHAPE FIX:
//   L-shape still uses two overlapping AABBs. The new obstacle system runs
//   AFTER the AABB check so any prop inside the L (e.g. a bench) still blocks.
// ────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';

// Register a mesh as a collision obstacle.
// The mesh must have its position + rotation + scale finalised BEFORE calling.
// We compute an axis-aligned bounding box (AABB) from the geometry, expand
// it by the mesh's world matrix, and store that box.
export function registerObstacle(mesh, padding = 0.3) {
    if (!mesh) return;
    const box = new THREE.Box3().setFromObject(mesh);
    // Expand by player-radius padding so we don't clip into the mesh
    box.min.x -= padding; box.max.x += padding;
    box.min.z -= padding; box.max.z += padding;
    // Only care about XZ plane (player can't move vertically)
    this._obstacles.push({
        box,
        // Keep min Y / max Y for vertical obstacles (e.g. ceiling beams) —
        // we skip those for collision since the player can't reach them.
        skipY: true,
    });
}

export function clearObstacles() {
    this._obstacles.length = 0;
}

// ── Unified collision enforcement ────────────────────────────────────────────
// Called every frame from updateMovement(). Mutates camera.position in place.
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
        // Sculpture Garden + void venues — circular grass plane
        const r = this._circularBoundsRadius - 0.5;
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

    // ── 2. Obstacle collision (dividers, hedges, benches, sculptures) ───────
    // Runs AFTER layout-shape collision so we push the player out of any
    // registered obstacle they may have entered during this frame's movement.
    if (this._obstacles && this._obstacles.length > 0) {
        for (const obs of this._obstacles) {
            const box = obs.box;
            if (pos.x > box.min.x && pos.x < box.max.x &&
                pos.z > box.min.z && pos.z < box.max.z) {
                // Player is inside the obstacle AABB — push out along the
                // nearest face (smallest penetration axis wins).
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
