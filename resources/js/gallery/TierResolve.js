// ─────────────────────────────────────────────────────────────────────────────
// TierResolve — pure tier-fallback resolution for declared venue effects
//
// WHY THIS EXISTS (Iteration 2 "Phenomena", roadmap P1.2 / §11.3):
//   Roadmap rule: "Every venue declares tier_fallbacks in config —
//   degradation is DESIGNED, not emergent." Before this module, effect
//   quality was decided ad-hoc at build sites: Crystal Cathedral shipped
//   MeshPhysicalMaterial.transmission unconditionally, which renders NULL on
//   the mobile tier (no HDRI environment → transmission has nothing to
//   refract — verified defect), and Mirror Lake shipped roughness: 0 and
//   called it a mirror on every tier.
//
//   This module is the DECISION CORE only: it maps (declared intent from
//   venue config, device tier) → a mode keyword. It deliberately imports
//   NOTHING (not even THREE) so the decision matrix is unit-testable in
//   plain Node — the roadmap's §10.8 "cover the pure logic" requirement.
//   The material/geometry side of each decision lives in TierEffects.js.
//
// HARD RULES ENCODED HERE (§11.1–§11.3):
//   1. A declared effect degrades by DESIGN — never to a null, never to
//      "nothing rendered".
//   2. An UNDECLARED effect never appears (config is the only on-switch —
//      rollback per venue = remove the key from that venue's JSON).
//   3. No slug is ever consulted here. This module cannot know venue names.
//
// TIER MODEL (matches Renderer.js / detectLowEnd):
//   isLowEnd     → lambert-class materials, no PBR, no env, 30fps cap
//   _isMobileTier → PBR materials stay, HDRI skipped, bloom off
//   neither      → high tier (HDRI present → transmission/reflection safe)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve how a venue's glass should be built.
 *
 * @param {object} input
 * @param {boolean} input.isLowEnd      low-end device flag (Renderer.js)
 * @param {boolean} input.isMobileTier  mobile tier flag (Renderer.js)
 * @param {string}  [input.declared]    value of visual_config.glass_material
 *                                      ('transmission' = venue wants true glass)
 * @returns {'transmission'|'cheap'|'flat'|'none'}
 *   'transmission' → MeshPhysicalMaterial with transmission=… (HIGH tier only:
 *                    HDRI is guaranteed to load, so transmission can never
 *                    render null).
 *   'cheap'        → transparent MeshPhysicalMaterial WITHOUT the transmission
 *                    pass — the designed mobile fallback (§11.3 row 2). Renders
 *                    identically everywhere; never null.
 *   'flat'         → transparent Lambert — low-end.
 *   'none'         → venue did not declare glass; caller renders nothing glass.
 */
export function resolveGlassTier({ isLowEnd = false, isMobileTier = false, declared = null } = {}) {
    if (declared !== 'transmission') return 'none';

    // The null-glass guard: transmission requires a scene environment. Only
    // the high tier is guaranteed one (mobile + low-end both set _skipHdri).
    // Anything that is not provably high-tier gets the designed fallback —
    // NEVER an unverifiable bet on the environment.
    if (isLowEnd) return 'flat';
    if (isMobileTier) return 'cheap';
    return 'transmission';
}

/**
 * Resolve how a venue's declared reflective floor should be built.
 *
 * @param {object} input
 * @param {boolean} input.isLowEnd
 * @param {boolean} input.isMobileTier
 * @param {boolean} input.declared  visual_config.floor_reflection === 'planar'
 * @returns {'planar'|'gloss'|'none'}
 *   'planar' → true planar reflection (THREE.Reflector), HIGH tier only —
 *              the Mirror Lake flagship promise, kept where the GPU allows.
 *   'gloss'  → designed dark-gloss mood (§11.3 row 1): venue keeps its
 *              near-zero-roughness PBR/Lambert floor (specular moon streak
 *              from the existing moonlight) + a light-streak plane + raised
 *              mist, added by the caller. An intentional look, not a
 *              crippled planar.
 *   'none'   → venue did not declare a reflective floor.
 */
export function resolveReflectionMode({ isLowEnd = false, isMobileTier = false, declared = false } = {}) {
    if (!declared) return 'none';
    if (isLowEnd || isMobileTier) return 'gloss';
    return 'planar';
}

/**
 * Resolve whether the venue's declared floor-edge fade should render, and at
 * which quality. Used by the void-family "endless" floor treatment: the
 * ground disc must dissolve into the venue background instead of ending at a
 * visible geometric seam (§4.2 Infinite Void "floor-edge fix").
 *
 * @param {object} input
 * @param {boolean} input.isLowEnd
 * @param {boolean} input.declared  visual_config.floor_edge_fade === true
 * @returns {'shader'|'basic'|'none'}
 *   'shader' → gradient ShaderMaterial ring (high + mobile — both run real GLSL)
 *   'basic'  → flat opaque ring (low-end keeps one material class everywhere)
 *   'none'   → not declared
 */
export function resolveFloorFadeMode({ isLowEnd = false, declared = false } = {}) {
    if (!declared) return 'none';
    return isLowEnd ? 'basic' : 'shader';
}

/**
 * The placement-mode contract (§10.5): `wall | easel | float`, read from
 * venue config. Circular venues WITHOUT a declared mode keep the legacy
 * easel behaviour (sculpture garden keeps its easels BY IDENTITY, §4.10).
 *
 * @param {object} input
 * @param {string|null} input.declared   visual_config.placement_mode
 * @param {boolean}      input.circular  venue builds a circular layout
 * @returns {'wall'|'easel'|'float'}
 */
export function resolvePlacementMode({ declared = null, circular = false } = {}) {
    if (declared === 'float' || declared === 'easel') return declared;
    // Legacy default: circular venues hang on easels, everything else on walls.
    return circular ? 'easel' : 'wall';
}
