<?php

declare(strict_types=1);

/**
 * Nebula Drift "THE DEEP FIELD" identity tests (2026-09-08 audit pass).
 *
 * Pins the v2.0.0 contract so the venue can never silently regress to the
 * v1.0.0 state ("a dark room with purple fog and stars"):
 *
 *   - Declared identity: the seeder baseline AND the guarded migration
 *     outcome are THE SAME Deep Field row (byte-parity of content, key-order
 *     insensitive) — production galleries migrate, fresh installs seed, and
 *     both roads must end at one identity.
 *   - The double purple centre light is gone: one declared cold key light.
 *   - Artwork colour honesty: the ambient is neutral moon-slate, never the
 *     purple that tinted every lit canvas.
 *   - The promise matrix: the copy names what renders (deep field, band,
 *     drift, ring, pools) and promises no reflection (there is none).
 *   - The palette is venue-owned (s6): a stale gallery override can never
 *     recolour the sky.
 *   - The migration is guarded, idempotent, and reversible; admin edits
 *     survive up() and down().
 *
 * Run: php artisan test --filter=VenueNebulaIterationTest
 */

namespace Tests\Feature;

use App\Services\VenueConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VenueNebulaIterationTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    // The seeder baseline — the fresh-install identity
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_seeded_row_is_the_deep_field(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $config = $this->visualConfig('nebula-drift');

        // The Deep Field body — not the v1.0.0 starfield, not any foreign body.
        $this->assertTrue($config['void_deepfield'] ?? false, 'The Deep Field body must be declared.');
        $this->assertArrayNotHasKey('void_starfield', $config, 'The v1.0.0 starfield body is the rollback body only — a fresh install must not declare it.');
        $this->assertSame('float', $config['placement_mode'], 'The hang floats ("drift", not "stand").');
        $this->assertSame('phenomena', $config['structure_pass'], 'The phenomena pass remains the interpreter gate + rollback switch.');

        // The sky is procedural: no HDRI download, no silenced accident.
        $this->assertSame('none', $config['environment'], 'environment none — the sky is procedural, the night.hdr download disappears (N9).');
        $this->assertSame(0, $config['env_intensity'], 'env_intensity 0 — the environment is silenced at the source.');

        // Artwork colour honesty (N2): the v1.0.0 purple ambient tinted
        // every lit canvas.
        $this->assertSame('0x7a86b8', $config['ambient_color'], 'Ambient is neutral moon-slate — artwork canvases stay visually honest (§12).');
        $this->assertSame(0.55, $config['ambient_intensity'], 'The base wash lifts every canvas — at 40 works only 12 carry a pool light, the rest must still read.');
        $this->assertSame(0.35, $config['hemisphere_intensity'], 'Vertical fill keeps unlit far canvases legible.');
        $this->assertSame(0.85, $config['tone_mapping_exposure'], 'The rig is luminous (the 0.6 murk is gone).');
        $this->assertSame(1.2, $config['spot_intensity'], 'The artwork pool target ≈ 4.2 — art stays the hero.');

        // Artwork legibility (N6, the void-family standing glow).
        $this->assertSame(0.5, $config['artwork_light_base'], 'Floating artworks carry a standing glow beyond the proximity radius.');
        $this->assertSame(12, $config['artwork_light_pool_cap'], 'The desktop pool lights a typical 12-piece hang at once.');

        // Spatial composition keys (N5/N7/N10).
        $this->assertTrue($config['void_depth_gradient'] ?? false, 'The shared zenith depth cue is declared.');
        $this->assertTrue($config['floor_edge_fade'] ?? false, 'The floor disc dissolves into the void — no hard geometric seam.');
        $this->assertSame(['depth_bands' => 2, 'light_pools' => true], $config['placement'], 'Two-band curation + light pools under every floating work.');

        // Restrained post-fx (N8) — the legacy grey veil never ships again.
        $this->assertSame(0.35, $config['post_fx']['bloom_strength'] ?? null, 'Bloom is declared ON but restrained (0.35).');
        $this->assertSame(0.8, $config['post_fx']['bloom_threshold'] ?? null, 'The threshold is high — the band core may kiss bloom, nothing else.');
        $this->assertSame('black', $config['post_fx']['vignette_blend'] ?? null, 'The vignette blends toward TRUE BLACK (the cathedral deploy-review precedent).');

        // The colour hierarchy (§11) is DECLARED, venue-owned.
        $this->assertSame(
            ['dominant' => '0x5a4ae0', 'secondary' => '0x2e6ac8', 'accent' => '0xd85a9e'],
            $config['nebula'] ?? null,
            'The celestial palette is declared: dominant indigo-violet → secondary cool blue → ONE rare rose accent.'
        );

        // Floor (N7): declared colours are authoritative over the marble.
        $material = $this->materialConfig('nebula-drift');
        $this->assertSame('0x0b0724', $material['floor_color'], 'The floor is deep indigo stone.');
        $this->assertTrue($material['texture_tint'] ?? false, 'Declared colours reach the marble texture (the v1.0.0 row shipped stock cream marble).');
        $this->assertSame(0.15, $material['floor_metalness'], 'Metal without an environment renders dead — the floor is stone, not a fake mirror.');

        // The double light is gone (N1): one declared cold key.
        $row = DB::table('venue_templates')->where('slug', 'nebula-drift')->first();
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        $this->assertCount(1, $fixtures, 'The rig is ONE declared light — the v1.0.0 fixture + body light were stacked doubles.');
        $this->assertSame('nebula-key', $fixtures[0]['id'] ?? null, 'The key light is the declared cold directional.');
        $this->assertDoesNotMatchRegularExpression(
            '/0x8844ff/i',
            (string) ($row->visual_config ?? '').($row->lighting_fixtures ?? ''),
            'The purple rig must not survive anywhere in the row.'
        );
        $this->assertSame('2.0.0', $row->version, 'The identity pass bumps the venue version.');
    }

    public function test_the_copy_promise_matches_the_delivered_deep_field(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $desc = mb_strtolower((string) DB::table('venue_templates')->where('slug', 'nebula-drift')->value('description'));

        // Honesty matrix (the same contract the phenomena suite enforces).
        $this->assertMatchesRegularExpression('/float|drift|hover/', $desc, 'Copy must promise the float placement the config declares.');
        $this->assertStringNotContainsStringIgnoringCase('reflect', $desc, 'Copy must NOT promise a reflection — the venue declares none.');

        // Every noun the copy promises is a rendered element of the body.
        foreach (['nebula', 'band', 'drift', 'ring', 'pools'] as $noun) {
            $this->assertStringContainsStringIgnoringCase($noun, $desc, "Copy promises the noun '{$noun}' — the Deep Field body must render it.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // The guarded migration — production roads converge on one identity
    // ─────────────────────────────────────────────────────────────────────

    private function nebulaMigration(): object
    {
        return require database_path('migrations/2026_09_08_000002_nebula_drift_deepfield.php');
    }

    /** The v1.0.0 row exactly as production holds it pre-migration. */
    private function seedLegacyNebulaRow(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        DB::table('venue_templates')->where('slug', 'nebula-drift')->update([
            'description' => 'Artworks drift through a cosmic cloud — distant stars and a purple nebula with quiet depth between them. For digital art and otherworldly exhibitions.',
            'version'     => '1.0.0',
            'visual_config' => json_encode([
                'wall_height'            => 15,
                'wall_depth'             => 0.3,
                'ceiling_type'           => 'none',
                'ceiling_height'         => 0,
                'background_color'       => '0x050015',
                'fog_color'              => '0x050015',
                'fog_near'               => 10,
                'fog_far'                => 40,
                'ambient_color'          => '0x8844ff',
                'ambient_intensity'      => 0.2,
                'spot_intensity'         => 0.55,
                'fill_intensity'         => 0.15,
                'tone_mapping_exposure'  => 0.6,
                'frame_override'         => null,
                'placement_mode'         => 'float',
                'env_intensity'          => 0.05,
                'structure_pass'         => 'phenomena',
                'open_air'               => true,
                'layout_shape'           => 'circular',
                'void_starfield'         => true,
            ]),
            'material_config' => json_encode([
                'wall_color'            => '0x080015',
                'wall_roughness'        => 0.4,
                'wall_metalness'        => 0.2,
                'wall_normal_strength'  => 0.3,
                'floor_color'           => '0x100525',
                'floor_roughness'       => 0.3,
                'floor_metalness'       => 0.5,
                'floor_normal_strength' => 0.3,
            ]),
            'lighting_fixtures' => json_encode([
                [
                    'id'          => 'nebula-center',
                    'type'        => 'point',
                    'position'    => [0, 5, 0],
                    'color'       => '0x8844ff',
                    'intensity'   => 0.5,
                    'cast_shadow' => false,
                    'distance'    => 30,
                    'decay'       => 1.5,
                ],
            ]),
        ]);
    }

    public function test_the_migration_lands_the_exact_seeder_state(): void
    {
        // Production path: the v1.0.0 row is transformed by the migration;
        // a fresh install is seeded straight to the final state. Both roads
        // MUST end at the same identity (drift here means previews and
        // production diverge).
        $this->seedLegacyNebulaRow();
        $this->nebulaMigration()->up();

        $migrated = DB::table('venue_templates')->where('slug', 'nebula-drift')->first();

        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $seeded = DB::table('venue_templates')->where('slug', 'nebula-drift')->first();

        // Canonical comparison: JSON object key ORDER legitimately differs
        // (the migration appends added keys after the v1.0.0 ones; the
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
        $this->assertSame(
            $this->canonicalJson($seeded->lighting_fixtures),
            $this->canonicalJson($migrated->lighting_fixtures),
            'Migration final state must equal the seeder baseline (lighting_fixtures).'
        );
        $this->assertSame($seeded->description, $migrated->description, 'Descriptions converge.');
        $this->assertSame($seeded->version, $migrated->version, 'Versions converge.');
    }

    public function test_the_migration_is_guarded_idempotent_and_reversible(): void
    {
        $this->seedLegacyNebulaRow();

        // An admin retune that must survive the migration (guarded swap).
        DB::table('venue_templates')->where('slug', 'nebula-drift')->update([
            'visual_config' => json_encode(array_merge($this->visualConfig('nebula-drift'), [
                'spot_intensity'    => 0.9,   // differs from the seeded 0.55 — the admin owns it
                'ambient_intensity' => 0.5,
            ])),
            'description' => 'Our house nebula.',
        ]);

        $migration = $this->nebulaMigration();
        $migration->up();

        $config = $this->visualConfig('nebula-drift');
        $this->assertSame(0.9, $config['spot_intensity'], 'Admin-tuned values are never overwritten (guarded swap).');
        $this->assertSame(0.5, $config['ambient_intensity'], 'Admin-tuned values are never overwritten.');
        $this->assertTrue($config['void_deepfield'] ?? false, 'New identity keys still arrive around the admin edits (union).');
        $this->assertArrayNotHasKey('void_starfield', $config, 'The superseded body flag is removed even under admin-edited rows (removal is guarded per key).');
        $this->assertSame(
            'Our house nebula.',
            (string) DB::table('venue_templates')->where('slug', 'nebula-drift')->value('description'),
            'Admin-customized copy is never clobbered.'
        );

        // Idempotence: a second run changes nothing.
        $before = DB::table('venue_templates')->where('slug', 'nebula-drift')->first();
        $migration->up();
        $after = DB::table('venue_templates')->where('slug', 'nebula-drift')->first();
        $this->assertSame($before->visual_config, $after->visual_config, 'Re-running the migration rewrites nothing.');

        // Reversibility: down() restores every value it changed (only where
        // still equal), removes what it added, restores the superseded flag.
        $migration->down();
        $rolled = $this->visualConfig('nebula-drift');
        $this->assertSame(true, $rolled['void_starfield'] ?? null, 'down() restores the v1.0.0 body flag (the rollback chain).');
        $this->assertArrayNotHasKey('void_deepfield', $rolled, 'down() removes the keys up() added.');
        $this->assertArrayNotHasKey('nebula', $rolled, 'down() removes the palette it added.');
        $this->assertArrayNotHasKey('placement', $rolled, 'down() removes the curation opt-in it added.');
        $this->assertSame(0.9, $rolled['spot_intensity'], 'down() preserves the admin edit (only migration-owned values revert).');
        $this->assertSame('Our house nebula.', (string) DB::table('venue_templates')->where('slug', 'nebula-drift')->value('description'), 'down() keeps admin copy.');

        // Reversibility on an untouched row: full restore.
        $this->seedLegacyNebulaRow();
        $pristine = DB::table('venue_templates')->where('slug', 'nebula-drift')->first(['visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        $migration->up();
        $migration->down();
        $restored = DB::table('venue_templates')->where('slug', 'nebula-drift')->first(['visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        $this->assertSame($pristine->visual_config, $restored->visual_config, 'Untouched rows restore exactly (visual_config).');
        $this->assertSame($pristine->material_config, $restored->material_config, 'Untouched rows restore exactly (material_config).');
        $this->assertSame($pristine->lighting_fixtures, $restored->lighting_fixtures, 'Untouched rows restore exactly (lighting_fixtures).');
        $this->assertSame($pristine->description, $restored->description, 'Untouched rows restore exactly (description).');
        $this->assertSame($pristine->version, $restored->version, 'Untouched rows restore exactly (version).');
    }

    // ─────────────────────────────────────────────────────────────────────
    // The client payload — the Deep Field must reach preview AND public
    // ─────────────────────────────────────────────────────────────────────

    public function test_the_payload_carries_the_deep_field_to_the_client(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue  = \App\Models\VenueTemplate::where('slug', 'nebula-drift')->firstOrFail();
        $config = app(VenueConfigExporter::class)->forVenuePreview($venue);

        $visual = $config['visual_config'] ?? [];
        $this->assertTrue($visual['void_deepfield'] ?? false, 'The Deep Field body flag reaches the viewer payload.');
        $this->assertSame('none', $visual['environment'] ?? null, 'The declared environment reaches the payload.');
        $this->assertSame(0.5, $visual['artwork_light_base'] ?? null, 'The standing glow reaches the payload.');
        $this->assertSame('black', $visual['post_fx']['vignette_blend'] ?? null, 'The declared post-fx reaches the payload.');
        $this->assertSame(['depth_bands' => 2, 'light_pools' => true], $visual['placement'] ?? null, 'The curation object reaches the payload.');
        $this->assertSame('0x5a4ae0', $visual['nebula']['dominant'] ?? null, 'The celestial palette reaches the payload.');
    }

    public function test_the_nebula_palette_is_venue_owned(): void
    {
        // s6: the palette is colour IDENTITY — a stale gallery visual
        // override can never recolour the sky.
        $this->assertContains('nebula', VenueConfigExporter::VENUE_OWNED_VISUAL_KEYS,
            '[nebula] is venue-owned colour identity — a stale gallery override cannot recolour the sky.');
        $this->assertSame('s6', VenueConfigExporter::SCHEMA,
            'The s6 bump re-keys every cached payload on deploy.');

        // End-to-end: a gallery carrying a saved override layer renders the
        // VENUE's palette, not the override.
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue   = \App\Models\VenueTemplate::where('slug', 'nebula-drift')->firstOrFail();
        $owner   = \App\Models\User::factory()->create(['plan' => 'pro']);
        $gallery = \App\Models\Gallery::factory()->create([
            'user_id'           => $owner->id,
            'venue_template_id' => $venue->id,
            'visual_overrides'  => [
                'visual_config' => [
                    'nebula' => ['dominant' => '0xff00ff', 'secondary' => '0x00ff00', 'accent' => '0x0000ff'],
                ],
            ],
        ]);

        $payload = app(VenueConfigExporter::class)->forGallery($gallery->refresh());

        $this->assertSame(
            '0x5a4ae0',
            $payload['visual_config']['nebula']['dominant'] ?? null,
            'A curator-saved palette override is stripped — the venue declaration wins.'
        );
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
