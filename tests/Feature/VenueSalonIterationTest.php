<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE SALON v2.1.0 — "turn + threshold" (post-deploy pass, as CI).
 *
 * The v2.0.0 body (superseding the Iteration 8 stub) authored the venue —
 * and its field report found the one defect the authored payload carried:
 * ten side-wall fit descriptors shipped WITHOUT turn:'in', so the side
 * fields/baseboards/rails/cornices/coves rendered PERPENDICULAR to their
 * walls — phantom mid-room slabs the visitor could walk through
 * ("is this a wall or a curtain?"). v2.1.0 turns the side wall architecture
 * onto its walls and widens the threshold into a classical double-leaf
 * doorcase (portes à deux vantaux) worthy of the enfilade axis.
 *
 * Pins:
 *   1. The catalog is TWELVE seeded venues; `the-salon` is Pro, published
 *      and active (unchanged from Iteration 8).
 *   2. The seeder entry and the v2.1 migration payload are byte-equal —
 *      one authored identity, two delivery paths. (The unchanged v2 blocks
 *      — post_fx, material_config, description — stay pinned to the v2
 *      migration that authored them.)
 *   3. The v2.1 migration is a guarded, per-element heal: side elements
 *      gain turn:'in' only in their exact v2 form; the door group splices
 *      only when ALL SEVEN v2 door descriptors match exactly; keep_clear
 *      rewrites only when exact; a super-admin's retuned element survives
 *      untouched; idempotent; down() reverses under mirrored guards.
 *   4. The placement block IS the salon's hang: intimate density +
 *      orientation pairing + the focal hero wall + the domestic room cap
 *      (wall_length_cap 12.6) + the two-line salon hang (salon_rows 2) +
 *      the door keep-clear + per-row size caps.
 *   5. The structure uses ONLY the descriptor vocabulary (closed primitive
 *      set, preset or explicit materials, anchors, fit:'wall') — zero JS
 *      shipped (§10.2, DoD #7). Every element renders on the LOWEST tier.
 *   6. The register map: intimacy via the salon; grandeur remains the open
 *      register. Pricing copy claims twelve. The gallery JS stays slug-free
 *      for ALL TWELVE slugs.
 *   7. Preview payload parity: the placement + structure + post_fx blocks
 *      reach the client through the shared exporter (one bundle, one
 *      payload — parity by construction).
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

    private const MIGRATION = '2026_09_10_000001_salon_collector_identity.php';
    private const MIGRATION_V21 = '2026_09_11_000001_salon_v2_1_turn_and_door.php';

    /**
     * Payload accessor for the migrations' (historically private) shape
     * methods — reflection keeps the historical files untouched while the
     * pins read the same payload the DB-side guards compare against.
     */
    private function migrationPayload(string $file, string $method): array
    {
        $migration = require database_path('migrations/' . $file);
        $ref = new \ReflectionMethod($migration, $method);
        $ref->setAccessible(true);
        return $ref->invoke($migration);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. The twelfth venue
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_salon_is_the_twelfth_seeded_venue(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $rows = DB::table('venue_templates')->get();
        $this->assertCount(12, $rows, 'The catalog is twelve venues.');

        $salon = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        $this->assertNotNull($salon, '[the-salon] must be seeded.');

        $this->assertSame('The Salon', $salon->name);
        $this->assertSame('pro', $salon->plan_required, 'The Salon is a Pro venue.');
        $this->assertSame('classic', $salon->category);
        $this->assertSame(12, (int) $salon->sort_order);
        $this->assertEquals(5, (int) $salon->capacity_min);
        $this->assertEquals(30, (int) $salon->capacity_max);
        $this->assertTrue((bool) $salon->is_active);
        $this->assertFalse((bool) $salon->is_draft);
        $this->assertNotNull($salon->published_at);
        $this->assertSame('2.1.0', $salon->version, 'The v2.1.0 turn+threshold body is the fresh-install baseline.');

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

    public function test_seeder_and_v21_migration_payloads_are_equal(): void
    {
        $salon = collect(\Database\Seeders\VenueTemplateSeeder::templates())
            ->firstWhere('slug', 'the-salon');
        $this->assertNotNull($salon, 'Seeder must carry the-salon entry #12.');

        $this->assertSame($this->migrationPayload(self::MIGRATION_V21, 'v21Structure'), $salon['visual_config']['structure'],
            'Seeder structure and the v2.1 migration payload MUST be byte-equal — one identity, two delivery paths.'
        );
        $this->assertSame($this->migrationPayload(self::MIGRATION_V21, 'v21KeepClear'), $salon['visual_config']['placement']['keep_clear'],
            'Seeder keep_clear and the v2.1 migration payload MUST be byte-equal.'
        );
        // the blocks v2.1 does not touch stay pinned to the migration that
        // authored them
        $this->assertSame($this->migrationPayload(self::MIGRATION, 'v2PostFx'), $salon['visual_config']['post_fx'],
            'Seeder post_fx and the v2 migration payload MUST be byte-equal.'
        );
        $this->assertSame($this->migrationPayload(self::MIGRATION, 'v2Material'), $salon['material_config'],
            'Seeder material_config and the v2 migration payload MUST be byte-equal.'
        );
        $this->assertSame($this->migrationPayload(self::MIGRATION, 'v2Description'), $salon['description'],
            'Seeder description and the v2 migration payload MUST be byte-equal.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. The v2 migration — guarded, idempotent, admin-respecting
    // ─────────────────────────────────────────────────────────────────────

    private function runSalonIdentityMigration(): void
    {
        Artisan::call('migrate', ['--path' => 'database/migrations/' . self::MIGRATION, '--force' => true]);
    }

    private function rollbackSalonIdentityMigration(): void
    {
        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/' . self::MIGRATION, '--force' => true]);
    }

    /** The exact v1.0.0 production row (either pre-v2 variant). */
    private function v1ProductionRow(bool $driftedSeederVariant = false): void
    {
        $bench = ['id' => 'bench-top', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0, 0.42, 1.4]]];
        if ($driftedSeederVariant) {
            $bench['turn'] = 'in';
        }
        $bench += ['size' => [1.5, 0.09, 0.42], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'salon-bench', 'tier_floor' => 'low'];

        DB::table('venue_templates')->where('slug', 'the-salon')->update([
            'version'       => '1.0.0',
            'description'   => 'A small, warm room in the domestic tradition: works hung close together at conversational distance, a wooden picture rail and a bench, under soft warm light. Made for studies, prints, photography and portrait formats.',
            'default_settings' => json_encode([
                'wall_texture'    => 'white',
                'floor_material'  => 'wood',
                'lighting_preset'  => 'bright',
                'frame_style'     => 'minimal',
                'room_layout'     => 'square',
            ]),
            'visual_config' => json_encode([
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
                'structure_pass'         => 'rooms',
                'placement'              => [
                    'density'          => 'intimate',
                    'pair_orientation' => true,
                ],
                'structure'              => [
                    ['id' => 'rail-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                    ['id' => 'rail-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                    ['id' => 'rail-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                    ['id' => 'rail-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                    $bench,
                    ['id' => 'bench-leg-l', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [-0.65, 0.19, 1.4]], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'salon-bench', 'tier_floor' => 'low'],
                    ['id' => 'bench-leg-r', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0.65, 0.19, 1.4]], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'salon-bench', 'tier_floor' => 'low'],
                    ['id' => 'rug', 'primitive' => 'plane', 'at' => ['from' => 'center', 'offset' => [0, 0.012, 1.4]], 'rot' => [-1.5707963, 0, 0], 'size' => [2.6, 1.8], 'material' => 'fabric_warm', 'tier_floor' => 'low'],
                ],
            ]),
            'material_config' => json_encode([
                'wall_color'            => '0xe6dcc6',
                'wall_roughness'        => 0.92,
                'wall_metalness'        => 0.0,
                'wall_normal_strength'  => 0.35,
                'floor_color'           => '0x6b5236',
                'floor_roughness'       => 0.65,
                'floor_metalness'       => 0.0,
                'floor_normal_strength' => 0.55,
            ]),
        ]);
    }

    public function test_the_migration_upgrades_a_v1_production_row(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v1ProductionRow();
        $this->runSalonIdentityMigration();

        $venue = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        $config = json_decode((string) $venue->visual_config, true);

        $this->assertSame('2.0.0', $venue->version);
        $this->assertSame(3.8, $config['wall_height']);
        $this->assertSame(1.0, $config['tone_mapping_exposure'], 'readable warm light, not murk');
        $this->assertSame('classic', $config['frame_override']);
        $this->assertSame('studio', $config['environment'] ?? null);
        $this->assertSame(0.3, $config['artwork_light_base'] ?? null);
        $this->assertSame(12, $config['artwork_light_pool_cap'] ?? null);
        $this->assertFalse($config['post_fx']['bloom'] ?? true);
        // the salon hang block, whole
        $this->assertSame(12.6, $config['placement']['wall_length_cap'] ?? null);
        $this->assertSame(2, $config['placement']['salon_rows'] ?? null);
        $this->assertSame('front', $config['placement']['focal_wall'] ?? null);
        $this->assertSame('back', $config['placement']['keep_clear']['wall'] ?? null);
        $this->assertCount(2, $config['placement']['row_caps'] ?? []);
        // the authored architecture replaced the three-prop stub
        $this->assertCount(45, $config['structure'], '45 descriptors: trim + field + rail + cornice + cove per wall, the doorcase, the furniture, the rose.');
        $ids = array_column($config['structure'], 'id');
        $this->assertContains('door-leaf', $ids, 'the threshold exists');
        $this->assertContains('rose-disc', $ids, 'the ceiling rose exists');
        // rails live ABOVE the hang now (the v1 frame-clipping defect is dead)
        $rail = collect($config['structure'])->firstWhere('id', 'rail-front');
        $this->assertSame(3.53, $rail['at']['offset'][1], 'the picture rail stands above the two-row hang');

        $material = json_decode((string) $venue->material_config, true);
        $this->assertSame('0xd9cbaf', $material['wall_color']);
        $this->assertTrue($material['texture_tint'] ?? false, 'the declared colours reach textured builds');
        $this->assertSame(2.4, $material['floor_tile_meters'] ?? null);

        $settings = json_decode((string) $venue->default_settings, true);
        $this->assertSame('plaster', $settings['wall_texture']);
        $this->assertSame('classic', $settings['frame_style']);

        $this->assertStringContainsString('doorcase', (string) $venue->description);
        $this->assertStringContainsString('salon-style', (string) $venue->description);
    }

    public function test_the_migration_heals_the_drifted_seeder_variant_too(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v1ProductionRow(true); // bench-top 'turn' => 'in' (the v1 drift)
        $this->runSalonIdentityMigration();

        $config = json_decode((string) DB::table('venue_templates')->where('slug', 'the-salon')->value('visual_config'), true);
        $this->assertSame('2.0.0', DB::table('venue_templates')->where('slug', 'the-salon')->value('version'));
        $this->assertCount(45, $config['structure'], 'the drifted v1 structure variant upgrades identically');
    }

    public function test_the_migration_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v1ProductionRow();
        $this->runSalonIdentityMigration();

        $afterFirst = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        $this->runSalonIdentityMigration();
        $afterSecond = DB::table('venue_templates')->where('slug', 'the-salon')->first();

        $this->assertSame($afterFirst->visual_config, $afterSecond->visual_config, 're-running must rewrite nothing.');
        $this->assertSame($afterFirst->material_config, $afterSecond->material_config);
        $this->assertSame($afterFirst->description, $afterSecond->description);
    }

    public function test_the_migration_respects_admin_edits(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v1ProductionRow();

        // A super-admin retuned the mood before the migration ran.
        DB::table('venue_templates')->where('slug', 'the-salon')->update([
            'visual_config' => json_encode([
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
                'ambient_intensity'      => 0.42,   // ← the admin's value
                'spot_intensity'         => 0.5,
                'fill_intensity'         => 0.16,
                'tone_mapping_exposure'  => 0.6,
                'frame_override'         => null,
                'structure_pass'         => 'rooms',
                'placement'              => [
                    'density'          => 'intimate',
                    'pair_orientation' => true,
                ],
                'structure'              => [
                    ['id' => 'rail-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                ],
            ]),
        ]);

        $this->runSalonIdentityMigration();

        $config = json_decode((string) DB::table('venue_templates')->where('slug', 'the-salon')->value('visual_config'), true);
        $this->assertSame(0.42, $config['ambient_intensity'], 'the admin retune survives the migration');
        $this->assertCount(1, $config['structure'], 'the admin structure survives the migration');
        // unambiguous v1 scalars still heal
        $this->assertSame(1.0, $config['tone_mapping_exposure']);
    }

    public function test_the_migration_is_reversible(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v1ProductionRow();
        $this->runSalonIdentityMigration();
        $this->rollbackSalonIdentityMigration();

        $venue = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        $config = json_decode((string) $venue->visual_config, true);

        $this->assertSame('1.0.0', $venue->version);
        $this->assertSame(3.0, $config['wall_height']);
        $this->assertSame(0.6, $config['tone_mapping_exposure']);
        $this->assertNull($config['frame_override']);
        $this->assertArrayNotHasKey('environment', $config, 'the added identity keys come back off');
        $this->assertArrayNotHasKey('post_fx', $config);
        $this->assertSame(['density' => 'intimate', 'pair_orientation' => true], $config['placement']);
        $this->assertCount(8, $config['structure'], 'the v1 stub structure is restored');

        $material = json_decode((string) $venue->material_config, true);
        $this->assertSame('0xe6dcc6', $material['wall_color']);
        $this->assertArrayNotHasKey('texture_tint', $material);
        $this->assertArrayNotHasKey('floor_tile_meters', $material);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3b. The v2.1 turn+threshold migration — per-element guarded heal
    // ─────────────────────────────────────────────────────────────────────

    /** Invoke the v2.1 migration's up()/down() directly (true re-run semantics). */
    private function runTurnMigration(string $direction = 'up'): void
    {
        $migration = require database_path('migrations/' . self::MIGRATION_V21);
        $migration->$direction();
    }

    /** Overwrite the seeded row with the exact v2.0.0 production payload. */
    private function v2ProductionRow(): void
    {
        DB::table('venue_templates')->where('slug', 'the-salon')->update([
            'version'       => '2.0.0',
            'visual_config' => json_encode([
                'wall_height'            => 3.8,
                'wall_depth'             => 0.15,
                'ceiling_type'           => 'flat',
                'ceiling_color'          => '0xd8cbb0',
                'ceiling_height'         => 3.8,
                'background_color'       => '0x171310',
                'fog_color'              => '0x171310',
                'fog_near'               => 22,
                'fog_far'                => 70,
                'ambient_color'          => '0xffe9cf',
                'ambient_intensity'      => 0.5,
                'spot_intensity'         => 1.5,
                'fill_intensity'         => 0.8,
                'tone_mapping_exposure'  => 1.0,
                'frame_override'         => 'classic',
                'structure_pass'         => 'rooms',
                'placement'              => $this->migrationPayload(self::MIGRATION, 'v2Placement'),
                'structure'              => $this->migrationPayload(self::MIGRATION, 'v2Structure'),
            ]),
        ]);
    }

    public function test_the_turn_migration_heals_a_v2_production_row(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v2ProductionRow();
        $this->runTurnMigration('up');

        $venue = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        $this->assertSame('2.1.0', $venue->version, 'the heal bumps the version');
        $config = json_decode((string) $venue->visual_config, true);

        // side elements carry the tangent yaw now
        $byId = collect($config['structure'])->keyBy('id');
        $this->assertCount(49, $config['structure'], '7 door descriptors became 11');
        foreach (['base-left', 'base-right', 'field-left', 'field-right', 'rail-left', 'rail-right', 'cornice-left', 'cornice-right', 'cove-left', 'cove-right'] as $side) {
            $this->assertSame('in', $byId[$side]['turn'] ?? null, "[{$side}] gains turn:'in'");
        }
        // the double-leaf threshold stands
        foreach (['door-leaf-l', 'door-leaf-r', 'door-panel-ll', 'door-panel-lu', 'door-panel-rl', 'door-panel-ru'] as $door) {
            $this->assertArrayHasKey($door, $byId, "the double-leaf doorcase exists ([{$door}])");
        }
        $this->assertArrayNotHasKey('door-leaf', $byId, 'the single leaf is retired');
        $leafL = $byId['door-leaf-l'];
        $leafR = $byId['door-leaf-r'];
        $this->assertSame(0.74, $leafL['size'][0], 'each leaf is 0.74 m — 1.48 m clear');
        $this->assertSame(2.52, $leafL['size'][1], 'leaves stand 2.52 m — taller than the v2 door');
        $this->assertEqualsWithDelta(0.37, abs($leafR['at']['offset'][0]), 1e-9, 'leaves meet at the centre stile');
        $this->assertSame(1.66, $byId['door-head']['size'][0], 'the entablature spans the assembly');
        // the hang respects the wider assembly
        $this->assertSame(1.9, $config['placement']['keep_clear']['width']);
        $this->assertSame(1.2, $config['placement']['keep_clear']['max_width']);
        // untouched blocks stay byte-identical
        $this->assertSame($this->migrationPayload(self::MIGRATION, 'v2Material'), json_decode((string) $venue->material_config, true));
    }

    public function test_the_turn_migration_respects_admin_edits(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v2ProductionRow();

        // an admin retunes the left cove before the migration runs
        $config = json_decode((string) DB::table('venue_templates')->where('slug', 'the-salon')->value('visual_config'), true);
        foreach ($config['structure'] as $i => $el) {
            if ($el['id'] === 'cove-left') {
                $config['structure'][$i]['material']['emissiveIntensity'] = 1.4; // ← the admin's retune
            }
        }
        DB::table('venue_templates')->where('slug', 'the-salon')->update([
            'visual_config' => json_encode($config),
        ]);

        $this->runTurnMigration('up');

        $after = json_decode((string) DB::table('venue_templates')->where('slug', 'the-salon')->value('visual_config'), true);
        $byId = collect($after['structure'])->keyBy('id');
        $this->assertSame(1.4, $byId['cove-left']['material']['emissiveIntensity'],
            'the admin retune survives — the heal never touches a non-v2 element');
        $this->assertArrayNotHasKey('turn', $byId['cove-left'],
            'the edited element is not force-turned (admin edits win)');
        $this->assertSame('in', $byId['field-left']['turn'] ?? null,
            'the untouched side elements still heal');
        $this->assertSame('2.1.0', DB::table('venue_templates')->where('slug', 'the-salon')->value('version'));
    }

    public function test_the_turn_migration_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v2ProductionRow();
        $this->runTurnMigration('up');

        $afterFirst = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        $this->runTurnMigration('up');
        $afterSecond = DB::table('venue_templates')->where('slug', 'the-salon')->first();

        $this->assertSame($afterFirst->visual_config, $afterSecond->visual_config, 're-running must rewrite nothing.');
        $this->assertSame($afterFirst->version, $afterSecond->version);
    }

    public function test_the_turn_migration_is_reversible(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->v2ProductionRow();
        $this->runTurnMigration('up');
        $this->runTurnMigration('down');

        $venue = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        $config = json_decode((string) $venue->visual_config, true);

        $this->assertSame('2.0.0', $venue->version);
        $this->assertSame($this->migrationPayload(self::MIGRATION, 'v2Structure'), $config['structure'],
            'down() restores the exact v2 structure — element order included.');
        $this->assertSame($this->migrationPayload(self::MIGRATION, 'v2Placement'), $config['placement'],
            'down() restores the exact v2 placement.');
    }

    public function test_the_turn_migration_noops_on_a_fresh_v21_install(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $before = DB::table('venue_templates')->where('slug', 'the-salon')->first();

        $this->runTurnMigration('up');

        $after = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        $this->assertSame($before->visual_config, $after->visual_config,
            'the seeder already ships v2.1 — the migration must write nothing.');
        $this->assertSame('2.1.0', $after->version);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. The declared identity — curation contract + descriptor vocabulary
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
        $this->assertIsArray($placement, '[the-salon] declares a placement block.');

        $this->assertSame('intimate', $placement['density'] ?? null,
            'the salon-close ~2.8 m rhythm is the venue\'s declared hang.');
        $this->assertTrue($placement['pair_orientation'] ?? false,
            'orientation-aware pairing composes the wall runs.');
        $this->assertSame('front', $placement['focal_wall'] ?? null,
            'the hero wall faces the arrival (§6.5 hierarchy, carefully).');
        $this->assertSame(12.6, $placement['wall_length_cap'] ?? null,
            'the room stays domestic at any count (the v1 hall defect is dead).');
        $this->assertSame(2, $placement['salon_rows'] ?? null,
            'the two-line salon hang engages when the cap bites.');
        $this->assertSame('back', $placement['keep_clear']['wall'] ?? null,
            'the doorcase keeps its wall — the architectural threshold.');
        $this->assertSame(1.9, $placement['keep_clear']['width'] ?? null,
            'the keep-clear spans the double-leaf assembly (1.66 m) plus its breathing room.');
        $this->assertSame(1.2, $placement['keep_clear']['max_width'] ?? null,
            'works beside the threshold take the near-door canvas cap — frames clear the assembly edge-wise.');
        $this->assertNotEmpty($placement['row_caps'] ?? [],
            'per-row size caps: large works at eye, smaller above.');

        $this->assertSame('rooms', $config['structure_pass'] ?? null,
            '[the-salon] renders through the Room-family structure interpreter.');
        $this->assertSame(['square'], json_decode((string) DB::table('venue_templates')->where('slug', 'the-salon')->value('supported_layouts'), true),
            'The salon is a domestic square room — one layout, honestly supported.');
    }

    public function test_salon_structure_uses_only_the_descriptor_vocabulary(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $structure = $this->salonVisualConfig()['structure'] ?? null;

        $this->assertIsArray($structure);
        $this->assertCount(49, $structure,
            '20 wall trim pieces + doorcase (11: double leaf ×2, panels ×4, jambs ×2, head, overdoor, knob) + bench (3) + chairs (8) + table (3) + rug + rose (3) = 49.');

        foreach ($structure as $el) {
            $this->assertContains($el['primitive'] ?? null, self::CLOSED_PRIMITIVES,
                "[{$el['id']}] primitive must be in the closed set.");
            $this->assertSame('low', $el['tier_floor'] ?? 'low',
                "[{$el['id']}] renders on the LOWEST tier — identity survives degradation.");

            $at = $el['at'] ?? null;
            $this->assertIsArray($at, "[{$el['id']}] must place itself.");
            if (is_array($at) && isset($at['from'])) {
                $this->assertContains($at['from'], ['center', 'wall_front', 'wall_back', 'wall_left', 'wall_right'],
                    "[{$el['id']}] anchor must be a known room anchor.");
            }
        }

        $ids = array_column($structure, 'id');
        foreach (['base-front', 'field-front', 'rail-front', 'cornice-front', 'cove-front'] as $wallPiece) {
            $this->assertContains($wallPiece, $ids, "the per-wall architecture exists ([{$wallPiece}]).");
        }
        foreach (['door-leaf-l', 'door-leaf-r', 'door-panel-ll', 'door-panel-lu', 'door-panel-rl', 'door-panel-ru', 'door-jamb-l', 'door-jamb-r', 'door-head', 'door-overdoor', 'door-knob'] as $door) {
            $this->assertContains($door, $ids, "the threshold exists ([{$door}]).");
        }
        $this->assertNotContains('door-leaf', $ids, 'the single-leaf v2 threshold is gone — the enfilade door is a double leaf.');
        foreach (['chair-a-seat', 'chair-b-seat', 'table-top', 'rug', 'bench-top'] as $furniture) {
            $this->assertContains($furniture, $ids, "the social layer exists ([{$furniture}]).");
        }
        foreach (['rose-disc', 'rose-ring', 'rose-glow'] as $rose) {
            $this->assertContains($rose, $ids, "the ceiling rose exists ([{$rose}]).");
        }

        // collision discipline: walkable things never collide, solid things do
        $colliding = array_column(array_filter($structure, fn ($e) => !empty($e['collide'])), 'id');
        $this->assertEqualsCanonicalizing(
            ['bench-top', 'chair-a-seat', 'chair-b-seat', 'table-top'],
            $colliding,
            'exactly the four solid furniture pieces register collision.'
        );
        $rug = collect($structure)->firstWhere('id', 'rug');
        $this->assertEmpty($rug['collide'] ?? null, 'the rug is walkable.');

        // side-wall fit discipline (the v2.0.0 phantom-slab defect class):
        // every fit:'wall' descriptor anchored on a SIDE wall must declare
        // the tangent yaw — the vocabulary stretches local X, and a side
        // wall's tangent is world Z.
        foreach ($structure as $el) {
            $from = $el['at']['from'] ?? null;
            $isSideWall = in_array($from, ['wall_left', 'wall_right'], true);
            if ($isSideWall && isset($el['fit'])) {
                $yaw = (float) ($el['rot'][1] ?? 0.0);
                $declaredYaw = in_array($el['turn'] ?? null, ['in', 'out'], true) || $yaw !== 0.0;
                $this->assertTrue($declaredYaw,
                    "[{$el['id']}] anchors fit:'{$el['fit']}' on a side wall — it must declare turn:'in' (the phantom-slab guard).");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Plan gating — the ladder stays true
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
        $this->assertStringContainsString('salon', (string) $response->viewData('sampleNote'),
            'The curated curtain note names the salon rationale.');
    }

    public function test_preview_payload_carries_the_collector_identity(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue = \App\Models\VenueTemplate::where('slug', 'the-salon')->firstOrFail();
        $exporter = new \App\Services\VenueConfigExporter();
        $payload = $exporter->forVenuePreview($venue);

        $vc = $payload['visual_config'] ?? [];
        $this->assertSame('rooms', $vc['structure_pass'] ?? null);
        $this->assertSame(3.8, $vc['wall_height'] ?? null);
        $this->assertSame(1.0, $vc['tone_mapping_exposure'] ?? null);
        $this->assertSame('front', $vc['placement']['focal_wall'] ?? null,
            'the salon hang reaches the client whole (preview/public parity by construction).');
        $this->assertNotEmpty($vc['structure'] ?? [], 'the descriptor payload ships whole.');
        $this->assertFalse($vc['post_fx']['bloom'] ?? true, 'the restraint declaration ships whole.');
        $this->assertTrue($payload['material_config']['texture_tint'] ?? false,
            'texture authority ships whole.');
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
        $this->assertSame('uncovered', $grandeur['status'] ?? null, 'Grandeur remains the open register.');
    }

    public function test_pricing_copy_claims_twelve_venues(): void
    {
        $pricing = file_get_contents(resource_path('views/pages/pricing.blade.php'));
        $this->assertStringContainsString('12 distinct 3D spaces', $pricing, 'Pricing hero claims twelve.');
        $this->assertStringContainsString('8 venues', $pricing, 'Pro card claims eight venues.');
        $this->assertStringContainsString('The Salon', $pricing, 'The Pro venue list names the salon.');
        $this->assertStringContainsString('All 12 venues', $pricing, 'Studio claims all twelve.');
        $this->assertStringNotContainsString('All 11 venues', $pricing);
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
