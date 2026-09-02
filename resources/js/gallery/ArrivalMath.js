// ─────────────────────────────────────────────────────────────────────────────
// ArrivalMath — pure math for the arrival choreography (roadmap P1.4)
//
// ZERO imports, ZERO three.js, ZERO DOM, ZERO venue slugs (DoD rule #7).
// Every function here is deterministic and directly executable in Node
// (scripts/verify_iteration4.mjs) as well as in the browser bundle — the
// same pattern as TierResolve.js and PlacementMath.js.
//
// Arrival.js (the sibling module) owns the scene-facing orchestration:
// THREE vectors, gsap tweens, event listeners, guards.
// ─────────────────────────────────────────────────────────────────────────────

// Tunables — choreography constants, not venue identity.
export const ARRIVAL = {
    dollyDistance: 2.6,      // metres pulled back from the classic spawn pose
    duration: 1.5,           // seconds (roadmap contract: "1.5 s ease-out dolly")
    ease: 'power2.out',      // ease-out: fast reveal, gentle settle
    minHeroDistance: 2.2,    // hero closer than this from spawn reads as clutter
    maxHeroCandidates: 6,    // LOS attempts before the distance-only fallback
    losSlack: 0.15,          // metres of padding around obstacle boxes
    minGapForDolly: 0.35,    // less free space than this ⇒ skip the dolly move
};

// ── Hero ranking (deterministic) ─────────────────────────────────────────────

// Hero score: bigger wall presence wins; ties resolve to the earlier hang
// order. World-space canvas area (m²) is the honest measure — it is what
// the visitor actually sees. Pixel dimensions are a fallback ordering
// signal for degenerate groups without a canvas mesh.
//
// Iteration 6 (P2.3 §6.5): when the venue declares a focal wall, artworks
// hung on it receive a score bonus so the arrival composes on the venue's
// intended hero moment. STRICTLY OPT-IN — `opts` defaults to no focal wall
// and the score is bit-identical to the pre-IT6 formula (regression-safe;
// default galleries unchanged is IT6's own contract).
export const FOCAL_HERO_BONUS = 1.25;

export function heroScore(artwork, index, opts) {
    const focalWall = opts && opts.focalWall ? opts.focalWall : null;
    const bonus = focalWall && artwork?.userData?.wallId === focalWall
        ? FOCAL_HERO_BONUS
        : 1;

    const canvas = artwork?.userData?._canvasMesh;
    const params = canvas?.geometry?.parameters;
    if (params && params.width && params.height) {
        return { area: params.width * params.height * bonus, index };
    }
    const w = artwork?.userData?.width;
    const h = artwork?.userData?.height;
    if (w && h) {
        // EXACT mirror of makeArtworkGroup's world-size math (ArtworkPlacer):
        // height caps at 2.0 m, width at 3.0 m, aspect preserved — so the
        // fallback ranks consistently with what the renderer actually builds.
        const aspect = w / Math.max(1, h);
        let height = 2.0;
        let width  = height * aspect;
        if (width > 3.0) { width = 3.0; height = width / aspect; }
        return { area: width * height * bonus, index };
    }
    return { area: 0, index };
}

// Ordered candidate list: area descending, hang order ascending on ties.
// Deterministic by construction — no RNG. The venue composition already
// carries the seeded randomness (P0.3); hero selection must not add a
// second roll or the composed first frame would flicker between reloads.
export function rankHeroCandidates(artworks, opts) {
    if (!Array.isArray(artworks) || artworks.length === 0) return [];
    return artworks
        .map((art, index) => ({ art, ...heroScore(art, index, opts) }))
        .sort((a, b) => (b.area - a.area) || (a.index - b.index))
        .map(entry => entry.art);
}

// ── Line of sight ────────────────────────────────────────────────────────────

// Segment-vs-AABB intersection in the walk plane (XZ), honouring the same
// skipY semantics as Collisions: obstacles entirely above the segment's Y
// band (ceiling beams) do not block a view. `from`/`to` are plain {x,y,z};
// `boxes` accepts Collisions' {box: Box3-like} entries or raw Box3-likes
// (anything with numeric min/max). Pure math — no Raycaster, deterministic.
export function segmentBlockedByBoxes(from, to, boxes, slack = ARRIVAL.losSlack) {
    if (!Array.isArray(boxes)) return false;
    const minY = Math.min(from.y, to.y);
    const maxY = Math.max(from.y, to.y);

    for (let i = 0; i < boxes.length; i++) {
        const box = boxes[i]?.box || boxes[i];
        if (!box?.min || !box?.max) continue;
        if (typeof box.min.x !== 'number' || typeof box.max.y !== 'number') continue;

        // Vertical band check: beams overhead don't block; low plinths below
        // the sight-line don't either (the 1.2 m head-room above the eye
        // band covers tall canvases seen from eye level).
        if (box.max.y < minY - 0.1 || box.min.y > maxY + 1.2) continue;

        const minX = box.min.x - slack, maxX = box.max.x + slack;
        const minZ = box.min.z - slack, maxZ = box.max.z + slack;

        // Slab method on the XZ plane; t ∈ [0,1] parametrises the segment.
        const dx = to.x - from.x, dz = to.z - from.z;
        let t0 = 0, t1 = 1;

        if (Math.abs(dx) < 1e-9) {
            if (from.x < minX || from.x > maxX) continue;
        } else {
            let ta = (minX - from.x) / dx;
            let tb = (maxX - from.x) / dx;
            if (ta > tb) { const tmp = ta; ta = tb; tb = tmp; }
            t0 = Math.max(t0, ta);
            t1 = Math.min(t1, tb);
            if (t0 > t1) continue;
        }

        if (Math.abs(dz) < 1e-9) {
            if (from.z < minZ || from.z > maxZ) continue;
        } else {
            let ta = (minZ - from.z) / dz;
            let tb = (maxZ - from.z) / dz;
            if (ta > tb) { const tmp = ta; ta = tb; tb = tmp; }
            t0 = Math.max(t0, ta);
            t1 = Math.min(t1, tb);
            if (t0 > t1) continue;
        }

        return true; // segment passes through this box
    }
    return false;
}

// ── Walkable-domain clamp (mirror of Collisions.enforceRoomBounds) ──────────

// Clamp a position to the walkable domain — the same shape logic
// Collisions.enforceRoomBounds applies per frame (l-shape AABBs / rotunda /
// circular / room AABB / obstacle push-out), without velocity side-effects.
// Works on plain {x,y,z} objects and scene-like plain state, so it runs in
// Node against synthetic layouts. Returns a NEW object; never mutates input.
export function clampToWalkDomain(sceneLike, pos) {
    const out = { x: pos.x, y: pos.y, z: pos.z };
    const push = (x, z) => { out.x = x; out.z = z; };

    if (sceneLike?._lShapeBounds) {
        const { a, b } = sceneLike._lShapeBounds;
        const inA = out.x >= a.minX && out.x <= a.maxX && out.z >= a.minZ && out.z <= a.maxZ;
        const inB = out.x >= b.minX && out.x <= b.maxX && out.z >= b.minZ && out.z <= b.maxZ;
        if (!inA && !inB) {
            const cAx = Math.max(a.minX, Math.min(a.maxX, out.x));
            const cAz = Math.max(a.minZ, Math.min(a.maxZ, out.z));
            const dA  = (out.x - cAx) ** 2 + (out.z - cAz) ** 2;
            const cBx = Math.max(b.minX, Math.min(b.maxX, out.x));
            const cBz = Math.max(b.minZ, Math.min(b.maxZ, out.z));
            const dB  = (out.x - cBx) ** 2 + (out.z - cBz) ** 2;
            if (dA <= dB) push(cAx, cAz); else push(cBx, cBz);
        }
    } else if (sceneLike?._rotundaRadius) {
        const r = sceneLike._rotundaRadius - 0.5;
        const d = Math.sqrt(out.x * out.x + out.z * out.z);
        if (d > r) push((out.x / d) * r, (out.z / d) * r);
    } else if (sceneLike?._circularBoundsRadius) {
        const r = sceneLike._circularBoundsRadius - 0.5;
        const d = Math.sqrt(out.x * out.x + out.z * out.z);
        if (d > r) push((out.x / d) * r, (out.z / d) * r);
    } else if (sceneLike?.roomBounds) {
        const b = sceneLike.roomBounds;
        push(Math.max(b.minX, Math.min(b.maxX, out.x)), Math.max(b.minZ, Math.min(b.maxZ, out.z)));
    }

    // Obstacle push-out (Collisions step 2 — nearest face wins).
    if (Array.isArray(sceneLike?._obstacles)) {
        for (const obs of sceneLike._obstacles) {
            const box = obs?.box;
            if (!box?.min || !box?.max) continue;
            if (out.x > box.min.x && out.x < box.max.x &&
                out.z > box.min.z && out.z < box.max.z) {
                const penLeft  = out.x - box.min.x;
                const penRight = box.max.x - out.x;
                const penFront = out.z - box.min.z;
                const penBack  = box.max.z - out.z;
                const minPen = Math.min(penLeft, penRight, penFront, penBack);
                if      (minPen === penLeft)  out.x = box.min.x;
                else if (minPen === penRight) out.x = box.max.x;
                else if (minPen === penFront) out.z = box.min.z;
                else                          out.z = box.max.z;
            }
        }
    }

    return out;
}

// ── Pose composition ─────────────────────────────────────────────────────────

// Compose the arrival poses. `end` is the classic spawn pose (plain {x,y,z},
// y = eye height — RoomBuilder already placed it; the dolly must END there
// so the off-flag state and the handoff pose are identical). `heroPos` is
// the hero's world centre.
//
// The dolly moves along ONE straight line (end stepped straight back from
// the hero) — a camera dolly reads correctly only on its view axis. The
// start is found by sampling that line from the full dolly distance
// downward and taking the largest step whose endpoint is ALREADY walkable
// (i.e. clampToWalkDomain leaves it untouched — inside the layout bounds
// and outside every registered obstacle). Guarantees:
//   • start is on the line AND walkable — never clipped, never pushed;
//   • moveDistance ≤ dollyDistance, quantised to 5 cm (deterministic);
//   • below minGapForDolly the arrival degrades to an instant composed cut
//     (moveDistance 0), never a broken or blocked move.
export function composeArrivalPose(end, heroPos, sceneLike) {
    const dx = end.x - heroPos.x;
    const dz = end.z - heroPos.z;
    const len = Math.hypot(dx, dz);
    let ux = 0, uz = 1; // degenerate: hero dead-centre → approach from +Z
    if (len > 1e-8) { ux = dx / len; uz = dz / len; }

    const STEP = 0.05; // 5 cm sampling — ≤52 clamp calls, runs once per enter
    const maxStep = Math.floor(ARRIVAL.dollyDistance / STEP);
    const minStep = Math.ceil(ARRIVAL.minGapForDolly / STEP);

    let moveDistance = 0;
    for (let i = maxStep; i >= minStep; i--) {
        const t = i * STEP;
        const p = { x: end.x + ux * t, y: end.y, z: end.z + uz * t };
        const c = clampToWalkDomain(sceneLike, p);
        if (Math.abs(c.x - p.x) < 1e-4 && Math.abs(c.z - p.z) < 1e-4) {
            moveDistance = t;
            break;
        }
    }

    const start = moveDistance > 0
        ? { x: end.x + ux * moveDistance, y: end.y, z: end.z + uz * moveDistance }
        : { x: end.x, y: end.y, z: end.z };

    return { start, moveDistance };
}
