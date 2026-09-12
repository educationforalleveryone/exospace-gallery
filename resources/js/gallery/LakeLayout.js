export const LAKE_DEFAULTS = Object.freeze({
    shoreBase: 0.30,
    shoreAmp1: 0.050, shoreFreq1: 1.9,   // broad bay curve
    shoreAmp2: 0.028, shoreFreq2: 3.7,   // gentle edge variation
    shoreDrop: 3.2,      // metres from waterline to full depth (wet band read)
    waterLevel: -0.24,   // water surface y (land is y=0 — no camera-follow)
    bedDepth: 0.9,       // lakebed y at full depth (hidden under the water)

    spawnRadiusFactor: 0.72,   // south, on the land, water due ahead
    plazaRadius: 2.7,          // cut-stone arrival plaza (metres)

    walkWidth: 1.8,
    walkOffset: 1.7,           // land-side offset from the waterline
    walkHalfSpan: 0.58,        // fraction of R — from −x·span to +x·span

    pierXFrac: 0.50,           // east, fraction of R
    pierWidth: 2.2,            // deck width (metres)
    pierEndZFrac: -0.46,       // north end of the deck, fraction of R
    pavilionSize: 4.6,         // stilted viewing deck square (metres)
    pavilionDeckY: 0.14,       // deck sits a plank's thickness above the land
                               // datum so it reads as lifted over the water

    berthOffsets: [2.8, 5.2, 7.6, 10.0, 12.4],  // metres north of the
    heroXFrac: -0.264,              // arrival-axis hero anchor x (west, under
    heroRing: 1,                    // the hero reads ACROSS the water: one
                                    // line out, not toe-level
    thirdRingFrom: 12,
    fourthLineFrom: 20,
    fifthLineFrom: 28,
    berthGap: 2.7,             // minimum metres between berths
    berthGapDense: 2.45,       // dense shows: the line tightens (never < 2.4)
    arcWestXFrac: -0.80,       // the art line's x-range (fractions of R):
    arcEastXFrac: 0.30,        // west end toward the disc edge, east end short
    pierClearance: 3.7,        // berth distance from the pier centreline
    pavilionClearance: 5.4,
    plazaClearance: 6.0,
    shoreMargin: 2.4,          // berths stay this far north (water side) of shore
    berthElevation: 1.56,      // hover height (land datum, eye-ish) over the water
    berthElevationJitter: 0.14,
    berthRollDeg: 2.6,         // seeded float roll (the void heritage, calmed)

    farshoreFromFrac: 0.985,   // terrain tucks under the water plane…
    farshoreToExtra: 10,       // …and rises north for `extra` metres
    farshoreHeight: 1.5,       // outer-edge height (metres)
    farshoreArcFromDeg: 96, farshoreArcToDeg: 264,

    treelineCount: 14,         // far-shore silhouette trees
    treelineRMin: 1.6, treelineRMax: 6.5, // beyond the field edge, fraction of R + offset
    landingTrees: 2,           // framing the plaza
    reedCount: 9,              // water's-edge clumps (grass role, dark)
    shrubCount: 6,
    boulderCount: 3,
    benchCount: 2,             // on the shore walk, facing the water

    moonAzimuthDeg: 198,       // NNW — 18° left of north
    moonElevationDeg: 30,
});

const TAU = Math.PI * 2;
const DEG = Math.PI / 180;

function clamp(v, lo, hi) { return v < lo ? lo : v > hi ? hi : v; }
function smoothstep(e0, e1, x) {
    const t = clamp((x - e0) / (e1 - e0 || 1e-6), 0, 1);
    return t * t * (3 - 2 * t);
}
function polar(r, theta) { return [Math.sin(theta) * r, Math.cos(theta) * r]; }
function dist2D(ax, az, bx, bz) { return Math.hypot(ax - bx, az - bz); }

function catmullSpline(ctrl, segs = 12) {
    const pts = [];
    const p = (i) => ctrl[clamp(i, 0, ctrl.length - 1)];
    for (let i = 0; i < ctrl.length - 1; i++) {
        const p0 = p(i - 1), p1 = p(i), p2 = p(i + 1), p3 = p(i + 2);
        for (let s = 0; s < segs; s++) {
            const t = s / segs, t2 = t * t, t3 = t2 * t;
            pts.push([
                0.5 * ((2 * p1[0]) + (-p0[0] + p2[0]) * t + (2 * p0[0] - 5 * p1[0] + 4 * p2[0] - p3[0]) * t2 + (-p0[0] + 3 * p1[0] - 3 * p2[0] + p3[0]) * t3),
                0.5 * ((2 * p1[1]) + (-p0[1] + p2[1]) * t + (2 * p0[1] - 5 * p1[1] + 4 * p2[1] - p3[1]) * t2 + (-p0[1] + 3 * p1[1] - 3 * p2[1] + p3[1]) * t3),
            ]);
        }
    }
    pts.push([...ctrl[ctrl.length - 1]]);
    return pts;
}

function arcPoints(r, fromDeg, toDeg, step) {
    const from = fromDeg * DEG, to = toDeg * DEG;
    const n = Math.max(2, Math.ceil(Math.abs(to - from) / (step / Math.max(r, 0.01))));
    const pts = [];
    for (let i = 0; i <= n; i++) {
        const th = from + (to - from) * (i / n);
        pts.push(polar(r, th));
    }
    return pts;
}

export function buildLakePlan(opts) {
    const o = { ...LAKE_DEFAULTS, ...(opts.config || {}) };
    const R = Math.max(10, Number(opts.radius) || 10);
    const count = Math.max(1, Math.floor(Number(opts.count) || 1));
    const rng = opts.rng;

    const ph1 = rng.next() * TAU;
    const ph2 = rng.next() * TAU;
    const shoreZ = (x) => {
        const u = x / R;
        return R * (o.shoreBase
            + o.shoreAmp1 * Math.sin(o.shoreFreq1 * u * Math.PI + ph1)
            + o.shoreAmp2 * Math.sin(o.shoreFreq2 * u * Math.PI + ph2));
    };
    const height = (x, z) => {
        const d = z - shoreZ(x);              // >0 on land (south of the line)
        if (d >= 0) return 0;
        return -o.bedDepth * smoothstep(0, o.shoreDrop, -d);
    };
    const isWater = (x, z, margin = 0) => z < shoreZ(x) - margin;

    const spawn = polar(R * o.spawnRadiusFactor, 0);
    const waterLevel = o.waterLevel;

    const walkCtrl = [];
    for (let i = 0; i <= 6; i++) {
        const x = -o.walkHalfSpan * R + (2 * o.walkHalfSpan * R) * (i / 6);
        walkCtrl.push([x, shoreZ(x) + o.walkOffset]);
    }
    const walkPoints = catmullSpline(walkCtrl, 10);
    const walkSamples = [];
    for (let i = 0; i < walkPoints.length - 1; i++) {
        const [ax, az] = walkPoints[i];
        const [bx, bz] = walkPoints[i + 1];
        const d = Math.hypot(bx - ax, bz - az);
        const steps = Math.max(1, Math.ceil(d / 0.6));
        for (let s = 0; s < steps; s++) {
            walkSamples.push([ax + (bx - ax) * (s / steps), az + (bz - az) * (s / steps)]);
        }
    }
    walkSamples.push([...walkPoints[walkPoints.length - 1]]);

    const nearestWalkDist = (x, z) => {
        let best = Infinity;
        for (const [wx, wz] of walkSamples) {
            const d = dist2D(x, z, wx, wz);
            if (d < best) best = d;
        }
        return best;
    };
    const nearestWalkPoint = (x, z) => {
        let best = Infinity, bp = walkSamples[0];
        for (const w of walkSamples) {
            const d = dist2D(x, z, w[0], w[1]);
            if (d < best) { best = d; bp = w; }
        }
        return bp;
    };

    const pierX = o.pierXFrac * R;
    const pierFootZ = shoreZ(pierX) - 0.6;    // apron bites the waterline
    const pierEndZ = o.pierEndZFrac * R;
    const pavilion = {
        x: pierX,
        z: pierEndZ - o.pavilionSize * 0.5 - 0.4,
        size: o.pavilionSize,
        deckY: o.pavilionDeckY,
    };
    // The pavilion looks back across the whole composition: at the landing.
    pavilion.yaw = Math.atan2(spawn[0] - pavilion.x, spawn[1] - pavilion.z);
    const pier = { x: pierX, footZ: pierFootZ, endZ: pierEndZ, width: o.pierWidth };

    const nPrimary = clamp(Math.round(count * 0.2), Math.min(1, count), 3);
    const nTransitional = Math.round(count * 0.3);
    const lineCount = count >= o.fifthLineFrom ? 5
        : count >= o.fourthLineFrom ? 4
        : (count >= o.thirdRingFrom ? 3 : 2);
    const offsets = o.berthOffsets.slice(0, lineCount);
    const berthGap = count > 26 ? o.berthGapDense : o.berthGap;

    const berthFits = (x, z, courts) => {
        const r = Math.hypot(x, z);
        if (r > R - 1.1) return false;                                   // bounds
        if (!isWater(x, z, o.shoreMargin)) return false;                 // over open water
        if (dist2D(x, z, spawn[0], spawn[1]) < o.plazaClearance) return false;
        // pier corridor: the deck's swept band (fixed x, z spans foot → end)
        if (Math.abs(x - pierX) < o.pierClearance
            && z > pierEndZ - 1.2 && z < pierFootZ + 1.2) return false;
        if (dist2D(x, z, pavilion.x, pavilion.z) < o.pavilionClearance) return false;
        for (const c of courts) if (dist2D(x, z, c.x, c.z) < berthGap) return false;
        return true;
    };

    const lineAnchors = (offset) => {
        const x0 = o.arcWestXFrac * R, x1 = o.arcEastXFrac * R;
        const n = Math.max(2, Math.ceil(Math.abs(x1 - x0) / berthGap));
        const pts = [];
        for (let i = 0; i <= n; i++) {
            const x = x0 + (x1 - x0) * (i / n);
            pts.push([x, shoreZ(x) - offset]);
        }
        return pts;
    };

    const courts = [];
    let heroPlaced = false;

    {
        const heroX = o.heroXFrac * R + (rng.next() - 0.5) * (berthGap * 1.15);
        const heroZ = shoreZ(heroX) - offsets[o.heroRing];
        for (let a = 0; a < 10 && !heroPlaced; a++) {
            const widen = 0.14 * a;
            const x = heroX + (rng.next() - 0.5) * 2 * widen;
            const z = heroZ + (rng.next() - 0.5) * 2 * widen;
            if (!berthFits(x, z, courts)) continue;
            const [wx, wz] = nearestWalkPoint(x, z);
            const elev = o.berthElevation + rng.next() * o.berthElevationJitter;
            const roll = (rng.next() - 0.5) * 2 * o.berthRollDeg * DEG;
            courts.push({
                x, z, y: elev,
                facing: Math.atan2(wx - x, wz - z),
                scale: 1.2,
                role: 'hero',
                ring: o.heroRing,
            });
            heroPlaced = true;
        }
    }

    outer:
    for (let ring = 0; ring < offsets.length; ring++) {
        const off = offsets[ring];
        const linePts = lineAnchors(off);

        for (const [bx, bz] of linePts) {
            if (courts.length >= count) break outer;
            let placed = false;
            for (let a = 0; a < 10 && !placed; a++) {
                const widen = 0.14 * a;
                const x = bx + (rng.next() - 0.5) * 2 * widen;
                const z = bz + (rng.next() - 0.5) * 2 * widen;
                if (!berthFits(x, z, courts)) continue;
                const idx = courts.length;
                const role = idx <= nPrimary
                    ? 'primary'
                    : (idx >= count - nTransitional ? 'transitional' : 'secondary');
                const [wx, wz] = nearestWalkPoint(x, z);
                const elev = o.berthElevation + rng.next() * o.berthElevationJitter;
                const roll = (rng.next() - 0.5) * 2 * o.berthRollDeg * DEG;
                courts.push({
                    x, z, y: elev,
                    facing: Math.atan2(wx - x, wz - z),
                    scale: role === 'primary' ? 1.12 : role === 'transitional' ? 0.9 : 1.0,
                    role,
                    ring,
                });
                placed = true;
            }
        }
    }

    // ── 5. The far shore — the horizon closes north ─────────────────────
    const farshore = {
        innerR: o.farshoreFromFrac * R,
        outerR: R + o.farshoreToExtra,
        fromDeg: o.farshoreArcFromDeg,
        toDeg: o.farshoreArcToDeg,
        height: o.farshoreHeight,
    };

    const vegetation = [];
    const benches = [];

    // 6a. Far-shore treeline — silhouettes against the sky, in the mist.
    for (let i = 0; i < o.treelineCount; i++) {
        const th = (farshore.fromDeg + rng.next() * (farshore.toDeg - farshore.fromDeg)) * DEG;
        const rr = R + o.treelineRMin + rng.next() * (o.treelineRMax - o.treelineRMin);
        const [tx, tz] = polar(rr, th);
        vegetation.push({
            x: tx, z: tz,
            role: i % 3 === 0 ? 'tree_large' : (i % 3 === 1 ? 'tree_medium' : 'tree_accent'),
            scale: 0.85 + rng.next() * 0.5,
            yaw: rng.next() * TAU,
            purpose: 'farshore',
        });
    }

    // 6b. Landing pair — framing the plaza, one each side.
    for (let i = 0; i < o.landingTrees; i++) {
        const side = i === 0 ? -1 : 1;
        const lx = spawn[0] + side * (o.plazaRadius + 1.6 + rng.next() * 0.8);
        const lz = spawn[1] + 0.6 + rng.next() * 1.2;
        vegetation.push({
            x: lx, z: lz,
            role: i === 0 ? 'tree_accent' : 'tree_medium',
            scale: 0.72 + rng.next() * 0.16,
            yaw: rng.next() * TAU,
            purpose: 'landing',
        });
    }

    const edgeAnchor = (purpose) => {
        for (let a = 0; a < 14; a++) {
            const t = rng.next();
            const idx = Math.floor(t * (walkSamples.length - 1));
            const [wx, wz] = walkSamples[idx];
            const nx = wx + (rng.next() - 0.5) * 2.4;
            const nz = wz + (0.35 + rng.next() * 0.75) * -1;
            if (Math.hypot(nx, nz) > R - 1.2) continue;               // bounds
            if (dist2D(nx, nz, spawn[0], spawn[1]) < o.plazaRadius + 1.2) continue;
            if (Math.abs(nx - pierX) < 2.2 && nz > pierFootZ - 1) continue; // apron
            if (isWater(nx, nz, 0.35)) continue;                      // stays on land
            if (nearestWalkDist(nx, nz) < 0.95) continue;             // never in the walk
            return { x: nx, z: nz };
        }
        return null;
    };
    const pushEdge = (n, role, sMin, sMax, purpose) => {
        for (let i = 0; i < n; i++) {
            const p = edgeAnchor(purpose);
            if (!p) continue;
            vegetation.push({
                x: p.x, z: p.z, role,
                scale: sMin + rng.next() * (sMax - sMin),
                yaw: rng.next() * TAU,
                purpose,
            });
        }
    };
    pushEdge(o.reedCount, 'grass', 0.75, 1.25, 'edge-reed');
    pushEdge(o.shrubCount, 'shrub', 0.7, 1.1, 'edge-shrub');
    pushEdge(o.boulderCount, 'boulder', 0.7, 1.15, 'edge-boulder');

    // 6d. Benches — two moments on the walk, facing the water.
    for (let i = 0; i < o.benchCount; i++) {
        for (let a = 0; a < 10; a++) {
            const t = 0.22 + (i === 0 ? 0 : 0.5) * 0.56 + (rng.next() - 0.5) * 0.12;
            const idx = Math.floor(clamp(t, 0, 0.999) * (walkSamples.length - 1));
            const [wx, wz] = walkSamples[idx];
            const bx = wx + 1.3;                     // clear of the walk ribbon
            const bz = wz + 0.1;
            if (Math.hypot(bx, bz) > R - 1.4) continue;
            if (isWater(bx, bz, 0.5)) continue;
            if (Math.abs(bx - pierX) < 2.4 && bz > pierFootZ - 1) continue;
            if (dist2D(bx, bz, spawn[0], spawn[1]) < o.plazaRadius + 2.2) continue;
            benches.push({ x: bx, z: bz, yaw: Math.atan2(0, -1) });
            break;
        }
    }

    return {
        radius: R,
        count,
        berthGap,
        spawn: { x: spawn[0], z: spawn[1] },
        waterLevel,
        shoreZ,
        terrain: { height, isWater },
        walk: { points: walkPoints, samples: walkSamples, width: o.walkWidth },
        pier,
        pavilion,
        plaza: { x: spawn[0], z: spawn[1], r: o.plazaRadius },
        courts,
        vegetation,
        benches,
        farshore,
        moon: { azimuth: o.moonAzimuthDeg * DEG, elevation: o.moonElevationDeg * DEG },
        config: o,
    };
}

export function validateLakePlan(plan) {
    const v = [];
    const o = plan.config;
    const R = plan.radius;

    if (!plan.courts.length) v.push('no berths placed');

    plan.courts.forEach((c, i) => {
        const r = Math.hypot(c.x, c.z);
        if (r > R - 1.0) v.push(`berth ${i} outside the field: r=${r.toFixed(2)}`);
        if (!plan.terrain.isWater(c.x, c.z, o.shoreMargin * 0.5)) {
            v.push(`berth ${i} not over open water: (${c.x.toFixed(2)}, ${c.z.toFixed(2)})`);
        }
        const zc = clamp(c.z, plan.pier.endZ, plan.pier.footZ);
        if (Math.abs(c.x - plan.pier.x) < 2.6 && Math.abs(c.z - zc) < 1.2) {
            v.push(`berth ${i} crowds the pier corridor`);
        }
        if (dist2D(c.x, c.z, plan.pavilion.x, plan.pavilion.z) < o.pavilionClearance - 0.4) {
            v.push(`berth ${i} crowds the pavilion`);
        }
        if (dist2D(c.x, c.z, plan.spawn.x, plan.spawn.z) < o.plazaClearance - 0.5) {
            v.push(`berth ${i} crowds the landing`);
        }
    });

    // pairwise gaps
    const gap = plan.berthGap || o.berthGap;
    for (let i = 0; i < plan.courts.length; i++) {
        for (let j = i + 1; j < plan.courts.length; j++) {
            const d = dist2D(plan.courts[i].x, plan.courts[i].z, plan.courts[j].x, plan.courts[j].z);
            if (d < gap - 0.3) v.push(`berths ${i}/${j} too close: ${d.toFixed(2)}`);
        }
    }

    if (plan.courts.length < plan.count) {
        v.push(`berth shortfall: ${plan.courts.length}/${plan.count}`);
    }

    // spawn + walk on land, inside the field
    if (plan.terrain.isWater(plan.spawn.x, plan.spawn.z, 0)) v.push('spawn is in the water');
    if (Math.hypot(plan.spawn.x, plan.spawn.z) > R - 1) v.push('spawn outside the field');
    for (const [wx, wz] of plan.walk.samples) {
        if (plan.terrain.isWater(wx, wz, 0.4)) { v.push('walk dips into the water'); break; }
    }

    // pier geometry: foot on land, end over water
    if (!plan.terrain.isWater(plan.pier.x, plan.pier.endZ - 1, 0)) v.push('pier end is not over water');
    if (plan.terrain.isWater(plan.pier.x, plan.pier.footZ + 1, 0)) v.push('pier foot is not on land');

    // vegetation: edge planting stays on land; benches face north water
    plan.vegetation.forEach((a, i) => {
        if ((a.purpose === 'edge-reed' || a.purpose === 'edge-shrub' || a.purpose === 'edge-boulder')
            && plan.terrain.isWater(a.x, a.z, 0.2)) {
            v.push(`edge anchor ${i} (${a.purpose}) sits in the water`);
        }
        if (a.purpose === 'landing' && dist2D(a.x, a.z, plan.spawn.x, plan.spawn.z) < o.plazaRadius) {
            v.push(`landing tree ${i} inside the plaza`);
        }
    });
    plan.benches.forEach((b, i) => {
        if (plan.terrain.isWater(b.x, b.z, 0.3)) v.push(`bench ${i} in the water`);
        // facing the water = looking north (cos of the yaw ≈ −1)
        if (Math.cos(b.yaw) > -0.8) v.push(`bench ${i} does not face the water`);
    });

    return { ok: v.length === 0, violations: v };
}
