<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Iteration 8 "The Salon" (roadmap P3.2 + P3.5) — the first pipeline-born
 * venue, as CI.
 *
 * Pins:
 *   1. The catalog is TWELVE seeded venues; `the-salon` is Pro, published
 *      and active the moment it is seeded/migrated (the chooser test
 *      extends to 12 or the venue does not ship — brief §5.3).
 *   2. The seeder entry and the migration payload are byte-equal — one
 *      authored identity, two delivery paths (fresh install vs. existing
 *      install), zero drift (DO NOT DO #13: the migration never re-seeds,
 *      the seeder never runs on tuned installs).
 *   3. The migration is a GUARDED INSERT: idempotent, never touches the
 *      eleven seeded venues; down() deletes ONLY while the row is
 *      unmodified AND unreferenced — a tuned or in-use venue survives.
 *   4. The placement block IS the brief's placement character: density
 *      'intimate' + pair_orientation, and NO focal wall (hierarchy stays
 *      "carefully"; the brief assigns focal treatment to the grand hall).
 *   5. The structure uses ONLY the descriptor vocabulary (closed primitive
 *      set, preset materials, anchors, fit:'wall') — zero JS shipped, the
 *      interpreter renders it as-is (§10.2, DoD #7).
 *   6. The register map closes the intimacy gap via the salon; grandeur
 *      remains the open register. Pricing copy claims twelve. The gallery
 *      JS stays slug-free for ALL TWELVE slugs.
 *
 * Run: php artisan test --filter=VenueSalonIterationTest
 */
class VenueSalonIterationTest extends TestCase
{
    use RefreshDatabase;

    private const ELEVEN_SEEDED = [
        'white-cube', 'infinite-void', 'industrial-loft', 'dark-museum',
        'zen-gallery', 'crystal-cathedral', 'nebula-drift', 'luxury-penthouse',
        'cyber-gallery', 'sculpture-garden', 'mirror-lake',
    ];

    private const CLOSED_PRIMITIVES = [
        'box', 'cylinder', 'cone', 'plane', 'sphere', 'torus',
        'emissive-strip', 'points-cloud', 'glyph-plane', 'instance-grid',
    ];

    private const SB_MATERIAL_PRESETS = [
        'wood_dark', 'wood_warm', 'paper_shoji', 'plaster_warm', 'stone',
        'fabric_warm', 'fabric_dark',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // 1. The twelfth venue
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_salon_is_the_twelfth_seeded_venue(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $rows = DB::table('venue_templates')->get();
        $this->assertCount(12, $rows, 'The catalog is twelve venues after Iteration 8.');

        $salon = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        $this->assertNotNull($salon, '[the-salon] must be seeded.');

        $this->assertSame('The Salon', $salon->name);
        $this->assertSame('pro', $salon->plan_required, 'The Salon is a Pro venue (brief §4 candidate A, decision record).');
        $this->assertSame('classic', $salon->category);
        $this->assertSame(12, (int) $salon->sort_order);
        $this->assertEquals(5, (int) $salon->capacity_min);
        $this->assertEquals(30, (int) $salon->capacity_max);
        $this->assertTrue((bool) $salon->is_active, 'Live the moment it is seeded — the chooser test extends to 12.');
        $this->assertFalse((bool) $salon->is_draft);
        $this->assertNotNull($salon->published_at);

        foreach (self::ELEVEN_SEEDED as $slug) {
            $this->assertNotNull(
                DB::table('venue_templates')->where('slug', $slug)->first(),
                "[{$slug}] must still be seeded — the salon ADDS, never replaces."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. One authored identity, two delivery paths — byte-equal payloads
    // ─────────────────────────────────────────────────────────────────────

    public function test_seeder_and_migration_payloads_are_equal(): void
    {
        $salon = collect(\Database\Seeders\VenueTemplateSeeder::templates())
            ->firstWhere('slug', 'the-salon');
        $this->assertNotNull($salon, 'Seeder must carry the-salon entry #12.');

        $migration = require database_path('migrations/2026_09_01_000006_salon.php');
        $payload   = $migration->salonTemplate();

        $this->assertSame($payload, $salon,
            'Seeder entry #12 and the migration payload MUST be byte-equal — one identity, two delivery paths.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. The migration — guarded insert, idempotent, gentle rollback
    // ─────────────────────────────────────────────────────────────────────

    private function salonMigration(): object
    {
        return require database_path('migrations/2026_09_01_000006_salon.php');
    }

    public function test_migration_inserts_the_salon_on_existing_installs(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        DB::table('venue_templates')->where('slug', 'the-salon')->delete();

        $this->salonMigration()->up();

        $salon = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        $this->assertNotNull($salon, 'up() inserts the salon on an eleven-venue install.');
        $this->assertSame('pro', $salon->plan_required);
        $this->assertTrue((bool) $salon->is_active);
        $this->assertFalse((bool) $salon->is_draft);
        $this->assertNotNull($salon->published_at, 'Published at migration time — walkable immediately.');
    }

    public function test_migration_is_idempotent_and_never_touches_the_eleven(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $before = DB::table('venue_templates')
            ->whereIn('slug', self::ELEVEN_SEEDED)
            ->orderBy('slug')->get()->toJson();

        $migration = $this->salonMigration();
        $migration->up();
        $migration->up(); // second run — a no-op, not a duplicate

        $this->assertSame(
            1,
            DB::table('venue_templates')->where('slug', 'the-salon')->count(),
            'Re-running up() must not duplicate the venue.'
        );
        $this->assertSame(
            $before,
            DB::table('venue_templates')->whereIn('slug', self::ELEVEN_SEEDED)->orderBy('slug')->get()->toJson(),
            'The eleven seeded venues must be byte-untouched by the salon migration.'
        );
    }

    public function test_migration_down_deletes_only_unmodified_unused_venue(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        DB::table('venue_templates')->where('slug', 'the-salon')->delete();
        $migration = $this->salonMigration();

        // (a) Untouched + unused → down() removes it.
        $migration->up();
        $migration->down();
        $this->assertNull(DB::table('venue_templates')->where('slug', 'the-salon')->first(),
            'down() removes the venue it added while it is unmodified and unused.');

        // (b) Admin-tuned → down() LEAVES it (super-admin edits win, always).
        $migration->up();
        DB::table('venue_templates')->where('slug', 'the-salon')
            ->update(['visual_config' => json_encode(['wall_height' => 3.4, 'admin_tuned' => true])]);
        $migration->down();
        $this->assertNotNull(DB::table('venue_templates')->where('slug', 'the-salon')->first(),
            'down() must never delete a venue the admin has tuned.');

        // (c) Unmodified but IN USE → down() LEAVES it (no orphaned galleries).
        DB::table('venue_templates')->where('slug', 'the-salon')->delete();
        $migration->up();
        $user = \App\Models\User::factory()->create();
        DB::table('galleries')->insert([
            'user_id'           => $user->id,
            'title'             => 'Salon show',
            'slug'              => 'salon-show-rollback-guard',
            'venue_template_id' => DB::table('venue_templates')->where('slug', 'the-salon')->value('id'),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        $migration->down();
        $this->assertNotNull(DB::table('venue_templates')->where('slug', 'the-salon')->first(),
            'down() must never delete a venue a gallery references.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Plan gating — the ladder stays true
    // ─────────────────────────────────────────────────────────────────────

    public function test_salon_is_pro_gated(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $freeSlugs   = \App\Models\VenueTemplate::query()->accessibleByPlan('free')->pluck('slug')->all();
        $proSlugs    = \App\Models\VenueTemplate::query()->accessibleByPlan('pro')->pluck('slug')->all();
        $studioSlugs = \App\Models\VenueTemplate::query()->accessibleByPlan('studio')->pluck('slug')->all();

        $this->assertNotContains('the-salon', $freeSlugs, 'Free users cannot build with the salon (Pro gate).');
        $this->assertContains('the-salon', $proSlugs, 'Pro users can build with the salon.');
        $this->assertContains('the-salon', $studioSlugs, 'Studio users inherit every Pro venue.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. The declared identity — curation contract + descriptor vocabulary
    // ─────────────────────────────────────────────────────────────────────

    private function salonVisualConfig(): array
    {
        return json_decode(
            (string) DB::table('venue_templates')->where('slug', 'the-salon')->value('visual_config'),
            true
        ) ?: [];
    }

    public function test_salon_declares_the_briefs_placement_character(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $config = $this->salonVisualConfig();

        $placement = $config['placement'] ?? null;
        $this->assertIsArray($placement, '[the-salon] declares a placement block — the first seeded USE of the IT6 curation machinery.');

        $this->assertSame('intimate', $placement['density'] ?? null,
            "Brief §4: the salon's placement character is the intimate density (~2.8 m close-hung rhythm).");
        $this->assertTrue($placement['pair_orientation'] ?? false,
            'Brief §4: orientation-aware pairing is the salon\'s second declared character key.');
        $this->assertArrayNotHasKey('focal_wall', $placement,
            'No focal wall on the salon — the brief assigns focal treatment to the grand-hall candidate; the salon reads egalitarian.');

        $this->assertSame('rooms', $config['structure_pass'] ?? null,
            '[the-salon] renders through the Room-family structure interpreter.');
        $this->assertSame(['square'], json_decode((string) DB::table('venue_templates')->where('slug', 'the-salon')->value('supported_layouts'), true),
            'The salon is a domestic square room — one layout, honestly supported.');
    }

    public function test_salon_structure_uses_only_the_descriptor_vocabulary(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $config  = $this->salonVisualConfig();
        $structure = $config['structure'] ?? null;

        $this->assertIsArray($structure);
        $this->assertCount(8, $structure, 'Four rails + three bench parts + one rug = eight descriptors.');

        foreach ($structure as $el) {
            $this->assertContains($el['primitive'] ?? null, self::CLOSED_PRIMITIVES,
                "[{$el['id']}] primitive must be in the closed §10.3 set.");
            if (is_string($el['material'] ?? null)) {
                $this->assertContains($el['material'], self::SB_MATERIAL_PRESETS,
                    "[{$el['id']}] material must be a StructureBuilder preset key.");
            }
            $this->assertSame('low', $el['tier_floor'] ?? 'low',
                "[{$el['id']}] renders on the LOWEST tier — the salon is the cheapest build since the White Cube (brief §4).");

            $at = $el['at'] ?? null;
            $this->assertIsArray($at, "[{$el['id']}] must place itself (anchor or absolute).");
            if (is_array($at) && isset($at['from'])) {
                $this->assertContains($at['from'], ['center', 'wall_front', 'wall_back', 'wall_left', 'wall_right'],
                    "[{$el['id']}] anchor must be a known room anchor — structure adapts to any square room.");
            }
        }

        $ids = array_column($structure, 'id');
        foreach (['rail-front', 'rail-back', 'rail-left', 'rail-right'] as $rail) {
            $this->assertContains($rail, $ids, "The picture rail wraps all four walls ([{$rail}]).");
        }
        $rails = array_filter($structure, fn ($el) => str_starts_with((string) $el['id'], 'rail-'));
        foreach ($rails as $rail) {
            $this->assertSame('wall', $rail['fit'] ?? null,
                "[{$rail['id']}] fits the wall span (fit:'wall') — the salon-wall convention made physical.");
        }
        $this->assertContains('bench-top', $ids, 'The domestic bench exists (§4.5 convention).');
        $this->assertContains('rug', $ids, 'The rug is the one warm floor note.');

        // The rug is flat — it must never be a collision obstacle.
        $rug = collect($structure)->firstWhere('id', 'rug');
        $this->assertEmpty($rug['collide'] ?? null, 'The rug is walkable — no collide registration.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 6. The chooser test extends to 12 — walkable, curated, noindex
    // ─────────────────────────────────────────────────────────────────────

    public function test_salon_preview_is_walkable_with_the_curated_sample_hang(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $response = $this->get('/venues/the-salon/preview');
        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $galleryData = $response->viewData('galleryData');
        $this->assertSame('the-salon', $galleryData['venue_slug']);
        $this->assertCount(8, $galleryData['images'],
            'The salon preview hangs the curated 8-work orientation-mixed set.');
        $this->assertTrue(is_array($galleryData['venueConfig']['visual_config'] ?? null)
            && isset($galleryData['venueConfig']['visual_config']['placement']),
            'The preview payload carries the placement block end-to-end (exporter → runtime).');

        $note = $response->viewData('sampleNote');
        $this->assertIsString($note);
        $this->assertStringContainsStringIgnoringCase('salon', $note,
            'The curated curtain note names the salon rationale.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 7. The catalog instrument + copy + slug-free JS, extended to twelve
    // ─────────────────────────────────────────────────────────────────────

    public function test_catalog_report_covers_intimacy_via_the_salon(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        Artisan::call('venues:catalog-report', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);

        $coverage = collect($json['register_coverage']);
        $intimacy = $coverage->firstWhere('register', 'intimacy');
        $this->assertSame('the-salon', $intimacy['venue'] ?? null, 'Intimacy is covered by The Salon.');
        $this->assertSame('covered', $intimacy['status'] ?? null);

        $grandeur = $coverage->firstWhere('register', 'grandeur');
        $this->assertSame('uncovered', $grandeur['status'] ?? null, 'Grandeur remains the open register (§3.3).');
    }

    public function test_pricing_copy_claims_twelve_venues(): void
    {
        $pricing = file_get_contents(resource_path('views/pages/pricing.blade.php'));
        $this->assertStringContainsString('12 distinct 3D spaces', $pricing, 'Pricing hero claims twelve.');
        $this->assertStringContainsString('8 venues', $pricing, 'Pro card claims eight venues.');
        $this->assertStringContainsString('The Salon', $pricing, 'The Pro venue list names the salon.');
        $this->assertStringContainsString('All 12 venues', $pricing, 'Studio claims all twelve.');
        $this->assertStringNotContainsString('All 11 venues', $pricing, 'The eleven-claim must be gone everywhere.');
    }

    public function test_gallery_js_stays_slug_free_for_all_twelve(): void
    {
        $slugs = array_merge(self::ELEVEN_SEEDED, ['the-salon']);
        $files = array_merge(
            glob(resource_path('js/gallery') . '/*.js'),
            glob(resource_path('js/gallery') . '/*/*.js') ?: []
        );
        $this->assertNotEmpty($files, 'Gallery runtime files must exist.');

        foreach ($files as $file) {
            // Read CODE, not history comments (the incident log lives in
            // docs and git — a comment may name a slug, logic may not).
            $code = preg_replace('/\/\*.*?\*\//s', '', file_get_contents($file));
            $code = (string) preg_replace('/^\s*\/\/.*$/m', '', (string) $code);
            foreach ($slugs as $slug) {
                $this->assertStringNotContainsString($slug, $code,
                    basename($file) . " must not know the slug '{$slug}' — the DB is the sole identity source (DoD #7)."
                );
            }
        }
    }
}
