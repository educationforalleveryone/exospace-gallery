<?php

declare(strict_types=1);

/**
 * Mirror Lake iteration tests — v3.0.0 "The Still Shore".
 *
 * Pins the contract so future changes cannot silently break the venue:
 *
 *   - Declared identity: the seeder row carries structure_pass 'lake'
 *     (the waterfront body; 'phenomena' + void_lake stays the rollback),
 *     placement_mode 'lake' (the over-water art arc), the planar water
 *     reflection gate, environment 'none' (the PMREM night sky — the v1
 *     accidental rural_evening HDRI leak is structurally unreachable),
 *     the hemisphere night tints, the ceiling-orb opt-out, the field
 *     sizing (a lake needs shore + water + far shore), the hero focal
 *     declaration, the lake tuning block (sky_environment + the asset
 *     manifest), and the bloom-off post_fx.
 *   - Honesty matrix: the copy promises the landing, the shoreline walk,
 *     the pier, the pavilion and the reflections — the superseded v1
 *     wording is gone.
 *   - The migration is a safe, guarded rewrite: exact-match guards keep a
 *     super-admin's custom values, absent keys are added only when missing,
 *     the run is idempotent, and down() reverses each rewrite under the
 *     same guard (structure_pass back to 'phenomena' re-activates the v1
 *     void-lake body, untouched in the bundle).
 *   - The waterfront is venue-owned: 'lake' ships on the exporter's
 *     VENUE_OWNED_VISUAL_KEYS (a curator override cannot recompose the
 *     shoreline); placement_mode / floor_reflection / field sizing /
 *     hemisphere tints were already owned.
 *   - Preview/gallery payload parity: the declaration reaches the client on
 *     both the public view and the editor preview (the shared exporter).
 *
 * Run: php artisan test --filter=VenueLakeIterationTest
 */

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class VenueLakeIterationTest extends TestCase
{
    private const V1_DESCRIPTION = 'A still, dark lake reflects the floating artworks and the moon. Mist drifts low. Quiet, spacious, meditative.';

    private const V3_DESCRIPTION = 'A gallery at dusk on the shore of a still lake. Works hover above the calm water along the shore, doubled by their reflection. Arrive on the stone landing, follow the shoreline walk, cross the timber pier to the lantern-lit viewing pavilion, and look back as the far shore fades into mist under a rising moon.';

    private const V3_LAKE = [
        'sky_environment' => true,
        'assets_base'     => '/assets/venues/mirror-lake/',
        'assets'          => [
            'tree_large'  => 'tree_large_01.glb',
            'tree_medium' => 'tree_medium_01.glb',
            'tree_accent' => 'tree_medium_02.glb',
            'shrub'       => 'shrub_01.glb',
            'grass'       => 'grass_clump_01.glb',
            'boulder'     => 'boulder_01.glb',
            'bench'       => 'bench_01.glb',
        ],
    ];

    private const V3_POST_FX = [
        'bloom'             => false,
        'vignette'          => true,
        'vignette_darkness' => 0.5,
        'vignette_offset'   => 1.15,
        'vignette_blend'    => 'black',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Declared identity — the seeder contract the JS interpreter consumes
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_seeded_row_declares_the_still_shore(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $config = $this->visualConfig('mirror-lake');

        $this->assertSame('lake', $config['structure_pass'] ?? null, '[mirror-lake] must select the waterfront interpreter (the v1 rollback is structure_pass phenomena + void_lake).');
        $this->assertSame(true, $config['void_lake'] ?? null, '[mirror-lake] keeps the void_lake family flag — the v1 rollback route.');
        $this->assertSame('lake', $config['placement_mode'] ?? null, '[mirror-lake] must declare the over-water art arc.');
        $this->assertSame('planar', $config['floor_reflection'] ?? null, '[mirror-lake] must keep the planar reflection gate — the WATER Reflector (tier-resolved, never a bare mirror-metal floor).');
        $this->assertSame('none', $config['environment'] ?? null, '[mirror-lake] must declare environment none — the venue IS its own night sky (PMREM); the v1 rural_evening preset leak is structurally unreachable.');
        $this->assertSame(0.14, $config['env_intensity'] ?? null);
        $this->assertSame('circular', $config['layout_shape'] ?? null);
        $this->assertSame(true, $config['open_air'] ?? null);
        $this->assertSame(4, $config['field_radius_bonus'] ?? null, '[mirror-lake] the field carries a declared bonus — a lake needs shore + water + far shore.');
        $this->assertSame(17, $config['field_radius_min'] ?? null, '[mirror-lake] a 5-piece show must still compose the bay.');
        $this->assertSame(0.45, $config['hemisphere_intensity'] ?? null);
        $this->assertSame('0x3d5680', $config['hemisphere_sky_color'] ?? null, '[mirror-lake] ambient above the water IS the night sky.');
        $this->assertSame('0x0c0f14', $config['hemisphere_ground_color'] ?? null);
        $this->assertSame(false, $config['ceiling_fill_light'] ?? null, '[mirror-lake] must opt out of the ceiling-orb point light (no glowing orb in the night).');
        $this->assertSame(0.6, $config['artwork_light_base'] ?? null, '[mirror-lake] every hovering artwork carries its pool light — readable art over dark water.');
        $this->assertSame(['focal_wall' => 'lake-hero'], $config['placement'] ?? null, '[mirror-lake] the Arrival must compose on the plan hero berth (the frame under the moon).');
        $this->assertSame(self::V3_LAKE, $config['lake'] ?? null, '[mirror-lake] the lake block (sky environment + asset manifest) ships whole.');
        $this->assertSame(self::V3_POST_FX, $config['post_fx'] ?? null, '[mirror-lake] bloom stays OFF — calm, not spectacle; the vignette is a black blend.');
        $this->assertSame(1.15, $config['tone_mapping_exposure'] ?? null, '[mirror-lake] readable night, not murk.');

        $material = $this->materialConfig('mirror-lake');
        $this->assertSame('0x46523a', $material['floor_color'] ?? null, '[mirror-lake] the land is a dark lakeside meadow (the WATER is its own object).');
        $this->assertSame(1.0, $material['floor_roughness'] ?? null, '[mirror-lake] the land is NOT a mirror-metal floor (the v1 roughness-0 / metalness-1 pretence is gone).');
        $this->assertSame(0.0, $material['floor_metalness'] ?? null);

        $fixtures = json_decode((string) DB::table('venue_templates')->where('slug', 'mirror-lake')->value('lighting_fixtures'), true);
        $this->assertSame([], $fixtures, '[mirror-lake] carries no lighting fixtures — the moon is plan-built (position, streak, reflection).');
    }

    public function test_the_copy_promise_matches_the_delivered_waterfront(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue = DB::table('venue_templates')->where('slug', 'mirror-lake')->first();

        $this->assertSame(self::V3_DESCRIPTION, $venue->description, '[mirror-lake] the description must promise what the venue delivers.');
        $this->assertStringNotContainsString('Mist drifts low. Quiet, spacious, meditative.', (string) $venue->description, '[mirror-lake] the superseded v1 copy must be gone.');
        $this->assertSame('3.0.0', $venue->version);

        foreach (['landing', 'pier', 'pavilion', 'reflection'] as $word) {
            $this->assertStringContainsString($word, (string) $venue->description, "[mirror-lake] the copy must name the '{$word}'.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // The migration — guarded, idempotent, admin-respecting, reversible
    // ─────────────────────────────────────────────────────────────────────

    private function v1ProductionRow(): void
    {
        // The exact v1.0.0 seeded row (pre-migration production state).
        DB::table('venue_templates')->where('slug', 'mirror-lake')->update([
            'version'           => '1.0.0',
            'description'       => self::V1_DESCRIPTION,
            'tags'              => json_encode(['mirror', 'reflection', 'moonlit', 'meditative']),
            'visual_config'     => json_encode([
                'wall_height' => 0, 'wall_depth' => 0, 'ceiling_type' => 'none', 'ceiling_height' => 0,
                'background_color' => '0x0a0a18', 'fog_color' => '0x0a0a18', 'fog_near' => 15, 'fog_far' => 45,
                'ambient_color' => '0xb0c8ff', 'ambient_intensity' => 0.18,
                'spot_intensity' => 0.5, 'fill_intensity' => 0.12,
                'tone_mapping_exposure' => 0.55, 'frame_override' => 'silver',
                'placement_mode' => 'float',
                'floor_reflection' => 'planar',
                'env_intensity' => 0.15,
                'structure_pass' => 'phenomena',
                'open_air' => true,
                'layout_shape' => 'circular',
                'void_lake' => true,
            ]),
            'material_config'   => json_encode([
                'wall_color' => null, 'wall_roughness' => 1.0, 'wall_metalness' => 0.0, 'wall_normal_strength' => 0.3,
                'floor_color' => '0x202830', 'floor_roughness' => 0.0, 'floor_metalness' => 1.0, 'floor_normal_strength' => 0.1,
            ]),
            'lighting_fixtures' => json_encode([[
                'id'          => 'moonlight',
                'type'        => 'directional',
                'position'    => [12, 22, -8],
                'color'       => '0xb0c8ff',
                'intensity'   => 0.6,
                'cast_shadow' => false,
            ]]),
        ]);
    }

    public function test_the_migration_upgrades_a_v1_production_row(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v1ProductionRow();

        Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_09_000013_mirror_lake_still_shore.php', '--force' => true]);

        $venue = DB::table('venue_templates')->where('slug', 'mirror-lake')->first();
        $config = json_decode((string) $venue->visual_config, true);

        $this->assertSame('3.0.0', $venue->version);
        $this->assertSame('lake', $config['structure_pass']);
        $this->assertSame('lake', $config['placement_mode']);
        $this->assertSame('0x0f1726', $config['fog_color']);
        $this->assertSame(1.15, $config['tone_mapping_exposure']);
        $this->assertSame(self::V3_LAKE, $config['lake']);
        $this->assertSame(self::V3_POST_FX, $config['post_fx']);
        $this->assertSame([], json_decode((string) $venue->lighting_fixtures, true));
        $this->assertSame(self::V3_DESCRIPTION, $venue->description);

        $material = json_decode((string) $venue->material_config, true);
        $this->assertSame('0x46523a', $material['floor_color']);
        $this->assertSame(1.0, $material['floor_roughness']);
        $this->assertSame(0.0, $material['floor_metalness']);
        $this->assertSame(3.0, $material['floor_tile_meters']);
    }

    public function test_the_migration_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v1ProductionRow();

        Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_09_000013_mirror_lake_still_shore.php', '--force' => true]);
        $afterFirst = DB::table('venue_templates')->where('slug', 'mirror-lake')->first();

        Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_09_000013_mirror_lake_still_shore.php', '--force' => true]);
        $afterSecond = DB::table('venue_templates')->where('slug', 'mirror-lake')->first();

        $this->assertSame($afterFirst->visual_config, $afterSecond->visual_config, '[mirror-lake] re-running the migration must rewrite nothing.');
        $this->assertSame($afterFirst->material_config, $afterSecond->material_config);
    }

    public function test_the_migration_respects_admin_edits(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v1ProductionRow();

        // A super-admin retuned the mood before the migration ran: the
        // guarded rewrites must keep the custom values.
        DB::table('venue_templates')->where('slug', 'mirror-lake')->update([
            'visual_config' => json_encode(array_merge(json_decode((string) DB::table('venue_templates')->where('slug', 'mirror-lake')->value('visual_config'), true), [
                'tone_mapping_exposure' => 1.4,   // custom — not the v1 seeded 0.55
                'fog_color'             => '0x05070a',
            ])),
            'description' => 'My custom lake copy.',
        ]);

        Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_09_000013_mirror_lake_still_shore.php', '--force' => true]);

        $config = $this->visualConfig('mirror-lake');
        $this->assertSame(1.4, $config['tone_mapping_exposure'], '[mirror-lake] a custom exposure is never overwritten.');
        $this->assertSame('0x05070a', $config['fog_color'], '[mirror-lake] a custom fog is never overwritten.');
        $this->assertSame('My custom lake copy.', DB::table('venue_templates')->where('slug', 'mirror-lake')->value('description'), '[mirror-lake] custom copy is never overwritten.');
        // The structural identity still lands (those keys were untouched).
        $this->assertSame('lake', $config['structure_pass']);
    }

    public function test_the_migration_is_reversible(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v1ProductionRow();

        Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_09_000013_mirror_lake_still_shore.php', '--force' => true]);
        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_09_09_000013_mirror_lake_still_shore.php', '--force' => true]);

        $venue = DB::table('venue_templates')->where('slug', 'mirror-lake')->first();
        $config = json_decode((string) $venue->visual_config, true);

        $this->assertSame('1.0.0', $venue->version);
        $this->assertSame('phenomena', $config['structure_pass'], '[mirror-lake] down() must re-activate the v1 void-lake rollback body.');
        $this->assertSame('float', $config['placement_mode']);
        $this->assertSame('0x0a0a18', $config['fog_color']);
        $this->assertSame(0.55, $config['tone_mapping_exposure']);
        $this->assertArrayNotHasKey('lake', $config, '[mirror-lake] down() must remove the lake block.');
        $this->assertArrayNotHasKey('post_fx', $config, '[mirror-lake] down() must remove post_fx.');
        $this->assertSame(self::V1_DESCRIPTION, $venue->description);

        $material = json_decode((string) $venue->material_config, true);
        $this->assertSame('0x202830', $material['floor_color']);
        $this->assertSame(0.0, $material['floor_roughness']);
        $this->assertSame(1.0, $material['floor_metalness']);

        $fixtures = json_decode((string) $venue->lighting_fixtures, true);
        $this->assertSame('moonlight', $fixtures[0]['id'] ?? null, '[mirror-lake] down() restores the v1 moonlight fixture.');
    }

    public function test_down_never_discards_an_admin_lake_declaration(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v1ProductionRow();

        Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_09_000013_mirror_lake_still_shore.php', '--force' => true]);

        // The admin retunes the lake AFTER the migration: down() must leave
        // the tuned block alone (remove only what up() wrote).
        $config = $this->visualConfig('mirror-lake');
        $config['lake']['assets_base'] = '/assets/venues/mirror-lake/custom/';
        DB::table('venue_templates')->where('slug', 'mirror-lake')->update([
            'visual_config' => json_encode($config),
        ]);

        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_09_09_000013_mirror_lake_still_shore.php', '--force' => true]);

        $after = $this->visualConfig('mirror-lake');
        $this->assertSame('/assets/venues/mirror-lake/custom/', $after['lake']['assets_base'] ?? null, '[mirror-lake] a custom lake block survives down().');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Venue ownership + payload parity
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_waterfront_declaration_is_venue_owned(): void
    {
        $exporter = new \App\Services\VenueConfigExporter();
        $owned = $exporter::VENUE_OWNED_VISUAL_KEYS;

        $this->assertContains('lake', $owned, '[mirror-lake] the lake identity block must be venue-owned wholesale (a curator override cannot recompose the shoreline).');
        foreach (['structure_pass', 'placement_mode', 'floor_reflection', 'environment', 'field_radius_bonus', 'field_radius_min', 'hemisphere_sky_color', 'hemisphere_ground_color', 'ceiling_fill_light', 'post_fx', 'placement'] as $key) {
            $this->assertContains($key, $owned, "[mirror-lake] '{$key}' must be venue-owned.");
        }
    }

    public function test_preview_payload_carries_the_waterfront_identity(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // The shared exporter feeds BOTH the editor preview and the public
        // view; if the declaration reaches the payload, parity holds by
        // construction (one bundle, one payload).
        $venue = \App\Models\VenueTemplate::where('slug', 'mirror-lake')->firstOrFail();
        $exporter = new \App\Services\VenueConfigExporter();
        $payload = $exporter->forVenuePreview($venue);

        $vc = $payload['visual_config'] ?? [];
        $this->assertSame('lake', $vc['structure_pass'] ?? null);
        $this->assertSame('lake', $vc['placement_mode'] ?? null);
        $this->assertSame(self::V3_LAKE, $vc['lake'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function visualConfig(string $slug): array
    {
        return json_decode((string) DB::table('venue_templates')->where('slug', $slug)->value('visual_config'), true) ?? [];
    }

    private function materialConfig(string $slug): array
    {
        return json_decode((string) DB::table('venue_templates')->where('slug', $slug)->value('material_config'), true) ?? [];
    }
}
