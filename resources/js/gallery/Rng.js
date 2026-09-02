// ─────────────────────────────────────────────────────────────────────────────
// Rng — deterministic seeded PRNG for procedural venue composition
//
// WHY THIS EXISTS (Iteration 0 "Honesty" / roadmap P0.3):
//   Venue props (Cathedral shards, Nebula stars, Mirror Lake mist, Garden
//   path stones, void dust) used raw Math.random() at build time. The same
//   venue rendered a different composition on EVERY load, which broke:
//     - marketing truthfulness ("the venue I chose doesn't match the still")
//     - QA / regression testing of scene composition
//     - determinism required by the roadmap's quality bar (§13.6)
//
//   All procedural venue distribution now draws from a SEEDED generator:
//       seed = xmur3(venue_slug + ':' + gallery_id)
//   Same venue + same gallery ⇒ identical composition on every load, every
//   device, every tier. A build without a gallery id (future previews,
//   local mock data) is still stable per venue via the ':venue-default' key.
//
//   Implementation: xmur3 string hash → mulberry32. Tiny, allocation-free
//   after creation, dependency-free, statistically adequate for visual
//   distribution (positions/rotations/scales/hues — not cryptography).
//
//   NOTE: Movement.js footstep playback-rate variation intentionally keeps
//   Math.random() — audio micro-variation is not venue composition and is
//   invisible to screenshots and QA.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * xmur3 — 32-bit string hash. Produces a stable uint32 seed from a string.
 */
export function hashString(str) {
    let h = 1779033703 ^ str.length;
    for (let i = 0; i < str.length; i++) {
        h = Math.imul(h ^ str.charCodeAt(i), 3432918353);
        h = (h << 13) | (h >>> 19);
    }
    h = Math.imul(h ^ (h >>> 16), 2246822507);
    h = Math.imul(h ^ (h >>> 13), 3266489909);
    h ^= h >>> 16;
    return h >>> 0;
}

/**
 * mulberry32 — fast 32-bit PRNG. Returns a function producing floats in [0, 1).
 */
export function mulberry32(seed) {
    let a = seed >>> 0;
    return function () {
        a |= 0; a = (a + 0x6D2B79F5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * Build the canonical seed source for a venue build. The same
 * `venue + gallery` pair must always produce the same string, hence the
 * same composition. Gallery id comes from the inline GALLERY_DATA payload
 * (GalleryViewController::show() already provides `id`).
 */
export function venueSeedSource(slug) {
    const galleryId = (typeof window !== 'undefined' && window.GALLERY_DATA)
        ? window.GALLERY_DATA.id
        : null;
    return galleryId != null ? `${slug}:${galleryId}` : `${slug}:venue-default`;
}

/**
 * Create the rng used by one venue structure build.
 *   rng.next()          → float in [0, 1)
 *   rng.range(min, max) → float in [min, max)
 *   rng.pick(array)     → element (array must be non-empty)
 */
export function createVenueRng(source) {
    const next = mulberry32(hashString(String(source)));
    return {
        next,
        range: (min, max) => min + next() * (max - min),
        pick: (arr) => {
            if (!Array.isArray(arr) || arr.length === 0) {
                console.warn('[Rng] pick() called with an empty collection — returning undefined');
                return undefined;
            }
            return arr[Math.floor(next() * arr.length)];
        },
    };
}
