export function resolveGlassTier({ isLowEnd = false, isMobileTier = false, declared = null } = {}) {
    if (declared === 'cheap') return isLowEnd ? 'flat' : 'cheap';
    if (declared !== 'transmission') return 'none';

    if (isLowEnd) return 'flat';
    if (isMobileTier) return 'cheap';
    return 'transmission';
}

export function resolveReflectionMode({ isLowEnd = false, isMobileTier = false, declared = false } = {}) {
    if (!declared) return 'none';
    if (isLowEnd || isMobileTier) return 'gloss';
    return 'planar';
}

export function resolveFloorFadeMode({ isLowEnd = false, declared = false } = {}) {
    if (!declared) return 'none';
    return isLowEnd ? 'basic' : 'shader';
}

export function resolvePlacementMode({ declared = null, circular = false } = {}) {
    if (declared === 'float' || declared === 'easel') return declared;
    // Legacy default: circular venues hang on easels, everything else on walls.
    return circular ? 'easel' : 'wall';
}
