<?php

declare(strict_types=1);

/**
 * Luxury Penthouse identity tests — v2.1.0 "Evening Light"
 * (2026-09-09 deploy-review pass; predecessors: 2026_09_01_000003 "Rooms",
 * 2026_09_08_000005 "The Collector's Floor").
 *
 * Pins the v2.1.0 contract so the venue can never silently regress:
 *
 *   - The FULL MIGRATION CHAIN lands on the seeder row: a production venue
 *     walks v1.0.0 → 000005 (2.0.0) → 000006 (2.1.0); a fresh install
 *     seeds the final state directly. Both roads MUST end at one identity.
 *   - The evening interior: lit warm ceiling 0x5c4c3a (the black-hole
 *     ceiling is gone), the rig luminous (ambient 0.42 / hemi 0.3 / fill
 *     0.42 / exposure 0.92), dusk-haze fog 26/160.
 *   - The honed floor: roughness 0.62 / metalness 0.03 (the specular
 *     orb-matrix mirror of the v2.0.0 production deploy is gone).
 *   - The glazing is the 'cheap' open-air class (opacity 0.1, roughness
 *     0.35) — the transmission sheet that forced opacity 1.0 + threw
 *     specular orbs can never return.
 *   - The dusk sky renders: four planes (city-haze / horizon-glow /
 *     sky-mid / sky-deep) all anchored glazing_outside with turn 'out'
 *     (the v2.0.0 horizon glow was back-face culled from day one) and the
 *     skyline is three low-slung silhouette layers.
 *   - The fireplace TERMINUS: wall_end (the walk lands on it), fixtures
 *     follow (fire 5 cd + wash + two cove washes + lounge wash = 5).
 *   - Artwork legibility base 0.5 — every canvas reads along the wing.
 *   - The promise matrix: the copy names what renders.
 *   - Guarded, idempotent, reversible at EVERY chain step; admin edits
 *     survive up() and down().
 *   - Every key the row uses is venue-owned (s6) — no schema bump.
 *
 * Run: php artisan test --filter=VenuePenthouseIterationTest
 */

namespace Tests\Feature;

use App\Services\VenueConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VenuePenthouseIterationTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    // The seeder baseline — the fresh-install identity
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_seeded_row_is_the_evening_light(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $config = $this->visualConfig('luxury-penthouse');

        // The Rooms interpreter + glazing remain the mechanism.
        $this->assertSame('rooms', $config['structure_pass'], 'The descriptor interpreter remains the identity vehicle.');
        $this->assertTrue($config['glazing_wall'] ?? false, 'The glazing wall is the venue\'s view mechanism.');

        // The evening interior (D1): the ceiling is LIT plaster.
        $this->assertSame(5.2, $config['wall_height'], 'Wall height 5.2 — the volume stays.');
        $this->assertSame('0x5c4c3a', $config['ceiling_color'], 'The ceiling is lit warm plaster (the black-hole 0x14110d is gone).');

        // The sky is the city: declared absence (no HDRI download).
        $this->assertSame('none', $config['environment'], 'environment none — no HDRI download, no rural_evening 404.');
        $this->assertSame(0, $config['env_intensity']);

        // The evening rig (D1) + dusk-haze fog (D4).
        $this->assertSame('0xe9dfcd', $config['ambient_color']);
        $this->assertSame(0.42, $config['ambient_intensity'], 'Ambient 0.42 — the tan-on-black starvation is gone.');
        $this->assertSame(0.3, $config['hemisphere_intensity']);
        $this->assertSame(0.42, $config['fill_intensity'], 'The ceiling fills at 0.42.');
        $this->assertSame(0.92, $config['tone_mapping_exposure'], 'Exposure 0.92 — the evening interior reads.');
        $this->assertSame(26, $config['fog_near'], 'Dusk-haze fog (near) — distance melts, never blacks out.');
        $this->assertSame(160, $config['fog_far'], 'Dusk-haze fog (far) — the afterglow survives at depth.');
        $this->assertSame('0x191c26', $config['fog_color'], 'The fog color is dusk haze, not near-black.');

        // Artwork legibility (D1): the base sits above half the boost.
        $this->assertSame(0.5, $config['artwork_light_base'], 'Every canvas reads along the whole wing.');
        $this->assertSame(12, $config['artwork_light_pool_cap']);
        $this->assertSame(0.5, $config['spot_intensity'], 'Flatter picture lights — washes, not hotspots.');

        // The grey veil can never ship again.
        $this->assertSame(0.32, $config['post_fx']['bloom_strength'] ?? null, 'Bloom declared ON but restrained.');
        $this->assertSame('black', $config['post_fx']['vignette_blend'] ?? null, 'The vignette blends toward TRUE BLACK.');

        // Material authority (D2): the floor is HONED, not a mirror.
        $material = $this->materialConfig('luxury-penthouse');
        $this->assertSame('0xe9e2d4', $material['wall_color'], 'The walls are warm mineral white.');
        $this->assertSame('0x9b8d78', $material['floor_color'], 'The floor is honed warm stone.');
        $this->assertSame(0.62, $material['floor_roughness'], 'Honed, not polished — the specular orb-matrix is gone.');
        $this->assertSame(0.03, $material['floor_metalness'], 'Stone, not metal mirror.');
        $this->assertTrue($material['texture_tint'] ?? false);
        $this->assertSame(2.4, $material['floor_tile_meters']);

        // The descriptor payload: 47 entries with the v2.1.0 identity set.
        $structure = $config['structure'] ?? [];
        $this->assertCount(47, $structure, 'The Evening Light payload ships 47 descriptors.');
        $ids = array_column($structure, 'id');
        foreach ([
            'fireplace-stone', 'fireplace-mantel', 'fireplace-band', 'fireplace-hearth',
            'cove-left', 'cove-right', 'cove-inner', 'cove-back',
            'cove-shelf-left', 'cove-shelf-right', 'cove-shelf-inner', 'cove-shelf-back',
            'base-left', 'base-right', 'base-inner', 'base-back',
            'skyline-near', 'skyline-mid', 'skyline-far',
            'horizon-glow', 'city-haze', 'sky-mid', 'sky-deep',
            'chair-seat', 'lamp-shade', 'plinth', 'sculpture-torus', 'bench-top', 'floor-joints',
        ] as $required) {
            $this->assertContains($required, $ids, "The identity payload must include '{$required}'.");
        }

        // The terminus: the fireplace family rides the wall_end anchor (D5).
        $byId = array_column($structure, null, 'id');
        foreach (['fireplace-stone', 'fireplace-mantel', 'fireplace-band', 'fireplace-hearth'] as $id) {
            $this->assertSame('wall_end', $byId[$id]['at']['from'] ?? null, "'{$id}' rides the wall_end terminus anchor.");
        }
        // The dusk sky faces the interior (D4): turn 'out' on glazing_outside
        // (turn 'in' faces AWAY — the v2.0.0 horizon glow never rendered).
        foreach (['horizon-glow', 'city-haze', 'sky-mid', 'sky-deep'] as $id) {
            $this->assertSame('out', $byId[$id]['turn'] ?? null, "'{$id}' must face the interior (turn 'out').");
        }
        // The glazing is the cheap open-air class (D3).
        $this->assertSame('cheap', $byId['glazing-glass']['material']['tier'] ?? null, 'The glazing declares the cheap glass class (no transmission sheet).');
        $this->assertSame(0.1, $byId['glazing-glass']['material']['opacity'] ?? null);
        $this->assertSame(0.35, $byId['glazing-glass']['material']['roughness'] ?? null);

        $row = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $this->assertSame('2.1.0', $row->version, 'The deploy-review pass bumps the venue version to 2.1.0.');

        // The fixtures (D5/D6): the terminus fire + four soft washes, all
        // anchored (layout-relative — never drift).
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        $this->assertCount(5, $fixtures, 'Exactly FIVE fixtures: fire + hearth wash + two cove washes + lounge wash.');
        $byFid = array_column($fixtures, null, 'id');
        foreach (['fire-glow', 'hearth-wash', 'cove-wash-a', 'cove-wash-b', 'lounge-wash'] as $id) {
            $this->assertArrayHasKey($id, $byFid, "fixture '{$id}' ships.");
            $this->assertArrayHasKey('anchor', $byFid[$id], 'Every fixture is layout-relative.');
        }
        $this->assertSame('wall_end', $byFid['fire-glow']['anchor']['from'], 'The fire rides the wall_end terminus anchor.');
        $this->assertSame(5, $byFid['fire-glow']['intensity'], 'The fire accent is 5 cd — the 16 cd floodlight is gone.');
        $this->assertSame('wall_left', $byFid['cove-wash-a']['anchor']['from']);
        $this->assertSame('wall_inner', $byFid['cove-wash-b']['anchor']['from']);
        $this->assertSame('glazing', $byFid['lounge-wash']['anchor']['from']);
    }

    public function test_the_copy_promise_matches_the_delivered_floor(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $desc = mb_strtolower((string) DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('description'));

        // Honesty matrix: every noun the copy promises is a rendered element.
        foreach (['walnut', 'stone', 'lounge', 'glass', 'city'] as $noun) {
            $this->assertStringContainsStringIgnoringCase($noun, $desc, "Copy promises the noun '{$noun}' — the payload must render it.");
        }
        $this->assertStringNotContainsStringIgnoringCase('dark walls', $desc, 'Copy must not promise dark walls — the declared walls are warm mineral white.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The guarded migration CHAIN — production roads converge on one identity
    // ─────────────────────────────────────────────────────────────────────

    private function penthouseMigration5(): object
    {
        return require database_path('migrations/2026_09_08_000005_luxury_penthouse_residence.php');
    }

    private function penthouseMigration6(): object
    {
        return require database_path('migrations/2026_09_08_000006_luxury_penthouse_evening_light.php');
    }

    /** The v1.0.0 row exactly as production holds it pre-chain. */
    private function seedLegacyPenthouseRow(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        DB::table('venue_templates')->where('slug', 'luxury-penthouse')->update([
            'description' => 'A private collector\'s evening — a glazed wall over the city lights, a lounge by the glass, dark walls and gold frames.',
            'version'     => '1.0.0',
            'visual_config' => json_encode([
                'wall_height'            => 4.5,
                'wall_depth'             => 0.3,
                'ceiling_type'           => 'flat',
                'ceiling_color'          => '0x080808',
                'ceiling_height'         => 4.5,
                'background_color'       => '0x08090d',
                'fog_color'              => '0x08090d',
                'fog_near'               => 8,
                'fog_far'                => 25,
                'ambient_color'          => '0xb8c8e8',
                'ambient_intensity'      => 0.2,
                'spot_intensity'         => 0.5,
                'fill_intensity'         => 0.15,
                'tone_mapping_exposure'  => 0.55,
                'frame_override'         => 'gold',
                'structure_pass'         => 'rooms',
                'glazing_wall'           => true,
                // The v1.0.0 "Rooms" payload — VERBATIM (the exact-match
                // guard in 000005 compares against this 17-descriptor list;
                // it is also the harness's legacy body).
                'structure'              => [
                    ['id' => 'terrace-deck', 'primitive' => 'box', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'dark_trim'],
                    ['id' => 'glazing-glass', 'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 2.2, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 4.4], 'material' => ['glass' => true, 'tint' => '0xc4d8ea', 'opacity' => 0.18]],
                    ['id' => 'glazing-mullions', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 2.2, 0.05]], 'turn' => 'in', 'size' => [0.06, 4.4, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
                    ['id' => 'glazing-sill', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.05, 0]], 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
                    ['id' => 'glazing-head', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 4.42, 0]], 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
                    ['id' => 'rail-bar', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.95, 0.55]], 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
                    ['id' => 'rail-posts', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
                    ['id' => 'skyline-cool', 'primitive' => 'instance-grid', 'size' => [2.2, 10, 2.2], 'material' => 'tower_cool', 'grid' => ['mode' => 'scatter', 'count' => 9, 'seed' => 'skyline-cool', 'area' => ['from' => 'glazing_outside', 'size' => [26, 0, 30], 'forward' => 5], 'scale_jitter' => [0.55, 2.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
                    ['id' => 'skyline-warm', 'primitive' => 'instance-grid', 'size' => [1.5, 14, 1.5], 'material' => 'tower_warm', 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-warm', 'area' => ['from' => 'glazing_outside', 'size' => [26, 0, 30], 'forward' => 9], 'scale_jitter' => [0.5, 1.7], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
                    ['id' => 'lounge-pendant', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [0, 3.55, 1.75]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 2.6, 'size' => [1, 0.045, 0.045], 'material' => ['color' => '0xffd9a8', 'emissive' => '0xffc98a', 'emissiveIntensity' => 2.2]],
                    ['id' => 'lounge-rug', 'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 0.012, 2.0]], 'turn' => 'in', 'rot' => [-1.5707963, 0, 0], 'size' => [3.2, 2.3], 'material' => 'fabric_dark'],
                    ['id' => 'sofa-base', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.24, 1.75]], 'turn' => 'in', 'size' => [2.3, 0.48, 0.95], 'material' => 'fabric_warm', 'collide' => true],
                    ['id' => 'sofa-back', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.72, 2.15]], 'turn' => 'in', 'size' => [2.3, 0.5, 0.24], 'material' => 'fabric_warm'],
                    ['id' => 'sofa-arm-l', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
                    ['id' => 'sofa-arm-r', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
                    ['id' => 'table-top', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.34, 0.9]], 'turn' => 'in', 'size' => [1.15, 0.05, 0.55], 'material' => 'wood_dark'],
                    ['id' => 'table-pedestal', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.15, 0.9]], 'turn' => 'in', 'size' => [0.5, 0.3, 0.35], 'material' => 'dark_trim', 'collide' => true],
                ],
            ]),
            'material_config' => json_encode([
                'wall_color'            => null,
                'wall_roughness'        => 0.8,
                'wall_metalness'        => 0.0,
                'wall_normal_strength'  => 0.3,
                'floor_color'           => null,
                'floor_roughness'       => 0.3,
                'floor_metalness'       => 0.2,
                'floor_normal_strength' => 0.5,
            ]),
            'lighting_fixtures' => json_encode([]),
        ]);
    }

    public function test_the_full_migration_chain_lands_the_exact_seeder_state(): void
    {
        // Production path: v1.0.0 → 000005 (2.0.0) → 000006 (2.1.0).
        // Fresh-install path: the seeder ships the final state directly.
        // Both roads MUST end at the same identity.
        $this->seedLegacyPenthouseRow();
        $this->penthouseMigration5()->up();

        $mid = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $this->assertSame('2.0.0', $mid->version, 'The first pass lands on v2.0.0 (the chain intermediate).');

        $this->penthouseMigration6()->up();

        $migrated = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();

        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $seeded = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();

        // Canonical comparison: JSON object key ORDER legitimately differs.
        $this->assertSame(
            $this->canonicalJson($seeded->visual_config),
            $this->canonicalJson($migrated->visual_config),
            'The FULL chain final state must equal the seeder baseline (visual_config).'
        );
        $this->assertSame(
            $this->canonicalJson($seeded->material_config),
            $this->canonicalJson($migrated->material_config),
            'The FULL chain final state must equal the seeder baseline (material_config).'
        );
        $this->assertSame(
            $this->canonicalJson($seeded->lighting_fixtures),
            $this->canonicalJson($migrated->lighting_fixtures),
            'The FULL chain final state must equal the seeder baseline (lighting_fixtures).'
        );
        $this->assertSame($seeded->description, $migrated->description, 'Descriptions converge.');
        $this->assertSame('2.1.0', $migrated->version, 'The chain lands on v2.1.0.');
        $this->assertSame($seeded->version, $migrated->version, 'Versions converge.');
    }

    public function test_the_v21_pass_is_guarded_idempotent_and_reversible(): void
    {
        // Build the v2.0.0 state the way production has it: through pass 5.
        $this->seedLegacyPenthouseRow();
        $this->penthouseMigration5()->up();

        // An admin retune that must survive the pass (guarded swap):
        // ambient differs from the migration's declared 0.26→0.42 pair.
        DB::table('venue_templates')->where('slug', 'luxury-penthouse')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('luxury-penthouse'), [
                'ambient_intensity' => 0.5, // the admin owns it now
            ])),
            'version' => '2.0.0',
        ]);

        $migration = $this->penthouseMigration6();
        $migration->up();

        $config = $this->visualConfig('luxury-penthouse');
        $this->assertSame(0.5, $config['ambient_intensity'], 'Admin-tuned values are never overwritten (guarded swap).');
        $this->assertSame('0x5c4c3a', $config['ceiling_color'] ?? null, 'The lit ceiling still arrives around the admin edit.');
        $this->assertSame('cheap', $config['structure'][1]['material']['tier'] ?? null, 'The cheap glass still arrives (exact-match structure swap).');

        // Idempotence: a second run changes nothing.
        $before = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $migration->up();
        $after = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $this->assertSame($before->visual_config, $after->visual_config, 'Re-running the migration rewrites nothing.');

        // Reversibility: down() restores migration-owned values only.
        $migration->down();
        $rolled = $this->visualConfig('luxury-penthouse');
        $this->assertSame(0.5, $rolled['ambient_intensity'] ?? null, 'down() preserves the admin edit.');
        $this->assertSame('0x14110d', $rolled['ceiling_color'] ?? null, 'down() restores the v2.0.0 ceiling.');
        $this->assertSame(0.42, $rolled['fog_far'] ?? null, 'down() restores the v2.0.0 fog depth.');
        $this->assertCount(40, $rolled['structure'] ?? [], 'down() restores the v2.0.0 payload.');
        $fixturesRolled = json_decode((string) DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('lighting_fixtures'), true) ?: [];
        $this->assertCount(2, $fixturesRolled, 'down() restores the v2.0.0 fixture pair.');
        $this->assertSame('2.0.0', DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('version'), 'down() restores the v2.0.0 version.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The client payload — the Evening Light must reach preview AND public
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_payload_carries_the_evening_light_to_the_client(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue  = \App\Models\VenueTemplate::where('slug', 'luxury-penthouse')->firstOrFail();
        $config = app(VenueConfigExporter::class)->forVenuePreview($venue);

        $visual = $config['visual_config'] ?? [];
        $this->assertSame('0x5c4c3a', $visual['ceiling_color'] ?? null, 'The lit ceiling reaches the viewer payload.');
        $this->assertSame(0.42, $visual['ambient_intensity'] ?? null, 'The evening rig reaches the payload.');
        $this->assertSame(0.5, $visual['artwork_light_base'] ?? null, 'The legibility floor reaches the payload.');
        $this->assertSame('black', $visual['post_fx']['vignette_blend'] ?? null, 'The declared post-fx reaches the payload.');
        $this->assertCount(47, $visual['structure'] ?? [], 'The descriptor payload reaches the viewer.');
        $this->assertSame(0.62, $config['material_config']['floor_roughness'] ?? null, 'The honed floor reaches the payload.');
        $this->assertTrue($config['material_config']['texture_tint'] ?? false, 'The tint authority reaches the payload.');
    }

    public function test_the_penthouse_architecture_is_venue_owned(): void
    {
        // Every key this pass uses is ALREADY venue-owned (s6) — a stale
        // gallery override can never reshape the architecture, and no
        // schema bump was needed (sibling venues untouched).
        foreach (['structure', 'glazing_wall', 'post_fx', 'environment', 'env_intensity',
            'artwork_light_base', 'artwork_light_pool_cap', 'hemisphere_intensity',
            'wall_height', 'ceiling_color', 'ambient_color', 'fog_near', 'fog_far',
            'fog_color', 'spot_intensity', 'fill_intensity', 'tone_mapping_exposure'] as $key) {
            $this->assertContains($key, VenueConfigExporter::VENUE_OWNED_VISUAL_KEYS, "'{$key}' is venue-owned architecture/rig identity.");
        }
        foreach (['texture_tint', 'wall_color', 'floor_color', 'floor_tile_meters', 'floor_roughness', 'floor_metalness'] as $key) {
            $this->assertContains($key, VenueConfigExporter::VENUE_OWNED_MATERIAL_KEYS, "material '{$key}' is venue-owned.");
        }

        // End-to-end: a gallery carrying a saved override layer renders the
        // VENUE's architecture, not the override.
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue   = \App\Models\VenueTemplate::where('slug', 'luxury-penthouse')->firstOrFail();
        $owner   = \App\Models\User::factory()->create(['plan' => 'pro']);
        $gallery = \App\Models\Gallery::factory()->create([
            'user_id'           => $owner->id,
            'venue_template_id' => $venue->id,
            'visual_overrides'  => [
                'visual_config' => [
                    'wall_height'   => 3.0,
                    'structure'     => [],
                    'environment'   => 'studio',
                ],
            ],
        ]);

        $payload = app(VenueConfigExporter::class)->forGallery($gallery->refresh());

        $this->assertSame(5.2, $payload['visual_config']['wall_height'] ?? null, 'A curator-saved height override is stripped — the venue declaration wins.');
        $this->assertCount(47, $payload['visual_config']['structure'] ?? [], 'A curator-saved empty structure is stripped — the residence cannot be hollowed out.');
        $this->assertSame('none', $payload['visual_config']['environment'] ?? null, 'A curator-saved sky override is stripped — the sky is the city.');
    }

    // ─────────────────────────────────────────────────────────────────────

    private function visualConfig(string $slug): array
    {
        return json_decode((string) DB::table('venue_templates')->where('slug', $slug)->value('visual_config'), true) ?: [];
    }

    private function materialConfig(string $slug): array
    {
        return json_decode((string) DB::table('venue_templates')->where('slug', $slug)->value('material_config'), true) ?: [];
    }

    /** Recursively key-sort a JSON blob so identity comparisons ignore order. */
    private function canonicalJson(mixed $json): string
    {
        $data = is_string($json) ? (json_decode((string) $json, true) ?: []) : $json;

        $sort = function (&$array) use (&$sort): void {
            if (is_array($array)) {
                ksort($array);
                array_walk($array, $sort);
            }
        };
        $sort($data);

        return json_encode($data);
    }
}
