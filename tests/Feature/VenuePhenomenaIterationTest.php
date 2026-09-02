<?php

declare(strict_types=1);

/**
 * Iteration 2 "PHENOMENA" regression tests (3D venue roadmap, P1.2).
 *
 * Pins the void-family identity contract so future changes cannot silently
 * re-introduce the promise/delivery gap or break the rollback switches:
 *
 *   - Declared identity: the four void venues carry the config keys the JS
 *     interpreter consumes (placement_mode, structure_pass, plus the
 *     per-venue effect declarations). The seven other venues declare NONE —
 *     effects are opt-in per venue, never global (§11.3).
 *   - Per-venue promise matrix: copy may only promise a phenomenon the
 *     venue's config actually declares (float language ⇔ placement_mode;
 *     "reflects" ⇔ floor_reflection). This is the tier-safe successor of the
 *     Iteration 0 global over-claim list, which Iteration 2 deliberately
 *     narrowed.
 *   - The migration is a safe, idempotent, UNION-merge: admin-set config keys
 *     and admin-written descriptions are never clobbered; down() removes
 *     exactly what up() added, and only while it still matches.
 *   - The preview/gallery payload carries the identity keys to the client.
 *   - DoD rule #7 (hard from Iteration 2 onward): the new interpreter modules
 *     contain ZERO venue slugs — venue identity lives only in config.
 *
 * Run: php artisan test --filter=VenuePhenomenaIterationTest
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VenuePhenomenaIterationTest extends TestCase
{
    use RefreshDatabase;

    private const VOID_VENUES = ['infinite-void', 'crystal-cathedral', 'nebula-drift', 'mirror-lake'];

    // ─────────────────────────────────────────────────────────────────────
    // Declared identity — the config contract the JS interpreter consumes
    // ─────────────────────────────────────────────────────────────────────

    public function test_void_venues_declare_the_phenomena_identity_keys(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        foreach (self::VOID_VENUES as $slug) {
            $config = $this->visualConfig($slug);

            $this->assertSame(
                'float',
                $config['placement_mode'] ?? null,
                "[{$slug}] must declare placement_mode 'float' — the floating-artworks promise."
            );
            $this->assertSame(
                'phenomena',
                $config['structure_pass'] ?? null,
                "[{$slug}] must declare structure_pass 'phenomena' (also its per-venue rollback switch)."
            );
        }

        // Per-venue effect declarations.
        $infinite = $this->visualConfig('infinite-void');
        $this->assertTrue($infinite['floor_edge_fade'] ?? false, '[infinite-void] must declare the floor-edge fade (§4.2).');
        $this->assertSame(0, $infinite['env_intensity'] ?? null, '[infinite-void] must silence the preset HDRI glow.');

        $cathedral = $this->visualConfig('crystal-cathedral');
        $this->assertSame('transmission', $cathedral['glass_material'] ?? null, '[crystal-cathedral] must declare true glass (tier-resolved downstream).');
        $this->assertNotEmpty($cathedral['colonnade_tint'] ?? null, '[crystal-cathedral] must declare its glass tint.');

        $mirror = $this->visualConfig('mirror-lake');
        $this->assertSame('planar', $mirror['floor_reflection'] ?? null, '[mirror-lake] must declare the planar reflection (§4.11).');

        $nebula = $this->visualConfig('nebula-drift');
        $this->assertSame(0.05, $nebula['env_intensity'] ?? null, '[nebula-drift] must damp the night-HDRI horizon glow.');
    }

    public function test_non_void_venues_declare_no_phenomena_keys(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // The Room family + garden must be untouched by the void pass:
        // effects are opt-in per venue, never global (§11.3), and the garden
        // keeps its easel identity (§4.10) by NOT declaring a placement mode.
        foreach (['white-cube', 'industrial-loft', 'dark-museum', 'zen-gallery', 'luxury-penthouse', 'cyber-gallery', 'sculpture-garden'] as $slug) {
            $config = $this->visualConfig($slug);
            $this->assertArrayNotHasKey('placement_mode', $config, "[{$slug}] must not declare a void placement mode.");
        }

        // Iteration 3 "Rooms" DELIBERATELY NARROWED this list (the same way
        // Iteration 2 narrowed Iteration 0's over-claim list): zen-gallery,
        // luxury-penthouse, cyber-gallery and white-cube now legitimately
        // declare structure_pass = 'rooms' (their own identity pass — see
        // VenueRoomsIterationTest). The remaining venues must still not
        // declare any structure pass.
        foreach (['industrial-loft', 'dark-museum', 'sculpture-garden'] as $slug) {
            $config = $this->visualConfig($slug);
            $this->assertArrayNotHasKey('structure_pass', $config, "[{$slug}] must not declare a structure pass.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // The per-venue promise matrix — words ⇔ declared render, as CI
    // ─────────────────────────────────────────────────────────────────────

    public function test_copy_promises_only_declared_phenomena(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $rows = DB::table('venue_templates')->select('slug', 'description', 'visual_config')->get();
        $this->assertCount(11, $rows);

        foreach ($rows as $row) {
            $config = json_decode((string) $row->visual_config, true) ?: [];
            $desc   = mb_strtolower((string) $row->description);
            $floats = ($config['placement_mode'] ?? null) === 'float';

            if ($floats) {
                $this->assertMatchesRegularExpression(
                    '/float|drift|hover/',
                    $desc,
                    "[{$row->slug}] declares float placement — its copy should say so."
                );
            } else {
                $this->assertDoesNotMatchRegularExpression(
                    '/float|hover|drift/',
                    $desc,
                    "[{$row->slug}] copy promises floating artworks but its config does not declare placement_mode float."
                );
            }

            $reflects = ($config['floor_reflection'] ?? null) === 'planar';
            if ($reflects) {
                $this->assertStringContainsStringIgnoringCase(
                    'reflect',
                    $desc,
                    "[{$row->slug}] declares a planar reflector — its copy should promise the reflection."
                );
            } else {
                $this->assertStringNotContainsStringIgnoringCase(
                    'reflect',
                    $desc,
                    "[{$row->slug}] copy promises a reflection but its config declares none (the PBR lie)."
                );
            }
        }

        // The garden keeps its easel identity, stated in words (§4.10).
        $garden = DB::table('venue_templates')->where('slug', 'sculpture-garden')->value('description');
        $this->assertStringContainsStringIgnoringCase('easel', (string) $garden, 'Garden easels are load-bearing identity — the copy must keep saying so.');

        // The cathedral copy must name its colonnade (the verticality gate).
        $cathedral = DB::table('venue_templates')->where('slug', 'crystal-cathedral')->value('description');
        $this->assertStringContainsStringIgnoringCase('colonnade', (string) $cathedral, 'Cathedral copy must name the colonnade the pass delivers.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The migration — union merge, guarded copy, idempotent, reversible
    // ─────────────────────────────────────────────────────────────────────

    private function phenomenaMigration(): object
    {
        return require database_path('migrations/2026_09_01_000002_phenomena_void_identity.php');
    }

    public function test_migration_merges_keys_without_clobbering_admin_edits(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // A super-admin has already tuned infinite-void: their background
        // colour AND their own placement_mode (they prefer easels) must win.
        DB::table('venue_templates')->where('slug', 'infinite-void')->update([
            'visual_config' => json_encode([
                'background_color' => '0x123456',
                'placement_mode'   => 'easel',
            ]),
            'description' => 'Our house void.',
        ]);

        $this->phenomenaMigration()->up();

        $config = $this->visualConfig('infinite-void');
        $this->assertSame('0x123456', $config['background_color'], 'Admin-tuned config values must never be overwritten.');
        $this->assertSame('easel', $config['placement_mode'], 'Admin-set placement_mode must win over the migration default (union semantics).');
        $this->assertSame('phenomena', $config['structure_pass'], 'Absent keys are still added around the admin edits.');
        $this->assertSame(
            'Our house void.',
            DB::table('venue_templates')->where('slug', 'infinite-void')->value('description'),
            'Admin-customized descriptions are never clobbered.'
        );

        // A venue with default rows receives the full identity.
        $mirror = $this->visualConfig('mirror-lake');
        $this->assertSame('planar', $mirror['floor_reflection']);
        $this->assertSame(
            'A still, dark lake reflects the floating artworks and the moon. Mist drifts low. Quiet, spacious, meditative.',
            DB::table('venue_templates')->where('slug', 'mirror-lake')->value('description'),
            'Matched Iteration 0 copy is re-tightened to the delivered phenomena.'
        );
    }

    public function test_migration_down_removes_only_its_own_keys(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $migration = $this->phenomenaMigration();

        $migration->up();
        // Admin changes placement_mode AFTER the pass (a real rollback scenario:
        // an operator disables float for one venue, then rolls the migration back).
        DB::table('venue_templates')->where('slug', 'crystal-cathedral')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('crystal-cathedral'), [
                'placement_mode' => 'easel',
            ])),
        ]);
        $migration->down();

        $cathedral = $this->visualConfig('crystal-cathedral');
        $this->assertSame('easel', $cathedral['placement_mode'] ?? null, 'down() must preserve the admin post-pass edit of a key it added.');
        $this->assertArrayNotHasKey('structure_pass', $cathedral, 'down() removes the untouched keys it added.');
        $this->assertArrayNotHasKey('glass_material', $cathedral);

        $this->assertSame(
            'Crystalline forms drift through a deep blue void, lit by shifting colour. An ethereal, open exhibition space.',
            DB::table('venue_templates')->where('slug', 'crystal-cathedral')->value('description'),
            'down() restores the Iteration 0 copy (rollback path of the re-tightening).'
        );

        // Idempotence: up() after down() lands the identity again.
        $migration->up();
        $this->assertSame('phenomena', $this->visualConfig('crystal-cathedral')['structure_pass'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // The client payload — identity keys must reach the viewer + previews
    // ─────────────────────────────────────────────────────────────────────

    public function test_preview_payload_carries_the_identity_keys(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue = \App\Models\VenueTemplate::where('slug', 'mirror-lake')->firstOrFail();
        $config = app(\App\Services\VenueConfigExporter::class)->forVenuePreview($venue);

        $this->assertSame('float', $config['visual_config']['placement_mode'] ?? null);
        $this->assertSame('planar', $config['visual_config']['floor_reflection'] ?? null);
        $this->assertSame('phenomena', $config['visual_config']['structure_pass'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // DoD rule #7 (hard from Iteration 2 onward): no slug-keyed JS added
    // ─────────────────────────────────────────────────────────────────────

    public function test_new_interpreter_modules_contain_zero_venue_slugs(): void
    {
        $slugs = [
            'white-cube', 'industrial-loft', 'dark-museum', 'zen-gallery',
            'luxury-penthouse', 'cyber-gallery', 'sculpture-garden',
            'infinite-void', 'crystal-cathedral', 'nebula-drift', 'mirror-lake',
        ];

        // These modules are the GENERIC interpreter (§10.2): they may never
        // know a venue's name — venues opt in per config key.
        $modules = [
            'resources/js/gallery/TierResolve.js',
            'resources/js/gallery/TierEffects.js',
            'resources/js/gallery/PlacementMath.js',
        ];

        foreach ($modules as $module) {
            $contents = file_get_contents(base_path($module));
            foreach ($slugs as $slug) {
                $this->assertStringNotContainsString(
                    $slug,
                    $contents,
                    "[{$module}] must stay slug-free — venue identity lives only in config (DoD rule #7)."
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    private function visualConfig(string $slug): array
    {
        $raw = DB::table('venue_templates')->where('slug', $slug)->value('visual_config');

        return json_decode((string) $raw, true) ?: [];
    }
}
