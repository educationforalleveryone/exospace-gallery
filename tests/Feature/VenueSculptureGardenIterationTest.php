<?php

declare(strict_types=1);

/**
 * Outdoor Sculpture Garden iteration tests.
 *
 * v3.0.0 "The Curated Walk" (landscape-first identity) and v4.0.0 "The
 * Sculpture Park" (the ASSET-DRIVEN environment reset).
 *
 * Pins the contract so future changes cannot silently break the venue:
 *
 *   - Declared identity: the seeder row carries visual_config.placement_mode
 *     'garden' (the curated courts), the field sizing (bonus + floor), the
 *     declared environment absence + sky IBL strength, the hemisphere
 *     sky/ground daylight tints, the ceiling-orb opt-out, the garden tuning
 *     block (sky_environment + the v4 ASSET MANIFEST), bloom-off post_fx,
 *     the artwork standing glow, and the sun_shadows gate.
 *   - Honesty matrix: the copy promises the gravel walk + museum stands, and
 *     the superseded v2/v3 wording is gone.
 *   - The migrations are safe, guarded rewrites: exact-match guards keep a
 *     super-admin's custom values, absent keys are added only when missing,
 *     the run is idempotent, and down() reverses each rewrite under the same
 *     guard (the v3 identity chain AND the v4 asset-manifest chain).
 *   - The landscape is venue-owned: 'garden', 'ceiling_fill_light',
 *     'field_radius_bonus', 'field_radius_min', 'hemisphere_sky_color' and
 *     'hemisphere_ground_color' ship on the exporter's VENUE_OWNED_VISUAL_KEYS.
 *   - Preview/gallery payload parity: the declaration reaches the client on
 *     both the public view and the editor preview (the shared exporter).
 *
 * Run: php artisan test --filter=VenueSculptureGardenIterationTest
 */

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VenueSculptureGardenIterationTest extends TestCase
{
    private const V2_DESCRIPTION = 'Open-air garden exhibition. Hedges, trees, sky, and stone paths. Artworks on easels along a winding path.';

    private const V3_DESCRIPTION = 'A curated landscape exhibition. A stone promenade leads from the garden gate to a bronze centrepiece, then on to sculpture clearings framed by trees, hedges and rolling meadow. Works are discovered one by one — never all at once.';

    private const V4_DESCRIPTION = 'A curated open-air exhibition. A gravel walk leads from the tree-lined gate to a bronze centrepiece on a travertine court, then on to works presented on outdoor museum stands across lawns and sculpture clearings, framed by mature trees and a distant treeline.';

    private const V4_ASSETS = [
        'tree_large'  => 'tree_large_01.glb',
        'tree_medium' => 'tree_medium_01.glb',
        'tree_accent' => 'tree_medium_02.glb',
        'shrub'       => 'shrub_01.glb',
        'grass'       => 'grass_clump_01.glb',
        'boulder'     => 'boulder_01.glb',
        'bench'       => 'bench_01.glb',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Declared identity — the seeder contract the JS interpreter consumes
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_seeded_row_declares_the_asset_park(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $config = $this->visualConfig('sculpture-garden');

        $this->assertSame('garden', $config['placement_mode'] ?? null, '[sculpture-garden] must declare the curated-walk placement mode (courts, approaches, hierarchy — not the legacy fence ring).');
        $this->assertSame('garden', $config['structure_pass'] ?? null, '[sculpture-garden] must select the bespoke garden interpreter.');
        $this->assertTrue($config['open_air'] ?? false, '[sculpture-garden] must declare open air.');
        $this->assertSame('circular', $config['layout_shape'] ?? null);
        $this->assertSame(2.6, $config['field_radius_bonus'] ?? null, '[sculpture-garden] a landscape needs ground per artwork — the field radius carries a declared bonus.');
        $this->assertSame(14, $config['field_radius_min'] ?? null, '[sculpture-garden] a 5-piece show must still compose as a garden, not a cramped circle.');
        $this->assertSame('none', $config['environment'] ?? null, '[sculpture-garden] must declare environment none — the sky is the venue dome + PMREM, never a 10 MB HDRI (and never an interior studio sky in the bronze).');
        $this->assertSame(0.22, $config['env_intensity'] ?? null, '[sculpture-garden] must declare the sky IBL strength.');
        $this->assertSame(0.4, $config['hemisphere_intensity'] ?? null, '[sculpture-garden] the hemisphere rig carries the daylight.');
        $this->assertSame('0xbfd9ee', $config['hemisphere_sky_color'] ?? null, '[sculpture-garden] ambient above the lawn IS the sky.');
        $this->assertSame('0x51663c', $config['hemisphere_ground_color'] ?? null, '[sculpture-garden] ambient below IS the grass bounce.');
        $this->assertSame(false, $config['ceiling_fill_light'] ?? null, '[sculpture-garden] must opt out of the ceiling-orb point light (no glowing orb in an open sky).');
        $this->assertSame(0.22, $config['artwork_light_base'] ?? null, '[sculpture-garden] every artwork carries a standing glow — no dead canvases in daylight.');
        $this->assertSame(true, $config['sun_shadows'] ?? null, '[sculpture-garden] the garden is the only venue whose sky establishes a sun (tier + config gated).');
        $this->assertSame(0.16, $config['ambient_intensity'] ?? null, '[sculpture-garden] the daylight rig must not wash the lawn (was 0.4 murk-flat).');
        $this->assertSame(0.9, $config['tone_mapping_exposure'] ?? null, '[sculpture-garden] exposure must read as afternoon daylight.');
        $this->assertSame('0xdfe2d1', $config['background_color'] ?? null, '[sculpture-garden] the world colour is the warm horizon haze.');
        $this->assertSame('0xdfe2d1', $config['fog_color'] ?? null, '[sculpture-garden] aerial perspective matched to the horizon.');

        // ── The v4 ASSET MANIFEST (the owner's GLB library interface) ────
        $garden = $config['garden'] ?? [];
        $this->assertSame(true, $garden['sky_environment'] ?? null, '[sculpture-garden] the garden block must gate the PMREM sky environment (rollback switch).');
        $this->assertSame('/assets/venues/sculpture-garden/', $garden['assets_base'] ?? null, '[sculpture-garden] assets_base must be ROOT-RELATIVE — a page-relative base breaks on every nested route.');
        $this->assertSame(self::V4_ASSETS, $garden['assets'] ?? null, '[sculpture-garden] the asset manifest must declare all 7 roles (the owner fills these exact filenames).');

        $postFx = $config['post_fx'] ?? [];
        $this->assertFalse($postFx['bloom'] ?? true, '[sculpture-garden] bloom must be OFF — it milked the daylight sky (the grey-veil defect class).');
        $this->assertSame('black', $postFx['vignette_blend'] ?? null, '[sculpture-garden] vignette must blend to black.');

        $material = $this->materialConfig('sculpture-garden');
        $this->assertSame('0x5e7a46', $material['floor_color'] ?? null, '[sculpture-garden] must declare the muted lawn green fallback.');
        $this->assertSame(3.0, $material['floor_tile_meters'] ?? null);

        $this->assertSame('4.0.0', (string) DB::table('venue_templates')->where('slug', 'sculpture-garden')->value('version'), '[sculpture-garden] version must pin 4.0.0 (The Sculpture Park).');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Honesty matrix — the copy promises exactly what renders
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_copy_promise_matches_the_delivered_landscape(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $row = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['description']);

        $this->assertStringNotContainsString('winding path', (string) $row->description, 'The v2.0.0 wording promised a path the renderer never built — it must be gone.');
        $this->assertStringNotContainsString('hedges and rolling meadow', (string) $row->description, 'The v3.0.0 wording promised a box hedge the v4 removed — it must be gone.');
        $this->assertMatchesRegularExpression('/gravel walk/i', (string) $row->description, 'The copy must promise the gravel walk (the continuous paved circulation).');
        $this->assertMatchesRegularExpression('/gate/i', (string) $row->description, 'The copy must promise the arrival gate (the tree-lined spawn).');
        $this->assertMatchesRegularExpression('/museum stands/i', (string) $row->description, 'The copy must promise the outdoor museum stands (the artwork presentation).');
        $this->assertMatchesRegularExpression('/centrepiece/i', (string) $row->description, 'The copy must name the central bronze court.');
        $this->assertMatchesRegularExpression('/treeline/i', (string) $row->description, 'The copy must promise the distant treeline (the horizon must not be a void).');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The migration — guarded, idempotent, reversible, admin-respecting
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_migration_upgrades_a_v200_production_row(): void
    {
        $this->seedLegacyGardenRow();

        $this->migration()->up();

        $row = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config', 'description', 'version']);
        $config = json_decode((string) $row->visual_config, true);

        $this->assertSame('garden', $config['placement_mode'] ?? null, 'up() must add the curated-walk mode to a legacy row.');
        $this->assertSame('none', $config['environment'] ?? null);
        $this->assertSame(0.22, $config['env_intensity'] ?? null);
        $this->assertSame(false, $config['ceiling_fill_light'] ?? null);
        $this->assertSame(2.2, $config['field_radius_bonus'] ?? null);
        $this->assertSame(12.5, $config['field_radius_min'] ?? null);
        $this->assertSame(['sky_environment' => true], $config['garden'] ?? null);
        $this->assertSame(0.16, $config['ambient_intensity'] ?? null, 'The guarded rewrite must rebalance the flat rig (0.4 → 0.16).');
        $this->assertSame('0xd6e0e2', $config['background_color'] ?? null, 'up() must replace the v2 sky-blue with the horizon haze.');
        $this->assertSame('0xd6e0e2', $config['fog_color'] ?? null, 'up() must replace the explicit null fog (no aerial perspective in the v2 garden).');
        $this->assertSame(self::V3_DESCRIPTION, (string) $row->description, 'up() must upgrade the v2.0.0 copy.');
        $this->assertSame('3.0.0', (string) $row->version);
    }

    /** The v3.0.0 row exactly as production holds it pre-pass. */
    private function seedV3GardenRow(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Rewind the row to the exact v3.0.0 state (what migration 000011
        // produced and production holds before the v4 pass).
        $config = $this->visualConfig('sculpture-garden');
        $config['background_color'] = '0xd6e0e2';
        $config['fog_color'] = '0xd6e0e2';
        $config['field_radius_bonus'] = 2.2;
        $config['field_radius_min'] = 12.5;
        $config['garden'] = ['sky_environment' => true];
        unset($config['garden']['assets_base'], $config['garden']['assets']);

        $material = $this->materialConfig('sculpture-garden');
        $material['floor_color'] = '0x3a6a2a';
        $material['floor_tile_meters'] = 2.0;

        DB::table('venue_templates')->where('slug', 'sculpture-garden')->update([
            'description' => self::V3_DESCRIPTION,
            'version' => '3.0.0',
            'visual_config' => json_encode($config),
            'material_config' => json_encode($material),
        ]);
    }

    public function test_the_v4_migration_upgrades_a_v300_row_to_the_asset_park(): void
    {
        $this->seedV3GardenRow();

        $this->migrationV4()->up();

        $row = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config', 'material_config', 'description', 'version']);
        $config = json_decode((string) $row->visual_config, true);
        $material = json_decode((string) $row->material_config, true);

        $this->assertSame('0xdfe2d1', $config['background_color'] ?? null, 'up() must retune the horizon haze (warmer).');
        $this->assertSame('0xdfe2d1', $config['fog_color'] ?? null);
        $this->assertSame(2.6, $config['field_radius_bonus'] ?? null, 'up() must enlarge the declared field (the gate corridor needs the lawn).');
        $this->assertSame(14, $config['field_radius_min'] ?? null);
        $this->assertSame('/assets/venues/sculpture-garden/', $config['garden']['assets_base'] ?? null, 'up() must add the root-relative asset base.');
        $this->assertSame(self::V4_ASSETS, $config['garden']['assets'] ?? null, 'up() must add the 7-role asset manifest.');
        $this->assertSame(true, $config['garden']['sky_environment'] ?? null, 'up() must preserve the v3 sky_environment switch.');
        $this->assertSame('0x5e7a46', $material['floor_color'] ?? null, 'up() must retune the lawn colour.');
        $this->assertSame(3.0, $material['floor_tile_meters'] ?? null);
        $this->assertSame(self::V4_DESCRIPTION, (string) $row->description, 'up() must upgrade the v3.0.0 copy.');
        $this->assertSame('4.0.0', (string) $row->version);
    }

    public function test_the_v4_migration_is_idempotent(): void
    {
        $this->seedV3GardenRow();

        $this->migrationV4()->up();
        $afterFirst = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config', 'material_config', 'description', 'version']);

        $this->migrationV4()->up();
        $afterSecond = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config', 'material_config', 'description', 'version']);

        $this->assertSame((array) $afterFirst, (array) $afterSecond, 'A second up() run must be a no-op.');
    }

    public function test_the_v4_migration_respects_admin_edits(): void
    {
        $this->seedV3GardenRow();

        // A super-admin retuned the haze, owns the garden block (their own
        // asset library), the field sizing and the lawn colour BEFORE the pass.
        $config = $this->visualConfig('sculpture-garden');
        $config['background_color'] = '0x223344';
        $config['fog_color'] = '0x112233';
        $config['field_radius_bonus'] = 9.9;
        $config['field_radius_min'] = 40;
        $config['garden'] = ['sky_environment' => false, 'assets_base' => 'https://cdn.example.com/trees/', 'assets' => ['tree_large' => 'my_oak.glb']];
        $material = $this->materialConfig('sculpture-garden');
        $material['floor_color'] = '0x123456';
        $material['floor_tile_meters'] = 7.5;
        DB::table('venue_templates')->where('slug', 'sculpture-garden')->update([
            'visual_config' => json_encode($config),
            'material_config' => json_encode($material),
            'description' => 'My own garden copy.',
            'version' => '9.9.9',
        ]);

        $this->migrationV4()->up();

        $row = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config', 'material_config', 'description', 'version']);
        $config = json_decode((string) $row->visual_config, true);
        $material = json_decode((string) $row->material_config, true);

        $this->assertSame('0x223344', $config['background_color'], 'An admin haze wins over the rewrite.');
        $this->assertSame('0x112233', $config['fog_color']);
        $this->assertSame(9.9, $config['field_radius_bonus'], 'An admin field sizing is never rewritten.');
        $this->assertSame('https://cdn.example.com/trees/', $config['garden']['assets_base'] ?? null, 'An admin-declared asset library is never overwritten.');
        $this->assertSame(['tree_large' => 'my_oak.glb'], $config['garden']['assets'] ?? null);
        $this->assertSame('0x123456', $material['floor_color'], 'An admin lawn colour is never rewritten.');
        $this->assertSame('My own garden copy.', (string) $row->description, 'Admin copy is never touched.');
        $this->assertSame('9.9.9', (string) $row->version, 'A drifted version pin is never touched.');
    }

    public function test_the_v4_migration_is_reversible(): void
    {
        $this->seedV3GardenRow();

        $this->migrationV4()->up();
        $this->migrationV4()->down();

        $row = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config', 'material_config', 'description', 'version']);
        $config = json_decode((string) $row->visual_config, true);
        $material = json_decode((string) $row->material_config, true);

        $this->assertSame('0xd6e0e2', $config['background_color'] ?? null, 'down() restores the v3 haze.');
        $this->assertSame('0xd6e0e2', $config['fog_color'] ?? null);
        $this->assertSame(2.2, $config['field_radius_bonus'] ?? null, 'down() restores the v3 field sizing.');
        $this->assertSame(12.5, $config['field_radius_min'] ?? null);
        $this->assertArrayNotHasKey('assets_base', $config['garden'] ?? [], 'down() removes the asset manifest it added (and only while it still matches).');
        $this->assertArrayNotHasKey('assets', $config['garden'] ?? []);
        $this->assertSame(true, $config['garden']['sky_environment'] ?? null, 'down() preserves the v3 sky_environment switch.');
        $this->assertSame('0x3a6a2a', $material['floor_color'] ?? null, 'down() restores the v3 lawn colour.');
        $this->assertSame(2.0, $material['floor_tile_meters'] ?? null);
        $this->assertSame(self::V3_DESCRIPTION, (string) $row->description);
        $this->assertSame('3.0.0', (string) $row->version);

        // Idempotent down: a second run is a safe no-op.
        $this->migrationV4()->down();
        $again = (array) DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config']);
        $this->assertSame((array) $row, $again, 'A second down() run must be a no-op.');
    }

    public function test_the_migration_is_idempotent(): void
    {
        $this->seedLegacyGardenRow();

        $this->migration()->up();
        $afterFirst = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config', 'description', 'version']);

        $this->migration()->up();
        $afterSecond = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config', 'description', 'version']);

        $this->assertSame((array) $afterFirst, (array) $afterSecond, 'A second up() run must be a no-op.');
    }

    public function test_the_migration_respects_admin_edits(): void
    {
        $this->seedLegacyGardenRow();

        // A super-admin retuned the rig and declared their own landscape
        // block BEFORE the pass.
        $adminConfig = [
            'wall_height'            => 0,
            'open_air'               => true,
            'layout_shape'           => 'circular',
            'structure_pass'         => 'garden',
            'background_color'       => '0x223344',
            'ambient_intensity'      => 0.6,
            'tone_mapping_exposure'  => 1.1,
            'placement_mode'         => 'float',
            'garden'                 => ['sky_environment' => false],
        ];
        DB::table('venue_templates')->where('slug', 'sculpture-garden')->update([
            'visual_config' => json_encode($adminConfig),
            'description'   => 'My own garden copy.',
            'version'       => '9.9.9',
        ]);

        $this->migration()->up();

        $row = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config', 'description', 'version']);
        $config = json_decode((string) $row->visual_config, true);

        $this->assertSame(0.6, $config['ambient_intensity'], 'An admin value that differs from the seeded "from" guard must never be rewritten.');
        $this->assertSame('0x223344', $config['background_color'], 'An admin background wins over the haze rewrite.');
        $this->assertSame('float', $config['placement_mode'], 'An admin-declared placement mode is never overwritten.');
        $this->assertSame(['sky_environment' => false], $config['garden'] ?? null, 'An admin-declared garden block (any shape) is never overwritten.');
        $this->assertSame('My own garden copy.', (string) $row->description, 'Admin copy is never touched.');
        $this->assertSame('9.9.9', (string) $row->version, 'A drifted version pin is never touched.');
    }

    public function test_the_migration_is_reversible(): void
    {
        $this->seedLegacyGardenRow();

        $this->migration()->up();
        $this->migration()->down();

        $row = DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config', 'description', 'version']);
        $config = json_decode((string) $row->visual_config, true);

        foreach (['placement_mode', 'garden', 'post_fx', 'environment', 'env_intensity',
            'hemisphere_intensity', 'hemisphere_sky_color', 'hemisphere_ground_color',
            'ceiling_fill_light', 'field_radius_bonus', 'field_radius_min', 'artwork_light_base'] as $key) {
            $this->assertArrayNotHasKey($key, $config, "down() removes {$key} it added (and only while it still matches).");
        }
        $this->assertSame('0x87ceeb', $config['background_color'] ?? null, 'down() restores the v2 sky blue.');
        $this->assertNull($config['fog_color'] ?? null, 'down() restores the null fog.');
        $this->assertSame(0.4, $config['ambient_intensity'] ?? null, 'down() restores the v2 rig.');
        $this->assertSame(self::V2_DESCRIPTION, (string) $row->description);
        $this->assertSame('2.0.0', (string) $row->version);

        // Idempotent down: a second run is a safe no-op.
        $this->migration()->down();
        $again = (array) DB::table('venue_templates')->where('slug', 'sculpture-garden')->first(['visual_config']);
        $this->assertSame((array) $row, $again, 'A second down() run must be a no-op.');
    }

    public function test_down_never_discards_an_admin_landscape_declaration(): void
    {
        $this->seedLegacyGardenRow();

        $this->migration()->up();

        // The admin retunes the landscape AFTER the pass — down() must leave it.
        DB::table('venue_templates')->where('slug', 'sculpture-garden')->update([
            'visual_config' => json_encode(array_merge(
                json_decode((string) DB::table('venue_templates')->where('slug', 'sculpture-garden')->value('visual_config'), true),
                ['garden' => ['sky_environment' => false, 'terrain_scale' => 0.5]]
            )),
        ]);

        $this->migration()->down();

        $config = json_decode((string) DB::table('venue_templates')->where('slug', 'sculpture-garden')->value('visual_config'), true);
        $this->assertSame(['sky_environment' => false, 'terrain_scale' => 0.5], $config['garden'] ?? null, 'down() must preserve an admin post-pass edit of the landscape block.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ownership + payload parity
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_landscape_declaration_is_venue_owned(): void
    {
        $exporter = \Illuminate\Support\Facades\File::get(app_path('Services/VenueConfigExporter.php'));

        foreach (['garden', 'ceiling_fill_light', 'field_radius_bonus', 'field_radius_min', 'hemisphere_sky_color', 'hemisphere_ground_color'] as $key) {
            $this->assertMatchesRegularExpression(
                "/'{$key}'/",
                $exporter,
                "The landscape key '{$key}' must ship on VENUE_OWNED_VISUAL_KEYS — a curator override would recompose the landscape itself."
            );
        }
    }

    public function test_the_payload_carries_the_curated_walk_to_the_client(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $exporter = app(\App\Services\VenueConfigExporter::class);

        $garden = \App\Models\VenueTemplate::where('slug', 'sculpture-garden')->firstOrFail();
        $config = $exporter->forVenuePreview($garden);

        $this->assertSame(
            'garden',
            $config['visual_config']['placement_mode'] ?? null,
            'The curated-walk declaration must reach the client payload (preview and public view share the exporter).'
        );
        $this->assertSame('none', $config['visual_config']['environment'] ?? null);
        $this->assertSame('/assets/venues/sculpture-garden/', $config['visual_config']['garden']['assets_base'] ?? null, 'The asset manifest must reach the client payload (preview and public view share the exporter).');
        $this->assertSame(self::V4_ASSETS, $config['visual_config']['garden']['assets'] ?? null);
        $this->assertSame('0x5e7a46', $config['material_config']['floor_color'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function migration(): object
    {
        return require database_path('migrations/2026_09_09_000011_sculpture_garden_curated_walk.php');
    }

    private function migrationV4(): object
    {
        return require database_path('migrations/2026_09_09_000012_sculpture_garden_asset_park.php');
    }

    /** The v2.0.0 row exactly as production holds it pre-pass. */
    private function seedLegacyGardenRow(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        DB::table('venue_templates')->where('slug', 'sculpture-garden')->update([
            'description' => self::V2_DESCRIPTION,
            'version'     => '2.0.0',
            'visual_config' => json_encode([
                'wall_height'            => 0,
                'wall_depth'             => 0,
                'ceiling_type'           => 'none',
                'ceiling_height'         => 0,
                'background_color'       => '0x87ceeb',
                'fog_color'              => null,
                'fog_near'               => 0,
                'fog_far'                => 0,
                'ambient_color'          => '0xe0f0ff',
                'open_air'               => true,
                'layout_shape'           => 'circular',
                'structure_pass'         => 'garden',
                'ambient_intensity'      => 0.4,
                'spot_intensity'         => 0.3,
                'fill_intensity'         => 0.2,
                'tone_mapping_exposure'  => 0.7,
                'frame_override'         => null,
                'sun_shadows'            => true,
            ]),
        ]);
    }

    private function visualConfig(string $slug): array
    {
        return json_decode((string) DB::table('venue_templates')->where('slug', $slug)->value('visual_config'), true) ?? [];
    }

    private function materialConfig(string $slug): array
    {
        return json_decode((string) DB::table('venue_templates')->where('slug', $slug)->value('material_config'), true) ?? [];
    }
}
