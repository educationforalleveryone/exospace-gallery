<?php

declare(strict_types=1);

/**
 * Luxury Penthouse identity tests — v2.0.0 "The Collector's Floor"
 * (2026-09-08 audit pass; predecessor: 2026_09_01_000003 "Rooms").
 *
 * Pins the v2.0.0 contract so the venue can never silently regress:
 *
 *   - Declared identity: the seeder baseline AND the guarded migration
 *     (2026_09_08_000005) are THE SAME final row (byte-parity of content,
 *     key-order insensitive) — production galleries migrate, fresh installs
 *     seed, and both roads must end at one identity.
 *   - The grand volume: wall height 5.2 (the 4.5 m corridor is gone), a
 *     warm dark ceiling (the grave-black 0x080808 is gone).
 *   - The sky is the CITY: environment declared 'none' (the rural_evening
 *     fallback 404 and the wrong-sky accident are structurally impossible).
 *   - The grey-veil class: post_fx is DECLARED with the black vignette
 *     blend (the stock grey veil that shipped on this venue for its whole
 *     life can never return).
 *   - Material authority: texture_tint + the warm mineral-white wall and
 *     the honed warm-stone floor (the plastic "marble" is gone) at slab
 *     scale (floor_tile_meters 2.4).
 *   - Artwork legibility floor: base 0.22 + pool cap 12 + spot 0.62.
 *   - The structure payload: 40 descriptors — the fireplace volume on the
 *     NEW l-shape wall anchors, the perimeter cove, the bronze base trim,
 *     the three-layer skyline + horizon glow, the curated lounge, the slab
 *     joints.
 *   - The promise matrix: the copy names what renders (walnut, stone,
 *     lounge, glass, city) and promises no dark walls (the walls are warm
 *     mineral white).
 *   - The migration is guarded, idempotent, and reversible; admin edits
 *     survive up() and down().
 *   - Every architecture/rig/presentation key the row uses is already
 *     venue-owned (s6) — no schema bump, sibling venues untouched.
 *
 * Run: php artisan test --filter=VenuePenthouseIterationTest
 */

namespace Tests\Feature;

use App\Services\VenueConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VenuePenthouseIterationTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    // The seeder baseline — the fresh-install identity
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_seeded_row_is_the_collectors_floor(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $config = $this->visualConfig('luxury-penthouse');

        // The Rooms interpreter + glazing remain the mechanism (P-scope).
        $this->assertSame('rooms', $config['structure_pass'], 'The descriptor interpreter remains the identity vehicle.');
        $this->assertTrue($config['glazing_wall'] ?? false, 'The glazing wall is the venue\'s view mechanism.');

        // The grand volume (P2).
        $this->assertSame(5.2, $config['wall_height'], 'Wall height 5.2 — the 4.5 m corridor is gone.');
        $this->assertSame(5.2, $config['ceiling_height'], 'The ceiling rises with the volume.');
        $this->assertSame('0x14110d', $config['ceiling_color'], 'The ceiling is warm dark bronze-brown, not grave-black.');

        // The sky is the city (P4): declared absence.
        $this->assertSame('none', $config['environment'], 'environment none — no HDRI download, no rural_evening 404, no wrong sky.');
        $this->assertSame(0, $config['env_intensity'], 'env_intensity 0 — the environment is silenced at the source.');

        // The warm evening rig (P7) + depth fog for the skyline (P5).
        $this->assertSame('0xe6d6bc', $config['ambient_color'], 'The ambient is warm evening, not the cool museum cast.');
        $this->assertSame(0.26, $config['ambient_intensity']);
        $this->assertSame(0.14, $config['hemisphere_intensity']);
        $this->assertSame(0.78, $config['tone_mapping_exposure'], 'The rig is luminous (the 0.55 murk is gone).');
        $this->assertSame(16, $config['fog_near'], 'Fog carries the skyline depth layers (near).');
        $this->assertSame(55, $config['fog_far'], 'Fog carries the skyline depth layers (far).');

        // Artwork legibility floor (P9).
        $this->assertSame(0.22, $config['artwork_light_base'], 'Wall-family standing glow lifted for the 5.2 m room.');
        $this->assertSame(12, $config['artwork_light_pool_cap'], 'The desktop pool lights a typical hang at once.');
        $this->assertSame(0.62, $config['spot_intensity'], 'The pool target ≈ 2.2 — art stays the hero without glare.');

        // The grey veil can never ship again (P3).
        $this->assertSame(0.32, $config['post_fx']['bloom_strength'] ?? null, 'Bloom is declared ON but restrained (0.32).');
        $this->assertSame('black', $config['post_fx']['vignette_blend'] ?? null, 'The vignette blends toward TRUE BLACK (the cathedral deploy-review precedent).');

        // Material authority (P6).
        $material = $this->materialConfig('luxury-penthouse');
        $this->assertSame('0xe9e2d4', $material['wall_color'], 'The walls are warm mineral white (the copy/render mismatch is dead).');
        $this->assertSame('0x9b8d78', $material['floor_color'], 'The floor is honed warm stone, not plastic marble.');
        $this->assertTrue($material['texture_tint'] ?? false, 'Declared colours are authoritative (the texture-tint authority rule).');
        $this->assertSame(2.4, $material['floor_tile_meters'], 'The floor reads at large-slab scale.');

        // The descriptor payload: 40 entries with the identity set present.
        $structure = $config['structure'] ?? [];
        $this->assertCount(40, $structure, 'The Collector\'s Floor payload ships 40 descriptors.');
        $ids = array_column($structure, 'id');
        foreach ([
            'fireplace-stone', 'fireplace-mantel', 'fireplace-band', 'fireplace-hearth',
            'cove-left', 'cove-right', 'cove-inner', 'cove-back',
            'base-left', 'base-right', 'base-inner', 'base-back',
            'skyline-cool', 'skyline-warm', 'skyline-far', 'horizon-glow',
            'chair-seat', 'lamp-shade', 'plinth', 'sculpture-torus', 'bench-top', 'floor-joints',
        ] as $required) {
            $this->assertContains($required, $ids, "The identity payload must include '{$required}'.");
        }
        // The v1.0.0 identity is carried by NEW anchors — the wall_* l-shape
        // anchors must be exercised (the StructureBuilder extension lands).
        $anchors = array_column(array_column($structure, 'at'), 'from');
        $this->assertContains('wall_front', $anchors, 'The fireplace rides the NEW wing-A end-wall anchor.');
        $this->assertContains('wall_left', $anchors, 'The bench/cove/base ride the NEW wing-A west-wall anchor.');
        $this->assertContains('wall_inner', $anchors, 'The wing-B north wall is addressed by the payload.');
        $this->assertContains('wall_back', $anchors, 'The colinear south run is addressed by the payload.');

        $row = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $this->assertSame('2.0.0', $row->version, 'The identity pass bumps the venue version to 2.0.0.');

        // The fire becomes real light (P7): ONE warm anchored fixture.
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        $this->assertCount(2, $fixtures, 'The rig adds exactly TWO fixtures — the fire glow and the hearth wash (both anchored, zero unanchored).');
        $this->assertSame('fire-glow', $fixtures[0]['id'] ?? null);
        $this->assertSame('hearth-wash', $fixtures[1]['id'] ?? null);
        foreach ($fixtures as $f) {
            $this->assertArrayHasKey('anchor', $f, 'Every penthouse fixture is layout-relative — a fixed coordinate would drift off the moving end wall.');
        }
        $this->assertSame('wall_front', $fixtures[0]['anchor']['from'] ?? null, 'The fire light rides the NEW wall_front anchor (layout-relative, never drifts from the stone).');
    }

    public function test_the_copy_promise_matches_the_delivered_floor(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $desc = mb_strtolower((string) DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('description'));

        // Honesty matrix: every noun the copy promises is a rendered element.
        foreach (['walnut', 'stone', 'lounge', 'glass', 'city'] as $noun) {
            $this->assertStringContainsStringIgnoringCase($noun, $desc, "Copy promises the noun '{$noun}' — the payload must render it.");
        }
        // The v1.0.0 copy promised DARK WALLS; the walls are warm white.
        $this->assertStringNotContainsStringIgnoringCase('dark walls', $desc, 'Copy must not promise dark walls — the declared walls are warm mineral white.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The guarded migration — production roads converge on one identity
    // ─────────────────────────────────────────────────────────────────────

    private function penthouseMigration(): object
    {
        return require database_path('migrations/2026_09_08_000005_luxury_penthouse_residence.php');
    }

    /** The v1.0.0 row exactly as production holds it pre-pass. */
    private function seedLegacyPenthouseRow(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        DB::table('venue_templates')->where('slug', 'luxury-penthouse')->update([
            'description' => 'A private collector\'s evening — a glazed wall over the city lights, a lounge by the glass, dark walls and gold frames.',
            'version'     => '1.0.0',
            'visual_config' => json_encode([
                'wall_height'            => 4.5,
                'wall_depth'             => 0.3,
                'ceiling_type'           => 'flat',
                'ceiling_color'          => '0x080808',
                'ceiling_height'         => 4.5,
                'background_color'       => '0x08090d',
                'fog_color'              => '0x08090d',
                'fog_near'               => 8,
                'fog_far'                => 25,
                'ambient_color'          => '0xb8c8e8',
                'ambient_intensity'      => 0.2,
                'spot_intensity'         => 0.5,
                'fill_intensity'         => 0.15,
                'tone_mapping_exposure'  => 0.55,
                'frame_override'         => 'gold',
                'structure_pass'         => 'rooms',
                'glazing_wall'           => true,
                'structure'              => [
                    ['id' => 'terrace-deck', 'primitive' => 'box', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'dark_trim'],
                    ['id' => 'glazing-glass', 'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 2.2, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 4.4], 'material' => ['glass' => true, 'tint' => '0xc4d8ea', 'opacity' => 0.18]],
                    ['id' => 'glazing-mullions', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 2.2, 0.05]], 'turn' => 'in', 'size' => [0.06, 4.4, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
                    ['id' => 'glazing-sill', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.05, 0]], 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
                    ['id' => 'glazing-head', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 4.42, 0]], 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
                    ['id' => 'rail-bar', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.95, 0.55]], 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
                    ['id' => 'rail-posts', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
                    ['id' => 'skyline-cool', 'primitive' => 'instance-grid', 'size' => [2.2, 10, 2.2], 'material' => 'tower_cool', 'grid' => ['mode' => 'scatter', 'count' => 9, 'seed' => 'skyline-cool', 'area' => ['from' => 'glazing_outside', 'size' => [26, 0, 30], 'forward' => 5], 'scale_jitter' => [0.55, 2.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
                    ['id' => 'skyline-warm', 'primitive' => 'instance-grid', 'size' => [1.5, 14, 1.5], 'material' => 'tower_warm', 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-warm', 'area' => ['from' => 'glazing_outside', 'size' => [26, 0, 30], 'forward' => 9], 'scale_jitter' => [0.5, 1.7], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
                    ['id' => 'lounge-pendant', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [0, 3.55, 1.75]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 2.6, 'size' => [1, 0.045, 0.045], 'material' => ['color' => '0xffd9a8', 'emissive' => '0xffc98a', 'emissiveIntensity' => 2.2]],
                    ['id' => 'lounge-rug', 'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 0.012, 2.0]], 'turn' => 'in', 'rot' => [-1.5707963, 0, 0], 'size' => [3.2, 2.3], 'material' => 'fabric_dark'],
                    ['id' => 'sofa-base', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.24, 1.75]], 'turn' => 'in', 'size' => [2.3, 0.48, 0.95], 'material' => 'fabric_warm', 'collide' => true],
                    ['id' => 'sofa-back', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.72, 2.15]], 'turn' => 'in', 'size' => [2.3, 0.5, 0.24], 'material' => 'fabric_warm'],
                    ['id' => 'sofa-arm-l', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
                    ['id' => 'sofa-arm-r', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
                    ['id' => 'table-top', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.34, 0.9]], 'turn' => 'in', 'size' => [1.15, 0.05, 0.55], 'material' => 'wood_dark'],
                    ['id' => 'table-pedestal', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.15, 0.9]], 'turn' => 'in', 'size' => [0.5, 0.3, 0.35], 'material' => 'dark_trim', 'collide' => true],
                ],
            ]),
            'material_config' => json_encode([
                'wall_color'            => null,
                'wall_roughness'        => 0.8,
                'wall_metalness'        => 0.0,
                'wall_normal_strength'  => 0.3,
                'floor_color'           => null,
                'floor_roughness'       => 0.3,
                'floor_metalness'       => 0.2,
                'floor_normal_strength' => 0.5,
            ]),
        ]);
    }

    public function test_the_migration_lands_the_exact_seeder_state(): void
    {
        // Production path: the v1.0.0 row is transformed by the guarded
        // pass; a fresh install is seeded straight to the final state. Both
        // roads MUST end at the same identity.
        $this->seedLegacyPenthouseRow();
        $this->penthouseMigration()->up();

        $migrated = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();

        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $seeded = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();

        // Canonical comparison: JSON object key ORDER legitimately differs
        // (the migration appends its added keys; the seeder ships the
        // composed literal). Identity = same keys, same values, same types.
        $this->assertSame(
            $this->canonicalJson($seeded->visual_config),
            $this->canonicalJson($migrated->visual_config),
            'Migration final state must equal the seeder baseline (visual_config, key-order-insensitive).'
        );
        $this->assertSame(
            $this->canonicalJson($seeded->material_config),
            $this->canonicalJson($migrated->material_config),
            'Migration final state must equal the seeder baseline (material_config, key-order-insensitive).'
        );
        $this->assertSame(
            $this->canonicalJson($seeded->lighting_fixtures),
            $this->canonicalJson($migrated->lighting_fixtures),
            'Migration final state must equal the seeder baseline (lighting_fixtures).'
        );
        $this->assertSame($seeded->description, $migrated->description, 'Descriptions converge.');
        $this->assertSame('2.0.0', $migrated->version, 'The pass lands on v2.0.0.');
        $this->assertSame($seeded->version, $migrated->version, 'Versions converge.');
    }

    public function test_the_pass_is_guarded_idempotent_and_reversible(): void
    {
        $this->seedLegacyPenthouseRow();

        // An admin retune that must survive the pass (guarded swap):
        // artwork_light_base differs from the migration's declared 0.22 —
        // the admin owns it now.
        DB::table('venue_templates')->where('slug', 'luxury-penthouse')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('luxury-penthouse'), [
                'ambient_intensity' => 0.4, // differs from the v1.0.0 0.2 — the admin owns it
            ])),
            'description' => 'Our home.',
        ]);

        $migration = $this->penthouseMigration();
        $migration->up();

        $config = $this->visualConfig('luxury-penthouse');
        $this->assertSame(0.4, $config['ambient_intensity'], 'Admin-tuned values are never overwritten (guarded swap).');
        $this->assertSame(5.2, $config['wall_height'] ?? null, 'The grand volume still arrives around the admin edit.');
        $this->assertSame('none', $config['environment'] ?? null, 'The declared sky still arrives (union-add).');
        $this->assertSame(
            'Our home.',
            (string) DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('description'),
            'Admin-customized copy is never clobbered.'
        );
        // The structure swap is EXACT-match guarded: an admin-edited payload
        // keeps the admin composition.
        $this->assertCount(17, $config['structure'], 'An admin-retained legacy payload is respected (exact-match guard).');

        // Idempotence: a second run changes nothing.
        $before = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $migration->up();
        $after = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first();
        $this->assertSame($before->visual_config, $after->visual_config, 'Re-running the migration rewrites nothing.');

        // Reversibility: down() restores migration-owned values only.
        $migration->down();
        $rolled = $this->visualConfig('luxury-penthouse');
        $this->assertSame(0.4, $rolled['ambient_intensity'] ?? null, 'down() preserves the admin edit — only migration-owned values revert.');
        $this->assertSame(4.5, $rolled['wall_height'] ?? null, 'down() restores the v1.0.0 wall height.');
        $this->assertArrayNotHasKey('environment', $rolled, 'down() removes the declared-sky key it added.');
        $this->assertArrayNotHasKey('post_fx', $rolled, 'down() removes the post_fx object it added (the untouched legacy row declared none).');
        $this->assertSame('Our home.', (string) DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('description'), 'down() keeps admin copy.');
        $fixturesRolled = json_decode((string) DB::table('venue_templates')->where('slug', 'luxury-penthouse')->value('lighting_fixtures'), true) ?: [];
        $this->assertSame([], $fixturesRolled, 'down() removes the anchored fire-glow fixture it added (admin had none).');

        // Reversibility on an untouched row: full restore.
        $this->seedLegacyPenthouseRow();
        $migration->up();
        $pristine = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first(['visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        $migration->down();
        $restored = DB::table('venue_templates')->where('slug', 'luxury-penthouse')->first(['visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        $this->assertSame($pristine->visual_config, $restored->visual_config, 'Untouched rows restore exactly (visual_config).');
        $this->assertSame($pristine->material_config, $restored->material_config, 'Untouched rows restore exactly (material_config).');
        $this->assertSame($pristine->lighting_fixtures, $restored->lighting_fixtures, 'Untouched rows restore exactly (lighting_fixtures).');
        $this->assertSame($pristine->description, $restored->description, 'Untouched rows restore exactly (description).');
        $this->assertSame($pristine->version, $restored->version, 'Untouched rows restore exactly (version).');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The client payload — the Collector's Floor must reach preview AND public
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_payload_carries_the_collectors_floor_to_the_client(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue  = \App\Models\VenueTemplate::where('slug', 'luxury-penthouse')->firstOrFail();
        $config = app(VenueConfigExporter::class)->forVenuePreview($venue);

        $visual = $config['visual_config'] ?? [];
        $this->assertSame(5.2, $visual['wall_height'] ?? null, 'The grand volume reaches the viewer payload.');
        $this->assertSame('none', $visual['environment'] ?? null, 'The declared environment reaches the payload.');
        $this->assertSame(0.22, $visual['artwork_light_base'] ?? null, 'The legibility floor reaches the payload.');
        $this->assertSame('black', $visual['post_fx']['vignette_blend'] ?? null, 'The declared post-fx reaches the payload.');
        $this->assertCount(40, $visual['structure'] ?? [], 'The descriptor payload reaches the viewer.');
        $this->assertSame('0xe9e2d4', $config['material_config']['wall_color'] ?? null, 'The wall authority reaches the payload.');
        $this->assertTrue($config['material_config']['texture_tint'] ?? false, 'The tint authority reaches the payload.');
    }

    public function test_the_penthouse_architecture_is_venue_owned(): void
    {
        // Every key this pass uses is ALREADY venue-owned (s6) — a stale
        // gallery override can never reshape the architecture, and no
        // schema bump was needed (sibling venues untouched).
        foreach (['structure', 'glazing_wall', 'post_fx', 'environment', 'env_intensity',
            'artwork_light_base', 'artwork_light_pool_cap', 'hemisphere_intensity',
            'wall_height', 'ceiling_color', 'ambient_color', 'fog_near', 'fog_far'] as $key) {
            $this->assertContains($key, VenueConfigExporter::VENUE_OWNED_VISUAL_KEYS, "'{$key}' is venue-owned architecture/rig identity.");
        }
        foreach (['texture_tint', 'wall_color', 'floor_color', 'floor_tile_meters'] as $key) {
            $this->assertContains($key, VenueConfigExporter::VENUE_OWNED_MATERIAL_KEYS, "material '{$key}' is venue-owned.");
        }

        // End-to-end: a gallery carrying a saved override layer renders the
        // VENUE's architecture, not the override.
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue   = \App\Models\VenueTemplate::where('slug', 'luxury-penthouse')->firstOrFail();
        $owner   = \App\Models\User::factory()->create(['plan' => 'pro']);
        $gallery = \App\Models\Gallery::factory()->create([
            'user_id'           => $owner->id,
            'venue_template_id' => $venue->id,
            'visual_overrides'  => [
                'visual_config' => [
                    'wall_height'   => 3.0,
                    'structure'     => [],
                    'environment'   => 'studio',
                ],
            ],
        ]);

        $payload = app(VenueConfigExporter::class)->forGallery($gallery->refresh());

        $this->assertSame(5.2, $payload['visual_config']['wall_height'] ?? null, 'A curator-saved height override is stripped — the venue declaration wins.');
        $this->assertCount(40, $payload['visual_config']['structure'] ?? [], 'A curator-saved empty structure is stripped — the residence cannot be hollowed out.');
        $this->assertSame('none', $payload['visual_config']['environment'] ?? null, 'A curator-saved sky override is stripped — the sky is the city.');
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

    /** Recursively key-sort a JSON blob so identity comparisons ignore order. */
    private function canonicalJson(mixed $json): string
    {
        $data = is_string($json) ? (json_decode((string) $json, true) ?: []) : $json;

        $sort = function (&$array) use (&$sort): void {
            if (is_array($array)) {
                ksort($array);
                array_walk($array, $sort);
            }
        };
        $sort($data);

        return json_encode($data);
    }
}
