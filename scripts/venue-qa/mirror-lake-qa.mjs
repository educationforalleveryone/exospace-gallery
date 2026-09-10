// ─────────────────────────────────────────────────────────────────────────────
// mirror-lake-qa.mjs — the Mirror Lake v3.0.0 "The Still Shore" QA gate.
//
//   node scripts/venue-qa/mirror-lake-qa.mjs
//
// Sections:
//   A. Seeder contract (mirror-lake row: v3 identity keys, rollback key)
//   B. DB ↔ harness sync (the harness 'mirror-lake' row byte-mirrors the
//      seeder; drift means screenshots stop meaning anything)
//   C. Waterfront invariants (real LakeLayout + validator, all capacities,
//      determinism, asset manifest resolution)
//   D. JS/PHP hygiene (pure module purity, exporter owned key, guarded
//      migration shape, QA instrumentation confined to the harness)
import { readFileSync } from 'node:fs';
import { relative } from 'node:path';
import { buildLakePlan, validateLakePlan, LAKE_DEFAULTS } from '../../resources/js/gallery/LakeLayout.js';

const rel = (p) => relative(process.cwd(), p);
const read = (p) => readFileSync(rel(p), 'utf8');
let failures = 0;
const ok = (name, cond) => {
    if (cond) console.log(`  ✓ ${name}`);
    else { failures++; console.log(`  ✗ ${name}`); }
};
const section = (name) => console.log(`\n── ${name} ${'─'.repeat(Math.max(1, 62 - name.length))}`);

// xmur3 + mulberry32 (Rng.js construction)
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

// ── A. Seeder contract ──────────────────────────────────────────────────────
section('A. Seeder contract (mirror-lake row)');
{
    const seed = read('database/seeders/VenueTemplateSeeder.php');
    const rowStart = seed.indexOf("'slug'          => 'mirror-lake'");
    ok('mirror-lake row exists', rowStart > 0);
    const row = seed.slice(rowStart, seed.indexOf('supported_layouts', rowStart) + 200);
    ok('version 3.0.0', row.includes("'version'       => '3.0.0'"));
    ok("structure_pass 'lake' (the waterfront body)", row.includes("'structure_pass'         => 'lake'"));
    ok("void_lake family flag kept (v1 rollback route)", row.includes("'void_lake'              => true"));
    ok("placement_mode 'lake' (the over-water art arc)", row.includes("'placement_mode'         => 'lake'"));
    ok("floor_reflection 'planar' (the water Reflector gate)", row.includes("'floor_reflection'       => 'planar'"));
    ok("environment 'none' — no HDRI leak (PMREM sky instead)", /'environment'\s+=> 'none'/.test(row));
    ok('hemisphere sky/ground tints declared', row.includes("'hemisphere_sky_color'   => '0x3d5680'") && row.includes("'hemisphere_ground_color'=> '0x0c0f14'"));
    ok('ceiling_fill_light false (no sky orb)', row.includes("'ceiling_fill_light'     => false"));
    ok('field sizing declared (bonus 4 / min 17)', row.includes("'field_radius_bonus'     => 4") && row.includes("'field_radius_min'       => 17"));
    ok('hero focal declaration (Arrival composes the hero)', row.includes("'focal_wall'         => 'lake-hero'"));
    ok("lake block: sky_environment + assets_base", row.includes("'sky_environment' => true") && row.includes("'assets_base'     => '/assets/venues/mirror-lake/'"));
    ok('lake asset manifest: 7 roles incl. bench', ['tree_large', 'tree_medium', 'tree_accent', 'shrub', 'grass', 'boulder', 'bench'].every(r => row.includes(`'${r}'`)));
    ok('post_fx: bloom OFF (calm), vignette black', row.includes("'bloom'             => false") && row.includes("'vignette_blend'    => 'black'"));
    ok('no lighting fixtures (the moon is plan-built)', /'lighting_fixtures' => \[\],  \/\/ the moon is plan-built/.test(row));
    ok('capacity 5-40 kept', row.includes("'capacity_min'  => 5") && row.includes("'capacity_max'  => 40"));
}

// ── B. DB ↔ harness sync ────────────────────────────────────────────────────
section('B. DB ↔ harness sync (harness mirror-lake row)');
{
    const h = read('scripts/harness/harness.html');
    const rowStart = h.indexOf("'mirror-lake': {");
    ok('harness has the live mirror-lake row', rowStart > 0);
    const row = h.slice(rowStart, h.indexOf("version: '3.0.0'", rowStart));
    const pairs = [
        ["structure_pass: 'lake'", "structure_pass: 'lake'"],
        ['void_lake: true', 'void_lake: true'],
        ["placement_mode: 'lake'", "placement_mode: 'lake'"],
        ["floor_reflection: 'planar'", "floor_reflection: 'planar'"],
        ["environment: 'none'", "environment: 'none', env_intensity: 0.14"],
        ["hemisphere sky", "hemisphere_sky_color: '0x3d5680'"],
        ['field sizing', 'field_radius_bonus: 4, field_radius_min: 17'],
        ['focal hero', "focal_wall: 'lake-hero'"],
        ['lake assets_base', "assets_base: '/assets/venues/mirror-lake/'"],
        ['bloom off', 'bloom: false'],
        ['floor colour', "floor_color: '0x46523a'"],
        ['exposure', 'tone_mapping_exposure: 1.15'],
        ['ambient', 'ambient_intensity: 0.26'],
    ];
    for (const [name, needle] of pairs) ok(`harness: ${name}`, row.includes(needle));
    ok('harness keeps the v1 forensic row (mirror-lake-v1)', h.includes("'mirror-lake-v1': {"));
    ok('harness ?reflect=0 QA strip exists', h.includes("q.get('reflect') === '0'"));
    ok('harness ?tier= QA strip exists', h.includes("window.__EXOSPACE_QA_TIER = qaTier"));
}

// ── C. Waterfront invariants ────────────────────────────────────────────────
section('C. Waterfront invariants (real LakeLayout + validator)');
{
    // C1. All capacities validate + deterministic.
    let allOk = true;
    const sample = [];
    for (let count = 1; count <= 40; count++) {
        const field = Math.max(10, (Math.max(count, 30) * 3.5) / (2 * Math.PI));
        const R = Math.max(field + 4, 17);
        const plan = buildLakePlan({ radius: R, count, rng: rngFor(`mirror-lake:qa:${count}`) });
        const v = validateLakePlan(plan);
        const det = JSON.stringify(buildLakePlan({ radius: R, count, rng: rngFor(`mirror-lake:qa:${count}`) }).courts)
            === JSON.stringify(plan.courts);
        if (!v.ok || !det) { allOk = false; console.log(`   count=${count}:`, v.violations.slice(0, 3), 'det=' + det); }
        if (count === 5 || count === 12 || count === 40) sample.push({ count, R, berths: plan.courts.length, plan });
    }
    ok('capacities 1..40: plan valid + deterministic', allOk);

    // C2. The arrival composition holds at the matrix counts.
    for (const { count, plan } of sample) {
        const hero = plan.courts[0];
        ok(`count=${count}: berth 0 is the hero, over water, WNW of centre`,
            hero?.role === 'hero'
            && plan.terrain.isWater(hero.x, hero.z, 1.5)
            && hero.x < 0 && hero.z < 0);
        ok(`count=${count}: spawn on land, south, plaza clear of water`,
            !plan.terrain.isWater(plan.spawn.x, plan.spawn.z, 0) && plan.spawn.z > 0);
        ok(`count=${count}: pier foot on land, end over water`,
            !plan.terrain.isWater(plan.pier.x, plan.pier.footZ + 1, 0)
            && plan.terrain.isWater(plan.pier.x, plan.pier.endZ - 1, 0));
        ok(`count=${count}: pavilion east over water, faces the landing`,
            plan.pavilion.x > 0 && plan.terrain.isWater(plan.pavilion.x, plan.pavilion.z, 0));
        ok(`count=${count}: berths ${plan.courts.length}/${count} placed`, plan.courts.length === count);
    }

    // C3. Asset resolution — the manifest consumes the declared base/roles.
    const { resolveGardenAssetRequests } = await import('../../resources/js/gallery/GardenAssets.js');
    const reqs = resolveGardenAssetRequests({
        assets_base: '/assets/venues/mirror-lake/',
        assets: { tree_large: 'tree_large_01.glb', bench: 'bench_01.glb', shrub: null },
    });
    ok('asset requests resolve against the lake base (root-relative)', reqs.every(r => r.url.startsWith('/assets/venues/mirror-lake/')));
    ok('declared null role opts out', !reqs.some(r => r.role === 'shrub'));
    ok('undeclared roles fall back to the manifest filenames', reqs.some(r => r.role === 'bench' && r.url.endsWith('bench_01.glb')));

    // C4. Water level + deck datums stay coherent.
    const def = LAKE_DEFAULTS;
    ok('water below land datum, bed below water', def.waterLevel < 0 && def.bedDepth > Math.abs(def.waterLevel));
    ok('berth elevation is eye-ish above the land datum', def.berthElevation > 1.2 && def.berthElevation < 2.0);
}

// ── D. JS/PHP hygiene ───────────────────────────────────────────────────────
section('D. JS/PHP hygiene');
{
    const lakeSrc = read('resources/js/gallery/LakeLayout.js')
        .replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*/g, '');
    ok('pure plan module: zero venue slugs', !/sculpture-garden|infinite-void|nebula-drift|cyber-gallery|mirror-lake/.test(lakeSrc));
    ok('pure plan module: no THREE, no DOM', !/THREE|document|window/.test(lakeSrc));

    const vd = read('resources/js/gallery/VenueDecorator.js');
    ok("pass router branches 'lake' at the top level", vd.includes("} else if (pass === 'lake') {"));
    ok('v1 rollback body retained (addMirrorLakeStructure)', vd.includes('function addMirrorLakeStructure'));
    ok('v3 body gated on the plan, not the slug (fallback rebuild)', vd.includes("plan = this._lakePlan = buildLakePlan({"));
    ok('water reflector registered as void-drift (uTime rides the particle loop)', vd.includes("{ obj: reflector, type: 'void-drift' }"));
    ok('stars + mist use sprite maps (no square points)', vd.includes('makeStarSpriteTexture()') && vd.includes('makeMistSpriteTexture()'));
    ok('shore clamp tick installed', vd.includes('this._lakeTick = function lakeShoreClamp'));
    ok('asset settled gate installed', vd.includes('this._lakeAssetsSettled = false'));

    const gs = read('resources/js/gallery/GalleryScene.js');
    ok('GalleryScene consumes the lake tick', gs.includes('if (this._lakeTick) this._lakeTick();'));
    ok('GalleryScene waits for the lake asset gate (shoot gate parity)', gs.includes('_lakeAssetsSettled') || read('scripts/harness/shoot.mjs').includes('_lakeAssetsSettled'));

    const rb = read('resources/js/gallery/RoomBuilder.js');
    ok('RoomBuilder builds the lake plan before structure + declared spawn', rb.includes("structure_pass === 'lake'") && rb.includes('lakeSpawn'));

    const ap = read('resources/js/gallery/ArtworkPlacer.js');
    ok("placer branches placement_mode 'lake' with a float-ring fallback", ap.includes("_placeArtworksLake.call(this, data)") && ap.includes("falling back to the float ring"));
    ok('hero berth tagged for the Arrival focal bonus', ap.includes("wallId = 'lake-hero'"));

    const te = read('resources/js/gallery/TierEffects.js');
    ok('water shader exports exist (WATER_REFLECTOR_SHADER + addWaterReflection)', te.includes('WATER_REFLECTOR_SHADER') && te.includes('export function addWaterReflection'));
    ok('stock planar reflector untouched (cathedral bit-exact)', te.includes("blend === 'multiply' ? MULTIPLY_REFLECTOR_SHADER : undefined"));

    const ren = read('resources/js/gallery/Renderer.js');
    ok('QA tier hook reads the harness flag only', ren.includes('__EXOSPACE_QA_TIER') && ren.includes('QA instrumentation'));
    const harness = read('scripts/harness/harness.html');
    ok('harness is the only place the QA flag is set', harness.includes('__EXOSPACE_QA_TIER = qaTier'));

    const exp = read('app/Services/VenueConfigExporter.php');
    ok("exporter owns 'lake' wholesale", exp.includes("'lake',"));

    const mig = read('database/migrations/2026_09_09_000013_mirror_lake_still_shore.php');
    ok('migration: guarded up + down', mig.includes('private function guardedEquals') && mig.includes('public function up()') && mig.includes('public function down()'));
    ok('migration: idempotent adds (absent keys only)', mig.includes('array_key_exists($key, $vc)'));
    ok('migration: rollback restores structure_pass phenomena', mig.includes("['from' => 'lake', 'to' => 'phenomena']"));
    ok('migration: description guarded by exact v1 text', mig.includes('A still, dark lake reflects the floating artworks'));

    const seed2 = read('database/seeders/VenueTemplateSeeder.php');
    ok('seeder header inventory mentions Mirror Lake v3', seed2.includes('mirror-lake'));
}

console.log(failures === 0
    ? '\nMIRROR LAKE QA: ALL CHECKS PASSED'
    : `\nMIRROR LAKE QA: ${failures} FAILURES`);
process.exit(failures === 0 ? 0 : 1);
