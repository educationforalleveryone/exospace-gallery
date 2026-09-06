<?php

declare(strict_types=1);

/**
 * Iteration 3 "ROOMS" regression tests (3D venue roadmap, P1.3).
 *
 * Pins the Room-family identity contract so future changes cannot silently
 * re-introduce the material-swap problem or break the rollback switches:
 *
 *   - Declared structure: zen/penthouse/cyber carry `structure` descriptors
 *     + `structure_pass = 'rooms'`; penthouse adds `glazing_wall`; white-cube
 *     selects its bespoke respect pass with `structure_pass = 'cube'`
 *     (Iteration 6 made structure_pass the single interpreter selector);
 *     the garden declares `sun_shadows` + its own 'garden' pass.
 *     Interpreter selection is opt-in per venue — the config is the only
 *     on-switch (§11.3 rule 2); venues without a pass render no structure.
 *   - Vocabulary ceiling: the descriptor arrays use ONLY the ≤10 primitives
 *     of §10.3 (checked by name against the frozen JS list).
 *   - Copy matrix extension: a venue that declares structure promises its
 *     signature in words; venues without structure must not.
 *   - The migration is a safe, idempotent, UNION-merge (visual + material
 *     config), with exact-match guards on the museum wall texture and the
 *     three re-tightened descriptions; down() removes exactly what up()
 *     added, and only while it still matches.
 *   - The payload carries the structure keys to the viewer + previews.
 *   - DoD rule #7 (hard from Iteration 2 onward): StructureBuilder — the new
 *     interpreter — contains ZERO venue slugs.
 *
 * Run: php artisan test --filter=VenueRoomsIterationTest
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VenueRoomsIterationTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTOR_VENUES = ['zen-gallery', 'luxury-penthouse', 'cyber-gallery'];
    private const ROOM_PASS_VENUES  = ['white-cube', 'zen-gallery', 'luxury-penthouse', 'cyber-gallery'];

    // ─────────────────────────────────────────────────────────────────────
    // Declared structure — the config contract the interpreter consumes
    // ─────────────────────────────────────────────────────────────────────

    public function test_descriptor_venues_declare_rooms_structure(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        foreach (self::DESCRIPTOR_VENUES as $slug) {
            $config = $this->visualConfig($slug);

            $this->assertSame(
                'rooms',
                $config['structure_pass'] ?? null,
                "[{$slug}] must declare structure_pass 'rooms' (also its per-venue rollback switch)."
            );
            $this->assertIsArray(
                $config['structure'] ?? null,
                "[{$slug}] must declare a structure descriptor array."
            );
            $this->assertNotEmpty(
                $config['structure'],
                "[{$slug}] structure array must not be empty."
            );
            $this->assertArrayNotHasKey(
                'placement_mode',
                $config,
                "[{$slug}] is a Room-family venue — walls hold the art, no float mode."
            );
        }

        // Per-venue declarations.
        $penthouse = $this->visualConfig('luxury-penthouse');
        $this->assertTrue($penthouse['glazing_wall'] ?? false, '[luxury-penthouse] must declare the glazing wall (§4.8).');

        $zen = $this->visualConfig('zen-gallery');
        $ids = array_column($zen['structure'], 'id');
        $this->assertNotEmpty(preg_grep('/shoji/', $ids), '[zen-gallery] structure must include the shoji screens (§4.5).');
        $this->assertNotEmpty(preg_grep('/alcove/', $ids), '[zen-gallery] structure must include the tokonoma alcove (§4.5).');
        $this->assertNotEmpty(preg_grep('/bench/', $ids), '[zen-gallery] structure must include the low bench (§4.5).');

        $cyber = $this->visualConfig('cyber-gallery');
        $this->assertCount(4, array_values(array_filter($cyber['structure'], fn ($e) => str_starts_with((string) ($e['id'] ?? ''), 'neon-top-'))),
            '[cyber-gallery] must ring ALL FOUR ceiling edges with neon (§4.9 — the old two-strip defect).');
    }

    public function test_white_cube_gates_only_the_respect_pass(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $config = $this->visualConfig('white-cube');
        // Iteration 6: 'cube' is the explicit interpreter selector for the
        // respect pass (was 'rooms' + a JS slug gate pre-consolidation).
        $this->assertSame('cube', $config['structure_pass'] ?? null, '[white-cube] structure_pass selects the respect-pass interpreter.');
        $this->assertArrayNotHasKey('structure', $config, '[white-cube] "clean is the point" — it must NOT carry descriptors (§4.1).');
        $this->assertSame(2.0, (float) ($this->materialConfig('white-cube')['floor_tile_meters'] ?? 0), '[white-cube] declares its floor tile density (§4.1 floor-scale fix).');
    }

    public function test_garden_declares_sun_shadows_and_museum_painted_default(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $garden = $this->visualConfig('sculpture-garden');
        $this->assertTrue($garden['sun_shadows'] ?? false, '[sculpture-garden] is the only venue allowed sun shadows (§4.10, tier-gated in JS).');
        // Iteration 6: the garden's bespoke body is config-selected like
        // every other interpreter — 'garden' is its selector (the JS slug
        // branch is gone).
        $this->assertSame('garden', $garden['structure_pass'] ?? null, '[sculpture-garden] selects its bespoke interpreter via structure_pass.');
        $this->assertSame(2.0, (float) ($this->materialConfig('sculpture-garden')['floor_tile_meters'] ?? 0));

        $museum = $this->defaultSettings('dark-museum');
        $this->assertSame('white', $museum['wall_texture'] ?? null, '[dark-museum] default wall is a painted museum wall, not brick (§4.4).');
    }

    public function test_venues_without_the_rooms_pass_declare_no_structure_keys(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        foreach (['industrial-loft', 'dark-museum', 'sculpture-garden', 'infinite-void', 'crystal-cathedral', 'nebula-drift', 'mirror-lake'] as $slug) {
            $config = $this->visualConfig($slug);
            $this->assertArrayNotHasKey('structure', $config, "[{$slug}] must not carry structure descriptors.");
            $this->assertArrayNotHasKey('glazing_wall', $config, "[{$slug}] must not declare a glazing wall.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // The vocabulary ceiling (§10.3: ≤10 primitives, closed set)
    // ─────────────────────────────────────────────────────────────────────

    public function test_structure_descriptors_use_only_the_declared_vocabulary(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Mirror of STRUCTURE_PRIMITIVES in StructureBuilder.js (frozen list).
        $allowed = ['box', 'cylinder', 'cone', 'plane', 'sphere', 'torus', 'emissive-strip', 'points-cloud', 'glyph-plane', 'instance-grid'];
        $this->assertCount(10, $allowed, '§10.3: the vocabulary stays at ≤10 primitives — growth signals a venue needs modeling.');

        foreach (self::DESCRIPTOR_VENUES as $slug) {
            foreach ($this->visualConfig($slug)['structure'] ?? [] as $entry) {
                $this->assertContains(
                    $entry['primitive'] ?? null,
                    $allowed,
                    "[{$slug}] descriptor \"" . ($entry['id'] ?? '?') . '" uses an unknown primitive.'
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Copy matrix extension — structure in render ⇔ structure in words
    // ─────────────────────────────────────────────────────────────────────

    public function test_descriptor_venues_copy_names_their_signature(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $zen = (string) DB::table('venue_templates')->where('slug', 'zen-gallery')->value('description');
        $this->assertMatchesRegularExpression('/shoji/i', $zen, 'Zen copy must name the shoji screens the pass delivers.');
        $this->assertMatchesRegularExpression('/alcove/i', $zen, 'Zen copy must name the tokonoma alcove.');

        $penthouse = (string) DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('description');
        $this->assertMatchesRegularExpression('/glazed|glass/i', $penthouse, 'Penthouse copy must promise the glazing wall.');
        $this->assertMatchesRegularExpression('/skyline|city lights/i', $penthouse, 'Penthouse copy must promise the skyline.');
        $this->assertMatchesRegularExpression('/lounge/i', $penthouse, 'Penthouse copy must promise the lounge.');

        $cyber = (string) DB::table('venue_templates')->where('slug', 'cyber-gallery')->value('description');
        $this->assertMatchesRegularExpression('/neon/i', $cyber, 'Cyber copy must promise the neon (now all four edges).');
        $this->assertMatchesRegularExpression('/floor/i', $cyber, 'Cyber copy must promise the floor light grid.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The migration — union merge, guarded fields, idempotent, reversible
    // ─────────────────────────────────────────────────────────────────────

    private function roomsMigration(): object
    {
        return require database_path('migrations/2026_09_01_000003_rooms_structure_pass.php');
    }

    public function test_migration_merges_keys_without_clobbering_admin_edits(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // A super-admin has already tuned zen-gallery: their structure array,
        // their background colour and their description must all survive.
        DB::table('venue_templates')->where('slug', 'zen-gallery')->update([
            'visual_config' => json_encode([
                'background_color' => '0x223344',
                'structure'        => [['id' => 'admin-panel', 'primitive' => 'box', 'at' => [0, 1, 0], 'size' => [1, 1, 1], 'material' => 'stone']],
            ]),
            'description' => 'Our house zen room.',
        ]);
        // Museum's default texture was hand-changed: the guard must keep it.
        DB::table('venue_templates')->where('slug', 'dark-museum')->update([
            'default_settings' => json_encode(['wall_texture' => 'velvet', 'floor_material' => 'marble', 'lighting_preset' => 'moody', 'frame_style' => 'classic', 'room_layout' => 'square']),
        ]);

        $this->roomsMigration()->up();

        $zen = $this->visualConfig('zen-gallery');
        $this->assertSame('0x223344', $zen['background_color'], 'Admin-tuned config values must never be overwritten.');
        $this->assertSame('rooms', $zen['structure_pass'], 'Absent keys are still added around the admin edits.');
        $this->assertSame(
            [['id' => 'admin-panel', 'primitive' => 'box', 'at' => [0, 1, 0], 'size' => [1, 1, 1], 'material' => 'stone']],
            $zen['structure'],
            'An admin-authored structure array is NEVER replaced by the seeded one.'
        );

        $this->assertSame('velvet', $this->defaultSettings('dark-museum')['wall_texture'], 'Guarded default_settings change must skip an admin-modified value.');

        // A default penthouse receives the full identity.
        $penthouse = $this->visualConfig('luxury-penthouse');
        $this->assertTrue($penthouse['glazing_wall'] ?? false);
        $this->assertSame('rooms', $penthouse['structure_pass'] ?? null);
        $this->assertSame(
            'A private collector\'s evening — a glazed wall over the city lights, a lounge by the glass, dark walls and gold frames.',
            DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('description'),
            'Matched Iteration 0 copy is re-tightened to the delivered rooms.'
        );
    }

    public function test_migration_down_removes_only_its_own_keys(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $migration = $this->roomsMigration();

        $migration->up();
        // Admin replaces the cyber structure AFTER the pass (real rollback
        // scenario: an operator tunes the venue, then rolls the migration back).
        DB::table('venue_templates')->where('slug', 'cyber-gallery')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('cyber-gallery'), [
                'structure_pass' => 'rooms-v2',
            ])),
        ]);
        $migration->down();

        $cyber = $this->visualConfig('cyber-gallery');
        $this->assertSame('rooms-v2', $cyber['structure_pass'] ?? null, 'down() must preserve the admin post-pass edit of a key it added.');
        $this->assertArrayNotHasKey('structure', $cyber, 'down() removes the untouched keys it added.');

        $this->assertSame(
            'A dark futuristic exhibition space with neon light accents. For digital and web3 creators.',
            DB::table('venue_templates')->where('slug', 'cyber-gallery')->value('description'),
            'down() restores the Iteration 0 copy (rollback path of the re-tightening).'
        );
        $this->assertSame(
            'brick',
            $this->defaultSettings('dark-museum')['wall_texture'] ?? null,
            'down() restores the pre-pass museum default (guard symmetrical).'
        );

        // Idempotence: up() after down() lands the identity again.
        $migration->up();
        $this->assertSame('rooms', $this->visualConfig('zen-gallery')['structure_pass'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // The client payload — structure keys must reach the viewer + previews
    // ─────────────────────────────────────────────────────────────────────

    public function test_preview_payload_carries_the_structure_keys(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $exporter = app(\App\Services\VenueConfigExporter::class);

        $penthouse = \App\Models\VenueTemplate::where('slug', 'luxury-penthouse')->firstOrFail();
        $config    = $exporter->forVenuePreview($penthouse);
        $this->assertSame('rooms', $config['visual_config']['structure_pass'] ?? null);
        $this->assertTrue($config['visual_config']['glazing_wall'] ?? false);
        $this->assertIsArray($config['visual_config']['structure'] ?? null, 'The descriptor array must reach the client untouched.');
        $this->assertNotEmpty($config['visual_config']['structure']);

        $whiteCube = \App\Models\VenueTemplate::where('slug', 'white-cube')->firstOrFail();
        $wcConfig  = $exporter->forVenuePreview($whiteCube);
        // Iteration 6: 'cube' is the respect-pass interpreter selector.
        $this->assertSame('cube', $wcConfig['visual_config']['structure_pass'] ?? null);
        $this->assertArrayNotHasKey('structure', $wcConfig['visual_config']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // DoD rule #7: the new interpreter stays slug-free
    // ─────────────────────────────────────────────────────────────────────

    public function test_structure_builder_contains_zero_venue_slugs(): void
    {
        $slugs = [
            'white-cube', 'industrial-loft', 'dark-museum', 'zen-gallery',
            'luxury-penthouse', 'cyber-gallery', 'sculpture-garden',
            'infinite-void', 'crystal-cathedral', 'nebula-drift', 'mirror-lake',
        ];

        $contents = file_get_contents(base_path('resources/js/gallery/StructureBuilder.js'));
        foreach ($slugs as $slug) {
            $this->assertStringNotContainsString(
                $slug,
                $contents,
                'StructureBuilder must stay slug-free — venue identity lives only in config (DoD rule #7).'
            );
        }
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

    private function defaultSettings(string $slug): array
    {
        return json_decode((string) DB::table('venue_templates')->where('slug', $slug)->value('default_settings'), true) ?: [];
    }
}
