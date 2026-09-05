<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DARK MUSEUM DEEPENING iteration — the forensic-audit remediation as CI.
 *
 * Pins:
 *   1. The seeded dark-museum row declares the deepened "night wing"
 *      identity: the texture_tint authority (the audit's headline material
 *      find — the v1.0.0 row declared wall colours that never reached a
 *      textured build), a readable-dark rig, a fog reach that covers its own
 *      rooms, post-fx restraint with the BLACK vignette blend (the stock
 *      Eskil shader blends edges toward LIGHT GREY — a dark scene glowed),
 *      dark-venue artwork legibility, the silenced hemisphere wash, and
 *      curation placement.
 *   2. The guarded migration rewrites ONLY the previously seeded values
 *      (admin customisations survive), is idempotent, adds its new keys only
 *      when absent, and down() reverses each rewrite under the same
 *      exact-match guard.
 *   3. The dark-rig legibility contract extends to this venue: exposure
 *      below 1.0 ⇒ artwork_light_base must be declared at a real standing
 *      glow.
 *   4. The harness venue payload stays in sync with the seeder row, and the
 *      v1.0.0 forensic body remains for before/after evidence.
 *
 * Portable patterns per the IT2–IT6 suites: sqlite-safe JSON
 * read-modify-write, migrations invoked directly.
 */
class VenueDarkMuseumIterationTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // The deepened identity (mirrors the seeder + harness payload)
    // ─────────────────────────────────────────────────────────────────────

    public const DEEPENED_VISUAL = [
        'fog_near'              => 12,
        'fog_far'               => 70,
        'ambient_intensity'     => 3.2,
        'spot_intensity'        => 1.9,
        'fill_intensity'        => 0.5,
        'tone_mapping_exposure' => 0.8,
        'frame_override'        => 'gold',
        'artwork_light_base'      => 0.32,
        'artwork_light_pool_cap'  => 14,
        'env_intensity'           => 0.14,
        'hemisphere_intensity'    => 0.04,
    ];

    public const DEEPENED_POST_FX = [
        'bloom'             => false,
        'vignette'          => true,
        'vignette_blend'    => 'black',
        'vignette_darkness' => 0.5,
        'vignette_offset'   => 1.15,
    ];

    public const DEEPENED_PLACEMENT = [
        'density'          => 'generous',
        'focal_wall'       => 'front',
        'pair_orientation' => true,
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_09_06_000002_dark_museum_deepening.php');
    }

    private function venueRow(string $slug): ?object
    {
        return DB::table('venue_templates')->where('slug', $slug)->first();
    }

    private function jsonCol(string $slug, string $col): array
    {
        $row = $this->venueRow($slug);
        if (!$row) {
            return [];
        }

        return json_decode((string) $row->$col, true) ?: [];
    }

    private function visualConfig(string $slug): array
    {
        return $this->jsonCol($slug, 'visual_config');
    }

    private function materialConfig(string $slug): array
    {
        return $this->jsonCol($slug, 'material_config');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. The fresh-install baseline IS the deepened identity
    // ─────────────────────────────────────────────────────────────────────

    public function test_seeded_dark_museum_declares_the_deepened_identity(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $vc = $this->visualConfig('dark-museum');

        foreach (self::DEEPENED_VISUAL as $key => $value) {
            $this->assertSame(
                $value,
                is_int($value) ? $vc[$key] ?? null : (float) ($vc[$key] ?? -1),
                "[dark-museum] visual_config.{$key} must declare the deepened value."
            );
        }

        $this->assertSame(
            self::DEEPENED_POST_FX,
            $vc['post_fx'] ?? null,
            '[dark-museum] must declare its post-fx restraint (bloom off; the black vignette blend — the stock shader added a grey glow to the dark scene).'
        );

        $this->assertSame(
            self::DEEPENED_PLACEMENT,
            $vc['placement'] ?? null,
            '[dark-museum] must declare its curation (generous density, focal front wall, orientation pairing).'
        );

        // Identity keys — unchanged by the deepening.
        $this->assertSame('museum', $vc['structure_pass'] ?? null, '[dark-museum] keeps its interpreter selector.');
        $this->assertSame('0x0a0a0a', $vc['ceiling_color'] ?? null, '[dark-museum] keeps its declared night ceiling.');
        $this->assertSame('gold', $vc['frame_override'] ?? null, '[dark-museum] keeps its gold frame signature.');
        $this->assertArrayNotHasKey('open_air', $vc, '[dark-museum] is a walled room — no open_air.');
        $this->assertArrayNotHasKey('layout_shape', $vc, '[dark-museum] is a walled room — no layout_shape override.');

        $mc = $this->materialConfig('dark-museum');
        $this->assertTrue($mc['texture_tint'] ?? false, '[dark-museum] texture_tint MUST be declared — without it the declared wall/floor colours never reach textured builds (the audit headline).');
        $this->assertSame('0x7a746c', $mc['wall_color'] ?? null, '[dark-museum] charcoal-plaster wall tint (readable under the declared rig).');
        $this->assertSame('0x3a3835', $mc['floor_color'] ?? null, '[dark-museum] dark-stone floor tint (the bright preset marble inverted the hierarchy).');
        $this->assertSame(3.0, (float) ($mc['floor_tile_meters'] ?? -1), '[dark-museum] floor reads at tile scale.');

        $row = $this->venueRow('dark-museum');
        $this->assertSame('2.0.0', $row->version, '[dark-museum] version bumps to 2.0.0.');
        // Customer-facing truth: the copy must promise the rendered identity.
        $this->assertTrue(
            Str::contains((string) $row->description, ['picture lights', 'charcoal']),
            '[dark-museum] description references the rendered identity (brass picture lights, charcoal galleries).'
        );
        $this->assertStringNotContainsString(
            'Dramatic lighting with black walls',
            (string) $row->description,
            '[dark-museum] the v1.0.0 "black walls" copy is gone (the walls never rendered black and the promise was the forbidden white-cube-invert).'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. The guarded migration: exact-match, idempotent, reversible
    // ─────────────────────────────────────────────────────────────────────

    public function test_migration_rewrites_only_the_previous_seeded_values(): void
    {
        // Start from the PRE-deepening row: re-seed, then hand-rewind the row
        // to the v1.0.0 values (the exact bytes the migration guards on).
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        DB::table('venue_templates')->where('slug', 'dark-museum')->update([
            'version'       => '1.0.0',
            'description'   => 'Dramatic lighting with black walls. Premium artwork presentation with gold-leaf frames.',
            'visual_config' => json_encode([
                'wall_height'            => 5,
                'wall_depth'             => 0.3,
                'ceiling_type'           => 'flat',
                'ceiling_color'          => '0x080808',
                'ceiling_height'         => 5,
                'background_color'       => '0x020202',
                'fog_color'              => '0x020202',
                'fog_near'               => 5,
                'fog_far'                => 18,
                'ambient_color'          => '0xfff4e6',
                'structure_pass'         => 'museum',
                'ambient_intensity'      => 0.15,
                'spot_intensity'         => 0.55,
                'fill_intensity'         => 0.08,
                'tone_mapping_exposure'  => 0.5,
                'frame_override'         => 'gold',
            ]),
            'material_config' => json_encode([
                'wall_color'            => '0x1a1a1a',
                'wall_roughness'        => 0.85,
                'wall_metalness'        => 0.0,
                'wall_normal_strength'  => 0.6,
                'floor_color'           => null,
                'floor_roughness'       => 0.3,
                'floor_metalness'       => 0.2,
                'floor_normal_strength' => 0.5,
            ]),
        ]);

        ($this->migration())->up();

        $vc = $this->visualConfig('dark-museum');
        foreach (self::DEEPENED_VISUAL as $key => $value) {
            $this->assertSame(
                $value,
                is_int($value) ? $vc[$key] ?? null : (float) ($vc[$key] ?? -1),
                "[migration] visual_config.{$key} rewritten from the v1.0.0 seed."
            );
        }
        $this->assertSame(self::DEEPENED_POST_FX, $vc['post_fx'] ?? null, '[migration] post_fx added when absent.');
        $this->assertSame(self::DEEPENED_PLACEMENT, $vc['placement'] ?? null, '[migration] placement added when absent.');
        $mc = $this->materialConfig('dark-museum');
        $this->assertTrue($mc['texture_tint'] ?? false, '[migration] texture_tint added when absent.');
        $this->assertSame('0x7a746c', $mc['wall_color'] ?? null, '[migration] wall tint rewritten.');
        $this->assertSame('0x3a3835', $mc['floor_color'] ?? null, '[migration] the v1.0.0 null floor colour (bright preset marble) rewritten to the dark stone.');
        $this->assertSame('2.0.0', $this->venueRow('dark-museum')->version, '[migration] never touches version (the seeder owns it).');
        $this->assertStringContainsString('night-lit institution', (string) $this->venueRow('dark-museum')->description, '[migration] the guarded description rewrite fired.');

        // Idempotent: a second run changes nothing (guards no longer match).
        $before = $this->venueRow('dark-museum');
        ($this->migration())->up();
        $this->assertSame($before->visual_config, $this->venueRow('dark-museum')->visual_config, '[migration] second run is a no-op (idempotent).');
    }

    public function test_migration_leaves_admin_customised_values_alone(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // An admin hand-tuned the rig BEFORE this iteration ships.
        DB::table('venue_templates')->where('slug', 'dark-museum')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('dark-museum'), [
                'ambient_intensity'     => 2.5,    // custom — must survive
                'tone_mapping_exposure' => 1.1,    // custom — must survive
                'post_fx'               => ['bloom' => true], // custom — must survive
            ])),
        ]);

        ($this->migration())->up();

        $vc = $this->visualConfig('dark-museum');
        $this->assertSame(2.5, (float) $vc['ambient_intensity'], '[migration] admin ambient survives.');
        $this->assertSame(1.1, (float) $vc['tone_mapping_exposure'], '[migration] admin exposure survives.');
        $this->assertSame(['bloom' => true], $vc['post_fx'], '[migration] admin post_fx survives.');
        // Untouched keys keep their deepened values (their guards no longer
        // match the stored values — nothing to rewrite).
        $this->assertSame(1.9, (float) $vc['spot_intensity'], '[migration] deepened keys keep their values when no guard matches.');
        // The blend-only add path: a saved post_fx without the blend key
        // gains ONLY the blend key.
        DB::table('venue_templates')->where('slug', 'dark-museum')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('dark-museum'), [
                'post_fx' => ['bloom' => false, 'vignette' => true, 'vignette_darkness' => 0.4],
            ])),
        ]);
        ($this->migration())->up();
        $postFx = $this->visualConfig('dark-museum')['post_fx'];
        $this->assertSame('black', $postFx['vignette_blend'] ?? null, '[migration] blend-only add: the dark-scene fix reaches curated post_fx arrays.');
        $this->assertSame(0.4, (float) $postFx['vignette_darkness'], '[migration] blend-only add preserves the curated darkness.');
        $this->assertArrayNotHasKey('bloom_strength', $postFx, '[migration] blend-only add adds nothing else.');
    }

    public function test_migration_down_reverses_each_rewrite(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        ($this->migration())->down();

        $vc = $this->visualConfig('dark-museum');
        $this->assertSame(0.15, (float) $vc['ambient_intensity']);
        $this->assertSame(0.5, (float) $vc['tone_mapping_exposure']);
        $this->assertSame(5, $vc['fog_near'] ?? null);
        $this->assertSame(18, $vc['fog_far'] ?? null);
        $this->assertArrayNotHasKey('post_fx', $vc, '[down] added post_fx removed.');
        $this->assertArrayNotHasKey('artwork_light_base', $vc, '[down] added artwork_light_base removed.');
        $this->assertArrayNotHasKey('hemisphere_intensity', $vc, '[down] added hemisphere_intensity removed.');
        $this->assertArrayNotHasKey('placement', $vc, '[down] added placement removed.');
        $mc = $this->materialConfig('dark-museum');
        $this->assertSame('0x1a1a1a', $mc['wall_color'] ?? null, '[down] wall tint reversed.');
        $this->assertNull($mc['floor_color'] ?? null, '[down] floor colour returns to null (the v1.0.0 preset-marble state).');
        $this->assertArrayNotHasKey('texture_tint', $mc, '[down] added texture_tint removed.');
        $this->assertArrayNotHasKey('floor_tile_meters', $mc, '[down] added floor_tile_meters removed.');
        $this->assertStringContainsString('Dramatic lighting with black walls', (string) $this->venueRow('dark-museum')->description, '[down] the v1.0.0 copy restored.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. The dark-rig legibility contract (this venue joins the club)
    // ─────────────────────────────────────────────────────────────────────

    public function test_dark_museum_declares_artwork_legibility(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $vc = $this->visualConfig('dark-museum');
        $exposure = (float) ($vc['tone_mapping_exposure'] ?? 0.5);
        $this->assertLessThan(1.0, $exposure, "[dark-museum] preconditions: a dark rig.");
        $this->assertArrayHasKey(
            'artwork_light_base',
            $vc,
            "[dark-museum] renders dark (exposure {$exposure}) — it must declare artwork_light_base so the hang is never unlit."
        );
        $this->assertGreaterThanOrEqual(
            0.3,
            (float) $vc['artwork_light_base'],
            "[dark-museum] artwork_light_base must be a real standing glow (≥ 0.3 — the museum is darker than the loft)."
        );
        $this->assertGreaterThanOrEqual(
            12,
            (int) $vc['artwork_light_pool_cap'],
            '[dark-museum] the pool must carry a typical hang at once (≥ 12).'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. The harness payload stays in sync with the seeder row
    // ─────────────────────────────────────────────────────────────────────

    public function test_harness_payload_matches_the_seeded_row(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $harness = file_get_contents(base_path('scripts/harness/harness.html'));
        $this->assertNotFalse($harness, 'harness.html must exist.');

        $start = strpos($harness, "'dark-museum': {");
        $this->assertNotFalse($start, 'harness must embed a dark-museum body.');
        $body = substr($harness, $start, (int) strpos($harness, 'version:', $start) - $start);

        $vc = $this->visualConfig('dark-museum');
        foreach (['fog_near', 'fog_far', 'ambient_intensity', 'spot_intensity', 'fill_intensity', 'tone_mapping_exposure'] as $key) {
            $this->assertStringContainsString(
                "{$key}: " . $vc[$key],
                $body,
                "[harness] dark-museum {$key} must mirror the seeded row."
            );
        }
        $this->assertStringContainsString("room_layout: 'square'", $body);
        $this->assertStringContainsString("vignette_blend: 'black'", $body);
        $this->assertStringContainsString("'dark-museum-v1': {", $harness, 'the v1.0.0 forensic body stays for before/after evidence.');
    }
}
