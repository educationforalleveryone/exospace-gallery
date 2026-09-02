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
});

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

    for (let i = 0; i < count; i++) {
        // Uniform angular ring — the calm, fair spacing the brand keeps.
        const angle = (i / count) * Math.PI * 2;

        // Seeded radial wander: stays inside the walkable bounds.
        // Worst case: (radius - edgeInset) + radialWander/2
        //           = radius - 1.5 + 0.5 = radius - 1.0  <  radius - 0.5 ✓
        const r = radius - o.edgeInset + (rng.next() - 0.5) * o.radialWander;

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
