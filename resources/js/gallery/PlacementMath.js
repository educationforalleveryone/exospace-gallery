// ─────────────────────────────────────────────────────────────────────────────
// PlacementMath — pure placement-layout math (no THREE, no DOM)
//
// WHY THIS EXISTS (Iteration 2 "Phenomena", roadmap P1.2 / §10.5):
//   The void venues promise "floating artworks". The physical hang (easel
//   under every canvas) lives in ArtworkPlacer, but the LAYOUT — where each
//   piece hovers in space — is pure math and belongs in a pure module:
//   roadmap §10.8 names "placement math (given artwork list + mode + layout →
//   positions deterministic)" as the first thing the 3D runtime unit-tests.
//
// DESIGN CONTRACT
// ---------------
//   • Deterministic: identical (count, radius, rng state) → identical layout.
//     The rng passed in is the venue's seeded generator (Rng.js, seed =
//     slug:galleryId) — never Math.random.
//   • Calm by default: pieces keep the uniform angular ring the brand's
//     spacing is built on (§5.6, DO-NOT-DO #6 — placement stays "fair",
//     not "smart"). Only radius, height and roll carry seeded variation.
//   • Legible: heights stay within a narrow band around eye level so no
//     artwork is ever a ceiling piece or a floor piece.
//   • Facing: the caller (ArtworkPlacer) turns each group toward the ring
//     center with the same lookAt() the easel ring uses — this module only
//     computes positions + roll.
// ─────────────────────────────────────────────────────────────────────────────

// Layout tuning constants — exported so tests pin them.
export const FLOAT_LAYOUT_DEFAULTS = Object.freeze({
    // artworks stand at radius - 1.5 on the easel ring; float mode shares the
    // same walkway so sightlines and collision bounds stay identical.
    edgeInset: 1.5,
    // seeded radial wander (± half-range, metres) — organic, but small enough
    // that no piece crosses the player bounds (radius - 0.5).
    radialWander: 1.0,
    // hover band around eye level (metres). 1.6 ± 0.45 keeps every canvas
    // readable without looking up or crouching.
    baseHeight: 1.6,
    heightWander: 0.9,
    // max roll around the view axis (radians, ± ) — a hand-hung feel, not a
    // funhouse. ~3.4°.
    maxRoll: 0.06,
    // Depth separation (§7 presentation language for float venues).
    // depthBands > 1 composes the hang in DEPTH: outer band keeps the
    // historic ring, inner bands step `bandGap` metres toward the centre so
    // walking reveals parallax and the collection reads as a constellation
    // instead of a fence. A venue opts in per config
    // (visual_config.placement.depth_bands); the default 1 reproduces the
    // single-ring layout bit-exactly.
    depthBands: 1,
    bandGap: 3.0,
    // Bands activate only past this count — a 6-piece show stays a calm
    // single ring; a 30-piece show gains its depth composition.
    depthBandsMinCount: 12,
    // Spacing honoured per band (metres of arc per artwork) — bands pack at
    // the brand rhythm so wide frames never overlap on an inner ring.
    bandSpacing: 3.5,
});

/**
 * Pure radius planner for a banded float field — RoomBuilder and this
 * module must NEVER disagree on the ring geometry, so both call this.
 *
 * bands = 1 reproduces the historical circular formula exactly:
 *   radius = max(10, max(count*spacing, 30) / (2π))
 *
 * bands ≥ 2 sizes the field for ceil(count/bands) works per ring plus
 * (bands−1) ring gaps:
 *   perBand  = ceil(count / bands)
 *   innerR   = max(6, perBand * bandSpacing / (2π))
 *   radius   = innerR + (bands − 1) * bandGap + edgeInset + 0.5
 * (so the OUTER ring lands at radius − edgeInset ≥ innerR + (bands−1)·gap,
 *  every band clears its arc-length spacing, and the walkway survives).
 *
 * @returns {{radius:number, bands:number, perBand:number}}
 */
export function computeFloatFieldRadius(count, spacing, opts = {}) {
    const o = { ...FLOAT_LAYOUT_DEFAULTS, bandSpacing: spacing, ...opts };
    const countSafe = Math.max(1, Math.floor(count) || 1);
    const bandsWanted = Math.max(1, Math.floor(o.depthBands || 1));
    const bands = (bandsWanted > 1 && countSafe >= o.depthBandsMinCount)
        ? bandsWanted
        : 1;

    if (bands === 1) {
        // Legacy path — byte-identical to the pre-banding circular builder.
        const circumference = Math.max(countSafe * o.bandSpacing, 30);
        return { radius: Math.max(10, circumference / (2 * Math.PI)), bands: 1, perBand: countSafe };
    }

    const perBand = Math.ceil(countSafe / bands);
    const innerR  = Math.max(6, (perBand * o.bandSpacing) / (2 * Math.PI));
    const radius  = innerR + (bands - 1) * o.bandGap + o.edgeInset + 0.5;
    return { radius, bands, perBand };
}

/**
 * Compute the float layout for n artworks on a circular floor of `radius`.
 *
 * @param {number} count   number of artworks
 * @param {number} radius  circular floor radius (metres)
 * @param {object} rng     seeded venue rng (Rng.js contract: next())
 * @param {object} [opts]  overrides for FLOAT_LAYOUT_DEFAULTS (tests only)
 * @returns {Array<{x:number, y:number, z:number, roll:number}>}
 */
export function computeFloatLayout(count, radius, rng, opts = {}) {
    if (!Number.isFinite(count) || count <= 0) return [];
    if (!Number.isFinite(radius) || radius <= 0) return [];
    if (!rng || typeof rng.next !== 'function') {
        throw new Error('[PlacementMath] computeFloatLayout requires a seeded rng with next()');
    }

    const o = { ...FLOAT_LAYOUT_DEFAULTS, ...opts };
    const layout = [];

    // How many bands are actually active for THIS hang (same rule as the
    // radius planner — the two can never disagree).
    const bandsWanted = Math.max(1, Math.floor(o.depthBands || 1));
    const bands = (bandsWanted > 1 && count >= o.depthBandsMinCount) ? bandsWanted : 1;

    // Per-band geometry. Band 0 is the historic outer ring; inner bands step
    // `bandGap` toward the centre. Each band lays its works out on a uniform
    // angular rhythm (counting only ITS OWN members), so per-band density
    // stays at the brand spacing and no two works in one band ever share an
    // angle. Band membership is round-robin (i % bands): orientations mix
    // across depths instead of sorting the hang into portrait/landscape
    // islands, and the assignment is a pure function of the index.

    for (let i = 0; i < count; i++) {
        const band = bands > 1 ? (i % bands) : 0;
        const posInBand = Math.floor(i / bands);   // 0..perBand-1
        // Round-robin membership: band b holds works b, b+bands, b+2·bands…
        // → its size is ceil((count − b) / bands). (A plain `perBand` would
        // over-open the angular rhythm on the smaller last band and cram
        // its works into an arc.)
        const bandCount = Math.ceil((count - band) / bands);

        // Uniform angular rhythm WITHIN the band.
        const angle = (posInBand / bandCount) * Math.PI * 2
            // bands interleave their start angles so inner works sit in the
            // GAPS of the outer band (constellation, not a target).
            + (band * Math.PI / bands);

        // Seeded radial wander: stays inside the walkable bounds.
        // Worst case (outer band): (radius - edgeInset) + radialWander/2
        //           = radius - 1.5 + 0.5 = radius - 1.0  <  radius - 0.5 ✓
        const r = (radius - o.edgeInset - band * o.bandGap)
            + (rng.next() - 0.5) * o.radialWander;

        // Seeded hover height inside the legibility band.
        const y = o.baseHeight + (rng.next() - 0.5) * o.heightWander;

        // Seeded roll around the view axis.
        const roll = (rng.next() - 0.5) * 2 * o.maxRoll;

        layout.push({
            x: Math.sin(angle) * r,
            y,
            z: Math.cos(angle) * r,
            roll,
        });
    }

    return layout;
}
