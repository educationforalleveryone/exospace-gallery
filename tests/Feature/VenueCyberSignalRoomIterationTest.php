<?php

declare(strict_types=1);

/**
 * Cyber Gallery v2.0.0 "SIGNAL ROOM" iteration tests (movement-reactive
 * artwork identity).
 *
 * Pins the contract so future changes cannot silently break the signature:
 *
 *   - Declared identity: the seeder row carries visual_config.artwork_reactive
 *     (the movement-reactive artwork declaration the JS interpreter consumes),
 *     the declared environment absence ('none' + env_intensity 0 — the room
 *     refuses a sky), the rig lift, bloom identity + black-blend vignette,
 *     frame_override 'black', artwork standing glow + pool cap, and the
 *     material parity fix (texture_tint + declared dark floor).
 *   - Honesty matrix: the copy promises the reactive mechanic, keeps the
 *     pinned neon/floor words (VenueRoomsIterationTest contract), and no
 *     longer carries the superseded v1.0.0 wording.
 *   - The migration is a safe, guarded rewrite: exact-match guards keep a
 *     super-admin's custom values, absent keys are added only when missing,
 *     the run is idempotent, and down() reverses each rewrite under the same
 *     guard.
 *   - The signature is venue-owned: 'artwork_reactive' ships on the
 *     exporter's VENUE_OWNED_VISUAL_KEYS (a curator override that retuned or
 *     disabled it would recompose the venue into a different one).
 *   - Preview/gallery payload parity: the declaration reaches the client on
 *     both the public view and the editor preview (VenuePreviewController /
 *     buildGalleryData share the exporter).
 *
 * Run: php artisan test --filter=VenueCyberSignalRoomIterationTest
 */

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VenueCyberSignalRoomIterationTest extends TestCase
{
    private const SEEDED_REACTIVE = [
        'enabled'       => true,
        'dead_zone'     => 0.18,
        'ref_speed'     => 3.0,
        'attack'        => 0.18,
        'release'       => 1.1,
        'max_intensity' => 1.0,
        'bezel_color'   => '0x00e5ff',
    ];

    private const V1_DESCRIPTION = 'A dark electric space ringed with neon on every edge, the floor traced in light. For digital and web3 creators.';

    private const V2_DESCRIPTION = 'A signal room for digital natives: dark anodized walls, a floor traced in light, neon ringing every edge — and artworks that behave like living media. Stand still and they hold still. Move, and they react to you.';

    // ─────────────────────────────────────────────────────────────────────
    // Declared identity — the seeder contract the JS interpreter consumes
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_seeded_row_declares_the_signal_room(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $config = $this->visualConfig('cyber-gallery');

        $this->assertSame(
            self::SEEDED_REACTIVE,
            $config['artwork_reactive'] ?? null,
            '[cyber-gallery] must declare the movement-reactive artwork signature with the designed tuning.'
        );
        $this->assertSame('none', $config['environment'] ?? null, '[cyber-gallery] must declare environment none — a controlled signal room refuses a sky (no night.hdr download, no sky leak).');
        $this->assertSame(0, $config['env_intensity'] ?? null, '[cyber-gallery] must silence the preset HDRI glow at its source.');
        $this->assertSame(0.05, $config['hemisphere_intensity'] ?? null, '[cyber-gallery] must soften the hemisphere fill.');
        $this->assertSame('black', $config['frame_override'] ?? null, '[cyber-gallery] must frame artworks with the black device bezel — the luminous boundary supplies the colour.');
        $this->assertSame(0.28, $config['artwork_light_base'] ?? null, '[cyber-gallery] must give every artwork a standing glow (no artwork sits in the dark).');
        $this->assertSame(12, $config['artwork_light_pool_cap'] ?? null, '[cyber-gallery] must cap the reactive light pool at 12.');
        $this->assertSame(0.42, $config['ambient_intensity'] ?? null, '[cyber-gallery] rig must be lifted to the legibility floor (was 0.18 murk).');
        $this->assertSame(1.6, $config['spot_intensity'] ?? null, '[cyber-gallery] rig must carry the hang (was 0.55).');
        $this->assertSame(0.7, $config['tone_mapping_exposure'] ?? null, '[cyber-gallery] exposure must clear the murk (was 0.5).');
        $this->assertSame(10, $config['fog_near'] ?? null, '[cyber-gallery] fog reach must widen (was 6).');
        $this->assertSame(26, $config['fog_far'] ?? null, '[cyber-gallery] fog reach must widen (was 22).');

        $postFx = $config['post_fx'] ?? [];
        $this->assertTrue($postFx['bloom'] ?? false, '[cyber-gallery] bloom is the venue identity (the neon + luminous bezels read as light sources).');
        $this->assertSame(0.55, $postFx['bloom_strength'] ?? null);
        $this->assertSame(0.8, $postFx['bloom_threshold'] ?? null);
        $this->assertSame('black', $postFx['vignette_blend'] ?? null, '[cyber-gallery] vignette must blend to black (the grey-veil defect class never ships again).');

        $material = $this->materialConfig('cyber-gallery');
        $this->assertTrue($material['texture_tint'] ?? false, '[cyber-gallery] THE parity fix — the declared dark tint must reach textured builds (the concrete PBR set used to re-tint every desktop wall 0xffffff while low-end rendered the declared dark).');
        $this->assertSame('0x0a0a14', $material['wall_color'] ?? null, '[cyber-gallery] must declare the dark anodized wall colour.');
        $this->assertSame('0x0b0d14', $material['floor_color'] ?? null, '[cyber-gallery] must declare a dark floor (null meant the bright preset concrete was the brightest plane in a dark venue).');
        $this->assertSame(2.0, $material['floor_tile_meters'] ?? null, '[cyber-gallery] must declare the polished signal-floor tile rhythm.');

        $this->assertSame('2.0.0', (string) DB::table('venue_templates')->where('slug', 'cyber-gallery')->value('version'), '[cyber-gallery] version must pin 2.0.0 (Signal Room).');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Honesty matrix — the copy promises exactly what renders
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_copy_promise_matches_the_delivered_mechanic(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $row = DB::table('venue_templates')->where('slug', 'cyber-gallery')->first(['description']);

        $this->assertStringNotContainsString('dark electric space', (string) $row->description, 'The v1.0.0 wording promised nothing that identified this venue — it must be gone.');
        $this->assertMatchesRegularExpression('/stand still/i', (string) $row->description, 'The copy must promise the stillness = clarity half of the mechanic.');
        $this->assertMatchesRegularExpression('/move/i', (string) $row->description, 'The copy must promise the movement = reaction half of the mechanic.');
        $this->assertMatchesRegularExpression('/living media/i', (string) $row->description, 'The copy must name the display methodology (living digital media, not framed prints).');
        $this->assertMatchesRegularExpression('/neon/i', (string) $row->description, 'The rooms-pass contract (VenueRoomsIterationTest) pins the neon promise.');
        $this->assertMatchesRegularExpression('/floor/i', (string) $row->description, 'The rooms-pass contract (VenueRoomsIterationTest) pins the floor light promise.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The migration — guarded, idempotent, reversible, admin-respecting
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_migration_upgrades_a_v100_production_row(): void
    {
        $this->seedLegacyCyberRow();

        $this->migration()->up();

        $row = DB::table('venue_templates')->where('slug', 'cyber-gallery')->first(['visual_config', 'material_config', 'description', 'version']);
        $config = json_decode((string) $row->visual_config, true);
        $material = json_decode((string) $row->material_config, true);

        $this->assertSame(self::SEEDED_REACTIVE, $config['artwork_reactive'] ?? null, 'up() must add the signature to a legacy row.');
        $this->assertSame('none', $config['environment'] ?? null);
        $this->assertSame('black', $config['frame_override'] ?? null);
        $this->assertSame(0.42, $config['ambient_intensity'] ?? null, 'The guarded rewrite must lift the murk rig (0.18 → 0.42).');
        $this->assertTrue($material['texture_tint'] ?? false, 'up() must add the parity fix.');
        $this->assertSame('0x0b0d14', $material['floor_color'] ?? null, 'up() must replace the explicit null floor (bright preset concrete in a dark venue).');
        $this->assertSame(self::V2_DESCRIPTION, (string) $row->description, 'up() must upgrade the v1.0.0 copy.');
        $this->assertSame('2.0.0', (string) $row->version);
    }

    public function test_the_migration_is_idempotent(): void
    {
        $this->seedLegacyCyberRow();

        $this->migration()->up();
        $afterFirst = DB::table('venue_templates')->where('slug', 'cyber-gallery')->first(['visual_config', 'material_config', 'description', 'version']);

        $this->migration()->up();
        $afterSecond = DB::table('venue_templates')->where('slug', 'cyber-gallery')->first(['visual_config', 'material_config', 'description', 'version']);

        $this->assertSame((array) $afterFirst, (array) $afterSecond, 'A second up() run must be a no-op.');
    }

    public function test_the_migration_respects_admin_edits(): void
    {
        $this->seedLegacyCyberRow();

        // A super-admin retuned the rig and the signature BEFORE the pass.
        $adminConfig = [
            'fog_near'              => 8,
            'fog_far'               => 30,
            'ambient_intensity'     => 0.5,
            'spot_intensity'        => 2.0,
            'fill_intensity'        => 0.6,
            'tone_mapping_exposure' => 0.9,
            'frame_override'        => 'brushed',
            'artwork_reactive'      => ['enabled' => true, 'dead_zone' => 0.3, 'ref_speed' => 4.0, 'bezel_color' => '0xff00aa'],
        ];
        DB::table('venue_templates')->where('slug', 'cyber-gallery')->update([
            'visual_config'  => json_encode($adminConfig),
            'material_config' => json_encode(['texture_tint' => false, 'floor_color' => '0x111122', 'floor_roughness' => 0.7, 'floor_metalness' => 0.2]),
            'description'    => 'My own signal room copy.',
            'version'        => '9.9.9',
        ]);

        $this->migration()->up();

        $row = DB::table('venue_templates')->where('slug', 'cyber-gallery')->first(['visual_config', 'material_config', 'description', 'version']);
        $config = json_decode((string) $row->visual_config, true);
        $material = json_decode((string) $row->material_config, true);

        $this->assertSame(0.5, $config['ambient_intensity'], 'An admin value that differs from the seeded "from" guard must never be rewritten.');
        $this->assertSame('brushed', $config['frame_override'], 'An admin frame choice wins over the pass default.');
        $this->assertSame($adminConfig['artwork_reactive'], $config['artwork_reactive'] ?? null, 'An admin-declared signature (any shape) is never overwritten.');
        $this->assertFalse($material['texture_tint'], 'An admin texture_tint choice wins.');
        $this->assertSame('0x111122', $material['floor_color'], 'An admin floor wins over the null-floor rewrite.');
        $this->assertSame('My own signal room copy.', (string) $row->description, 'Admin copy is never touched.');
        $this->assertSame('9.9.9', (string) $row->version, 'A drifted version pin is never touched.');
    }

    public function test_the_migration_is_reversible(): void
    {
        $this->seedLegacyCyberRow();

        $this->migration()->up();
        $this->migration()->down();

        $row = DB::table('venue_templates')->where('slug', 'cyber-gallery')->first(['visual_config', 'material_config', 'description', 'version']);
        $config = json_decode((string) $row->visual_config, true);
        $material = json_decode((string) $row->material_config, true);

        $this->assertArrayNotHasKey('artwork_reactive', $config, 'down() removes the signature it added (and only while it still matches).');
        $this->assertArrayNotHasKey('post_fx', $config);
        $this->assertArrayNotHasKey('environment', $config);
        $this->assertNull($config['frame_override'] ?? null, 'down() restores the null frame_override.');
        $this->assertSame(0.18, $config['ambient_intensity'] ?? null, 'down() restores the murk rig.');
        $this->assertArrayNotHasKey('texture_tint', $material);
        $this->assertNull($material['floor_color'] ?? null, 'down() restores the null floor.');
        $this->assertSame(self::V1_DESCRIPTION, (string) $row->description);
        $this->assertSame('1.0.0', (string) $row->version);

        // Idempotent down: a second run is a safe no-op.
        $this->migration()->down();
        $again = (array) DB::table('venue_templates')->where('slug', 'cyber-gallery')->first(['visual_config']);
        $this->assertSame((array) $row, $again, 'A second down() run must be a no-op.');
    }

    public function test_down_never_discards_an_admin_reactive_declaration(): void
    {
        $this->seedLegacyCyberRow();

        $this->migration()->up();

        // The admin retunes the signature AFTER the pass — down() must leave it.
        DB::table('venue_templates')->where('slug', 'cyber-gallery')->update([
            'visual_config' => json_encode(array_merge(
                json_decode((string) DB::table('venue_templates')->where('slug', 'cyber-gallery')->value('visual_config'), true),
                ['artwork_reactive' => ['enabled' => true, 'dead_zone' => 0.4, 'release' => 2.5]]
            )),
        ]);

        $this->migration()->down();

        $config = json_decode((string) DB::table('venue_templates')->where('slug', 'cyber-gallery')->value('visual_config'), true);
        $this->assertSame(['enabled' => true, 'dead_zone' => 0.4, 'release' => 2.5], $config['artwork_reactive'] ?? null, 'down() must preserve an admin post-pass edit of the signature.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ownership + payload parity
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_reactive_declaration_is_venue_owned(): void
    {
        $exporter = \Illuminate\Support\Facades\File::get(app_path('Services/VenueConfigExporter.php'));

        $this->assertMatchesRegularExpression(
            "/'artwork_reactive'/",
            $exporter,
            'The signature must ship on VENUE_OWNED_VISUAL_KEYS — a curator override that retuned or disabled it would recompose the venue into a different one.'
        );
    }

    public function test_the_payload_carries_the_signal_room_to_the_client(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $exporter = app(\App\Services\VenueConfigExporter::class);

        $cyber   = \App\Models\VenueTemplate::where('slug', 'cyber-gallery')->firstOrFail();
        $config  = $exporter->forVenuePreview($cyber);

        $this->assertSame(
            self::SEEDED_REACTIVE,
            $config['visual_config']['artwork_reactive'] ?? null,
            'The movement-reactive declaration must reach the client payload (preview and public view share the exporter).'
        );
        $this->assertSame('none', $config['visual_config']['environment'] ?? null);
        $this->assertTrue($config['material_config']['texture_tint'] ?? false, 'The parity fix must reach the client material config.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function migration(): object
    {
        return require database_path('migrations/2026_09_09_000010_cyber_gallery_signal_room.php');
    }

    /** The v1.0.0 row exactly as production holds it pre-pass. */
    private function seedLegacyCyberRow(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        DB::table('venue_templates')->where('slug', 'cyber-gallery')->update([
            'description' => self::V1_DESCRIPTION,
            'version'     => '1.0.0',
            'visual_config' => json_encode([
                'wall_height'            => 6,
                'wall_depth'             => 0.4,
                'ceiling_type'           => 'flat',
                'ceiling_color'          => '0x04081a',
                'ceiling_height'         => 6,
                'ceiling_neon'           => true,
                'background_color'       => '0x020412',
                'fog_color'              => '0x020412',
                'fog_near'               => 6,
                'fog_far'                => 22,
                'ambient_color'          => '0x3060ff',
                'ambient_intensity'      => 0.18,
                'spot_intensity'         => 0.55,
                'fill_intensity'         => 0.1,
                'tone_mapping_exposure'  => 0.5,
                'frame_override'         => null,
                'structure_pass'         => 'rooms',
            ]),
            'material_config' => json_encode([
                'wall_color'           => '0x0a0a14',
                'wall_roughness'       => 0.6,
                'wall_metalness'       => 0.3,
                'wall_normal_strength' => 0.5,
                'floor_color'          => null,
                'floor_roughness'      => 0.4,
                'floor_metalness'      => 0.5,
                'floor_normal_strength' => 0.5,
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
