<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VenuePhenomenaIterationTest extends TestCase
{
    use RefreshDatabase;

    private const VOID_VENUES = ['infinite-void', 'crystal-cathedral', 'nebula-drift', 'mirror-lake'];

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
        $this->assertSame('none', $nebula['environment'] ?? null, '[nebula-drift] must declare environment none — the sky is procedural, the HDRI download disappears (Deep Field pass, 2026-09-08).');
        $this->assertSame(0, $nebula['env_intensity'] ?? null, '[nebula-drift] must silence the preset HDRI glow at its source.');
    }

    public function test_non_void_venues_declare_no_phenomena_keys(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        foreach (['white-cube', 'industrial-loft', 'dark-museum', 'zen-gallery', 'luxury-penthouse', 'cyber-gallery', 'sculpture-garden'] as $slug) {
            $config = $this->visualConfig($slug);
            $this->assertArrayNotHasKey('placement_mode', $config, "[{$slug}] must not declare a void placement mode.");
        }

        foreach (['industrial-loft' => 'loft', 'dark-museum' => 'museum', 'sculpture-garden' => 'garden'] as $slug => $pass) {
            $config = $this->visualConfig($slug);
            $this->assertSame($pass, $config['structure_pass'] ?? null, "[{$slug}] declares its own interpreter selector (Iteration 6).");
        }
    }

    public function test_copy_promises_only_declared_phenomena(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $rows = DB::table('venue_templates')->select('slug', 'description', 'visual_config')->get();
        $this->assertCount(12, $rows);

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

    private function phenomenaMigration(): object
    {
        return require database_path('migrations/2026_09_01_000002_phenomena_void_identity.php');
    }

    public function test_migration_merges_keys_without_clobbering_admin_edits(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

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
            'A colonnade of faceted crystal piers carries pointed arches around a hall of polished dark stone; light falls from a vaulted oculus and reflects across the floor while artworks float before framed bays of stone.',
            DB::table('venue_templates')->where('slug', 'crystal-cathedral')->value('description'),
            'down() of an older iteration must not touch a newer iteration\'s guarded copy.'
        );

        DB::table('venue_templates')->where('slug', 'crystal-cathedral')->update([
            'description' => 'A colonnade of tall glass rises through a deep blue void, coloured light glowing between the pillars. Artworks float in that light.',
        ]);
        $migration->down();
        $this->assertSame(
            'Crystalline forms drift through a deep blue void, lit by shifting colour. An ethereal, open exhibition space.',
            DB::table('venue_templates')->where('slug', 'crystal-cathedral')->value('description'),
            'down() restores the Iteration 0 copy (rollback path of the re-tightening).'
        );

        // Idempotence: up() after down() lands the identity again.
        $migration->up();
        $this->assertSame('phenomena', $this->visualConfig('crystal-cathedral')['structure_pass'] ?? null);
    }

    public function test_preview_payload_carries_the_identity_keys(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue = \App\Models\VenueTemplate::where('slug', 'mirror-lake')->firstOrFail();
        $config = app(\App\Services\VenueConfigExporter::class)->forVenuePreview($venue);

        $this->assertSame('float', $config['visual_config']['placement_mode'] ?? null);
        $this->assertSame('planar', $config['visual_config']['floor_reflection'] ?? null);
        $this->assertSame('phenomena', $config['visual_config']['structure_pass'] ?? null);
    }

    public function test_new_interpreter_modules_contain_zero_venue_slugs(): void
    {
        $slugs = [
            'white-cube', 'industrial-loft', 'dark-museum', 'zen-gallery',
            'luxury-penthouse', 'cyber-gallery', 'sculpture-garden',
            'infinite-void', 'crystal-cathedral', 'nebula-drift', 'mirror-lake',
        ];

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

    private function visualConfig(string $slug): array
    {
        $raw = DB::table('venue_templates')->where('slug', $slug)->value('visual_config');

        return json_decode((string) $raw, true) ?: [];
    }
}
