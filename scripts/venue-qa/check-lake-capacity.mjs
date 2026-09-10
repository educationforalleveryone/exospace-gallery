// check-lake-capacity.mjs — verify berth capacity across 1..40 with proposed
// clearance values, before touching LakeLayout (pure module — runs in Node).
import { buildLakePlan, validateLakePlan } from '../../resources/js/gallery/LakeLayout.js';

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

const PIER = Number(process.argv[2] || 3.7);
const PAV = Number(process.argv[3] || 5.4);
console.log(`pierClearance=${PIER} pavilionClearance=${PAV}`);
let bad = 0;
for (let count = 1; count <= 40; count++) {
    const field = Math.max(10, (Math.max(count, 30) * 3.5) / (2 * Math.PI));
    const R = Math.max(field + 4, 17);
    const plan = buildLakePlan({
        radius: R, count, rng: rngFor(`mirror-lake:qa:${count}`),
        config: { pierClearance: PIER, pavilionClearance: PAV },
    });
    const v = validateLakePlan(plan);
    if (!v.ok || plan.courts.length !== count) {
        bad++;
        console.log(`  count=${count} R=${R.toFixed(1)}: placed ${plan.courts.length}/${count}`, v.violations.slice(0, 2));
    }
}
console.log(bad === 0 ? 'ALL CAPACITIES OK' : `${bad} capacities with shortfalls`);
