<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INDUSTRIAL LOFT DEEPENING iteration — the forensic-audit remediation as CI.
 *
 * Pins:
 *   1. The seeded industrial-loft row declares the deepened identity (a
 *      physical-unit rig the venue can actually render with, a fog reach
 *      that covers its own rooms, explicit post-fx restraint, dark-venue
 *      artwork legibility keys, blackened-steel frames, the open-floor
 *      default, loft corridor proportions) — an Industrial Loft that can no
 *      longer render as a near-black tunnel on a fresh install.
 *   2. The guarded migration rewrites ONLY the previously seeded values
 *      (admin customisations survive), is idempotent, adds its new keys only
 *      when absent, and down() reverses each rewrite under the same
 *      exact-match guard.
 *   3. The artwork-legibility contract is generic: a venue whose rig renders
 *      dark must declare a standing glow for its hang (artwork_light_base)
 *      — the audit's core finding: the venue was dark AND its artworks kept
 *      the generic 0.15 fraction, so the hang read as unlit rectangles.
 *   4. The harness venue payload stays in sync with the seeder row (the
 *      PHP-less visual harness renders the same JSON a fresh install gets).
 *
 * Portable patterns per the IT2–IT6 suites: sqlite-safe JSON
 * read-modify-write, migrations invoked directly.
 */
class VenueIndustrialLoftIterationTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // The deepened identity (mirrors the seeder + harness payload)
    // ─────────────────────────────────────────────────────────────────────

    public const DEEPENED_VISUAL = [
        'fog_near'              => 14,
        'fog_far'               => 55,
        'ambient_intensity'     => 0.55,
        'spot_intensity'        => 2.4,
        'fill_intensity'        => 1.1,
        'tone_mapping_exposure' => 0.9,
        'frame_override'        => 'black',
        'corridor_width'        => 9,
        'artwork_light_base'     => 0.22,
        'artwork_light_pool_cap' => 12,
        'env_intensity'          => 0.25,
    ];

    public const DEEPENED_POST_FX = [
        'bloom'             => false,
        'vignette'          => true,
        'vignette_darkness' => 0.35,
        'vignette_offset'   => 1.0,
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_09_06_000001_industrial_loft_deepening.php');
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

    private function defaultSettings(string $slug): array
    {
        return $this->jsonCol($slug, 'default_settings');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. The fresh-install baseline IS the deepened identity
    // ─────────────────────────────────────────────────────────────────────

    public function test_seeded_industrial_loft_declares_the_deepened_identity(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $vc = $this->visualConfig('industrial-loft');

        foreach (self::DEEPENED_VISUAL as $key => $value) {
            $this->assertSame(
                $value,
                is_int($value) ? $vc[$key] ?? null : (float) ($vc[$key] ?? -1),
                "[industrial-loft] visual_config.{$key} must declare the deepened value."
            );
        }

        $this->assertSame(
            self::DEEPENED_POST_FX,
            $vc['post_fx'] ?? null,
            '[industrial-loft] must declare its post-fx restraint (bloom off — the runtime default put bloom ON in a venue of emissive lamps).'
        );

        // Identity keys the consolidation iteration pinned — unchanged.
        $this->assertSame('loft', $vc['structure_pass'] ?? null, '[industrial-loft] keeps its interpreter selector.');
        $this->assertSame('0x1a1a18', $vc['ceiling_color'] ?? null, '[industrial-loft] keeps its declared ceiling colour.');
        $this->assertTrue($vc['ceiling_beams'] ?? false, '[industrial-loft] keeps its declared ceiling girders.');
        $this->assertArrayNotHasKey('open_air', $vc, '[industrial-loft] is a walled room — no open_air.');
        $this->assertArrayNotHasKey('layout_shape', $vc, '[industrial-loft] is a walled room — no layout_shape override.');

        $mc = $this->materialConfig('industrial-loft');
        $this->assertSame(3.0, (float) ($mc['floor_tile_meters'] ?? -1), '[industrial-loft] floor reads at pour scale.');
        $this->assertSame(0.8, (float) ($mc['floor_roughness'] ?? -1), '[industrial-loft] floor is sealed, not wet cement.');

        $ds = $this->defaultSettings('industrial-loft');
        $this->assertSame('square', $ds['room_layout'] ?? null, '[industrial-loft] default layout is the open warehouse floor (the corridor default produced a 6 × 108 m tunnel at capacity).');

        $row = $this->venueRow('industrial-loft');
        $this->assertSame('2.0.0', $row->version, '[industrial-loft] version bumps to 2.0.0.');
        // Customer-facing truth: the copy must promise the rendered identity.
        $this->assertTrue(
            Str::contains((string) $row->description, ['girders', 'joists']),
            '[industrial-loft] description references the rendered ceiling structure.'
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
        DB::table('venue_templates')->where('slug', 'industrial-loft')->update([
            'version'          => '1.0.0',
            'visual_config' => json_encode([
                'wall_height'            => 7,
                'wall_depth'             => 0.5,
                'ceiling_type'           => 'beamed',
                'ceiling_color'          => '0x1a1a18',
                'ceiling_beams'          => true,
                'ceiling_height'         => 7,
                'background_color'       => '0x111008',
                'fog_color'              => '0x111008',
                'fog_near'               => 8,
                'fog_far'                => 35,
                'ambient_color'          => '0xffd9a8',
                'structure_pass'         => 'loft',
                'ambient_intensity'      => 0.18,
                'spot_intensity'         => 0.5,
                'fill_intensity'         => 0.15,
                'tone_mapping_exposure'  => 0.55,
                'frame_override'         => null,
            ]),
            'material_config' => json_encode([
                'wall_color'            => null,
                'wall_roughness'        => 1.0,
                'wall_metalness'        => 0.0,
                'wall_normal_strength'  => 0.8,
                'floor_color'           => null,
                'floor_roughness'       => 0.9,
                'floor_metalness'       => 0.0,
                'floor_normal_strength' => 0.7,
            ]),
            'default_settings' => json_encode([
                'wall_texture'    => 'concrete',
                'floor_material'  => 'concrete',
                'lighting_preset' => 'dramatic',
                'frame_style'     => 'modern',
                'room_layout'     => 'corridor',
            ]),
        ]);

        ($this->migration())->up();

        $vc = $this->visualConfig('industrial-loft');
        foreach (self::DEEPENED_VISUAL as $key => $value) {
            $this->assertSame(
                $value,
                is_int($value) ? $vc[$key] ?? null : (float) ($vc[$key] ?? -1),
                "[migration] visual_config.{$key} rewritten from the v1.0.0 seed."
            );
        }
        $this->assertSame(self::DEEPENED_POST_FX, $vc['post_fx'] ?? null, '[migration] post_fx added when absent.');
        $this->assertSame(3.0, (float) ($this->materialConfig('industrial-loft')['floor_tile_meters'] ?? -1));
        $this->assertSame('square', $this->defaultSettings('industrial-loft')['room_layout'] ?? null);
        $this->assertSame('1.0.0', $this->venueRow('industrial-loft')->version, '[migration] never touches version (the seeder owns it).');

        // Idempotent: a second run changes nothing (guards no longer match).
        $before = $this->venueRow('industrial-loft');
        ($this->migration())->up();
        $this->assertSame($before->visual_config, $this->venueRow('industrial-loft')->visual_config, '[migration] second run is a no-op (idempotent).');
    }

    public function test_migration_leaves_admin_customised_values_alone(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // An admin hand-tuned the rig BEFORE this iteration ships.
        DB::table('venue_templates')->where('slug', 'industrial-loft')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('industrial-loft'), [
                'ambient_intensity'     => 0.77,   // custom — must survive
                'tone_mapping_exposure' => 1.3,    // custom — must survive
                'post_fx'               => ['bloom' => true], // custom — must survive
            ])),
        ]);

        ($this->migration())->up();

        $vc = $this->visualConfig('industrial-loft');
        $this->assertSame(0.77, (float) $vc['ambient_intensity'], '[migration] admin ambient survives.');
        $this->assertSame(1.3, (float) $vc['tone_mapping_exposure'], '[migration] admin exposure survives.');
        $this->assertSame(['bloom' => true], $vc['post_fx'], '[migration] admin post_fx survives.');
        // Untouched keys keep their deepened values (their guards no longer
        // match the stored values — nothing to rewrite).
        $this->assertSame(2.4, (float) $vc['spot_intensity'], '[migration] deepened keys keep their values when no guard matches.');
    }

    public function test_migration_down_reverses_each_rewrite(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        ($this->migration())->down();

        $vc = $this->visualConfig('industrial-loft');
        $this->assertSame(0.18, (float) $vc['ambient_intensity']);
        $this->assertSame(0.55, (float) $vc['tone_mapping_exposure']);
        $this->assertSame(8, $vc['fog_near'] ?? null);
        $this->assertNull($vc['frame_override'] ?? null, '[down] frame_override returns to null.');
        $this->assertArrayNotHasKey('post_fx', $vc, '[down] added post_fx removed.');
        $this->assertArrayNotHasKey('artwork_light_base', $vc, '[down] added artwork_light_base removed.');
        $this->assertArrayNotHasKey('corridor_width', $vc, '[down] added corridor_width removed.');
        $this->assertSame('corridor', $this->defaultSettings('industrial-loft')['room_layout'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. The generic dark-venue legibility contract
    // ─────────────────────────────────────────────────────────────────────

    public function test_dark_rigs_declare_artwork_legibility(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // A venue whose exposure sits below ~1.0 renders its rig dark — the
        // hang must then declare a standing glow (the audit's rule: no
        // artwork sits in the dark because the venue forgot the hang).
        //
        // SCOPED to the audited venues: industrial-loft (this iteration) and
        // infinite-void (its deepening). The remaining dark venues keep
        // their historical rows by contract (§27 iteration discipline —
        // they receive their own passes; this test widens with each).
        foreach (['industrial-loft', 'infinite-void'] as $slug) {
            $vc = $this->visualConfig($slug);
            $exposure = (float) ($vc['tone_mapping_exposure'] ?? 0.5);
            $this->assertLessThan(1.0, $exposure, "[{$slug}] preconditions: a dark rig.");
            $this->assertArrayHasKey(
                'artwork_light_base',
                $vc,
                "[{$slug}] renders dark (exposure {$exposure}) — it must declare artwork_light_base so the hang is never unlit."
            );
            $this->assertGreaterThanOrEqual(
                0.2,
                (float) $vc['artwork_light_base'],
                "[{$slug}] artwork_light_base must be a real standing glow (≥ 0.2), not the generic 0.15."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. The harness payload stays in sync with the seeder row
    // ─────────────────────────────────────────────────────────────────────

    public function test_harness_payload_matches_the_seeded_row(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $harness = file_get_contents(base_path('scripts/harness/harness.html'));
        $this->assertNotFalse($harness, 'harness.html must exist.');

        $start = strpos($harness, "'industrial-loft': {");
        $this->assertNotFalse($start, 'harness must embed an industrial-loft body.');
        $body = substr($harness, $start, (int) strpos($harness, 'version:', $start) - $start);

        $vc = $this->visualConfig('industrial-loft');
        foreach (['fog_near', 'fog_far', 'ambient_intensity', 'spot_intensity', 'fill_intensity', 'tone_mapping_exposure'] as $key) {
            $this->assertStringContainsString(
                "{$key}: " . $vc[$key],
                $body,
                "[harness] industrial-loft {$key} must mirror the seeded row."
            );
        }
        $this->assertStringContainsString('room_layout: \'square\'', $body);
    }
}
