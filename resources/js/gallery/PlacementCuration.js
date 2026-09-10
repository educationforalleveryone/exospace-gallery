// ─────────────────────────────────────────────────────────────────────────────
// PlacementCuration — pure math for opt-in curator placement (roadmap P2.3)
//
// ZERO imports, ZERO three.js, ZERO DOM, ZERO venue slugs (DoD rule #7).
// Directly executable in Node (scripts/verify_iteration6.mjs) as well as in
// the browser bundle — the same pattern as TierResolve.js / PlacementMath.js /
// ArrivalMath.js.
//
// §6 "Artwork Presentation Philosophy" is the contract:
//   §6.3 Rhythm over metronome  — density presets (intimate/standard/generous)
//   §6.4 Orientation matters    — portrait/landscape pairing inside wall runs
//   §6.5 Hierarchy, carefully   — ONE focal-wall hero treatment, rest equal
//
// DEFAULT STAYS UNIFORM: a venue that declares no `placement` block renders
// exactly as before IT6 (§17 outcome: "default galleries unchanged"). Every
// capability here is opt-in per venue config (visual_config.placement) — the
// config IS the switch (§11.3 rule 2); there is no feature flag to flip.
// ─────────────────────────────────────────────────────────────────────────────

// §6.3 density characters — metres between artworks along a wall.
// 'standard' reproduces the historical CONFIG.room.artworkSpacing exactly.
export const DENSITY_PRESETS = Object.freeze({
    intimate: 2.8,   // salon-close hanging (§6.3: "intimate ~2.8 m")
    standard: 3.5,   // the historical default — calm is the brand
    generous: 4.5,   // breathing room for large-format shows (§6.3)
});

// §6.5 focal-wall convention — the four outer walls of a square room.
// (Corridor / l-shape / rotunda focal treatment is documented as not-yet —
// focal is v1 square-only; the config key is simply ignored elsewhere.)
export const FOCAL_WALLS = Object.freeze(['front', 'back', 'left', 'right']);

// Focal-hero treatment multipliers (§6.5: "larger scale with a stronger
// pool"). Deliberately modest — hierarchy is allowed, carefully.
export const FOCAL = Object.freeze({
    scaleBoost: 1.15,   // group scale for the focal hero piece
    lightBoost: 1.35,   // proximity-light max for the focal hero piece
});

// ── Density ──────────────────────────────────────────────────────────────────

// Resolve the effective wall spacing for a venue. `placement` is the venue's
// visual_config.placement object (or undefined/null). Falls back to
// `fallback` (the current CONFIG.room.artworkSpacing) when absent or unknown
// — a typo'd preset degrades to the default rhythm, never to a broken room.
export function resolveSpacing(placement, fallback) {
    const fb = typeof fallback === 'number' && fallback > 0 ? fallback : 3.5;
    if (!placement || typeof placement !== 'object') return fb;
    const d = placement.density;
    if (typeof d === 'string' && Object.prototype.hasOwnProperty.call(DENSITY_PRESETS, d)) {
        return DENSITY_PRESETS[d];
    }
    return fb;
}

// ── Orientation pairing (§6.4) ───────────────────────────────────────────────

// Orientation class mirrors makeArtworkGroup's aspect handling: aspect >= 1
// reads landscape, < 1 portrait.
export function orientationOf(img) {
    const a = Number(img && img.aspectRatio);
    if (!Number.isFinite(a) || a <= 0) return 'landscape';
    return a >= 1 ? 'landscape' : 'portrait';
}

// Stable orientation interleave. Returns a PERMUTATION of input indices
// [0..n-1] arranged landscape, portrait, landscape, … (starting with the
// majority class so a single-class run is untouched). Stability: relative
// order inside each class is preserved, and the result is fully determined
// by the input — no RNG, no locale, no Date (Iteration 0 determinism).
//
// Why stable-partition interleave and not sorting: placement order carries
// curator intent (upload order reads as a sequence); pairing only removes
// the sawtooth (L L P P L → L P L P L), it never re-orders within a class.
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

// ── Focal wall (§6.5) ────────────────────────────────────────────────────────

// Validate + read the focal wall from a placement block. Returns the wall id
// or null (no focal treatment). Unknown values degrade to null — a typo must
// never silently move the hero to an unintended wall.
export function focalWallOf(placement) {
    if (!placement || typeof placement !== 'object') return null;
    const w = placement.focal_wall;
    return FOCAL_WALLS.includes(w) ? w : null;
}

// Should the artwork being hung at `wallId` receive the focal-hero treatment?
// `heroTaken` tracks one-shot semantics: exactly ONE piece per hang gets the
// treatment (the first outer-wall piece on the focal wall); bay-hung pieces
// never qualify (bays are context, not the hero moment).
export function isFocalHero(focalWall, wallId, heroTaken) {
    if (!focalWall || heroTaken) return false;
    if (wallId == null) return false; // bay hang (no wall id) — skip
    return wallId === focalWall;
}

// ── Square rows + keep-clear (Salon iteration, pure) ─────────────────────────
//
// TWO opt-in curation keys extend the square hang to a true salon hang:
//
//   placement.salon_rows      — max hang LINES per wall (2 = classic
//                               salon hang: large works at eye, smaller
//                               works above). The row count ENGAGES only
//                               when the one-row room would breach
//                               placement.wall_length_cap — small shows
//                               keep the single-row room (bit-identical
//                               when the keys are absent).
//   placement.wall_length_cap — soft identity ceiling on the square room's
//                               wall length (metres). When the one-row
//                               sizing exceeds it and salon_rows ≥ 2, the
//                               hang wraps onto a second line instead of
//                               stretching the room.
//   placement.keep_clear      — { wall, width }: no artwork column may
//                               fall within width/2 of that wall's centre
//                               (the architectural threshold — a doorcase —
//                               stands there). The nearest slot on that
//                               wall's line is dropped; the displaced work
//                               moves to the last line with spare capacity.
//
// ALL sizing flows through ONE plan (squareRunPlan in ArtworkPlacer) so the
// room and the hang can never disagree. This helper resolves the plan
// INPUTS (rows + reserve + keep) from the venue's placement block; pure,
// deterministic, unit-testable.
//
// RESERVE rule: a keep wall with an ODD run hangs its centre slot under
// the doorcase; that work must hang somewhere, and an exactly-full room has
// nowhere to put it — so the room grows by one column in exactly that case.
// Rooms with slack absorb the displacement as-is.
export function resolveSquareHang(placement, imageCount, wallCount, spacing, minWallLength) {
    const p = (placement && typeof placement === 'object') ? placement : {};
    const capNum = Number(p.wall_length_cap);
    const cap = Number.isFinite(capNum) && capNum > 0 ? capNum : 0;
    const rowsMax = Math.max(1, Math.floor(Number(p.salon_rows)) || 1);

    const keep = p.keep_clear && typeof p.keep_clear === 'object' ? p.keep_clear : null;
    const keepWall = keep && FOCAL_WALLS.includes(keep.wall) ? keep.wall : null;
    const keepWidth = keepWall ? Math.max(0, Number(keep.width) || 0) : 0;

    // Column parity decides whether the doorcase actually steals a slot:
    // an EVEN line run has no centre column (its nearest columns stand at
    // ±spacing, outside the doorcase), an ODD run hangs one dead-centre.
    // Strictness note: only a keep width narrower than one spacing is
    // served — a wider keep degrades to "no drop" (today's behaviour)
    // rather than attempting a multi-slot steal it cannot size for.
    const minWall = (typeof minWallLength === 'number' && minWallLength > 0) ? minWallLength : 8;
    const sizeFor = (perLine) => Math.max(minWall, perLine * spacing + spacing);
    // Per-line run counts from the same ceil-split the placer uses: line i
    // holds clamp(count − i·perLine, 0, perLine) works. The door wall is the
    // SECOND wall — its lines are index 1 and (rows 2) index 5.
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
    // How many keep-wall centre slots this plan would drop, and whether the
    // room has the slack to absorb them (each drop displaces one work).
    const strandedFor = (rowsN, pl) => {
        const runs = lineRunsFor(pl);
        const keepLines = rowsN === 2 ? [1, 5] : [1];
        const drops = keepLines.filter(i => centreHitsKeep(runs[i] ?? 0)).length;
        const slack = wallCount * rowsN * pl - imageCount;
        return Math.max(0, drops - slack);
    };

    // ── Row selection ────────────────────────────────────────────────────
    // Rows engage only when the one-row room (AFTER the exact-full bump the
    // doorcase can force) would breach the declared cap — a small show keeps
    // the single-line room and the historic look.
    let rows = 1;
    let perLine = Math.ceil(imageCount / (wallCount * rows));
    if (rowsMax > 1 && cap > 0) {
        let projected = perLine + strandedFor(1, perLine);
        if (sizeFor(projected) > cap) {
            rows = rowsMax;
            perLine = Math.ceil(imageCount / (wallCount * rows));
        }
    }

    // ── Exact-full bump ──────────────────────────────────────────────────
    // Stranded displacements (drops with no slack to absorb them) grow the
    // room by one column — one bump settles it (the grown line is even when
    // the odd one was, and slack turns positive).
    if (strandedFor(rows, perLine) > 0) {
        perLine += 1;
    }

    return {
        rows,
        perLine,
        keep: keepWall ? { wall: keepWall, half: keepWidth / 2 } : null,
    };
}
