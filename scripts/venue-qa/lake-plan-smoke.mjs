// LakeLayout plan smoke test — capacities 1..40, determinism, validator.
// Run: node scripts/venue-qa/lake-plan-smoke.mjs
import { buildLakePlan, validateLakePlan } from '../../resources/js/gallery/LakeLayout.js';

// xmur3 + mulberry32 (same construction as Rng.js)
function xmur3(str) {
    let h = 1779033703 ^ str.length;
    for (let i = 0; i < str.length; i++) {
        h = Math.imul(h ^ str.charCodeAt(i), 3432918353);
        h = (h << 13) | (h >>> 19);
    }
    return () => {
        h = Math.imul(h ^ (h >>> 16), 2246822507);
        h = Math.imul(h ^ (h >>> 13), 3266489909);
        return (h ^= h >>> 16) >>> 0;
    };
}
function mulberry32(a) {
    return () => {
        a |= 0; a = (a + 0x6D2B79F5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
const rngFor = (seed) => ({ next: mulberry32(xmur3(seed)()) });

let failures = 0;
const radii = [];
for (let count = 1; count <= 40; count++) {
    const field = Math.max(10, (Math.max(count, 30) * 3.5) / (2 * Math.PI)); // legacy floor formula approximation
    const R = Math.max(field + 4, 17);
    const plan = buildLakePlan({ radius: R, count, rng: rngFor(`mirror-lake:qa:${count}`) });
    const v = validateLakePlan(plan);
    const det = JSON.stringify(buildLakePlan({ radius: R, count, rng: rngFor(`mirror-lake:qa:${count}`) }).courts)
        === JSON.stringify(plan.courts);
    radii.push([count, R, plan.courts.length]);
    if (!v.ok || !det) {
        failures++;
        console.log(`count=${count} R=${R.toFixed(1)} berths=${plan.courts.length} det=${det}`);
        for (const s of v.violations.slice(0, 6)) console.log('   -', s);
    }
}
console.log(radii.map(([c, r, b]) => `${c}:${r.toFixed(1)}→${b}`).join('  '));
console.log(failures === 0 ? 'ALL CAPACITIES VALID + DETERMINISTIC' : `${failures} FAILURES`);
process.exit(failures === 0 ? 0 : 1);
