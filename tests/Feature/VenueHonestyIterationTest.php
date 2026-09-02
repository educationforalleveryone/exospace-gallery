<?php

declare(strict_types=1);

/**
 * Iteration 0 "HONESTY" regression tests (3D venue roadmap, P0.1/P0.2/P0.4).
 *
 * Pins the honesty contract so future changes cannot silently re-introduce
 * promise/delivery gaps or selection-integrity bugs:
 *
 *   - P0.1  The 11 seeded venue descriptions contain NO known over-claims
 *           ("floating artworks", "mirror floor reflects", "partial
 *           dividers", "prism of colour") — the promise test as CI.
 *   - P0.2  Draft venues are excluded from the picker query contract
 *           (active() + published()).
 *   - P0.2  Picker blades render DB descriptions/accent server-side and the
 *           JS description maps (which drifted from the DB) are gone.
 *   - P0.2  The literal "??" monogram fallback is unreachable in the pickers.
 *   - P0.4  capacityLabel() is honest (upper bound only, no unenforced min).
 *   - P0.4  The honesty migration is GUARDED: it applies only when the row
 *           still matches the original seeded copy and never clobbers
 *           super-admin-customized descriptions.
 *
 * Run: php artisan test --filter=VenueHonestyIterationTest
 */

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VenueHonestyIterationTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    // P0.1 — the promise test, as CI
    // ─────────────────────────────────────────────────────────────────────

    public function test_seeded_descriptions_contains_no_known_overclaims(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $forbidden = [
            'Floating artworks',            // void venues use easels until Iteration 2
            'mirror floor reflects',        // no planar reflection until Iteration 2
            'reflects artworks floating',
            'partial dividers',             // no divider geometry until Iteration 3
            'prism of colour',              // refraction is not rendered
            'city views',                   // penthouse has no skyline yet
            'catch refracted light',
        ];

        $rows = DB::table('venue_templates')->select('slug', 'description')->get();
        $this->assertCount(11, $rows, 'Seeder must seed exactly the 11-venue catalog.');

        foreach ($rows as $row) {
            foreach ($forbidden as $claim) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $claim,
                    (string) $row->description,
                    "Venue [{$row->slug}] description re-introduces the over-claim \"{$claim}\". "
                    . 'If the renderer now delivers it, update the honesty-test claim list deliberately.'
                );
            }
        }
    }

    public function test_every_venue_has_a_nonempty_description(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $empty = DB::table('venue_templates')
            ->whereNull('description')
            ->orWhere('description', '')
            ->count();

        $this->assertSame(0, $empty, 'Every venue must carry a customer-facing description.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // P0.2 — draft-leak fix: the picker query contract
    // ─────────────────────────────────────────────────────────────────────

    public function test_picker_query_excludes_draft_venues(): void
    {
        $published = \App\Models\VenueTemplate::factory()->create([
            'slug' => 'picker-published', 'is_active' => true, 'is_draft' => false,
        ]);
        \App\Models\VenueTemplate::factory()->create([
            'slug' => 'picker-draft', 'is_active' => true, 'is_draft' => true,
        ]);
        \App\Models\VenueTemplate::factory()->create([
            'slug' => 'picker-inactive', 'is_active' => false, 'is_draft' => false,
        ]);

        // The exact query the create/edit controllers use (Iteration 0).
        $picker = \App\Models\VenueTemplate::active()
            ->published()
            ->orderBy('sort_order')
            ->get();

        $this->assertTrue($picker->contains('id', $published->id));
        $this->assertFalse(
            $picker->contains('slug', 'picker-draft'),
            'Draft venues must not appear in customer pickers.'
        );
        $this->assertFalse($picker->contains('slug', 'picker-inactive'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // P0.2 — picker blades: server-side truth, no JS maps, no "??" path
    // ─────────────────────────────────────────────────────────────────────

    public function test_picker_blades_render_descriptions_from_db_and_have_no_js_maps(): void
    {
        foreach (['admin/galleries/create.blade.php', 'admin/galleries/edit.blade.php'] as $blade) {
            $contents = file_get_contents(resource_path("views/{$blade}"));

            $this->assertStringContainsString(
                'data-description="{{ $venue->description }}"',
                $contents,
                "[{$blade}] must carry the DB description server-side."
            );
            $this->assertStringContainsString(
                'data-accent=',
                $contents,
                "[{$blade}] must carry the accent color server-side."
            );
            $this->assertStringNotContainsString(
                'venueDescriptions',
                $contents,
                "[{$blade}] the drifted JS description map must stay deleted."
            );
            $this->assertStringNotContainsString(
                'VenueDescriptions',
                $contents,
                "[{$blade}] the drifted JS description map must stay deleted."
            );
            $this->assertStringNotContainsString(
                "'emoji' => '??'",
                $contents,
                "[{$blade}] the literal ?? monogram fallback must stay deleted."
            );
            $this->assertStringContainsString(
                'thumbnail_url ?:',
                $contents,
                "[{$blade}] must prefer the DB-uploaded thumbnail (thumbnail_url)."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // P0.4 — honest capacity label
    // ─────────────────────────────────────────────────────────────────────

    public function test_capacity_label_is_honest_upper_bound_only(): void
    {
        $venue = \App\Models\VenueTemplate::factory()->make([
            'capacity_min' => 20,
            'capacity_max' => 60,
        ]);
        $this->assertSame(
            'Up to 60 artworks',
            $venue->capacityLabel(),
            'Label must not advertise the unenforced capacity_min.'
        );

        $unlimited = \App\Models\VenueTemplate::factory()->make([
            'capacity_min' => 1,
            'capacity_max' => null,
        ]);
        $this->assertSame('Any exhibition size', $unlimited->capacityLabel());
    }

    // ─────────────────────────────────────────────────────────────────────
    // P0.4 — the honesty migration is guarded (production data safety)
    // ─────────────────────────────────────────────────────────────────────

    public function test_honesty_migration_updates_matched_copy_and_respects_admin_edits(): void
    {
        $migration = require database_path('migrations/2026_09_01_000001_honesty_pass_venue_descriptions.php');

        // (a) A row restored to the ORIGINAL seeded copy IS updated.
        DB::table('venue_templates')
            ->where('slug', 'mirror-lake')
            ->update(['description' => 'A perfectly still mirror floor reflects artworks floating above. Moonlit, misty, meditative.']);

        $migration->up();

        $this->assertSame(
            'A still, dark lake floor beneath soft mist and moonlight. Quiet, spacious, meditative.',
            DB::table('venue_templates')->where('slug', 'mirror-lake')->value('description'),
            'Migration must rewrite the original over-claiming copy.'
        );

        // (b) A super-admin-customized description is NOT clobbered.
        DB::table('venue_templates')
            ->where('slug', 'zen-gallery')
            ->update(['description' => 'Our house style: warm, quiet, wood.']);

        $migration->up();

        $this->assertSame(
            'Our house style: warm, quiet, wood.',
            DB::table('venue_templates')->where('slug', 'zen-gallery')->value('description'),
            'Migration must never overwrite admin-customized descriptions.'
        );

        // (c) down() restores the original copy (rollback safety).
        $migration->down();

        $this->assertSame(
            'A perfectly still mirror floor reflects artworks floating above. Moonlit, misty, meditative.',
            DB::table('venue_templates')->where('slug', 'mirror-lake')->value('description'),
            'down() must restore the original seeded description.'
        );
    }
}
