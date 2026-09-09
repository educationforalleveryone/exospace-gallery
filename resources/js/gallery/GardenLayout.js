// ─────────────────────────────────────────────────────────────────────────────
// GardenLayout — pure landscape-planning math for the Outdoor Sculpture Garden
//
// WHY THIS EXISTS (Sculpture Garden v3.0.0 "The Curated Walk"):
//   The v2 garden hung every artwork on ONE evenly-spaced ring facing the
//   centre — a metronome fence. From the spawn every piece was visible at
//   once; nothing was discovered; nothing was framed. This module replaces
//   that with a DESIGNED landscape plan, computed as pure math:
//
//     terrain first  →  paths second  →  courts third  →  vegetation last
//
//   …the same order a landscape architect works. Every number here encodes a
//   design rule (§20 of the garden brief: "procedural generation should
//   encode design rules; it should not merely generate noise").
//
// DESIGN CONTRACT
// ---------------
//   • Pure: no THREE, no DOM, no venue slugs. Directly executable in Node
//     venue QA gate under scripts/venue-qa/) and in the bundle — the
//     TierResolve / PlacementMath / ArrivalMath pattern.
//   • Deterministic: identical (radius, count, rng state) → identical plan.
//     The rng is the venue's seeded generator (Rng.js, seed = slug:galleryId)
//     — never an unseeded source. Draw order is fixed and documented below.
//   • The terrain height field is a CLOSED-FORM function of (x, z): the same
//     fn displaces the terrain mesh at build time, lifts every placed object
//     and carries the camera per frame. Allocation-free — safe per frame.
//   • Sculpture presentation: role hierarchy (primary / secondary /
//     transitional), each court has an APPROACH (the nearest walk), and the
//     easel faces its approach — pieces are turned toward the visitor's
//     arrival, not mechanically toward the centre.
//   • Everything validates: validateGardenPlan() returns concrete violations
//     (spacing, clearance, bounds) so QA pins the design, not the existence.
//
// BUILD ORDER (documented — placement, structure and QA consume one plan):
//   walks (promenade + ring) → courts (validated against the walks) →
//   spurs → facing → vegetation (validated against courts + walks) →
//   terrain phases. Every seeded draw happens inside these stages, in order.
// ─────────────────────────────────────────────────────────────────────────────

// ── Tuning constants — exported so tests pin them ───────────────────────────
export const GARDEN_DEFAULTS = Object.freeze({
    // ── Circulation ──────────────────────────────────────────────────────
    // Spawn plaza sits south (θ=0 in the garden's polar convention
    // x = sin θ·r, z = cos θ·r), the camera's default forward (-z) already
    // looks up the promenade toward the central court — the arrival reads
    // without any tutorial (brief §24).
    spawnRadiusFactor: 0.64,   // ·R — inside the ring walk, on the path
    spawnPlazaRadius: 2.1,     // flatten zone radius (metres)
    promenadeWidth: 1.5,       // main walk stone-strip width
    loopWidth: 1.2,            // ring walk width
    spurWidth: 1.0,            // connector width
    pathStep: 0.62,            // stone sampling interval along a polyline
    walkClearance: 1.55,       // court centre → path centreline (canvas
                               // never overhangs a walk)

    // ── Courts (sculpture clearings) ─────────────────────────────────────
    centralCourtR: 3.3,        // the pedestal clearing around (0,0)
    courtFlattenInner: 1.7,    // terrain fully flat inside this radius
    courtFlattenOuter: 3.6,    // …blended back to natural terrain here
    primaryRadius: [0.50, 0.66],      // ·R — generous space, strong sightlines
    secondaryRadius: [0.40, 0.58],    // ·R — the lawn around the ring walk
    transitionalRadius: [0.72, 0.86], // ·R — near the boundary, discovered
    courtEdge: 2.2,            // courts never closer than this to R (behind-
                               // the-easel viewing room at the bounds edge)
    minCourtGap: 3.4,          // min chord distance between any two courts
                               // (adaptive: dense shows relax to minCourtGapDense)
    minCourtGapDense: 3.1,     // …when count > denseCourtThreshold
    denseCourtThreshold: 12,
    spawnClearance: 3.2,       // court centre → spawn plaza centre
    spurStopShort: 2.4,        // spurs end this far from the court centre:
                               // the walk LEADS to the piece, it never
                               // underfoot-flushes the easel

    // Role mix (§8 spatial hierarchy). With n artworks:
    //   primary = clamp(round(n·0.2), 1, 3), transitional = round(n·0.3),
    //   secondary = the rest. Primary pieces carry focal scale.
    primaryScale: 1.12,
    secondaryScale: 1.0,
    transitionalScale: 0.9,

    // ── Terrain ──────────────────────────────────────────────────────────
    // Gentle, controlled relief (brief §4: "an art garden, not a wilderness
    // simulator"). Inner amplitude ≈ ±0.21 m at R = 16.7 — a lawn that
    // reads as designed, never as hills. The skirt beyond the hedge rises
    // into the DISTANT LANDSCAPE (brief §15) with amplitude up to ~2.4 m.
    terrainAmp: [0.13, 0.065, 0.024],   // octave amplitudes ·ampScale
    terrainWaves: [13.0, 7.5, 4.2],     // octave wavelengths (metres)
    skirtStart: 1.02,        // hills begin just outside the hedge (·R)
    skirtFull: 1.75,         // full amplitude here (·R)
    skirtAmp: 2.3,           // distant-hill amplitude
    skirtWaves: [29.0, 17.0],

    // ── Vegetation ───────────────────────────────────────────────────────
    // Trees keep their trunk clear of canvases and walks. A tree may stand
    // closer than treeCourtClear ONLY if it is OUTSIDE the court's facing
    // cone (≥ facingCone away from the approach direction) and at least
    // treeCourtMin away — that is the "framing backdrop" case: behind or
    // beside the piece, never in front of it.
    treeCourtClear: 3.2,     // unconditional trunk distance from a court
    treeCourtMin: 2.6,       // …or ≥ this when OUTSIDE the facing cone
    facingCone: 60 * Math.PI / 180,
    treePathClear: 1.9,      // trunk ≥ this from path centreline (canopy
                             // overhang is fine — the walk stays open)
    treeSpawnClear: 2.6,
    shrubCourtClear: 2.2,    // shrubs never crowd a canvas front
    shrubPathOffset: 0.95,   // shrub centre off the path edge (width/2 + this)
    groveSpread: 2.1,        // trees seeded within this radius of a grove
});

const TAU = Math.PI * 2;

// ── Small math helpers (local, allocation-free) ──────────────────────────────
function clamp(v, lo, hi) { return v < lo ? lo : v > hi ? hi : v; }
function smoothstep(e0, e1, x) {
    const t = clamp((x - e0) / (e1 - e0 || 1e-6), 0, 1);
    return t * t * (3 - 2 * t);
}
function lerp(a, b, t) { return a + (b - a) * t; }
// Polar helper — the garden's convention (matches ArtworkPlacer's ring):
// θ = 0 is +z (south, where the spawn plaza sits), π is −z (north).
function polar(r, theta) { return [Math.sin(theta) * r, Math.cos(theta) * r]; }
function dist2D(ax, az, bx, bz) { return Math.hypot(ax - bx, az - bz); }
// Shortest signed difference between two angles.
function angleDiff(a, b) {
    let d = (a - b) % TAU;
    if (d > Math.PI) d -= TAU;
    if (d < -Math.PI) d += TAU;
    return d;
}

// Catmull-Rom spline through control points (uniform, endpoint-duplicated).
// Returns a sampler producing a polyline through every control point.
function catmullSpline(ctrl) {
    const pts = [ctrl[0]];
    const P = [ctrl[0], ...ctrl, ctrl[ctrl.length - 1]];
    for (let i = 0; i < P.length - 3; i++) {
        const p0 = P[i], p1 = P[i + 1], p2 = P[i + 2], p3 = P[i + 3];
        const steps = 14;
        for (let s = 1; s <= steps; s++) {
            const t = s / steps, t2 = t * t, t3 = t2 * t;
            const f = (k) => 0.5 * ((2 * p1[k]) + (-p0[k] + p2[k]) * t +
                (2 * p0[k] - 5 * p1[k] + 4 * p2[k] - p3[k]) * t2 +
                (-p0[k] + 3 * p1[k] - 3 * p2[k] + p3[k]) * t3);
            pts.push([f(0), f(1)]);
        }
    }
    return pts;
}

// ── Path sampling — shared by stone placement AND clearance validation ──────
// Resamples a polyline into ~`step`-spaced points (endpoint included).
export function samplePolyline(points, step) {
    const out = [];
    if (!Array.isArray(points) || points.length < 2) return out;
    let carry = 0;
    out.push([points[0][0], points[0][1]]);
    for (let i = 1; i < points.length; i++) {
        const [ax, az] = points[i - 1];
        const [bx, bz] = points[i];
        const segLen = Math.hypot(bx - ax, bz - az);
        if (segLen < 1e-6) continue;
        let d = step - carry;
        while (d <= segLen) {
            const t = d / segLen;
            out.push([lerp(ax, bx, t), lerp(az, bz, t)]);
            d += step;
        }
        carry = segLen - (d - step);
    }
    const last = points[points.length - 1];
    const tail = out[out.length - 1];
    if (dist2D(tail[0], tail[1], last[0], last[1]) > step * 0.35) out.push([last[0], last[1]]);
    return out;
}

// Distance from a point to the nearest sampled path centreline.
function nearestPathDist(paths, x, z) {
    let best = Infinity;
    for (const p of paths) {
        const s = p.samples;
        for (let i = 0; i < s.length; i++) {
            const d = dist2D(x, z, s[i][0], s[i][1]);
            if (d < best) best = d;
        }
    }
    return best;
}
// The nearest single path point (for facing = the visitor's approach).
function nearestPathPoint(paths, x, z) {
    let best = null, bd = Infinity;
    for (const p of paths) for (const s of p.samples) {
        const d = dist2D(x, z, s[0], s[1]);
        if (d < bd) { bd = d; best = s; }
    }
    return best;
}

// ── Terrain height field ─────────────────────────────────────────────────────
// A closed-form function built from the plan's flatten zones (courts, spawn,
// pedestal clearing) + seeded octave phases. The SAME fn is used by:
//   • the terrain mesh displacement (build time)
//   • every placed object's ground height (build time)
//   • the camera's ground follow (per frame — allocation-free)
function buildTerrainField(flattenZones, radius, rng, o) {
    // Fixed draw order: 4 phases (documented contract — reordering changes
    // every composition; QA pins determinism, not values).
    const p1 = rng.next() * TAU, p2 = rng.next() * TAU;
    const p3 = rng.next() * TAU, p4 = rng.next() * TAU;
    const ampScale = clamp(radius / 16, 0.6, 1.25);
    const [A1, A2, A3] = o.terrainAmp;
    const [L1, L2, L3] = o.terrainWaves;
    const f1 = TAU / L1, f2 = TAU / L2, f3 = TAU / L3;
    const skA = o.skirtAmp, f4 = TAU / o.skirtWaves[0], f5 = TAU / o.skirtWaves[1];
    const skirtFrom = radius * o.skirtStart, skirtTo = radius * o.skirtFull;
    const zones = flattenZones;

    // Mask: 1 = natural terrain, 0 = fully flattened. Min over zones with a
    // smoothstep blend so courts sit LEVEL while the lawn around them rolls.
    function mask(x, z) {
        let m = 1;
        for (let i = 0; i < zones.length; i++) {
            const zn = zones[i];
            const d2 = (x - zn.x) * (x - zn.x) + (z - zn.z) * (z - zn.z);
            if (d2 >= zn.outer * zn.outer) continue;
            const t = smoothstep(zn.outer, zn.inner, Math.sqrt(d2)); // 0 outside → 1 inside
            const zm = 1 - t;
            if (zm < m) m = zm;
        }
        return m;
    }

    function height(x, z) {
        // Gentle lawn undulation, masked flat at courts/plaza.
        const m = mask(x, z);
        const inner = (
            A1 * Math.sin(x * f1 + p1) * Math.cos(z * f1 * 0.83 + p2) +
            A2 * Math.sin(x * f2 + p2) * Math.cos(z * f2 + p1) +
            A3 * Math.sin((x + z) * f3 + p3)
        ) * ampScale * m;
        // Distant landscape — rolling hills growing beyond the hedge.
        const w = smoothstep(skirtFrom, skirtTo, Math.hypot(x, z));
        const hills = (skA * Math.sin(x * f4 + p3) * Math.cos(z * f4 * 0.71 + p4) +
                       skA * 0.45 * Math.sin((x - z) * f5 + p1)) * w;
        return inner + hills;
    }
    return height;
}

// ── The plan ─────────────────────────────────────────────────────────────────
/**
 * Build the garden's landscape plan.
 *
 * @param {object} opts
 *   radius  {number} floor radius (RoomBuilder's computeFloatFieldRadius)
 *   count   {number} artwork count (drives role mix + court count)
 *   rng     {object} seeded venue rng (Rng.js contract: next())
 *   config  {object} [optional] visual_config.garden overrides (per-key)
 * @returns plan — courts/paths/trees/shrubs/terrain(+height fn)/spawn/pedestal
 */
export function buildGardenPlan(opts) {
    const o = { ...GARDEN_DEFAULTS, ...(opts.config || {}) };
    const R = Math.max(10, Number(opts.radius) || 10);
    const count = Math.max(1, Math.floor(Number(opts.count) || 1));
    const rng = opts.rng;

    const rMax = R - o.courtEdge;               // courts never past this
    const spawn = polar(R * o.spawnRadiusFactor, 0); // south plaza
    const ringR = Math.min(0.52 * R, rMax - 1.0);

    // ── 1. Walks first — circulation shapes the garden (§5) ──────────────
    // Promenade: spawn plaza → gentle S-curve → the central court's south
    // edge (the pedestal stays clear of the walk). Catmull-Rom through
    // designed control points: a composed curve, not a theme-park straight.
    const promCtrl = [
        [spawn[0], spawn[1] + 0.8],
        [0.10 * R, 0.40 * R],
        [-0.07 * R, 0.26 * R],
        [-0.02 * R, 0.13 * R],
        [0.0, o.centralCourtR + 1.5],
    ];
    const promenadePoints = catmullSpline(promCtrl);
    // Belt-and-suspenders: the approach never dips into the pedestal's
    // clearing (Catmull-Rom tangents can bulge; clamp radially).
    const promGuard = o.centralCourtR + 0.9;
    for (const p of promenadePoints) {
        const r = Math.hypot(p[0], p[1]);
        if (r < promGuard) {
            p[0] = p[0] / (r || 1) * promGuard;
            p[1] = p[1] / (r || 1) * promGuard;
        }
    }

    // Ring walk: a closed loop at ~0.52R with seeded wobble — the garden's
    // main circuit connecting the courts; the promenade meets it twice.
    const ringJitter = [];
    for (let k = 0; k < 8; k++) ringJitter.push((rng.next() - 0.5) * (10 * Math.PI / 180));
    const ringPoints = [];
    for (let k = 0; k <= 8; k++) {
        const theta = (k % 8) / 8 * TAU + ringJitter[k % 8];
        const r = ringR * (1 + (rng.next() - 0.5) * 0.10);
        ringPoints.push(polar(r, theta));
    }

    const paths = [];
    const pushPath = (points, width, kind) => {
        const samples = samplePolyline(points, o.pathStep);
        if (samples.length >= 2) paths.push({ points, samples, width, kind });
    };
    pushPath(promenadePoints, o.promenadeWidth, 'promenade');
    pushPath(ringPoints, o.loopWidth, 'loop');

    // ── 2. Courts — the sculpture clearings (§6/§8) ──────────────────────
    // Role mix: primary pieces own the sightlines, transitional pieces live
    // near the boundary and are discovered, secondary pieces people the
    // lawn. Min 1 primary so even a 1-piece show has a hero composition.
    const nPrimary = clamp(Math.round(count * 0.2), 1, 3);
    const nTransitional = Math.round(count * 0.3);
    const nSecondary = Math.max(0, count - nPrimary - nTransitional);

    const courts = [];
    const flattenZones = [
        { x: 0, z: 0, inner: o.centralCourtR, outer: o.centralCourtR + 1.6 },
        { x: spawn[0], z: spawn[1], inner: o.spawnPlazaRadius, outer: o.spawnPlazaRadius + 1.2 },
    ];
    const minGap = count > o.denseCourtThreshold ? o.minCourtGapDense : o.minCourtGap;

    // Constraint check for a candidate court position.
    const courtFits = (x, z) => {
        const r = Math.hypot(x, z);
        if (r > rMax || r < o.centralCourtR + 0.2) return false;   // bounds + central clearing
        if (dist2D(x, z, spawn[0], spawn[1]) < o.spawnClearance) return false;
        for (const c of courts) if (dist2D(x, z, c.x, c.z) < minGap) return false;
        if (nearestPathDist(paths, x, z) < o.walkClearance) return false;
        return true;
    };

    // Seeded retry with PROGRESSIVE widening: early attempts hug the designed
    // anchor (curated positions), later attempts widen the search. The final
    // fallback nudges radially outward step by step — placement never fails
    // silently, and every outcome is deterministic.
    const placeCourt = (baseR, baseTheta, arc, rSpread, tries = 36) => {
        let attempt = null;
        for (let i = 0; i < tries; i++) {
            const widen = 0.25 + 2.2 * (i / tries);
            const theta = baseTheta + (rng.next() - 0.5) * arc * widen;
            const r = clamp(baseR + (rng.next() - 0.5) * rSpread * widen, o.centralCourtR + 1.2, rMax);
            const [x, z] = polar(r, theta);
            attempt = { x, z };
            if (courtFits(x, z)) return attempt;
        }
        // Spiral fallback: sweep radius outward→inward while oscillating the
        // angle ±~48° — a deterministic last search that finds the open slots
        // a fixed-angle retry misses (dense gardens need the range).
        const thetaF = Math.atan2(attempt.x, attempt.z);
        for (let r = rMax; r >= o.centralCourtR + 1.2; r -= 0.35) {
            for (let s = 0; s < 15; s++) {
                const dTheta = (s % 2 === 0 ? 1 : -1) * Math.ceil(s / 2) * 0.12;
                const [x, z] = polar(r, thetaF + dTheta);
                if (courtFits(x, z)) return { x, z };
            }
        }
        return attempt; // last resort — validator will flag it
    };
    const addCourt = (p, role, scale) => {
        courts.push({ x: p.x, z: p.z, role, scale });
        flattenZones.push({ x: p.x, z: p.z, inner: o.courtFlattenInner, outer: o.courtFlattenOuter });
    };

    // PRIMARY — designed anchors on the garden's main sightlines:
    //   P0 "The Lawn piece" — north, across the open lawn from the central
    //       court; the spawn's first-reveal composition (knot foreground,
    //       artwork beyond, grove backdrop). It stands INSIDE the ring walk
    //       when the lawn is deep enough, otherwise just beyond it.
    //   P1 "The Grove court" — NE, reached by the ring walk.
    //   P2 "The West court" — WSW, closing the loop's long arc.
    const lawnR = ringR - 2.3;
    const lawnInside = lawnR >= Math.max(4.6, o.centralCourtR + 1.4);
    const primaryAnchors = [
        { theta: Math.PI, r: lawnInside ? lawnR : Math.min(ringR + 2.2, rMax) },
        { theta: 40 * Math.PI / 180, r: Math.min(o.primaryRadius[1] * R, rMax) },
        { theta: 195 * Math.PI / 180, r: Math.min(0.62 * R, rMax) },
    ];
    for (let i = 0; i < nPrimary; i++) {
        const a = primaryAnchors[i];
        addCourt(placeCourt(a.r, a.theta, 16 * Math.PI / 180, 0.8), 'primary', o.primaryScale);
    }

    // SECONDARY — on angular slots that skip the promenade corridor
    // (θ ∈ [−25°, 25°]) so the arrival vista stays composed, at radii that
    // straddle the ring walk (pieces approached FROM the walk, never on it).
    const corridor = 50 * Math.PI / 180;
    const sweep = TAU - corridor;
    for (let i = 0; i < nSecondary; i++) {
        const baseTheta = Math.PI / 2 + corridor / 2 + sweep / Math.max(1, nSecondary + 1) * (i + 1);
        const baseR = (o.secondaryRadius[0] + rng.next() * (o.secondaryRadius[1] - o.secondaryRadius[0])) * R;
        addCourt(placeCourt(baseR, baseTheta, 14 * Math.PI / 180, 0.16 * R), 'secondary', o.secondaryScale);
    }

    // TRANSITIONAL — near the boundary, screened by groves (added below),
    // smaller scale: pieces you find rather than pieces you're shown.
    for (let i = 0; i < nTransitional; i++) {
        const baseTheta = (i / Math.max(1, nTransitional)) * TAU + 0.7 + (rng.next() - 0.5) * 0.5;
        const baseR = clamp((o.transitionalRadius[0] + rng.next() * (o.transitionalRadius[1] - o.transitionalRadius[0])) * R, ringR + 1.7, rMax);
        addCourt(placeCourt(baseR, baseTheta, 10 * Math.PI / 180, 1.6), 'transitional', o.transitionalScale);
    }

    // ── 3. Spurs — primary courts (but the lawn piece, which is approached
    // across the grass by design) get a short walk from the ring.
    courts.forEach((c, idx) => {
        if (c.role !== 'primary' || idx !== 1) return; // only the grove court
        let best = ringPoints[0], bd = Infinity;
        for (const p of ringPoints) {
            const d = dist2D(c.x, c.z, p[0], p[1]);
            if (d < bd) { bd = d; best = p; }
        }
        // The walk LEADS to the piece: it stops walkClearance + margin short
        // of the easel, so the court keeps its designed walk clearance and
        // the visitor still steps off the path onto the court.
        const dx = c.x - best[0], dz = c.z - best[1];
        const d = Math.hypot(dx, dz) || 1;
        const stop = Math.max(0, d - o.spurStopShort);
        pushPath([best, [best[0] + dx / d * stop, best[1] + dz / d * stop]], o.spurWidth, 'spur');
    });

    // ── 4. Facing — each easel turns toward its approach (§6) ────────────
    // The lawn piece faces the spawn axis (the composed first reveal);
    // everyone else faces their nearest walk — the visitor's arrival point.
    courts.forEach((c, idx) => {
        const target = (idx === 0 && c.role === 'primary')
            ? [0, spawn[1]]
            : (nearestPathPoint(paths, c.x, c.z) || [0, 0]);
        c.facing = Math.atan2(target[0] - c.x, target[1] - c.z);
    });

    // ── 5. Vegetation anchors — framing, backdrop, screen (§9/§10) ──────
    // Groves are DESIGNED (anchor + purpose), then individual trees are
    // seeded inside each grove. Every tree is clearance-checked against
    // courts (with the facing-cone rule: backdrops/beside-trees may stand
    // closer, in FRONT of a canvas never), walks and the spawn plaza.
    const groves = [];
    const trees = [];
    const thetaOf = (c) => Math.atan2(c.x, c.z);

    const treeFits = (tx, tz) => {
        if (dist2D(tx, tz, 0, 0) < o.centralCourtR + 1.0) return false;
        if (dist2D(tx, tz, spawn[0], spawn[1]) < o.treeSpawnClear) return false;
        if (Math.hypot(tx, tz) > R - 1.0) return false;            // inside the hedge
        if (nearestPathDist(paths, tx, tz) < o.treePathClear) return false;
        for (const c of courts) {
            const d = dist2D(tx, tz, c.x, c.z);
            if (d < o.treeCourtClear) {
                // Framing exception: behind/beside the piece, never in front.
                if (d < o.treeCourtMin) return false;
                const angleToTree = Math.atan2(tx - c.x, tz - c.z);
                if (Math.abs(angleDiff(angleToTree, c.facing)) < o.facingCone) return false;
            }
        }
        return true;
    };

    const addGrove = (x, z, treeCount, purpose) => {
        const grove = { x, z, purpose, trees: [] };
        for (let i = 0; i < treeCount; i++) {
            let placed = false;
            // Seeded retry around the grove anchor (progressive widening).
            for (let a = 0; a < 10 && !placed; a++) {
                const widen = 0.3 + 1.2 * (a / 10);
                const ang = rng.next() * TAU;
                const rad = Math.sqrt(rng.next()) * o.groveSpread * widen;
                const tx = x + Math.sin(ang) * rad, tz = z + Math.cos(ang) * rad;
                if (!treeFits(tx, tz)) continue;
                const tree = {
                    x: tx, z: tz,
                    scale: 0.9 + rng.next() * 0.45,
                    species: rng.next() < 0.62 ? 'round' : 'conifer',
                    tone: Math.floor(rng.next() * 3),  // foliage tone index
                    rot: rng.next() * TAU,
                    purpose,
                };
                grove.trees.push(tree);
                trees.push(tree);
                placed = true;
            }
        }
        if (grove.trees.length) groves.push(grove);
    };

    // backdrop — behind the lawn piece: its silhouette separates the artwork
    // from the sky (the §6 background rule).
    const lawnPiece = courts[0] && courts[0].role === 'primary' ? courts[0] : null;
    if (lawnPiece) {
        const bt = thetaOf(lawnPiece);
        const br = Math.min(Math.hypot(lawnPiece.x, lawnPiece.z) + 3.4, R - 1.2);
        addGrove(...polar(br, bt + 0.16), 3, 'backdrop');
        addGrove(...polar(br, bt - 0.14), 2, 'backdrop');
    }
    // gate — flank the promenade's opening: frames the arrival vista.
    const promMid = promCtrl[1];
    const promDir = Math.atan2(promCtrl[2][0] - promCtrl[1][0], promCtrl[2][1] - promCtrl[1][1]);
    const perp = promDir + Math.PI / 2;
    addGrove(promMid[0] + Math.sin(perp) * 2.6, promMid[1] + Math.cos(perp) * 2.6, 2, 'gate');
    addGrove(promMid[0] - Math.sin(perp) * 2.6, promMid[1] - Math.cos(perp) * 2.6, 2, 'gate');
    // screen — SW behind the spawn: the far side stays to be found.
    addGrove(...polar(Math.min(0.68 * R, rMax - 0.5), 260 * Math.PI / 180), 3, 'screen');
    // crown — one focal tree NE of the central court.
    addGrove(...polar(Math.max(4.6, o.centralCourtR + 1.6), 55 * Math.PI / 180), 1, 'crown');
    // edge — beside each transitional court (partial concealment → reveal).
    courts.forEach((c) => {
        if (c.role !== 'transitional') return;
        const cr = Math.hypot(c.x, c.z);
        addGrove(...polar(Math.min(cr + 2.9, R - 1.2), thetaOf(c) + 0.30), 2, 'edge');
    });

    // Shrub drifts — low planting that SOFTENS, never hides: along path
    // edges (alternating sides), framing the spawn plaza, and at the hedge
    // base. Every shrub is clearance-checked against courts and the plaza.
    const shrubs = [];
    const shrubFits = (x, z) => {
        if (dist2D(x, z, 0, 0) < o.centralCourtR + 1.4) return false;
        if (dist2D(x, z, spawn[0], spawn[1]) < o.spawnPlazaRadius) return false;
        for (const c of courts) if (dist2D(x, z, c.x, c.z) < o.shrubCourtClear) return false;
        return true;
    };
    const addShrub = (x, z) => {
        if (!shrubFits(x, z)) return;
        shrubs.push({
            x, z,
            scale: 0.55 + rng.next() * 0.5,
            tone: Math.floor(rng.next() * 3),
            rot: rng.next() * TAU,
        });
    };
    for (const p of paths) {
        if (p.kind === 'spur') continue;
        let side = rng.next() < 0.5 ? 1 : -1;
        for (let i = 3; i < p.samples.length - 2; i += Math.round(3.2 / o.pathStep)) {
            const s = p.samples[i], sPrev = p.samples[i - 2];
            const dx = s[0] - sPrev[0], dz = s[1] - sPrev[1];
            const len = Math.hypot(dx, dz) || 1;
            const off = (p.width / 2 + o.shrubPathOffset) * side;
            addShrub(s[0] + (-dz / len) * off, s[1] + (dx / len) * off);
            side = -side;
        }
    }
    // Spawn plaza accents: two low masses flanking the walk's start.
    addShrub(spawn[0] + 2.6, spawn[1] + 0.3);
    addShrub(spawn[0] - 2.6, spawn[1] + 0.3);
    // Hedge base softening: inner ring accents every ~36°.
    for (let k = 0; k < 10; k++) {
        const theta = k / 10 * TAU + (rng.next() - 0.5) * 0.2;
        addShrub(...polar(R - 1.35, theta));
    }

    // ── 6. Terrain field (last draw: phases) ─────────────────────────────
    const height = buildTerrainField(flattenZones, R, rng, o);

    // ── Assemble ─────────────────────────────────────────────────────────
    const plan = {
        radius: R,
        count,
        spawn: { x: spawn[0], z: spawn[1] },
        pedestal: { x: 0, z: 0 },
        paths,                     // [{ points, samples, width, kind }]
        courts,                    // [{ x, z, role, scale, facing }]
        groves, trees, shrubs,
        terrain: { height },
        defaults: o,
    };
    return plan;
}

// ── Validation — QA pins the DESIGN, not just the existence ─────────────────
/**
 * Validate a plan against the design contract. Returns
 * { ok, violations: string[] } — empty violations = the composition is
 * curated: spaced, clear, navigable, bounded.
 */
export function validateGardenPlan(plan) {
    const v = [];
    const o = plan.defaults;
    const R = plan.radius;

    // Courts: pairwise spacing, walk clearance, spawn clearance, bounds,
    // reachability, central-clearing respect. The gap threshold mirrors the
    // planner's adaptive rule (dense shows relax to minCourtGapDense) — the
    // validator and planner must never disagree about the contract.
    const minGap = plan.count > o.denseCourtThreshold ? o.minCourtGapDense : o.minCourtGap;
    for (let i = 0; i < plan.courts.length; i++) {
        const c = plan.courts[i];
        const r = Math.hypot(c.x, c.z);
        if (r > R - o.courtEdge + 1e-6) v.push(`court ${i} (${c.role}) outside bounds: r=${r.toFixed(2)} > ${(R - o.courtEdge).toFixed(2)}`);
        if (r < o.centralCourtR) v.push(`court ${i} sits inside the central clearing: ${r.toFixed(2)}`);
        if (!Number.isFinite(c.facing)) v.push(`court ${i} has no facing`);
        for (let j = i + 1; j < plan.courts.length; j++) {
            const d = dist2D(c.x, c.z, plan.courts[j].x, plan.courts[j].z);
            if (d < minGap) v.push(`courts ${i}/${j} too close: ${d.toFixed(2)} < ${minGap}`);
        }
        const np = nearestPathDist(plan.paths, c.x, c.z);
        if (np < o.walkClearance) v.push(`court ${i} (${c.role}) crowds a walk: ${np.toFixed(2)} < ${o.walkClearance}`);
        if (np > 8.5) v.push(`court ${i} (${c.role}) is unreachable: nearest walk ${np.toFixed(2)} m away`);
        const ds = dist2D(c.x, c.z, plan.spawn.x, plan.spawn.z);
        if (ds < o.spawnClearance) v.push(`court ${i} crowds the spawn: ${ds.toFixed(2)}`);
    }

    // Walks respect the central clearing (the pedestal stays off every walk).
    for (const p of plan.paths) {
        for (const s of p.samples) {
            const d = dist2D(s[0], s[1], 0, 0);
            if (d < o.centralCourtR - 0.9) v.push(`${p.kind} walk invades the central clearing: ${d.toFixed(2)}`);
        }
    }

    // Vegetation: sightlines stay open (the same facing-cone rule as the
    // planner — backdrops/beside-trees may stand closer, never in front).
    plan.trees.forEach((t, i) => {
        if (Math.hypot(t.x, t.z) > R - 1.0 + 1e-6) v.push(`tree ${i} outside the hedge line`);
        for (const c of plan.courts) {
            const d = dist2D(t.x, t.z, c.x, c.z);
            if (d < o.treeCourtClear) {
                if (d < o.treeCourtMin) { v.push(`tree ${i} crowds a court (d=${d.toFixed(2)})`); continue; }
                const angleToTree = Math.atan2(t.x - c.x, t.z - c.z);
                if (Math.abs(angleDiff(angleToTree, c.facing)) < o.facingCone) {
                    v.push(`tree ${i} blocks a canvas front (d=${d.toFixed(2)})`);
                }
            }
        }
        for (const p of plan.paths) {
            for (const s of p.samples) {
                const d = dist2D(t.x, t.z, s[0], s[1]);
                if (d < o.treePathClear) { v.push(`tree ${i} stands on a walk (d=${d.toFixed(2)})`); break; }
            }
        }
        const ds = dist2D(t.x, t.z, plan.spawn.x, plan.spawn.z);
        if (ds < o.treeSpawnClear) v.push(`tree ${i} crowds the spawn (d=${ds.toFixed(2)})`);
    });
    plan.shrubs.forEach((s, i) => {
        for (const c of plan.courts) {
            const d = dist2D(s.x, s.z, c.x, c.z);
            if (d < o.shrubCourtClear) v.push(`shrub ${i} crowds a canvas front (d=${d.toFixed(2)})`);
        }
        const dp = dist2D(s.x, s.z, plan.spawn.x, plan.spawn.z);
        if (dp < o.spawnPlazaRadius - 1e-6) v.push(`shrub ${i} inside the spawn plaza`);
    });

    return { ok: v.length === 0, violations: v };
}
