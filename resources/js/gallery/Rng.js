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

export function mulberry32(seed) {
    let a = seed >>> 0;
    return function () {
        a |= 0; a = (a + 0x6D2B79F5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

export function venueSeedSource(slug) {
    const galleryId = (typeof window !== 'undefined' && window.GALLERY_DATA)
        ? window.GALLERY_DATA.id
        : null;
    return galleryId != null ? `${slug}:${galleryId}` : `${slug}:venue-default`;
}

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
