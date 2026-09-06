<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Iteration 6 "Consolidation" (roadmap P2.2 + P2.3) — the one-source-of-truth
 * contract as CI.
 *
 * Pins:
 *   1. Every seeded venue declares the consolidation keys (structure_pass
 *      selectors, ceiling colours, open_air / layout_shape, void flags) —
 *      the byte-equivalent replacements for the deleted JS strata.
 *   2. The migration union-merges without clobbering admin edits, is
 *      idempotent, and down() removes exactly what up() added (only while
 *      unmodified) — the IT6 rollback story as executable proof.
 *   3. The exporter payload carries the declared identity end-to-end
 *      (DB → forVenue → client visual_config).
 *   4. The placement curation contract (P2.3 §6.3–§6.5): validated keys,
 *      payload pass-through, DEFAULT ABSENT — no seeded venue declares a
 *      placement block ("default galleries unchanged" is IT6's own promise).
 *   5. DoD rule #7 at its STRONGEST: zero venue slugs anywhere in
 *      resources/js/gallery/*.js (IT3 scanned one file; consolidation scans
 *      them all — the JS is now fully config-interpreted).
 *
 * These tests run in CI / the developer environment (IT6-T3). They use the
 * same portable patterns as the IT2/IT3/IT5 suites (sqlite-safe JSON
 * read-modify-write, migrations invoked directly).
 */
class VenueConsolidationIterationTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    // The equivalence table — seeder ⇔ deleted JS strata, as data
    // ─────────────────────────────────────────────────────────────────────

    public const PASS_SELECTORS = [
        'white-cube'       => 'cube',
        'zen-gallery'      => 'rooms',
        'luxury-penthouse' => 'rooms',
        'cyber-gallery'    => 'rooms',
        'industrial-loft'  => 'loft',
        'dark-museum'      => 'museum',
        'sculpture-garden' => 'garden',
        'infinite-void'    => 'phenomena',
        'crystal-cathedral' => 'phenomena',
        'nebula-drift'     => 'phenomena',
        'mirror-lake'      => 'phenomena',
    ];

    public const CIRCULAR_OPEN_AIR = [
        'infinite-void', 'crystal-cathedral', 'nebula-drift', 'mirror-lake',
        'sculpture-garden',
    ];

    public const CEILING_COLORS = [
        'dark-museum'      => '0x0a0a0a', // deepened by the museum iteration (was 0x080808 pre-IT6)
        'luxury-penthouse' => '0x080808',
        'cyber-gallery'    => '0x04081a',
        'industrial-loft'  => '0x1a1a18',
        'zen-gallery'      => '0x1e1c14',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Declared identity — the config contract the JS interpreter consumes
    // ─────────────────────────────────────────────────────────────────────

    public function test_every_venue_declares_its_interpreter_selector(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        foreach (self::PASS_SELECTORS as $slug => $pass) {
            $this->assertSame(
                $pass,
                $this->visualConfig($slug)['structure_pass'] ?? null,
                "[{$slug}] must declare structure_pass '{$pass}' — the interpreter selector that replaced the JS slug dispatch."
            );
        }
    }

    public function test_circular_open_air_venues_declare_the_shell_keys(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        foreach (self::CIRCULAR_OPEN_AIR as $slug) {
            $config = $this->visualConfig($slug);
            $this->assertTrue($config['open_air'] ?? false, "[{$slug}] must declare open_air (replaces the OPEN_AIR_VENUES set).");
            $this->assertSame('circular', $config['layout_shape'] ?? null, "[{$slug}] must declare layout_shape 'circular' (replaces the CIRCULAR_VENUES set).");
        }

        // The six walled venues must NOT declare them (opt-in per venue).
        foreach (array_diff(array_keys(self::PASS_SELECTORS), self::CIRCULAR_OPEN_AIR) as $slug) {
            $config = $this->visualConfig($slug);
            $this->assertArrayNotHasKey('open_air', $config, "[{$slug}] is a walled room — no open_air.");
            $this->assertArrayNotHasKey('layout_shape', $config, "[{$slug}] is a walled room — no layout_shape override.");
        }
    }

    public function test_ceiling_colors_replay_the_deleted_chains_exactly(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        foreach (self::CEILING_COLORS as $slug => $color) {
            $this->assertSame(
                $color,
                $this->visualConfig($slug)['ceiling_color'] ?? null,
                "[{$slug}] ceiling_color must equal the pre-IT6 per-slug chain value."
            );
        }

        // Venues absent from the table default to white in the interpreter —
        // they must NOT declare a colour (absence IS the default).
        foreach (array_diff(array_keys(self::PASS_SELECTORS), array_keys(self::CEILING_COLORS)) as $slug) {
            $this->assertArrayNotHasKey('ceiling_color', $this->visualConfig($slug), "[{$slug}] must not declare a ceiling colour.");
        }
    }

    public function test_void_venues_declare_their_composable_flags(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $this->assertTrue($this->visualConfig('infinite-void')['void_dust'] ?? false, '[infinite-void] declares void_dust.');
        $this->assertTrue($this->visualConfig('crystal-cathedral')['void_colonnade'] ?? false, '[crystal-cathedral] declares void_colonnade.');
        $this->assertArrayNotHasKey('void_shards', $this->visualConfig('crystal-cathedral'), '[crystal-cathedral] ships the colonnade body — void_shards is the rollback body only.');
        $this->assertTrue($this->visualConfig('nebula-drift')['void_starfield'] ?? false, '[nebula-drift] declares void_starfield.');
        $this->assertTrue($this->visualConfig('mirror-lake')['void_lake'] ?? false, '[mirror-lake] declares void_lake.');

        // Declared shell details (was the loft/cyber slug branches).
        $this->assertTrue($this->visualConfig('industrial-loft')['ceiling_beams'] ?? false, '[industrial-loft] declares ceiling_beams.');
        $this->assertTrue($this->visualConfig('cyber-gallery')['ceiling_neon'] ?? false, '[cyber-gallery] declares ceiling_neon (rollback body key; dormant while structure_pass = rooms).');
    }

    public function test_default_galleries_unchanged_no_seeded_venue_declares_curation(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // The dark-museum deepening is the deliberate curation opt-in: it
        // declares placement (generous density, focal front wall, pairing).
        $curated = ['dark-museum', 'infinite-void']; // venue deepening iterations opted these in
        foreach (array_keys(self::PASS_SELECTORS) as $slug) {
            if (in_array($slug, $curated, true)) {
                $this->assertIsArray(
                    $this->visualConfig($slug)['placement'] ?? null,
                    "[{$slug}] opted into curation â its placement block must be declared."
                );
                continue;
            }
            $this->assertArrayNotHasKey(
                'placement',
                $this->visualConfig($slug),
                "[{$slug}] must NOT declare a placement block â curation is opt-in and IT6 promises default galleries unchanged."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // The migration — union merge, guarded update, idempotent, reversible
    // ─────────────────────────────────────────────────────────────────────

    private function consolidationMigration(): object
    {
        return require database_path('migrations/2026_09_01_000005_consolidation.php');
    }

    public function test_migration_merges_keys_without_clobbering_admin_edits(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // A super-admin has re-tuned the museum: their ceiling colour and a
        // custom pass must survive the consolidation.
        DB::table('venue_templates')->where('slug', 'dark-museum')->update([
            'visual_config' => json_encode([
                'background_color' => '0x010101',
                'ceiling_color'    => '0x123456',
                'structure_pass'   => 'museum-v2',
            ]),
        ]);

        $this->consolidationMigration()->up();

        $museum = $this->visualConfig('dark-museum');
        $this->assertSame('0x123456', $museum['ceiling_color'], 'Admin-tuned ceiling colour is never overwritten.');
        $this->assertSame('museum-v2', $museum['structure_pass'], 'Admin-tuned pass value is never overwritten.');
        $this->assertSame('0x010101', $museum['background_color'], 'Admin values untouched by IT6 stay untouched.');

        // Untouched venues gain the full key set.
        $garden = $this->visualConfig('sculpture-garden');
        $this->assertTrue($garden['open_air'] ?? false);
        $this->assertSame('circular', $garden['layout_shape'] ?? null);
        $this->assertSame('garden', $garden['structure_pass'] ?? null);
    }

    public function test_white_cube_pass_value_update_is_exact_match_guarded(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Admin already moved white-cube to a custom pass — up() must not touch it.
        DB::table('venue_templates')->where('slug', 'white-cube')->update([
            'visual_config' => json_encode(['structure_pass' => 'rooms-v9']),
        ]);
        $this->consolidationMigration()->up();
        $this->assertSame('rooms-v9', $this->visualConfig('white-cube')['structure_pass'], 'Exact-match guard: custom values stay.');

        // Seeded value ('rooms' from IT3) migrates to 'cube'.
        DB::table('venue_templates')->where('slug', 'white-cube')->update([
            'visual_config' => json_encode(['structure_pass' => 'rooms']),
        ]);
        $this->consolidationMigration()->up();
        $this->assertSame('cube', $this->visualConfig('white-cube')['structure_pass'], "IT3's 'rooms' migrates to IT6's 'cube'.");

        // Idempotent: running up() again changes nothing.
        $this->consolidationMigration()->up();
        $this->assertSame('cube', $this->visualConfig('white-cube')['structure_pass'], 'up() is idempotent.');
    }

    public function test_migration_down_removes_only_what_it_added_unmodified(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Fresh seeder rows already carry the keys — down() must remove them.
        $this->consolidationMigration()->down();
        $garden = $this->visualConfig('sculpture-garden');
        $this->assertArrayNotHasKey('open_air', $garden, 'down() removes the keys up() added.');
        $this->assertArrayNotHasKey('layout_shape', $garden, 'down() removes the keys up() added.');
        $this->assertArrayNotHasKey('structure_pass', $garden, 'down() removes the keys up() added.');
        $this->assertSame('rooms', $this->visualConfig('white-cube')['structure_pass'] ?? null, "down() restores white-cube's IT3-era 'rooms'.");

        // An admin-modified added key survives down().
        $this->consolidationMigration()->up();
        DB::table('venue_templates')->where('slug', 'zen-gallery')->update([
            'visual_config' => json_encode(['ceiling_color' => '0x999999']),
        ]);
        $this->consolidationMigration()->down();
        $this->assertSame('0x999999', $this->visualConfig('zen-gallery')['ceiling_color'], 'Admin-modified keys are never removed by down().');
    }

    public function test_migration_is_idempotent_over_seeded_rows(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $before = DB::table('venue_templates')->orderBy('slug')->get(['slug', 'visual_config'])->pluck('visual_config', 'slug')->all();
        $this->consolidationMigration()->up();
        $after = DB::table('venue_templates')->orderBy('slug')->get(['slug', 'visual_config'])->pluck('visual_config', 'slug')->all();

        foreach ($before as $slug => $json) {
            $this->assertSame($json, $after[$slug], "[{$slug}] re-running up() over consolidated rows changes nothing (union merge is a no-op).");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Exporter + curation pass-through
    // ─────────────────────────────────────────────────────────────────────

    public function test_exporter_payload_carries_the_declared_identity(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $exporter = app(\App\Services\VenueConfigExporter::class);
        $garden   = \App\Models\VenueTemplate::where('slug', 'sculpture-garden')->firstOrFail();
        $payload  = $exporter->forVenue($garden);

        $vc = $payload['visual_config'];
        $this->assertSame('garden', $vc['structure_pass'] ?? null, 'Interpreter selector reaches the client.');
        $this->assertTrue($vc['open_air'] ?? false, 'open_air reaches the client (the JS reads it instead of the slug set).');
        $this->assertSame('circular', $vc['layout_shape'] ?? null, 'layout_shape reaches the client.');

        $cathedral = \App\Models\VenueTemplate::where('slug', 'crystal-cathedral')->firstOrFail();
        $vcC = $exporter->forVenue($cathedral)['visual_config'];
        $this->assertTrue($vcC['void_colonnade'] ?? false, 'Composable void flags reach the client.');
    }

    public function test_placement_block_passes_through_end_to_end(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        DB::table('venue_templates')->where('slug', 'zen-gallery')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('zen-gallery'), [
                'placement' => ['density' => 'intimate', 'pair_orientation' => true, 'focal_wall' => 'front'],
            ])),
        ]);

        $exporter = app(\App\Services\VenueConfigExporter::class);
        $zen      = \App\Models\VenueTemplate::where('slug', 'zen-gallery')->firstOrFail();
        $vc       = $exporter->forVenue($zen)['visual_config'];

        $this->assertSame('intimate', $vc['placement']['density'] ?? null, 'Density reaches the client (§6.3).');
        $this->assertTrue($vc['placement']['pair_orientation'] ?? false, 'Orientation pairing reaches the client (§6.4).');
        $this->assertSame('front', $vc['placement']['focal_wall'] ?? null, 'Focal wall reaches the client (§6.5).');
    }

    public function test_placement_validation_contract(): void
    {
        $rules = (new \App\Http\Requests\SuperAdmin\VenueTemplateRequest())->rules();

        $base = [
            'name' => 'Curation Probe', 'slug' => 'curation-probe', 'category' => 'minimal',
            'plan_required' => 'free', 'description' => 'probe', 'capacity_min' => 10,
        ];

        // Valid placement block passes.
        $v = \Illuminate\Support\Facades\Validator::make($base + [
            'visual_config' => ['placement' => ['density' => 'intimate', 'pair_orientation' => '1', 'focal_wall' => 'front']],
        ], $rules);
        $this->assertFalse($v->fails(), 'A valid curation block passes: '.json_encode($v->errors()->all()));

        // Invalid density / focal wall are rejected at the form (§9.3).
        $v = \Illuminate\Support\Facades\Validator::make($base + [
            'visual_config' => ['placement' => ['density' => 'cramped']],
        ], $rules);
        $this->assertTrue($v->fails(), 'Unknown density preset is rejected.');

        $v = \Illuminate\Support\Facades\Validator::make($base + [
            'visual_config' => ['placement' => ['focal_wall' => 'ceiling']],
        ], $rules);
        $this->assertTrue($v->fails(), 'Unknown focal wall is rejected.');

        // Declared shell keys: unknown interpreter selector rejected.
        $v = \Illuminate\Support\Facades\Validator::make($base + [
            'visual_config' => ['structure_pass' => 'teleport'],
        ], $rules);
        $this->assertTrue($v->fails(), 'Unknown structure_pass is rejected (typo protection).');

        $v = \Illuminate\Support\Facades\Validator::make($base + [
            'visual_config' => ['ceiling_color' => 'blue'],
        ], $rules);
        $this->assertTrue($v->fails(), 'Malformed ceiling colour is rejected.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // DoD rule #7 at full strength — the WHOLE gallery JS is slug-free
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Strip JS comments so the slug/symbol pins read CODE, not history
     * notes. A comment may legitimately record which incident produced a
     * key; a venue branch in executable logic may not.
     */
    private function jsCodeOnly(string $contents): string
    {
        $noBlock = preg_replace('/\/\*.*?\*\//s', '', $contents);

        return (string) preg_replace('/^\s*\/\/.*$/m', '', (string) $noBlock);
    }

    public function test_gallery_js_contains_zero_venue_slugs(): void
    {
        $slugs = [
            'white-cube', 'industrial-loft', 'dark-museum', 'zen-gallery',
            'luxury-penthouse', 'cyber-gallery', 'sculpture-garden',
            'infinite-void', 'crystal-cathedral', 'nebula-drift', 'mirror-lake',
        ];

        $dir = base_path('resources/js/gallery');
        $this->assertDirectoryExists($dir);
        foreach (glob($dir.'/*.js') as $file) {
            $contents = $this->jsCodeOnly(file_get_contents($file));
            foreach ($slugs as $slug) {
                $this->assertStringNotContainsString(
                    $slug,
                    $contents,
                    basename($file).' contains venue slug "'.$slug.'" — venue knowledge belongs in the DB (DoD #7, IT6).'
                );
            }
        }
    }

    public function test_deleted_strata_symbols_are_gone_from_the_runtime(): void
    {
        $dir = base_path('resources/js/gallery');
        foreach (['legacyVenueSwitch', 'venueTints', 'venueFrameOverride', 'CIRCULAR_VENUES', 'OPEN_AIR_VENUES'] as $symbol) {
            foreach (glob($dir.'/*.js') as $file) {
                $this->assertStringNotContainsString(
                    $symbol,
                    $this->jsCodeOnly(file_get_contents($file)),
                    basename($file)." references {$symbol} — the strata were deleted in IT6."
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    private function visualConfig(string $slug): array
    {
        return json_decode((string) DB::table('venue_templates')->where('slug', $slug)->value('visual_config'), true) ?: [];
    }
}
