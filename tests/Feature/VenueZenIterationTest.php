<?php

namespace Tests\Feature;

use App\Services\VenueConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * JAPANESE ZEN GALLERY v2 — "The Quiet Procession" — as CI.
 *
 * Pins:
 *   1. The seeded zen-gallery row IS the v2 identity: structure_pass 'bays'
 *      + the bays proportion block, the warm-paper atmosphere, the
 *      procession rig, texture_tint authority over declared colours, the
 *      DECLARED-ABSENT environment ('none' + env_intensity 0 — the venue is
 *      a sealed interior and no sky can ever leak in), artwork legibility,
 *      placement curation, post-fx restraint, sumi-ink frames, and the
 *      linear-only supported_layouts (rotunda dropped — the procession is
 *      linear).
 *   2. The guarded deepening migration (2026_09_07_000002) is the second
 *      delivery path for the SAME identity: run against a hand-rewound
 *      v1.0.0 baseline row it produces the v2 values the seeder writes;
 *      on an already-v2 row it is a byte-exact no-op; down() reverses;
 *      hand-tuned values survive both directions; the v1 structure props
 *      are removed ONLY under the three-way baseline guard.
 *   3. A legacy gallery row holding room_layout 'rotunda' clamps to the
 *      venue default through layoutForGallery (the venue no longer
 *      advertises rotunda; no gallery can force a circular shell into the
 *      bay procession).
 *   4. Authority: 'bays' and 'structure' are venue-owned exporter keys
 *      (architecture cannot be recomposed by a stale gallery override),
 *      shipped in the payload lists so the runtime patch guard mirrors
 *      them; the exporter schema is bumped so cached payloads re-key.
 *   5. The super-admin request vocabulary admits the 'bays' pass.
 *
 * Run: php artisan test --filter=VenueZenIterationTest
 */
class VenueZenIterationTest extends TestCase
{
    use RefreshDatabase;

    /** The exact v1.0.0 baseline row (pre-deepening), for migration tests. */
    private function v1BaselineRow(): array
    {
        return [
            'name'          => 'Japanese Zen Gallery',
            'slug'          => 'zen-gallery',
            'description'   => 'A quiet, focused space: shoji screens, a tokonoma alcove and warm wood, tuned for close, calm looking.',
            'category'      => 'minimal',
            'tags'          => json_encode(['zen', 'natural', 'calm']),
            'plan_required' => 'pro',
            'capacity_min'  => 10,
            'capacity_max'  => 40,
            'sort_order'    => 5,
            'is_featured'   => false,
            'version'       => '1.0.0',
            'default_settings' => json_encode([
                'wall_texture'    => 'wood',
                'floor_material'  => 'wood',
                'lighting_preset'  => 'bright',
                'frame_style'     => 'minimal',
                'room_layout'     => 'rotunda',
            ]),
            'visual_config' => json_encode([
                'wall_height'            => 3.2,
                'wall_depth'             => 0.15,
                'ceiling_type'           => 'flat',
                'ceiling_color'          => '0x1e1c14',
                'ceiling_height'         => 3.2,
                'background_color'       => '0x1a1710',
                'fog_color'              => '0x1a1710',
                'fog_near'               => 12,
                'fog_far'                => 40,
                'ambient_color'          => '0xffe8c2',
                'ambient_intensity'      => 0.22,
                'spot_intensity'         => 0.45,
                'fill_intensity'         => 0.14,
                'tone_mapping_exposure'  => 0.55,
                'frame_override'         => null,
                'environment'            => 'studio',
                'structure_pass'        => 'rooms',
                'structure'              => [
                    ['id' => 'shoji-a-top', 'primitive' => 'box', 'at' => [1.9, 2.065, -0.55], 'size' => [0.06, 0.09, 1.15], 'material' => 'wood_dark'],
                    ['id' => 'alcove-stone', 'primitive' => 'sphere', 'at' => [1.62, 0.36, -2.1], 'size' => [0.4, 0.4, 0.4], 'material' => 'stone'],
                ],
            ]),
            'material_config' => json_encode([
                'wall_color'             => null,
                'wall_roughness'         => 0.7,
                'wall_metalness'         => 0.0,
                'wall_normal_strength'   => 0.5,
                'floor_color'            => null,
                'floor_roughness'        => 0.7,
                'floor_metalness'        => 0.0,
                'floor_normal_strength'  => 0.6,
            ]),
            'supported_layouts' => json_encode(['square', 'rotunda', 'l-shape']),
            'is_active'         => true,
            'is_draft'          => false,
        ];
    }

    private function zenRow()
    {
        return DB::table('venue_templates')->where('slug', 'zen-gallery')->first();
    }

    private function zenVisual(): array
    {
        return json_decode((string) $this->zenRow()?->visual_config, true) ?: [];
    }

    private function zenMaterial(): array
    {
        return json_decode((string) $this->zenRow()?->material_config, true) ?: [];
    }

    private function zenMigration(): object
    {
        return require database_path('migrations/2026_09_07_000002_zen_gallery_deepening.php');
    }

    /** Order-insensitive, type-normalising deep comparison payload. Two
     *  normalisations per the Task-3 test-infra convention:
     *  - ksort: the migration adds keys in a different insertion order than
     *    the seeder (JSON objects are maps);
     *  - int/float: PHP json_encode drops zero fractions (0.0 → 0), so a
     *    row that round-tripped through the DB reads int where the seeder
     *    literal is float — JSON has no int/float distinction anyway. */
    private function canonical($v)
    {
        if (is_array($v)) {
            foreach ($v as $k => $sub) { $v[$k] = $this->canonical($sub); }
            ksort($v);
        } elseif (is_int($v) || is_float($v)) {
            $v = (float) $v;
        }
        return $v;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. The seeded v2 identity
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_seeded_row_is_the_quiet_procession_identity(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $row = $this->zenRow();

        $this->assertNotNull($row, '[zen-gallery] must be seeded.');
        $this->assertSame('2.0.0', $row->version, 'Zen v2 — the Quiet Procession.');
        $this->assertSame('pro', $row->plan_required);

        $vc = $this->zenVisual();

        // The one idea: framed bays, rendered by the generic interpreter.
        $this->assertSame('bays', $vc['structure_pass'] ?? null,
            "structure_pass must select the 'bays' interpreter — the framed-bay architecture is the venue's one idea.");
        $this->assertSame(3.12, $vc['bays']['fin_top'] ?? null,
            'fin_top 3.12 clears the focal-hero frame (top ≈ 2.87 m at the 1.15 boost).');
        $this->assertSame(0.24, $vc['bays']['clerestory_height'] ?? null,
            'The paper clerestory band is the venue light signature.');

        // Environment: declared absence. A sealed interior; no sky, ever.
        $this->assertSame('none', $vc['environment'] ?? null,
            "environment must be 'none' — no HDRI download, no sky can leak in.");
        $this->assertSame(0, $vc['env_intensity'] ?? null,
            'env_intensity must be a DECLARED 0 (nullish authority keeps it 0).');

        // Warm architecture, honest artwork.
        $this->assertSame('0xfff2dd', $vc['ambient_color'] ?? null);
        $this->assertSame(0.95, $vc['tone_mapping_exposure'] ?? null);
        $this->assertSame(0.25, $vc['artwork_light_base'] ?? null);
        $this->assertSame(10, $vc['artwork_light_pool_cap'] ?? null);
        $this->assertSame(0.1, $vc['hemisphere_intensity'] ?? null);

        // The procession rhythm.
        $this->assertSame([
            'density'          => 'generous',
            'focal_wall'       => 'front',
            'pair_orientation' => true,
        ], $vc['placement'] ?? null);

        // Post-fx restraint.
        $this->assertFalse($vc['post_fx']['bloom'] ?? true, 'Calm is the brand — bloom off.');

        // Sumi ink frames.
        $this->assertSame('black', $vc['frame_override'] ?? null);

        // Linear procession only.
        $this->assertSame(['square', 'corridor', 'l-shape'],
            json_decode((string) $row->supported_layouts, true),
            'Rotunda is dropped — a circular zen would be a different venue.');

        // Material authority: declared palette + tatami scale.
        $mc = $this->zenMaterial();
        $this->assertTrue($mc['texture_tint'] ?? false, 'texture_tint IS the fix.');
        $this->assertSame('0xe6dfcf', $mc['wall_color'] ?? null, 'Warm limewash walls.');
        $this->assertSame('0xa98d64', $mc['floor_color'] ?? null, 'Pale cedar floor.');
        $this->assertSame(1.8, $mc['floor_tile_meters'] ?? null, 'Tatami-scale floor rhythm.');

        // Honest copy.
        $this->assertStringContainsString('framed bays', (string) $row->description);
        $this->assertStringContainsString('paper band', (string) $row->description);

        // The v1 props are gone — no absolute-coordinate shoji/tokonoma.
        $this->assertArrayNotHasKey('structure', $vc,
            'The v1 descriptor props must not coexist with the bays architecture.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. The deepening migration — one identity, two delivery paths
    // ─────────────────────────────────────────────────────────────────────

    public function test_migration_transforms_the_v1_baseline_into_the_seeded_v2(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Hand-rewind the row to the exact v1.0.0 baseline.
        DB::table('venue_templates')->where('slug', 'zen-gallery')
            ->update($this->v1BaselineRow());

        $this->zenMigration()->up();

        $row  = $this->zenRow();
        $seed = collect(\Database\Seeders\VenueTemplateSeeder::templates())
            ->firstWhere('slug', 'zen-gallery');

        $this->assertSame($seed['version'], $row->version, 'Migration stamps the same version as the seeder.');
        $this->assertSame($seed['description'], $row->description,
            'Migration writes the same honest copy as the seeder.');
        $this->assertSame(
            $this->canonical($seed['visual_config']),
            $this->canonical(json_decode((string) $row->visual_config, true)),
            'Migration visual_config MUST equal the seeder visual_config — one identity, two delivery paths.'
        );
        $this->assertSame(
            $this->canonical($seed['material_config']),
            $this->canonical(json_decode((string) $row->material_config, true)),
            'Migration material_config MUST equal the seeder material_config.'
        );
        $this->assertSame(
            $seed['supported_layouts'],
            json_decode((string) $row->supported_layouts, true),
            'Migration supported_layouts MUST equal the seeder (rotunda dropped).'
        );
    }

    public function test_migration_is_a_noop_on_an_already_v2_row(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $before = $this->zenRow()->visual_config;

        $migration = $this->zenMigration();
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame($before, $this->zenRow()->visual_config,
            'Re-running up() on the v2 row must be byte-exact no-op (every guard misses).');
    }

    public function test_migration_guards_respect_hand_tuned_values(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // (a) An admin-tuned rig value survives up().
        DB::table('venue_templates')->where('slug', 'zen-gallery')->update([
            'visual_config' => json_encode(array_merge($this->zenVisual(), [
                'tone_mapping_exposure' => 1.23,
            ])),
        ]);
        $this->zenMigration()->up();
        $this->assertSame(1.23, $this->zenVisual()['tone_mapping_exposure'],
            'A hand-tuned exposure must never be overwritten by the deepening.');

        // (b) An admin-tuned structure row keeps its descriptors (the
        //     three-way baseline guard): version tuned away → props stay.
        DB::table('venue_templates')->where('slug', 'zen-gallery')->update([
            'version'       => '9.9.9',
            'description'   => 'Admin bespoke zen',
            'visual_config' => json_encode([
                'structure_pass' => 'rooms',
                'structure'      => [
                    ['id' => 'custom-prop', 'primitive' => 'box', 'at' => [0, 1, 0], 'size' => [1, 1, 1], 'material' => 'stone'],
                ],
            ]),
        ]);
        $this->zenMigration()->up();
        $vc = $this->zenVisual();
        $this->assertSame('rooms', $vc['structure_pass'],
            'A hand-tuned row keeps its own interpreter choice.');
        $this->assertArrayHasKey('structure', $vc,
            'A hand-tuned row keeps its own descriptors.');
    }

    public function test_migration_down_reverses_the_baseline(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Rewind → up() → down() lands back on the v1 baseline shape.
        DB::table('venue_templates')->where('slug', 'zen-gallery')
            ->update($this->v1BaselineRow());
        $this->zenMigration()->up();
        $this->zenMigration()->down();

        $row = $this->zenRow();
        $v1  = $this->v1BaselineRow();

        $this->assertSame('1.0.0', $row->version, 'down() reverts the version stamp.');
        $this->assertSame($v1['description'], $row->description, 'down() reverts the copy.');
        $downVc = json_decode((string) $row->visual_config, true);
        $v1Vc   = json_decode($v1['visual_config'], true);
        unset($v1Vc['structure']);   // one-way removal (see the pin below)
        $this->assertSame(
            $v1Vc,
            $downVc,
            'down() restores the v1 visual_config (structure_pass rooms, pre-polish rig, studio environment).'
        );
        $this->assertArrayNotHasKey('structure', $downVc,
            'The v1 descriptor props are a ONE-WAY removal (down() cannot resurrect deleted rows) — full-fidelity rollback is the Venue Editor snapshot system, per EXOSPACE_VENUES.md §6.');
        $this->assertSame(
            json_decode($v1['supported_layouts'], true),
            json_decode((string) $row->supported_layouts, true),
            'down() restores the v1 supported_layouts (rotunda back).'
        );

        // A hand-tuned v2 row survives down() untouched where it differs.
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        DB::table('venue_templates')->where('slug', 'zen-gallery')->update([
            'visual_config' => json_encode(array_merge($this->zenVisual(), [
                'ambient_intensity' => 0.77,
            ])),
        ]);
        $this->zenMigration()->down();
        $this->assertSame(0.77, $this->zenVisual()['ambient_intensity'],
            'down() must never revert a value the admin hand-tuned.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. Legacy layout clamp — no circular shell in the procession
    // ─────────────────────────────────────────────────────────────────────

    public function test_legacy_rotunda_gallery_clamps_to_the_venue_default(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $user    = \App\Models\User::factory()->create(['plan' => 'pro']);
        $venueId = DB::table('venue_templates')->where('slug', 'zen-gallery')->value('id');

        $exporter = app(VenueConfigExporter::class);

        // A gallery row still carrying rotunda from the v1 era.
        $gallery = \App\Models\Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => $venueId,
            'room_layout'       => 'rotunda',
        ]);

        $this->assertSame('square', $exporter->layoutForGallery($gallery),
            'layoutForGallery must clamp an unsupported rotunda to the venue default (square) — the bay procession is linear.');

        // Supported layouts pass through untouched.
        $gallery->room_layout = 'corridor';
        $this->assertSame('corridor', $exporter->layoutForGallery($gallery));
        $gallery->room_layout = 'l-shape';
        $this->assertSame('l-shape', $exporter->layoutForGallery($gallery));
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Authority — the architecture cannot be recomposed by a gallery
    // ─────────────────────────────────────────────────────────────────────

    public function test_bays_and_structure_are_venue_owned_and_shipped(): void
    {
        foreach (['bays', 'structure'] as $key) {
            $this->assertContains($key, VenueConfigExporter::VENUE_OWNED_VISUAL_KEYS,
                "[{$key}] is venue-owned architecture — a stale gallery override cannot reshape the venue.");
        }
        $this->assertSame('s5', VenueConfigExporter::SCHEMA,
            'The s5 schema bump re-keys every cached payload on deploy.');

        // The shipped lists (runtime patch guard mirror) carry them too.
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $user    = \App\Models\User::factory()->create(['plan' => 'pro']);
        $gallery = \App\Models\Gallery::factory()->create([
            'user_id'           => $user->id,
            'venue_template_id' => DB::table('venue_templates')->where('slug', 'zen-gallery')->value('id'),
        ]);
        $payload = app(VenueConfigExporter::class)->forGallery($gallery);

        $this->assertContains('bays', $payload['venue_owned_visual'] ?? [],
            'The payload ships venue_owned_visual so the runtime patch guard mirrors this file.');
        $this->assertContains('structure', $payload['venue_owned_visual'] ?? []);

        // The venue's own bays block still reaches the payload (owned keys
        // are stripped from GALLERY overrides, never from the venue).
        $this->assertSame(3.12, $payload['visual_config']['bays']['fin_top'] ?? null,
            'The venue bay proportions reach the runtime.');
        $this->assertSame('none', $payload['visual_config']['environment'] ?? null,
            'The declared-absent environment reaches the runtime.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. The super-admin editor can express the venue
    // ─────────────────────────────────────────────────────────────────────

    public function test_venue_request_vocabulary_admits_the_bays_pass(): void
    {
        $request  = new \App\Http\Requests\SuperAdmin\VenueTemplateRequest();
        $rule     = $request->rules()['visual_config.structure_pass'] ?? null;
        $this->assertNotNull($rule, 'The structure_pass rule must exist.');
        $rendered = collect($rule)->map(fn ($r) => (string) $r)->implode('|');
        $this->assertStringContainsString('bays', $rendered,
            "The Venue Editor's validation vocabulary must admit the 'bays' interpreter.");
    }
}
