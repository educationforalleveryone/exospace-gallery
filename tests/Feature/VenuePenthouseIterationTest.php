<?php

declare(strict_types=1);

/**
 * Luxury Penthouse identity tests — v3.0.0 "The Double Volume"
 * (2026-09-09 full redesign; predecessors: 2026_09_01_000003 "Rooms",
 * 2026_09_08_000005 "The Collector's Floor", 2026_09_08_000006 "Evening Light").
 *
 * Pins the v3.0.0 contract so the venue can never silently regress:
 *
 *   - The FULL MIGRATION CHAIN lands on the seeder row: a production venue
 *     walks v1.0.0 → 000005 (2.0.0) → 000006 (2.1.0) → 000007 (3.0.0); a
 *     fresh install seeds the final state directly. Both roads MUST end at
 *     one identity.
 *   - THE DOUBLE VOLUME: wing_heights { gallery 3.55 / volume 6.3 } under
 *     the 6.3 nominal — the procession/gallery band is LOW, the living
 *     volume is DOUBLE-HIGH, and the seam between them is the venue's
 *     dominant spatial idea.
 *   - THE GLASS CORNER: glazing_walls opens BOTH faces (wing_b_end +
 *     wing_b_north) while glazing_wall stays true for square-layout
 *     galleries; the fireplace pier (wall_end) stays solid inside the
 *     north glass run.
 *   - THE SEAM: the junction family (fascia + full-width lit slot + the
 *     axis sculpture) is declared; the fireplace surface is hangable ABOVE
 *     the fire (y 3.25) and the walnut art wall (wall_left_high, y 2.6)
 *     carries the second statement work — the residential hang.
 *   - The evening interior: lit warm ceiling 0x5c4c3a, the rig luminous
 *     (ambient 0.42 / hemi 0.3 / fill 0.42 / exposure 0.92), dusk-haze fog
 *     26/160, the honed floor (0.62/0.03), the cheap-class glazing on BOTH
 *     faces, bronze frames (gold read as decoration), artwork legibility
 *     base 0.5, black-blend vignette.
 *   - The promise matrix: the copy names what renders.
 *   - Guarded, idempotent, reversible at EVERY chain step; admin edits
 *     survive up() and down().
 *   - The architecture is venue-owned (s7: wing_heights + glazing_walls
 *     joined the owned set) — a stale gallery override can never recompose
 *     the building.
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

    public function test_the_seeded_row_is_the_double_volume(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $config = $this->visualConfig('luxury-penthouse');

        // The Rooms interpreter + glazing remain the mechanism.
        $this->assertSame('rooms', $config['structure_pass'], 'The descriptor interpreter remains the identity vehicle.');
        $this->assertTrue($config['glazing_wall'] ?? false, 'The legacy glazing declaration stays (square-layout galleries).');

        // THE DOUBLE VOLUME (A1): the declared vertical split.
        $this->assertSame(['wing_a' => 3.55, 'wing_b' => 6.3], $config['wing_heights'] ?? null,
            'The venue declares the gallery↔volume split: 3.55 m procession, 6.3 m living volume.');
        $this->assertSame(6.3, $config['wall_height'], 'The NOMINAL height is the living volume.');
        $this->assertSame(6.3, $config['ceiling_height']);
        $this->assertSame('0x5c4c3a', $config['ceiling_color'], 'The ceiling is lit warm plaster.');

        // THE GLASS CORNER (A2): both faces declared.
        $this->assertSame(['wing_b_end', 'wing_b_north'], $config['glazing_walls'] ?? null,
            'The venue opens the wing B end AND the wing B north run as glass.');

        // The sky is the city: declared absence (no HDRI download).
        $this->assertSame('none', $config['environment'], 'environment none — no HDRI download, no rural_evening 404.');
        $this->assertSame(0, $config['env_intensity']);

        // The evening rig + dusk-haze fog.
        $this->assertSame('0xe9dfcd', $config['ambient_color']);
        $this->assertSame(0.42, $config['ambient_intensity']);
        $this->assertSame(0.3, $config['hemisphere_intensity']);
        $this->assertSame(0.42, $config['fill_intensity']);
        $this->assertSame(0.92, $config['tone_mapping_exposure'], 'Exposure 0.92 — the evening interior reads.');
        $this->assertSame(26, $config['fog_near'], 'Dusk-haze fog (near) — distance melts, never blacks out.');
        $this->assertSame(160, $config['fog_far'], 'Dusk-haze fog (far) — the afterglow survives at depth.');
        $this->assertSame('0x191c26', $config['fog_color'], 'The fog color is dusk haze, not near-black.');

        // Artwork legibility: the base sits above half the boost.
        $this->assertSame(0.5, $config['artwork_light_base'], 'Every canvas reads along the whole procession.');
        $this->assertSame(12, $config['artwork_light_pool_cap']);
        $this->assertSame(0.5, $config['spot_intensity']);

        // The grey veil can never ship again.
        $this->assertSame(0.32, $config['post_fx']['bloom_strength'] ?? null, 'Bloom declared ON but restrained.');
        $this->assertSame('black', $config['post_fx']['vignette_blend'] ?? null, 'The vignette blends toward TRUE BLACK.');

        // Bronze frames — gold read as decoration (the brief's material law).
        $this->assertSame('bronze', $config['frame_override'] ?? null, 'Frames are bronze — the room hardware line.');

        // Material authority: the floor is HONED, not a mirror.
        $material = $this->materialConfig('luxury-penthouse');
        $this->assertSame('0xe9e2d4', $material['wall_color'], 'The walls are warm mineral white.');
        $this->assertSame('0x9b8d78', $material['floor_color'], 'The floor is honed warm stone.');
        $this->assertSame(0.62, $material['floor_roughness'], 'Honed, not polished — the specular orb-matrix is gone.');
        $this->assertSame(0.03, $material['floor_metalness'], 'Stone, not metal mirror.');
        $this->assertTrue($material['texture_tint'] ?? false);
        $this->assertSame(2.4, $material['floor_tile_meters']);

        // The descriptor payload: 61 entries with the v3.0.0 identity set.
        $structure = $config['structure'] ?? [];
        $this->assertCount(61, $structure, 'The Double Volume payload ships 61 descriptors.');
        $ids = array_column($structure, 'id');
        foreach ([
            // the seam
            'step-fascia', 'step-slot', 'seam-slot-inner', 'plinth', 'sculpture-knot',
            // the fireplace pier + the art wall
            'fireplace-stone', 'fireplace-mantel', 'fireplace-band', 'fireplace-hearth', 'art-wall-panel',
            // both glazing faces
            'glazing-glass', 'glazing-mullions', 'glazing-sill', 'glazing-head',
            'glazing-glass-north', 'glazing-mullions-north', 'glazing-sill-north', 'glazing-head-north',
            // the terrace corner
            'terrace-deck-east', 'terrace-deck-north', 'rail-bar-east', 'rail-bar-north',
            // two dusk cities
            'skyline-near-east', 'skyline-mid-east', 'skyline-far-east',
            'skyline-near-north', 'skyline-mid-north', 'skyline-far-north',
            'horizon-glow-east', 'horizon-glow-north',
            // the gallery band
            'cove-left', 'cove-right', 'cove-front',
            'base-left', 'base-right', 'base-front', 'base-inner',
            'bench-top', 'floor-joints', 'lounge-pendant',
        ] as $required) {
            $this->assertContains($required, $ids, "The identity payload must include '{$required}'.");
        }
        // The retired v2.1 pieces must not ride along.
        foreach (['sculpture-torus', 'city-haze', 'cove-inner', 'cove-back', 'base-back'] as $retired) {
            $this->assertNotContains($retired, $ids, "'{$retired}' is retired by The Double Volume.");
        }

        // The seam family rides the junction anchors (A3).
        $byId = array_column($structure, null, 'id');
        foreach (['step-fascia', 'plinth', 'sculpture-knot'] as $id) {
            $this->assertSame('junction', $byId[$id]['at']['from'] ?? null, "'{$id}' rides the junction (seam) anchor.");
        }
        $this->assertSame('junction_outside', $byId['step-slot']['at']['from'] ?? null, 'The lit slot rides the gallery side of the seam.');
        $this->assertSame('wall_inner', $byId['seam-slot-inner']['at']['from'] ?? null, 'The slot continues along wing B\'s south wall.');

        // The terminus: the fireplace family rides the wall_end anchor (v2.1, kept).
        foreach (['fireplace-stone', 'fireplace-mantel', 'fireplace-band', 'fireplace-hearth'] as $id) {
            $this->assertSame('wall_end', $byId[$id]['at']['from'] ?? null, "'{$id}' rides the wall_end terminus anchor.");
        }
        // The pier spans the full volume height and is hangable ABOVE the fire.
        $this->assertSame(6.3, $byId['fireplace-stone']['size'][1] ?? null, 'The pier spans 6.3 m.');
        $this->assertSame(['y' => 3.25], $byId['fireplace-stone']['hangable'] ?? null,
            'The pier registers its hang surface above the mantel (the statement work lives here).');
        $this->assertSame(['y' => 2.6], $byId['art-wall-panel']['hangable'] ?? null,
            'The walnut art wall registers its hang surface.');

        // The second face family rides glazing_north.
        foreach (['glazing-glass-north', 'glazing-mullions-north', 'glazing-sill-north', 'glazing-head-north'] as $id) {
            $this->assertSame('glazing_north', $byId[$id]['at']['from'] ?? null, "'{$id}' rides the second glazed face.");
        }

        // Both dusk skies face the interior (turn 'out' on the *_outside anchors).
        foreach (['horizon-glow-east', 'horizon-glow-north'] as $id) {
            $this->assertSame('out', $byId[$id]['turn'] ?? null, "'{$id}' must face the interior (turn 'out').");
        }

        // The glazing is the cheap open-air class on BOTH faces (v2.1 D3, kept).
        foreach (['glazing-glass', 'glazing-glass-north'] as $id) {
            $this->assertSame('cheap', $byId[$id]['material']['tier'] ?? null, "'{$id}' declares the cheap glass class (no transmission sheet).");
            $this->assertSame(0.1, $byId[$id]['material']['opacity'] ?? null);
            $this->assertSame(0.35, $byId[$id]['material']['roughness'] ?? null);
        }

        $row = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $this->assertSame('3.0.0', $row->version, 'The redesign bumps the venue version to 3.0.0.');

        // The fixtures: the SAME five-light budget re-aimed at the double volume.
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        $this->assertCount(5, $fixtures, 'Exactly FIVE fixtures: fire + hearth wash + seam wash + gallery cove wash + lounge wash.');
        $byFid = array_column($fixtures, null, 'id');
        foreach (['fire-glow', 'hearth-wash', 'step-wash', 'gallery-cove-wash', 'lounge-wash'] as $id) {
            $this->assertArrayHasKey($id, $byFid, "fixture '{$id}' ships.");
            $this->assertArrayHasKey('anchor', $byFid[$id], 'Every fixture is layout-relative.');
        }
        $this->assertSame('wall_end', $byFid['fire-glow']['anchor']['from'], 'The fire rides the wall_end terminus anchor.');
        $this->assertSame('junction', $byFid['step-wash']['anchor']['from'], 'The seam wash lights the step from the volume side.');
        $this->assertSame('wall_left', $byFid['gallery-cove-wash']['anchor']['from'], 'The gallery cove wash keeps the procession lit.');
        $this->assertSame('glazing', $byFid['lounge-wash']['anchor']['from']);
    }

    public function test_the_copy_promise_matches_the_delivered_floor(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $desc = mb_strtolower((string) DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('description'));

        // Honesty matrix: every noun the copy promises is a rendered element.
        foreach (['two volumes', 'procession', 'seam', 'double-height', 'two faces', 'fireplace', 'terrace', 'city'] as $noun) {
            $this->assertStringContainsStringIgnoringCase($noun, $desc, "Copy promises the noun '{$noun}' — the payload must render it.");
        }
        $this->assertStringNotContainsStringIgnoringCase('gold', $desc, 'Copy must not promise gold — the frames are bronze by declaration.');
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

    private function penthouseMigration7(): object
    {
        return require database_path('migrations/2026_09_09_000007_luxury_penthouse_double_volume.php');
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
        // Production path: v1.0.0 → 000005 (2.0.0) → 000006 (2.1.0)
        //   → 000007 (3.0.0). Fresh-install path: the seeder ships the final
        //     state directly. Both roads MUST end at the same identity.
        $this->seedLegacyPenthouseRow();
        $this->penthouseMigration5()->up();

        $mid = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $this->assertSame('2.0.0', $mid->version, 'The first pass lands on v2.0.0 (the chain intermediate).');

        $this->penthouseMigration6()->up();
        $this->assertSame('2.1.0', DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('version'),
            'The second pass lands on v2.1.0 (the chain intermediate).');

        $this->penthouseMigration7()->up();

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
        $this->assertSame('3.0.0', $migrated->version, 'The chain lands on v3.0.0.');
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

    public function test_the_v3_pass_is_guarded_idempotent_and_reversible(): void
    {
        // Build the v2.1.0 state the way production has it: through 5 + 6.
        $this->seedLegacyPenthouseRow();
        $this->penthouseMigration5()->up();
        $this->penthouseMigration6()->up();

        // An admin retune that must survive the pass (guarded swap):
        // exposure differs from the migration's declared 0.92 pair.
        DB::table('venue_templates')->where('slug', 'luxury-penthouse')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('luxury-penthouse'), [
                'tone_mapping_exposure' => 1.05, // the admin owns it now
            ])),
            'version' => '2.1.0',
        ]);

        $migration = $this->penthouseMigration7();
        $migration->up();

        $config = $this->visualConfig('luxury-penthouse');
        $this->assertSame(1.05, $config['tone_mapping_exposure'], 'Admin-tuned values are never overwritten (guarded swap).');
        $this->assertSame(['wing_a' => 3.55, 'wing_b' => 6.3], $config['wing_heights'] ?? null, 'The double volume arrives around the admin edit.');
        $this->assertSame(['wing_b_end', 'wing_b_north'], $config['glazing_walls'] ?? null, 'The glass corner arrives.');
        $this->assertSame(6.3, $config['wall_height'] ?? null, 'The nominal height arrives (guarded changed value).');
        $byId = array_column($config['structure'] ?? [], null, 'id');
        $this->assertArrayHasKey('step-fascia', $byId, 'The seam arrives (exact-match structure swap).');
        $this->assertSame(['y' => 3.25], $byId['fireplace-stone']['hangable'] ?? null, 'The above-the-fire hang arrives.');

        // Idempotence: a second run changes nothing.
        $before = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $migration->up();
        $after = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $this->assertSame($before->visual_config, $after->visual_config, 'Re-running the migration rewrites nothing.');
        $this->assertSame($before->lighting_fixtures, $after->lighting_fixtures, 'Re-running rewrites no fixtures.');
        $this->assertSame($before->description, $after->description, 'Re-running rewrites no copy.');

        // Reversibility: down() restores migration-owned values only.
        $migration->down();
        $rolled = $this->visualConfig('luxury-penthouse');
        $this->assertSame(1.05, $rolled['tone_mapping_exposure'] ?? null, 'down() preserves the admin edit.');
        $this->assertArrayNotHasKey('wing_heights', $rolled, 'down() removes the union-added wing_heights.');
        $this->assertArrayNotHasKey('glazing_walls', $rolled, 'down() removes the union-added glazing_walls.');
        $this->assertSame(5.2, $rolled['wall_height'] ?? null, 'down() restores the v2.1.0 nominal height.');
        $this->assertSame('gold', $rolled['frame_override'] ?? null, 'down() restores the v2.1.0 frames.');
        $this->assertCount(47, $rolled['structure'] ?? [], 'down() restores the v2.1.0 payload.');
        $fixturesRolled = json_decode((string) DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('lighting_fixtures'), true) ?: [];
        $this->assertCount(5, $fixturesRolled, 'down() restores the v2.1.0 fixture set.');
        $this->assertSame('cove-wash-a', $fixturesRolled[2]['id'] ?? null, 'down() restores the v2.1.0 cove washes.');
        $this->assertSame('2.1.0', DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('version'), 'down() restores the v2.1.0 version.');
        $this->assertStringContainsString('floor at dusk', (string) DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('description'), 'down() restores the v2.1.0 copy.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The client payload — the Double Volume must reach preview AND public
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_payload_carries_the_double_volume_to_the_client(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue  = \App\Models\VenueTemplate::where('slug', 'luxury-penthouse')->firstOrFail();
        $config = app(VenueConfigExporter::class)->forVenuePreview($venue);

        $visual = $config['visual_config'] ?? [];
        $this->assertSame(['wing_a' => 3.55, 'wing_b' => 6.3], $visual['wing_heights'] ?? null, 'The declared split reaches the viewer payload.');
        $this->assertSame(['wing_b_end', 'wing_b_north'], $visual['glazing_walls'] ?? null, 'The glass corner reaches the payload.');
        $this->assertSame('0x5c4c3a', $visual['ceiling_color'] ?? null, 'The lit ceiling reaches the viewer payload.');
        $this->assertSame(0.42, $visual['ambient_intensity'] ?? null, 'The evening rig reaches the payload.');
        $this->assertSame(0.5, $visual['artwork_light_base'] ?? null, 'The legibility floor reaches the payload.');
        $this->assertSame('black', $visual['post_fx']['vignette_blend'] ?? null, 'The declared post-fx reaches the payload.');
        $this->assertCount(61, $visual['structure'] ?? [], 'The descriptor payload reaches the viewer.');
        $this->assertSame(0.62, $config['material_config']['floor_roughness'] ?? null, 'The honed floor reaches the payload.');
        $this->assertTrue($config['material_config']['texture_tint'] ?? false, 'The tint authority reaches the payload.');
    }

    public function test_the_penthouse_architecture_is_venue_owned(): void
    {
        // Every key this pass uses is venue-owned (s7 added wing_heights +
        // glazing_walls) — a stale gallery override can never recompose the
        // building, and the schema bump re-keys every cached payload on
        // deploy.
        foreach (['structure', 'glazing_wall', 'glazing_walls', 'wing_heights', 'post_fx', 'environment', 'env_intensity',
            'artwork_light_base', 'artwork_light_pool_cap', 'hemisphere_intensity',
            'wall_height', 'ceiling_color', 'ambient_color', 'fog_near', 'fog_far',
            'fog_color', 'spot_intensity', 'fill_intensity', 'tone_mapping_exposure'] as $key) {
            $this->assertContains($key, VenueConfigExporter::VENUE_OWNED_VISUAL_KEYS, "'{$key}' is venue-owned architecture/rig identity.");
        }
        foreach (['texture_tint', 'wall_color', 'floor_color', 'floor_tile_meters', 'floor_roughness', 'floor_metalness'] as $key) {
            $this->assertContains($key, VenueConfigExporter::VENUE_OWNED_MATERIAL_KEYS, "material '{$key}' is venue-owned.");
        }
        $this->assertSame('s7', VenueConfigExporter::SCHEMA, 'The s7 schema bump re-keys cached payloads on deploy.');

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
                    'wing_heights'  => ['wing_a' => 2.4, 'wing_b' => 2.4],
                    'glazing_walls' => [],
                    'structure'     => [],
                    'environment'   => 'studio',
                ],
            ],
        ]);

        $payload = app(VenueConfigExporter::class)->forGallery($gallery->refresh());

        $this->assertSame(6.3, $payload['visual_config']['wall_height'] ?? null, 'A curator-saved height override is stripped — the venue declaration wins.');
        $this->assertSame(['wing_a' => 3.55, 'wing_b' => 6.3], $payload['visual_config']['wing_heights'] ?? null, 'A curator-saved split override is stripped — the double volume cannot be flattened.');
        $this->assertSame(['wing_b_end', 'wing_b_north'], $payload['visual_config']['glazing_walls'] ?? null, 'A curator-saved glazing override is stripped — the glass corner cannot be walled up.');
        $this->assertCount(61, $payload['visual_config']['structure'] ?? [], 'A curator-saved empty structure is stripped — the residence cannot be hollowed out.');
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
