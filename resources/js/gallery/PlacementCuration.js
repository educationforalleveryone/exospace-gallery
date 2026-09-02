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
