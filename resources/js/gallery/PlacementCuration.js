export const DENSITY_PRESETS = Object.freeze({
    intimate: 2.8,   // salon-close hanging (§6.3: "intimate ~2.8 m")
    standard: 3.5,   // the historical default — calm is the brand
    generous: 4.5,   // breathing room for large-format shows (§6.3)
});

export const FOCAL_WALLS = Object.freeze(['front', 'back', 'left', 'right']);

export const FOCAL = Object.freeze({
    scaleBoost: 1.15,   // group scale for the focal hero piece
    lightBoost: 1.35,   // proximity-light max for the focal hero piece
});

export function resolveSpacing(placement, fallback) {
    const fb = typeof fallback === 'number' && fallback > 0 ? fallback : 3.5;
    if (!placement || typeof placement !== 'object') return fb;
    const d = placement.density;
    if (typeof d === 'string' && Object.prototype.hasOwnProperty.call(DENSITY_PRESETS, d)) {
        return DENSITY_PRESETS[d];
    }
    return fb;
}

export function orientationOf(img) {
    const a = Number(img && img.aspectRatio);
    if (!Number.isFinite(a) || a <= 0) return 'landscape';
    return a >= 1 ? 'landscape' : 'portrait';
}

export function pairByOrientation(images) {
    if (!Array.isArray(images) || images.length === 0) return [];
    const land = [], port = [];
    for (let i = 0; i < images.length; i++) {
        (orientationOf(images[i]) === 'landscape' ? land : port).push(i);
    }
    // Majority class leads; on ties landscape leads (deterministic).
    const [first, second] = land.length >= port.length ? [land, port] : [port, land];
    const out = [];
    let a = 0, b = 0, turnFirst = true;
    while (a < first.length || b < second.length) {
        if (turnFirst && a < first.length) out.push(first[a++]);
        else if (!turnFirst && b < second.length) out.push(second[b++]);
        // When the leading class is exhausted, drain the remainder.
        else if (a < first.length) out.push(first[a++]);
        else if (b < second.length) out.push(second[b++]);
        turnFirst = !turnFirst;
    }
    return out;
}

export function focalWallOf(placement) {
    if (!placement || typeof placement !== 'object') return null;
    const w = placement.focal_wall;
    return FOCAL_WALLS.includes(w) ? w : null;
}

export function isFocalHero(focalWall, wallId, heroTaken) {
    if (!focalWall || heroTaken) return false;
    if (wallId == null) return false; // bay hang (no wall id) — skip
    return wallId === focalWall;
}

export function resolveSquareHang(placement, imageCount, wallCount, spacing, minWallLength) {
    const p = (placement && typeof placement === 'object') ? placement : {};
    const capNum = Number(p.wall_length_cap);
    const cap = Number.isFinite(capNum) && capNum > 0 ? capNum : 0;
    const rowsMax = Math.max(1, Math.floor(Number(p.salon_rows)) || 1);

    const keep = p.keep_clear && typeof p.keep_clear === 'object' ? p.keep_clear : null;
    const keepWall = keep && FOCAL_WALLS.includes(keep.wall) ? keep.wall : null;
    const keepWidth = keepWall ? Math.max(0, Number(keep.width) || 0) : 0;

    const minWall = (typeof minWallLength === 'number' && minWallLength > 0) ? minWallLength : 8;
    const sizeFor = (perLine) => Math.max(minWall, perLine * spacing + spacing);
    const lineRunsFor = (pl) => {
        const runs = [];
        for (let i = 0; i < wallCount * 2; i++) {   // upper bound; rows ≤ 2
            const r = Math.min(pl, Math.max(0, imageCount - i * pl));
            if (r <= 0 && i > 0) break;
            runs.push(r);
        }
        return runs;
    };
    const centreHitsKeep = (run) =>
        !!keepWall && keepWidth > 0 && keepWidth / 2 < spacing / 2 && run % 2 === 1;
    const strandedFor = (rowsN, pl) => {
        const runs = lineRunsFor(pl);
        const keepLines = rowsN === 2 ? [1, 5] : [1];
        const drops = keepLines.filter(i => centreHitsKeep(runs[i] ?? 0)).length;
        const slack = wallCount * rowsN * pl - imageCount;
        return Math.max(0, drops - slack);
    };

    let rows = 1;
    let perLine = Math.ceil(imageCount / (wallCount * rows));
    if (rowsMax > 1 && cap > 0) {
        let projected = perLine + strandedFor(1, perLine);
        if (sizeFor(projected) > cap) {
            rows = rowsMax;
            perLine = Math.ceil(imageCount / (wallCount * rows));
        }
    }

    if (strandedFor(rows, perLine) > 0) {
        perLine += 1;
    }

    return {
        rows,
        perLine,
        keep: keepWall ? { wall: keepWall, half: keepWidth / 2 } : null,
    };
}

export const DIVIDER_FILL_ORDER = Object.freeze([
    'front', 'back-l', 'west-b', 'back-r', 'east-b', 'west-a', 'east-a',
]);

export const DIVIDER_EDGE = 0.25;

export function resolveDividerHang(placement, imageCount, fallbackSpacing, minWallLength) {
    const p = (placement && typeof placement === 'object') ? placement : {};
    const d = (p.room_divider && typeof p.room_divider === 'object') ? p.room_divider : null;
    if (!d) return null;

    const spacing = Number(d.spacing) > 0 ? Number(d.spacing)
        : (Number(fallbackSpacing) > 0 ? Number(fallbackSpacing) : 2.8);
    const minWall = (typeof minWallLength === 'number' && minWallLength > 0) ? minWallLength : 8;
    const capNum  = Number(p.wall_length_cap);
    const cap     = Number.isFinite(capNum) && capNum > 0 ? capNum : 14;
    const rows    = Math.max(1, Math.floor(Number(p.salon_rows)) || 2);
    const at      = Math.min(0.8, Math.max(0.2, Number(d.at) || 0.5));
    const opening = Math.max(1.0, Number(d.opening) || 2.4);
    const keep    = Math.max(0.3, Number(d.keep) || 0.55);
    const doorKeepRaw = Number(d.door_keep);
    const doorKeep = Number.isFinite(doorKeepRaw) && doorKeepRaw > 0 ? doorKeepRaw : 1.15;

    // Per-row canvas width caps (the salon row_caps block; sane default).
    const rowCaps = (Array.isArray(p.row_caps) && p.row_caps.length) ? p.row_caps : null;
    const capFor = (row) => {
        const c = rowCaps ? rowCaps[Math.min(row, rowCaps.length - 1)] : null;
        return Math.max(0.4, Number(c && c.maxWidth) || 1.7);
    };

    const spansFor = (L) => {
        const h = L / 2;
        const atZ = -h + at * L;    // curtain plane (world z)
        const segs = {
            'front':  { wall: 'front', a: -h + DIVIDER_EDGE, b: h - DIVIDER_EDGE },
            'back-l': { wall: 'back',  a: -h + DIVIDER_EDGE, b: -doorKeep },
            'back-r': { wall: 'back',  a: doorKeep,  b: h - DIVIDER_EDGE },
            'west-b': { wall: 'left',  a: -h + DIVIDER_EDGE, b: atZ - keep },
            'east-b': { wall: 'right', a: -h + DIVIDER_EDGE, b: atZ - keep },
            'west-a': { wall: 'left',  a: atZ + keep, b: h - DIVIDER_EDGE },
            'east-a': { wall: 'right', a: atZ + keep, b: h - DIVIDER_EDGE },
        };
        for (const id of Object.keys(segs)) {
            const s = segs[id];
            s.len = Math.max(0, s.b - s.a);
            s.center = (s.a + s.b) / 2;
        }
        return segs;
    };

    const lineCap = (len, capRow) => {
        const head = capRow + 0.68;
        if (len < head) return 0;
        return Math.max(0, Math.floor((len - head) / spacing) + 1);
    };

    const linesFor = (L) => {
        const segs = spansFor(L);
        const lines = new Map();               // 'seg|row' → count (insertion order = hang order)
        let remaining = imageCount;
        const capOf = (id, row) => {
            const seg = segs[id];
            if (!seg || seg.len <= 0) return 0;
            return lineCap(seg.len, capFor(row));
        };
        const add = (id, row, n) => {
            if (n <= 0) return;
            const k = `${id}|${row}`;
            lines.set(k, (lines.get(k) || 0) + n);
            remaining -= n;
        };
        // 1. hero
        if (remaining > 0) add('front', 0, Math.min(1, capOf('front', 0)));
        // 2. arrival seed
        if (remaining > 0) {
            for (const id of ['back-l', 'back-r', 'west-a', 'east-a']) {
                if (capOf(id, 0) > 0) { add(id, 0, 1); break; }
            }
        }
        for (let row = 0; row < rows && remaining > 0; row++) {
            for (const id of DIVIDER_FILL_ORDER) {
                if (remaining <= 0) break;
                const k = `${id}|${row}`;
                const cur = lines.get(k) || 0;
                let n = Math.min(capOf(id, row) - cur, remaining);
                if (n <= 0) continue;
                if (id === 'front' && row === 0) {
                    let total = Math.min(capOf('front', 0), cur + remaining);
                    if (total > 1 && total % 2 === 0) total -= 1;
                    n = Math.max(0, total - cur);
                    if (n <= 0) continue;
                }
                add(id, row, n);
            }
        }
        const out = [];
        for (const [k, count] of lines) {
            const pipe = k.lastIndexOf('|');
            out.push({ seg: k.slice(0, pipe), row: Number(k.slice(pipe + 1)), count });
        }
        return { segs, lines: out, remaining };
    };

    let wallLength = cap;
    let fitsAtCap = false;
    const steps = Math.max(1, Math.round((cap - minWall) / 0.1));
    for (let i = 0; i <= steps; i++) {
        const L = Math.min(cap, minWall + i * 0.1);
        if (linesFor(L).remaining === 0) { wallLength = L; fitsAtCap = true; break; }
    }
    if (!fitsAtCap) {
        let L = cap;
        let guard = 0;
        while (linesFor(L).remaining > 0 && guard < 4000) {
            L += 0.1;
            guard++;
        }
        wallLength = L;
    }

    let { segs, lines, remaining } = linesFor(wallLength);
    if (remaining > 0) {
        for (const ln of lines) {
            if (remaining <= 0) break;
            if (ln.seg === 'front' && ln.row === 0) continue;   // hero stays odd
            const extra = Math.min(remaining, 2);
            ln.count += extra;
            remaining -= extra;
        }
    }

    return {
        spacing, at, opening, keep, doorKeep, cap, rows,
        wallLength,
        atZ: -wallLength / 2 + at * wallLength,
        segs,
        lines,
        unplaced: remaining,
    };
}