// Layout tuning constants — exported so tests pin them.
export const FLOAT_LAYOUT_DEFAULTS = Object.freeze({
    edgeInset: 1.5,
    radialWander: 1.0,
    baseHeight: 1.6,
    heightWander: 0.9,
    maxRoll: 0.06,
    depthBands: 1,
    bandGap: 3.0,
    elevationStep: 0,
    depthBandsMinCount: 12,
    bandSpacing: 3.5,
});

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

export function computeFloatLayout(count, radius, rng, opts = {}) {
    if (!Number.isFinite(count) || count <= 0) return [];
    if (!Number.isFinite(radius) || radius <= 0) return [];
    if (!rng || typeof rng.next !== 'function') {
        throw new Error('[PlacementMath] computeFloatLayout requires a seeded rng with next()');
    }

    const o = { ...FLOAT_LAYOUT_DEFAULTS, ...opts };
    const layout = [];

    const bandsWanted = Math.max(1, Math.floor(o.depthBands || 1));
    const bands = (bandsWanted > 1 && count >= o.depthBandsMinCount) ? bandsWanted : 1;

    for (let i = 0; i < count; i++) {
        const band = bands > 1 ? (i % bands) : 0;
        const posInBand = Math.floor(i / bands);   // 0..perBand-1
        const bandCount = Math.ceil((count - band) / bands);

        // Uniform angular rhythm WITHIN the band.
        const angle = (posInBand / bandCount) * Math.PI * 2
            + (band * Math.PI / bands);

        const r = (radius - o.edgeInset - band * o.bandGap)
            + (rng.next() - 0.5) * o.radialWander;

        const y = o.baseHeight + (rng.next() - 0.5) * o.heightWander + band * o.elevationStep;

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
