<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Iteration 8 "The Salon" (roadmap P3.2): venue #12 — the catalog's first
 * pipeline-born venue (docs/VENUE_12_BRIEF.md, §16.7).
 *
 * THE DECISION (pre-committed rule, brief §3)
 * -------------------------------------------
 *   studio share >= 50% of venue-attributed views → GRANDEUR (great hall)
 *   below 50% — or NO MEANINGFUL VIEW DATA YET                   → INTIMACY (salon)
 *
 * This build runs under the rule's no-data branch: the build environment
 * has no production view data (the instrument, `php artisan
 * venues:catalog-report --json`, is the only numeric input and it reads
 * production). The decision record in the brief is completed accordingly;
 * if production data later shows the >=50% branch, the Grand Hall brief
 * (§4 candidate B) is pre-staged and unspent — the Salon remains justified
 * on its own merits (cheapest build in the catalog, free/funnel reach,
 * flatters the majority of uploads).
 *
 * WHAT IT SHIPS
 * -------------
 * One new venue row, "The Salon" (slug `the-salon`, Pro tier, sort_order
 * 12): a small warm domestic room whose identity is ENTIRELY declared in
 * visual_config — warm plaster + timber shell, anchor-based structure
 * descriptors (picture rail ×4 via fit:'wall', centre bench, rug), and the
 * catalog's first seeded `visual_config.placement` block: density
 * 'intimate' (§6.3 ≈ 2.8 m rhythm) + pair_orientation (§6.4 interleave) —
 * the IT6 curation machinery used AS VENUE CHARACTER, not auto-magic
 * (DO NOT DO #6). No focal wall: the brief assigns focal treatment to the
 * grand-hall candidate, and the salon's walls read egalitarian by design.
 *
 * Zero JS ships with this iteration: the descriptor interpreter
 * (StructureBuilder), the placement interpreter (ArtworkPlacer) and the
 * config pipeline (VenueConfigExporter) already render every declared key
 * generically. A venue with no code is the IT6 contract working as
 * intended (§10.2 — the DB is the sole source of venue identity).
 *
 * SAFETY (production data protection) — same family as Iterations 2/3/5/6:
 *   - up() is a GUARDED INSERT: runs only when the slug is absent, so
 *     re-running (and existing installs that already created the venue via
 *     the admin suite) is a no-op. It NEVER touches the eleven seeded
 *     venues and never overwrites anything (DO NOT DO #13: no re-seeding).
 *   - down() deletes the row ONLY while every config column still equals
 *     the payload this migration wrote AND no gallery references it — a
 *     super-admin-tuned or in-use venue survives rollback untouched
 *     (the row is then inert data, never broken references).
 *   - Fresh installs get the identical row from VenueTemplateSeeder (the
 *     payloads are pinned byte-equal by VenueSalonIterationTest).
 *
 * STATUS FIELDS: is_active true, is_draft false, published_at now() — the
 * venue is live the moment the migration runs, because the chooser test
 * (§13) extends to 12 or the venue does not ship (brief §5.3).
 *
 * ROLLBACK (runtime, no deploy): remove the structure/placement keys from
 * the venue's visual_config in the admin suite → the venue renders as a
 * plain default room, live. Full removal: unpublish/archive via the IT5
 * authoring suite, or migrate:rollback while the row is untouched.
 */
return new class extends Migration
{
    /**
     * The Salon payload — MUST stay byte-equal (same keys, same order,
     * same values) to entry #12 in VenueTemplateSeeder::templates().
     * Pinned by VenueSalonIterationTest::test_seeder_and_migration_payloads_are_equal.
     */
    public function salonTemplate(): array
    {
        return [
            'name'          => 'The Salon',
            'slug'          => 'the-salon',
            'description'   => 'A small, warm room in the domestic tradition: works hung close together at conversational distance, a wooden picture rail and a bench, under soft warm light. Made for studies, prints, photography and portrait formats.',
            'category'      => 'classic',
            'tags'          => ['salon', 'warm', 'intimate', 'portrait'],
            'plan_required' => 'pro',
            'capacity_min'  => 5,
            'capacity_max'  => 30,
            'sort_order'    => 12,
            'is_featured'   => false,
            'version'       => '1.0.0',
            'default_settings' => [
                'wall_texture'    => 'white',
                'floor_material'  => 'wood',
                'lighting_preset'  => 'bright',
                'frame_style'     => 'minimal',
                'room_layout'     => 'square',
            ],
            'visual_config' => [
                'wall_height'            => 3.0,
                'wall_depth'             => 0.15,
                'ceiling_type'           => 'flat',
                'ceiling_color'          => '0x2b241a',
                'ceiling_height'         => 3.0,
                'background_color'       => '0x1d1712',
                'fog_color'              => '0x1d1712',
                'fog_near'               => 10,
                'fog_far'                => 32,
                'ambient_color'          => '0xffdcae',
                'ambient_intensity'      => 0.26,
                'spot_intensity'         => 0.5,
                'fill_intensity'         => 0.16,
                'tone_mapping_exposure'  => 0.6,
                'frame_override'         => null,
                // ── Iteration 8 declared identity (rollback = remove
                // these keys — the venue reverts to a plain default
                // room, live; no deploy, no flag).
                'structure_pass'        => 'rooms',
                'placement'             => [
                    'density'          => 'intimate',  // §6.3 — ~2.8 m salon-close rhythm
                    'pair_orientation' => true,        // §6.4 — portrait/landscape interleave
                ],
                'structure'              => [
                // ── Picture rail — one thin timber line fitted to each wall
                // (fit: 'wall' stretches it to the wall span, minus corner
                // pads). The salon-wall convention made physical.
                ['id' => 'rail-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                ['id' => 'rail-back',  'primitive' => 'box', 'at' => ['from' => 'wall_back',  'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                ['id' => 'rail-left',  'primitive' => 'box', 'at' => ['from' => 'wall_left',  'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                ['id' => 'rail-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                // ── Bench — the domestic datum (§4.5 convention, centred),
                // colliding so walkers respect it.
                ['id' => 'bench-top',  'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0, 0.42, 1.4]], 'size' => [1.5, 0.09, 0.42], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'salon-bench', 'tier_floor' => 'low'],
                ['id' => 'bench-leg-l', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [-0.65, 0.19, 1.4]], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'salon-bench', 'tier_floor' => 'low'],
                ['id' => 'bench-leg-r', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0.65, 0.19, 1.4]], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'salon-bench', 'tier_floor' => 'low'],
                // ── Rug — the one warm floor note. Flat: walkable, not an
                // obstacle (no collide), cheapest tier renders it.
                ['id' => 'rug', 'primitive' => 'plane', 'at' => ['from' => 'center', 'offset' => [0, 0.012, 1.4]], 'rot' => [-1.5707963, 0, 0], 'size' => [2.6, 1.8], 'material' => 'fabric_warm', 'tier_floor' => 'low'],
            ],
            ],
            'material_config' => [
                'wall_color'             => '0xe6dcc6',
                'wall_roughness'         => 0.92,
                'wall_metalness'         => 0.0,
                'wall_normal_strength'   => 0.35,
                'floor_color'            => '0x6b5236',
                'floor_roughness'        => 0.65,
                'floor_metalness'        => 0.0,
                'floor_normal_strength'  => 0.55,
            ],
            'decorations'       => [],  // rail + bench + rug are descriptors (StructureBuilder)
            'lighting_fixtures' => [],
            'supported_layouts' => ['square'],
        ];
    }

    public function up(): void
    {
        // GUARDED INSERT — never overwrites, never re-seeds (§15.13).
        if (DB::table('venue_templates')->where('slug', 'the-salon')->exists()) {
            return;
        }

        $t     = $this->salonTemplate();
        $now   = now();

        DB::table('venue_templates')->insert([
            'name'             => $t['name'],
            'slug'             => $t['slug'],
            'description'      => $t['description'],
            'category'         => $t['category'],
            'tags'             => json_encode($t['tags']),
            'plan_required'    => $t['plan_required'],
            'capacity_min'     => $t['capacity_min'],
            'capacity_max'     => $t['capacity_max'],
            'sort_order'       => $t['sort_order'],
            'is_featured'      => $t['is_featured'],
            'is_active'        => true,
            'is_draft'         => false,
            'version'          => $t['version'],
            'default_settings' => json_encode($t['default_settings']),
            'visual_config'    => json_encode($t['visual_config']),
            'material_config'  => json_encode($t['material_config']),
            'decorations'      => json_encode($t['decorations']),
            'lighting_fixtures'=> json_encode($t['lighting_fixtures']),
            'supported_layouts'=> json_encode($t['supported_layouts']),
            'view_count'       => 0,
            'published_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }


    /** Numeric-tolerant deep equality for JSON-decoded config columns. */
    private function jsonEquals($a, $b): bool
    {
        if (is_array($a) || is_array($b)) {
            if (!is_array($a) || !is_array($b) || count($a) !== count($b)) {
                return false;
            }
            foreach ($b as $k => $v) {
                if (!array_key_exists($k, $a) || !$this->jsonEquals($a[$k], $v)) {
                    return false;
                }
            }
            return true;
        }
        if (is_string($a) || is_string($b)) {
            return (string) $a === (string) $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return $a === $b;
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        if (!$row) {
            return;
        }

        // Never delete a venue a gallery still uses — that would orphan
        // customer data (the IT5 archive guard exists for the same reason).
        $inUse = DB::table('galleries')->where('venue_template_id', $row->id)->exists();
        if ($inUse) {
            return;
        }

        // Never delete a venue the admin has tuned — every config column
        // must still equal what up() wrote (super-admin edits win, always).
        // Numeric-tolerant: JSON round-trips may re-type 1.0 as 1 depending
        // on the PHP build's json encoder; that is not an admin edit.
        $t = $this->salonTemplate();
        foreach (['tags', 'default_settings', 'visual_config', 'material_config', 'decorations', 'lighting_fixtures', 'supported_layouts'] as $col) {
            if (!$this->jsonEquals(json_decode((string) $row->{$col}, true), $t[$col])) {
                return;
            }
        }
        if ((string) $row->name !== $t['name']
            || (string) $row->description !== $t['description']
            || (string) $row->category !== $t['category']
            || (string) $row->plan_required !== $t['plan_required']
            || (int) $row->capacity_min !== $t['capacity_min']
            || (int) $row->capacity_max !== $t['capacity_max']
            || (int) $row->sort_order !== $t['sort_order']) {
            return;
        }

        DB::table('venue_templates')->where('id', $row->id)->delete();
    }
};
