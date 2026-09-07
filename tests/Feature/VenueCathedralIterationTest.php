<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\User;
use App\Models\VenueTemplate;
use App\Services\VenueConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CRYSTAL CATHEDRAL — "The Luminous Arcade" venue-deepening iteration
 * (2026-09-07).
 *
 * Forensic audit → architectural redesign. The seeded venue WAS twelve thin
 * smooth-shaded glass tubes + four pastel-rainbow point lights in a blue
 * void: crystal as decoration, no cathedral. This suite pins the new
 * identity end to end:
 *
 *   • the seeder declares the arcade body and its full identity payload;
 *   • the copy ⇔ render honesty matrix holds (colonnade / float / reflect);
 *   • the guarded migration transforms an Iteration-6-era row into
 *     EXACTLY the seeder's fresh-install state (byte parity), is
 *     idempotent, reversible, and never clobbers admin edits;
 *   • the venue-owned key guard strips every new identity key from gallery
 *     visual_overrides (a stale curator layer cannot recompose the venue);
 *   • the public render path resolves lighting_preset / room_layout through
 *     the venue authority — the preview/public parity defect the audit
 *     found in GalleryViewController;
 *   • the JS body ships the architecture (dispatch + instancing + no
 *     rainbow palette + no Math.random) with zero slug knowledge.
 */
class VenueCathedralIterationTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    // The seeded identity
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_seeder_declares_the_luminous_arcade_identity(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $config = $this->visualConfig('crystal-cathedral');

        // The architecture body + its rollback chain posture.
        $this->assertTrue($config['void_arcade'] ?? false, '[crystal-cathedral] must declare the arcade body — crystal as architecture, not decoration.');
        $this->assertArrayNotHasKey('void_colonnade', $config, '[crystal-cathedral] ships the arcade — the IT2 glass-tube ring is rollback body #1, not the default.');
        $this->assertArrayNotHasKey('void_shards', $config, 'void_shards stays rollback body #2 only.');

        // Presentation + atmosphere (the §4–§21 audit mandates).
        $this->assertSame('float', $config['placement_mode'], 'The cathedral hang floats before the framed bays.');
        $this->assertSame('phenomena', $config['structure_pass'], 'The phenomena pass remains the interpreter gate + rollback switch.');
        $this->assertSame('transmission', $config['glass_material'], 'Primary crystal is tier-resolved true glass.');
        $this->assertSame('0xe6f0fb', $config['colonnade_tint'], 'Crystal is ice-white — the blue lives in the atmosphere (colour restraint).');
        $this->assertSame('studio', $config['environment'], 'The environment is DECLARED (the preset HDRI accident is closed).');
        $this->assertSame(0.22, $config['env_intensity'], 'Glass definition is dim and deliberate.');
        $this->assertTrue($config['void_depth_gradient'] ?? false, 'The zenith depth cue is declared (the look-up mandate).');
        $this->assertSame(0.22, $config['hemisphere_intensity'], 'A vertical sky-above gradient is declared.');

        // Artwork legibility (the void family standing glow).
        $this->assertSame(0.38, $config['artwork_light_base'], 'Floating artworks carry a standing glow beyond the proximity radius.');
        $this->assertSame(12, $config['artwork_light_pool_cap'], 'The desktop pool lights a typical 12-piece hang at once.');

        // Curation opt-in + restrained luminosity + the promised reflection.
        $this->assertSame(['depth_bands' => 2], $config['placement'], 'Past 12 works the hang composes in two depth rings — 40-piece shows stay inside the arcade.');
        $this->assertSame('planar', $config['floor_reflection'], 'The copy promises the reflection — the config must declare it.');
        $this->assertTrue($config['post_fx']['bloom'] ?? false, 'Bloom is declared ON but restrained.');
        $this->assertSame(0.82, $config['post_fx']['bloom_threshold'], 'The threshold is high — only the oculus and the clerestory seam halo.');
        $this->assertSame(0.85, $config['tone_mapping_exposure'], 'The rig is luminous (the 0.6 murk is gone).');
        $this->assertSame(1.15, $config['spot_intensity'], 'The artwork pool target ≈ 4.0 — art stays the hero.');

        // Material hierarchy: opaque art-bay stone + slate floor, tint-authoritative.
        $material = $this->materialConfig('crystal-cathedral');
        $this->assertSame('0x131a26', $material['wall_color'], 'The art-bay wall is opaque dark slate — controlled artwork backdrop.');
        $this->assertSame('0x1a2230', $material['floor_color'], 'The floor is polished dark slate.');
        $this->assertTrue($material['texture_tint'] ?? false, 'Declared colours are authoritative over the marble texture.');

        // The rainbow nightclub is gone from the row.
        $row = DB::table('venue_templates')->where('slug', 'crystal-cathedral')->first();
        $this->assertDoesNotMatchRegularExpression(
            '/0xffaaaa|0xaaffaa|0xaaaaff|0xffffaa|0xffaaff|0xaaffff/',
            (string) ($row->visual_config ?? '') . ($row->lighting_fixtures ?? ''),
            'The pastel-rainbow point-light palette must not survive in the row.'
        );
        $this->assertSame('2.0.0', $row->version, 'The architectural identity pass bumps the venue version.');
    }

    public function test_the_copy_promise_matches_the_delivered_architecture(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $desc = mb_strtolower((string) DB::table('venue_templates')->where('slug', 'crystal-cathedral')->value('description'));

        // Honesty matrix (same contract the phenomena suite enforces).
        $this->assertStringContainsStringIgnoringCase('colonnade', $desc, 'Cathedral copy must name the colonnade the pass delivers (verticality gate).');
        $this->assertMatchesRegularExpression('/float|drift|hover/', $desc, 'Copy must promise the float placement the config declares.');
        $this->assertStringContainsStringIgnoringCase('reflect', $desc, 'Copy must promise the planar reflection the config declares.');

        // Every noun the copy promises is a rendered element of the body.
        foreach (['piers', 'arches', 'stone', 'oculus', 'bays'] as $noun) {
            $this->assertStringContainsStringIgnoringCase($noun, $desc, "Copy promises the noun '{$noun}' — the arcade body must render it.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // The guarded migration
    // ─────────────────────────────────────────────────────────────────────

    private function cathedralMigration(): object
    {
        return require database_path('migrations/2026_09_07_000001_crystal_cathedral_architecture.php');
    }

    /** The Iteration-6-era row exactly as production holds it pre-migration. */
    private function seedIterationSixCathedralRow(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        DB::table('venue_templates')->where('slug', 'crystal-cathedral')->update([
            'description' => 'A colonnade of tall glass rises through a deep blue void, coloured light glowing between the pillars. Artworks float in that light.',
            'version'     => '1.0.0',
            'tags'        => json_encode(['glass', 'crystal', 'ethereal', 'refraction']),
            'visual_config' => json_encode([
                'wall_height'            => 12,
                'wall_depth'             => 0.3,
                'ceiling_type'           => 'none',
                'ceiling_height'         => 0,
                'background_color'       => '0x0a0a1a',
                'fog_color'              => '0x0a0a1a',
                'fog_near'               => 15,
                'fog_far'                => 50,
                'ambient_color'          => '0xddeeff',
                'ambient_intensity'      => 0.25,
                'spot_intensity'         => 0.5,
                'fill_intensity'         => 0.15,
                'tone_mapping_exposure'  => 0.6,
                'frame_override'         => 'silver',
                'placement_mode'         => 'float',
                'glass_material'         => 'transmission',
                'colonnade_tint'         => '0xdfeaff',
                'structure_pass'         => 'phenomena',
                'open_air'               => true,
                'layout_shape'           => 'circular',
                'void_colonnade'         => true,
            ]),
            'material_config' => json_encode([
                'wall_color'             => '0x202030',
                'wall_roughness'         => 0.2,
                'wall_metalness'         => 0.0,
                'wall_normal_strength'   => 0.3,
                'floor_color'            => null,
                'floor_roughness'        => 0.1,
                'floor_metalness'        => 0.4,
                'floor_normal_strength'  => 0.3,
            ]),
        ]);
    }

    public function test_the_migration_lands_the_exact_seeder_state(): void
    {
        // Production path: the IT6-era row is transformed by the migration;
        // a fresh install is seeded straight to the final state. Both roads
        // MUST end at the same identity (drift here means previews and
        // production diverge).
        $this->seedIterationSixCathedralRow();
        $this->cathedralMigration()->up();

        $migrated = DB::table('venue_templates')->where('slug', 'crystal-cathedral')->first();

        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $seeded = DB::table('venue_templates')->where('slug', 'crystal-cathedral')->first();

        // Canonical comparison: JSON object key ORDER legitimately differs
        // (the migration appends added keys after the IT6-era ones; the
        // seeder ships the composed literal). Identity = same keys, same
        // values, same types — order-insensitive by design.
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
        $this->assertSame($seeded->description, $migrated->description, 'Descriptions converge.');
        $this->assertSame($seeded->version, $migrated->version, 'Versions converge.');
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

    public function test_the_migration_is_guarded_idempotent_and_reversible(): void
    {
        $this->seedIterationSixCathedralRow();

        // An admin retune that must survive the migration (guarded swap).
        DB::table('venue_templates')->where('slug', 'crystal-cathedral')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('crystal-cathedral'), [
                'spot_intensity' => 0.9,   // differs from the seeded 0.5 — the admin owns it
                'ambient_intensity' => 0.5,
            ])),
            'description' => 'Our house of light.',
        ]);

        $migration = $this->cathedralMigration();
        $migration->up();

        $config = $this->visualConfig('crystal-cathedral');
        $this->assertSame(0.9, $config['spot_intensity'], 'Admin-tuned values are never overwritten (guarded swap).');
        $this->assertSame(0.5, $config['ambient_intensity'], 'Admin-tuned values are never overwritten.');
        $this->assertTrue($config['void_arcade'] ?? false, 'New identity keys still arrive around the admin edits (union).');
        $this->assertArrayNotHasKey('void_colonnade', $config, 'The superseded body flag is removed even under admin-edited rows (removal is guarded per key).');
        $this->assertSame(
            'Our house of light.',
            (string) DB::table('venue_templates')->where('slug', 'crystal-cathedral')->value('description'),
            'Admin-customized copy is never clobbered.'
        );

        // Idempotence: a second run changes nothing.
        $before = DB::table('venue_templates')->where('slug', 'crystal-cathedral')->first();
        $migration->up();
        $after = DB::table('venue_templates')->where('slug', 'crystal-cathedral')->first();
        $this->assertSame($before->visual_config, $after->visual_config, 'Re-running the migration rewrites nothing.');

        // Reversibility: down() restores every value it changed (only where
        // still equal), removes what it added, restores the superseded flag.
        $migration->down();
        $rolled = $this->visualConfig('crystal-cathedral');
        $this->assertSame(true, $rolled['void_colonnade'] ?? null, 'down() restores the IT6 body flag (the rollback chain).');
        $this->assertArrayNotHasKey('void_arcade', $rolled, 'down() removes the keys up() added.');
        $this->assertArrayNotHasKey('placement', $rolled, 'down() removes the curation opt-in it added.');
        $this->assertSame(0.9, $rolled['spot_intensity'], 'down() preserves the admin edit (only migration-owned values revert).');
        $this->assertSame('Our house of light.', (string) DB::table('venue_templates')->where('slug', 'crystal-cathedral')->value('description'), 'down() keeps admin copy.');

        // Reversibility on an untouched row: full restore.
        $this->seedIterationSixCathedralRow();
        $pristine = DB::table('venue_templates')->where('slug', 'crystal-cathedral')->first(['visual_config', 'description', 'version', 'tags']);
        $migration->up();
        $migration->down();
        $restored = DB::table('venue_templates')->where('slug', 'crystal-cathedral')->first(['visual_config', 'description', 'version', 'tags']);
        $this->assertSame($pristine->visual_config, $restored->visual_config, 'Untouched rows restore exactly (visual_config).');
        $this->assertSame($pristine->description, $restored->description, 'Untouched rows restore exactly (description).');
        $this->assertSame($pristine->version, $restored->version, 'Untouched rows restore exactly (version).');
        $this->assertSame($pristine->tags, $restored->tags, 'Untouched rows restore exactly (tags).');
    }

    public function test_a_missing_row_is_respected(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        DB::table('venue_templates')->where('slug', 'crystal-cathedral')->delete();

        $this->cathedralMigration()->up();
        $this->cathedralMigration()->down();

        $this->assertSame(0, DB::table('venue_templates')->where('slug', 'crystal-cathedral')->count(),
            'A venue the operator removed is never resurrected by the migration.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The venue-owned guard — overrides cannot recompose the identity
    // ─────────────────────────────────────────────────────────────────────

    public function test_gallery_overrides_cannot_recompose_the_architecture(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        foreach ([
            'void_arcade', 'void_colonnade', 'void_shards',
            'environment', 'env_intensity', 'floor_reflection',
            'artwork_light_base', 'placement', 'post_fx',
            'colonnade_tint', 'background_color',
        ] as $key) {
            $this->assertTrue(
                VenueConfigExporter::isVenueOwnedKey($key),
                "[{$key}] is venue-owned — a stale curator override must not recompose the cathedral."
            );
        }

        // End-to-end: a gallery carrying an ancient override layer that
        // predates the guard renders the VENUE's identity, not the override.
        $venue   = VenueTemplate::where('slug', 'crystal-cathedral')->firstOrFail();
        $owner   = User::factory()->create(['plan' => 'pro']);
        $gallery = Gallery::factory()->create([
            'user_id'           => $owner->id,
            'venue_template_id' => $venue->id,
            'visual_overrides'  => [
                'visual_config' => [
                    'background_color' => '0x660066',
                    'void_colonnade'   => true,
                    'void_arcade'      => false,
                    'floor_reflection' => null,
                ],
            ],
        ]);

        $payload = app(VenueConfigExporter::class)->forGallery($gallery->refresh());
        $vc = $payload['visual_config'];

        $this->assertSame('0x070b14', $vc['background_color'], 'The void stays the venue\'s deep blue-black.');
        $this->assertTrue($vc['void_arcade'] ?? false, 'The arcade body always renders for the cathedral.');
        $this->assertSame('planar', $vc['floor_reflection'], 'The declared reflection cannot be stripped by a stale layer.');
        $this->assertArrayNotHasKey('void_colonnade', $vc, 'The superseded body cannot be re-armed by a stale layer.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Preview ⇄ public parity — the two resolved keys ship identically
    // ─────────────────────────────────────────────────────────────────────

    public function test_public_path_resolves_preset_and_layout_through_the_venue_authority(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue   = VenueTemplate::where('slug', 'crystal-cathedral')->firstOrFail();
        $owner   = User::factory()->create(['plan' => 'pro']);
        $gallery = Gallery::factory()->create([
            'user_id'           => $owner->id,
            'venue_template_id' => $venue->id,
            // A stale exhibition layer from an earlier venue choice.
            'lighting_preset'   => 'dramatic',
            'room_layout'       => 'corridor',
        ]);

        $exporter = app(VenueConfigExporter::class);
        $gallery  = $gallery->refresh();

        $this->assertSame(
            'bright',
            $exporter->presetForGallery($gallery),
            'A venue-managed gallery renders the venue\'s default preset publicly and in the editor alike.'
        );
        $this->assertSame(
            'rotunda',
            $exporter->layoutForGallery($gallery),
            'An unsupported layout clamps to the venue default on every render path.'
        );

        // Wiring: the public controller actually calls the authority (the
        // runtime-mirror greps the arrival suite established).
        $controller = file_get_contents(app_path('Http/Controllers/GalleryViewController.php'));
        $this->assertStringContainsString('presetForGallery($gallery)', $controller, 'The public payload resolves the preset through the venue authority.');
        $this->assertStringContainsString('layoutForGallery($gallery)', $controller, 'The public payload resolves the layout through the venue authority.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The JS body — architecture ships, no slugs, no randomness, no rainbow
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_arcade_body_ships_with_the_dispatch_and_hygiene_rules(): void
    {
        $decorator = file_get_contents(resource_path('js/gallery/VenueDecorator.js'));

        $this->assertStringContainsString('vc.void_arcade === true', $decorator, 'The void dispatcher selects the arcade body by config.');
        $this->assertStringContainsString('function addCrystalCathedralArcade', $decorator, 'The arcade body exists.');
        $this->assertStringContainsString('addCrystalCathedralColonnade.call', $decorator, 'The IT2 colonnade stays reachable as rollback body #1.');
        $this->assertStringContainsString('addCrystalCathedralLegacyShards.call', $decorator, 'The shard ring stays reachable as rollback body #2.');
        $this->assertStringContainsString('flatShading: true', $decorator, 'Crystal reads through facets (flat shading on the primary material).');
        $this->assertStringContainsString('InstancedMesh', $decorator, 'The composition is instanced (draw-call budget).');

        $arcade = explode('function addCrystalCathedralArcade', $decorator)[1]
            ?? '';
        $arcade = explode('// CRYSTAL CATHEDRAL — composed vertical light architecture', $arcade)[0];

        $this->assertDoesNotMatchRegularExpression(
            '/0xffaaaa|0xaaffaa|0xaaaaff|0xffffaa|0xffaaff|0xaaffff/',
            $arcade,
            'The arcade body carries no pastel-rainbow palette (colour restraint).'
        );
        $this->assertDoesNotMatchRegularExpression('/Math\.random/', $arcade, 'The arcade body is deterministic (rhythm is arithmetic, not noise).');
        $this->assertStringContainsString('resolveReflectionMode', $arcade, 'The floor reflection is tier-resolved, never emergent.');
        $this->assertStringContainsString('addPlanarReflection', $arcade, 'The planar reflector is the shared shipped effect (Mirror Lake precedent).');
    }

    public function test_the_missing_environments_constant_is_restored(): void
    {
        // The s4 environment authority referenced VenueTemplate::ENVIRONMENTS
        // from the super-admin request WITHOUT defining it — every structured
        // venue save fataled at validation. The constant must exist and stay
        // in lockstep with the runtime map (config.js `environments`).
        $this->assertSame(
            ['studio', 'rural_evening', 'night', 'none'],
            VenueTemplate::ENVIRONMENTS,
            'ENVIRONMENTS is defined and matches the runtime environment vocabulary.'
        );

        $config = file_get_contents(resource_path('js/gallery/config.js'));
        foreach (VenueTemplate::ENVIRONMENTS as $name) {
            $this->assertStringContainsString("'{$name}'", $config, "Runtime config.js knows the '{$name}' environment (lockstep contract).");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Harness parity — the visual harness renders the seeded identity
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_harness_carries_the_seeded_cathedral_body(): void
    {
        $harness = file_get_contents(base_path('scripts/harness/harness.html'));

        $this->assertStringContainsString("'crystal-cathedral': {", $harness, 'The harness can render the venue (QA gate B).');
        $this->assertStringContainsString('void_arcade: true', $harness, 'The harness body declares the arcade.');
        $this->assertStringContainsString("'crystal-cathedral-legacy': {", $harness, 'The rollback chain stays screenshot-able.');

        $seeder = file_get_contents(database_path('seeders/VenueTemplateSeeder.php'));
        $this->assertStringContainsString("'depth_bands' => 2", $seeder, 'Seeder parity: depth bands.');
        foreach (['0x070b14', '0xe6f0fb', "'studio'", '0.38', '0.82'] as $marker) {
            $this->assertStringContainsString($marker, $harness, "Harness parity marker {$marker} present.");
            $this->assertStringContainsString($marker, $seeder, "Seeder parity marker {$marker} present.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function visualConfig(string $slug): array
    {
        return json_decode((string) DB::table('venue_templates')->where('slug', $slug)->value('visual_config'), true) ?: [];
    }

    private function materialConfig(string $slug): array
    {
        return json_decode((string) DB::table('venue_templates')->where('slug', $slug)->value('material_config'), true) ?: [];
    }
}
