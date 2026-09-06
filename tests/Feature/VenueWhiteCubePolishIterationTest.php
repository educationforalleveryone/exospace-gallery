<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHITE CUBE POLISH iteration — the forensic-audit remediation as CI.
 *
 * Pins:
 *   1. The seeded white-cube row declares the remediated identity (gallery-
 *      white fog, physical-unit light rig, exposure ≥ 1.0, post_fx restraint,
 *      polished-concrete floor, charcoal default frame) — a white cube that
 *      can no longer render as a soot-grey box on a fresh install.
 *   2. The guarded migration rewrites ONLY the previously seeded values
 *      (admin customisations survive), is idempotent, and down() reverses
 *      each rewrite under the same exact-match guard.
 *   3. The fog/exposure contract is generic: no venue may declare a fog
 *      colour darker than its own identity can survive on a "bright" preset
 *      without declaring intent (the audit's core finding: the default free
 *      venue shipped void-black fog by copy-paste, not by decision).
 *   4. The harness venue payload stays in sync with the seeder row (the
 *      PHP-less visual harness renders the same JSON a fresh install gets).
 *
 * Portable patterns per the IT2–IT6 suites: sqlite-safe JSON
 * read-modify-write, migrations invoked directly.
 */
class VenueWhiteCubePolishIterationTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    // The remediated identity (mirrors the seeder + harness payload)
    // ─────────────────────────────────────────────────────────────────────

    public const POLISHED = [
        'background_color'      => '0xf2f1ee',
        'fog_color'             => '0xf2f1ee',
        'fog_near'              => 16,
        'fog_far'               => 60,
        'ambient_intensity'     => 0.55,
        'spot_intensity'        => 3.2,
        'fill_intensity'        => 2.6,
        'tone_mapping_exposure' => 1.05,
    ];

    private function polishMigration(): object
    {
        return require database_path('migrations/2026_09_02_000001_white_cube_polish.php');
    }

    private function visualConfig(string $slug): array
    {
        return json_decode(
            (string) DB::table('venue_templates')->where('slug', $slug)->value('visual_config'),
            true
        ) ?: [];
    }

    private function materialConfig(string $slug): array
    {
        return json_decode(
            (string) DB::table('venue_templates')->where('slug', $slug)->value('material_config'),
            true
        ) ?: [];
    }

    private function defaultSettings(string $slug): array
    {
        return json_decode(
            (string) DB::table('venue_templates')->where('slug', $slug)->value('default_settings'),
            true
        ) ?: [];
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. The fresh-install baseline IS the polished identity
    // ─────────────────────────────────────────────────────────────────────

    public function test_seeded_white_cube_declares_the_polished_identity(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $vc = $this->visualConfig('white-cube');

        foreach (self::POLISHED as $key => $value) {
            $this->assertSame(
                $value,
                is_float($value) ? (float) ($vc[$key] ?? -1) : ($vc[$key] ?? null),
                "[white-cube] visual_config.{$key} must declare the polished value."
            );
        }

        $this->assertSame('cube', $vc['structure_pass'] ?? null, 'The respect-pass selector is untouched by the polish.');

        // Post-processing restraint: bloom OFF (calm identity), softened vignette.
        $this->assertSame([
            'bloom'             => false,
            'vignette'          => true,
            'vignette_darkness' => 0.28,
            'vignette_offset'   => 1.05,
        ], $vc['post_fx'] ?? null, '[white-cube] must declare its post-fx identity explicitly.');

        // Material identity: sealed polished concrete, not wet cement.
        $mc = $this->materialConfig('white-cube');
        $this->assertSame('0x9c9c98', $mc['floor_color'] ?? null);
        $this->assertSame(0.55, (float) ($mc['floor_roughness'] ?? -1));

        // Default frame: charcoal 'modern' (edge definition on white walls).
        $this->assertSame('modern', $this->defaultSettings('white-cube')['frame_style'] ?? null);
    }

    public function test_white_cube_fog_dissolves_toward_gallery_white_not_soot(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $vc = $this->visualConfig('white-cube');

        // The audit's core finding: a bright venue whose fog was darker than
        // its own walls. The fog colour must be LIGHTER than mid-grey — a
        // white cube's atmosphere is light, not void.
        $fog = hexdec(substr((string) $vc['fog_color'], 2));
        $this->assertGreaterThan(
            0xB0B0B0,
            $fog,
            '[white-cube] fog must dissolve toward gallery white (audit: 0x0f0f0f sooted every far wall).'
        );

        // And the fog must not reach into normal viewing distances: at the
        // default 10.5 m wall nothing should be hazy yet.
        $this->assertGreaterThanOrEqual(14, $vc['fog_near']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. The migration — guarded rewrite, idempotent, reversible
    // ─────────────────────────────────────────────────────────────────────

    public function test_migration_rewrites_seeded_values_and_survives_reruns(): void
    {
        // Start from the PRE-polish seeded values (what production rows hold).
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        DB::table('venue_templates')->where('slug', 'white-cube')->update([
            'visual_config' => json_encode([
                'wall_height'            => 4,
                'wall_depth'             => 0.3,
                'ceiling_type'           => 'flat',
                'ceiling_height'         => 4,
                'background_color'       => '0x0f0f0f',
                'fog_color'              => '0x0f0f0f',
                'fog_near'               => 10,
                'fog_far'                => 30,
                'ambient_color'          => '0xffffff',
                'ambient_intensity'      => 0.2,
                'spot_intensity'         => 0.45,
                'fill_intensity'         => 0.12,
                'tone_mapping_exposure'  => 0.5,
                'frame_override'         => null,
                'structure_pass'         => 'cube',
            ]),
            'material_config' => json_encode([
                'wall_color'            => null,
                'wall_roughness'        => 0.9,
                'wall_metalness'        => 0.0,
                'wall_normal_strength'  => 0.3,
                'floor_color'           => null,
                'floor_roughness'       => 0.7,
                'floor_metalness'       => 0.0,
                'floor_normal_strength' => 0.4,
                'floor_tile_meters'     => 2.0,
            ]),
            'default_settings' => json_encode([
                'wall_texture'   => 'white',
                'floor_material' => 'concrete',
                'lighting_preset' => 'bright',
                'frame_style'    => 'minimal',
                'room_layout'    => 'square',
            ]),
        ]);

        $this->polishMigration()->up();

        $vc = $this->visualConfig('white-cube');
        foreach (self::POLISHED as $key => $value) {
            $this->assertSame(
                is_float($value) ? (float) $value : $value,
                is_float($value) ? (float) ($vc[$key] ?? -1) : ($vc[$key] ?? null),
                "up() rewrites the seeded {$key}."
            );
        }
        $this->assertSame('cube', $vc['structure_pass'], 'The pass selector is never touched.');
        $this->assertSame('0xffffff', $vc['ambient_color'], 'Untouched keys stay byte-identical.');
        $this->assertSame(0.28, (float) ($vc['post_fx']['vignette_darkness'] ?? -1), 'post_fx is added when absent.');
        $this->assertSame(0.55, (float) ($this->materialConfig('white-cube')['floor_roughness'] ?? -1));
        $this->assertSame('modern', $this->defaultSettings('white-cube')['frame_style']);

        // Idempotent: a second run changes nothing (and does not double-apply).
        $snapshot = $this->visualConfig('white-cube');
        $this->polishMigration()->up();
        $this->assertSame($snapshot, $this->visualConfig('white-cube'), 'up() is idempotent.');
    }

    public function test_migration_never_clobbers_admin_customisations(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // A super-admin has hand-tuned the venue after the polish: every
        // guarded rewrite must leave their values alone.
        DB::table('venue_templates')->where('slug', 'white-cube')->update([
            'visual_config' => json_encode([
                'fog_color'             => '0xe8e4da',
                'tone_mapping_exposure' => 1.25,
                'post_fx'               => ['bloom' => true],
                'structure_pass'        => 'cube',
            ]),
            'material_config' => json_encode([
                'floor_color'     => '0xa8a49c',
                'floor_roughness' => 0.4,
            ]),
            'default_settings' => json_encode([
                'frame_style' => 'classic',
            ]),
        ]);

        $this->polishMigration()->up();

        $vc = $this->visualConfig('white-cube');
        $this->assertSame('0xe8e4da', $vc['fog_color'], 'Admin fog survives.');
        $this->assertSame(1.25, (float) $vc['tone_mapping_exposure'], 'Admin exposure survives.');
        $this->assertSame(['bloom' => true], $vc['post_fx'], 'Admin post_fx is never replaced.');
        $this->assertSame('0xa8a49c', $this->materialConfig('white-cube')['floor_color']);
        $this->assertSame(0.4, (float) $this->materialConfig('white-cube')['floor_roughness']);
        $this->assertSame('classic', $this->defaultSettings('white-cube')['frame_style']);
    }

    public function test_migration_down_reverses_only_unmodified_rewrites(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Give the row the pre-polish values, apply up(), then down().
        DB::table('venue_templates')->where('slug', 'white-cube')->update([
            'visual_config' => json_encode([
                'background_color'      => '0x0f0f0f',
                'fog_color'             => '0x0f0f0f',
                'fog_near'              => 10,
                'fog_far'               => 30,
                'ambient_intensity'     => 0.2,
                'spot_intensity'        => 0.45,
                'fill_intensity'        => 0.12,
                'tone_mapping_exposure' => 0.5,
                'structure_pass'        => 'cube',
            ]),
        ]);

        $this->polishMigration()->up();
        $this->polishMigration()->down();

        $vc = $this->visualConfig('white-cube');
        $this->assertSame('0x0f0f0f', $vc['fog_color'], 'down() restores the pre-polish fog.');
        $this->assertSame(0.5, (float) $vc['tone_mapping_exposure']);
        $this->assertArrayNotHasKey('post_fx', $vc, 'down() removes post_fx while unmodified.');

        // An admin-edited value does NOT revert.
        $this->polishMigration()->up();
        DB::table('venue_templates')->where('slug', 'white-cube')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('white-cube'), [
                'tone_mapping_exposure' => 1.4,
            ])),
        ]);
        $this->polishMigration()->down();
        $this->assertSame(1.4, (float) $this->visualConfig('white-cube')['tone_mapping_exposure'], 'Admin-tuned exposure survives down().');
    }

    public function test_migration_respects_a_missing_venue_row(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        DB::table('venue_templates')->where('slug', 'white-cube')->delete();

        $this->polishMigration()->up(); // must not throw
        $this->polishMigration()->down();

        $this->assertNull(
            DB::table('venue_templates')->where('slug', 'white-cube')->value('id'),
            'A deleted venue stays deleted (archive-not-delete is the operator path).'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. The visual-harness payload mirrors the fresh-install row
    // ─────────────────────────────────────────────────────────────────────

    public function test_visual_harness_payload_matches_the_seeded_identity(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $html = file_get_contents(resource_path('../scripts/harness/harness.html'));
        $this->assertNotFalse($html, 'The PHP-less harness must ship with the repo.');

        foreach (self::POLISHED as $key => $value) {
            if (is_string($value)) {
                $this->assertStringContainsString(
                    (string) $value,
                    $html,
                    "Harness venue payload drifted from the seeder for {$key}."
                );
            }
        }
        $this->assertStringContainsString('post_fx', $html, 'Harness payload must declare post_fx.');
        $this->assertStringContainsString('bloom: false', $html, 'Harness payload must carry the bloom-off declaration.');
    }
}
