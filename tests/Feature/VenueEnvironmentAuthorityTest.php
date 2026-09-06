<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\VenueTemplate;
use App\Services\VenueConfigExporter;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENVIRONMENT AUTHORITY (s4) — the last unguarded identity channel as CI.
 *
 * The incident: the environment (HDRI) was resolved at runtime from the
 * GALLERY's lighting_preset column. A stale gallery-era preset installed a
 * bright studio/dusk sky inside the Dark Museum and — combined with floor/
 * frame envMapIntensity computed from the PRESET instead of the venue's
 * declared env_intensity — reflected as a bright cloudy sheen on the
 * polished dark stone (the deployed "sky/cloud on the floor" report).
 *
 * Pins:
 *   1. The seeded venue rows DECLARE their environment (the DB is the sole
 *      source of venue identity — the runtime reads the declaration, not
 *      the seeder).
 *   2. `environment` is venue-owned at every layer: the exporter strips it
 *      from gallery overrides, re-asserts the venue value, and ships the
 *      owned-key lists inside the payload so the runtime patch guard
 *      mirrors this file (single definition, no drift).
 *   3. The preset + layout resolve through the venue for venue-managed
 *      galleries (preview/public parity; no stale-column divergence).
 *   4. The guarded migration stamps environment on existing rows only when
 *      absent (admin declarations win), is idempotent, and down() removes
 *      only the values it added.
 *   5. The editor form contract: `visual_config.environment` validates
 *      against VenueTemplate::ENVIRONMENTS.
 *
 * Portable patterns per the IT2–IT6 suites: sqlite-safe JSON
 * read-modify-write, migrations invoked directly.
 */
class VenueEnvironmentAuthorityTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // 1. The seeded baseline DECLARES its skies
    // ─────────────────────────────────────────────────────────────────────

    public function test_seeded_venues_declare_their_environment(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $expected = [
            'white-cube'      => 'studio',  // neutral bright museum reflections
            'infinite-void'   => 'none',    // a void has no sky, ever
            'industrial-loft' => 'night',   // dusk-lit interior, no cloud deck
            'dark-museum'     => 'night',   // THE incident venue — a night institution
        ];

        foreach ($expected as $slug => $environment) {
            $vc = $this->visualConfig($slug);

            $this->assertArrayHasKey(
                'environment', $vc,
                "[{$slug}] must DECLARE visual_config.environment — an undeclared sky is an identity left to chance."
            );
            $this->assertSame(
                $environment, $vc['environment'],
                "[{$slug}] declares environment '{$environment}' (the runtime resolves the venue's sky from this key)."
            );
        }
    }

    public function test_dark_museum_pairs_night_environment_with_whispered_env_intensity(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $vc = $this->visualConfig('dark-museum');

        $this->assertSame('night', $vc['environment'], 'The museum declares the night sky (no daytime cloud deck).');
        $this->assertSame(0.14, (float) ($vc['env_intensity'] ?? -1), 'The environment stays at the declared whisper strength.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. environment is venue-owned at every layer
    // ─────────────────────────────────────────────────────────────────────

    public function test_environment_is_in_the_venue_owned_visual_set(): void
    {
        $this->assertTrue(
            VenueConfigExporter::isVenueOwnedKey('environment'),
            'environment must be venue-owned — a gallery override can never pick the venue\'s sky.'
        );

        $this->assertContains(
            'environment', VenueConfigExporter::VENUE_OWNED_VISUAL_KEYS,
            'The owned set enumerates environment explicitly (no prefix-rule reliance).'
        );
    }

    public function test_exporter_strips_environment_from_gallery_overrides_and_reasserts_the_venue_value(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue = VenueTemplate::where('slug', 'dark-museum')->firstOrFail();

        // A gallery carrying a STALE bright-era override layer (the incident
        // shape: a legacy visual_overrides row trying to repaint the sky).
        $gallery = Gallery::factory()
            ->forVenue($venue)
            ->create([
                'lighting_preset'  => 'bright', // the stale column value
                'visual_overrides' => [
                    'visual_config' => [
                        'environment' => 'studio',       // the hostile override
                        'env_intensity' => 1.8,          // hostile strength too
                        'background_color' => '0xffffff',
                        'fog_color' => '0xeeeeee',
                    ],
                ],
            ]);

        $payload = app(VenueConfigExporter::class)->forGallery($gallery->fresh());
        $vc      = $payload['visual_config'];

        $this->assertSame('night', $vc['environment'], 'The venue\'s declared night sky wins the final payload — the studio override is stripped.');
        $this->assertSame(0.14, (float) $vc['env_intensity'], 'The venue\'s declared env_intensity wins — no override-amplified reflections.');
        $this->assertSame('0x050505', $vc['background_color'], 'Belt-and-braces re-assertion covers every owned key.');
    }

    public function test_exporter_ships_the_owned_key_lists_inside_the_payload(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue   = VenueTemplate::where('slug', 'white-cube')->firstOrFail();
        $gallery = Gallery::factory()->forVenue($venue)->create();

        $payload = app(VenueConfigExporter::class)->forGallery($gallery->fresh());

        $this->assertContains('environment', $payload['venue_owned_visual'] ?? [],
            'The payload ships venue_owned_visual so the runtime patch guard mirrors this file (single definition — the two layers cannot drift).');
        $this->assertContains('post_fx', $payload['venue_owned_visual'] ?? [],
            'post_fx is venue-owned presentation (s3) — the runtime drops the whole patch bucket.');
        $this->assertContains('texture_tint', $payload['venue_owned_material'] ?? [],
            'The payload ships venue_owned_material (s3 material identity).');

        $this->assertSame(
            array_values(VenueConfigExporter::VENUE_OWNED_VISUAL_KEYS),
            $payload['venue_owned_visual'],
            'Shipped list === the PHP authority set, byte for byte.'
        );
        $this->assertSame(
            array_values(VenueConfigExporter::VENUE_OWNED_MATERIAL_KEYS),
            $payload['venue_owned_material']
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. Preset + layout resolve through the venue (preview/public parity)
    // ─────────────────────────────────────────────────────────────────────

    public function test_preset_for_gallery_resolves_through_the_venue_not_the_stale_column(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $venue   = VenueTemplate::where('slug', 'dark-museum')->firstOrFail();
        $gallery = Gallery::factory()->forVenue($venue)->create([
            'lighting_preset' => 'bright', // a stale gallery-era value
        ]);

        $this->assertSame(
            'moody',
            app(VenueConfigExporter::class)->presetForGallery($gallery->fresh()),
            'A venue-managed gallery renders the VENUE\'s default preset — the stale column cannot diverge preview from public.'
        );
    }

    public function test_preset_for_gallery_keeps_legacy_venue_less_galleries(): void
    {
        $gallery = Gallery::factory()->create(['lighting_preset' => 'dramatic']);

        $this->assertSame(
            'dramatic',
            app(VenueConfigExporter::class)->presetForGallery($gallery->fresh()),
            'Venue-less (legacy) galleries keep their own column value.'
        );
    }

    public function test_layout_for_gallery_rejects_layouts_the_venue_does_not_support(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // dark-museum supports ['square','rotunda'] — a corridor value from
        // a previous venue must fall back to the venue's default layout.
        $venue   = VenueTemplate::where('slug', 'dark-museum')->firstOrFail();
        $gallery = Gallery::factory()->forVenue($venue)->create(['room_layout' => 'corridor']);

        $this->assertSame(
            'square',
            app(VenueConfigExporter::class)->layoutForGallery($gallery->fresh()),
            'An unsupported layout falls back to the venue\'s default — a corridor shell can never be forced into the museum.'
        );

        $ok = Gallery::factory()->forVenue($venue)->create(['room_layout' => 'rotunda']);
        $this->assertSame('rotunda', app(VenueConfigExporter::class)->layoutForGallery($ok->fresh()),
            'A supported layout passes through untouched.');
    }

    public function test_layout_for_gallery_keeps_legacy_venue_less_galleries(): void
    {
        $gallery = Gallery::factory()->create(['room_layout' => 'corridor']);

        $this->assertSame(
            'corridor',
            app(VenueConfigExporter::class)->layoutForGallery($gallery->fresh()),
            'Venue-less (legacy) galleries keep their own column value.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. The guarded migration: add-when-absent, admin wins, reversible
    // ─────────────────────────────────────────────────────────────────────

    private function migration(): object
    {
        return require database_path('migrations/2026_09_07_000001_environment_authority.php');
    }

    public function test_migration_stamps_environment_on_undeclared_rows(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Hand-rewind the declarations (simulate pre-s4 rows).
        foreach (['white-cube', 'infinite-void', 'industrial-loft', 'dark-museum'] as $slug) {
            $vc  = $this->visualConfig($slug);
            unset($vc['environment']);
            DB::table('venue_templates')->where('slug', $slug)->update([
                'visual_config' => json_encode($vc),
            ]);
        }

        $this->migration()->up();

        foreach (['white-cube' => 'studio', 'infinite-void' => 'none', 'industrial-loft' => 'night', 'dark-museum' => 'night'] as $slug => $environment) {
            $this->assertSame($environment, $this->visualConfig($slug)['environment'] ?? null,
                "[{$slug}] gains its declared environment on deploy (no manual re-seed).");
        }
    }

    public function test_migration_never_touches_an_admin_declared_value(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // A super-admin declared a bespoke value through the editor/advanced JSON.
        $vc  = $this->visualConfig('dark-museum');
        $vc['environment'] = 'rural_evening';
        DB::table('venue_templates')->where('slug', 'dark-museum')->update([
            'visual_config' => json_encode($vc),
        ]);

        $this->migration()->up();

        $this->assertSame('rural_evening', $this->visualConfig('dark-museum')['environment'] ?? null,
            'The migration adds only when ABSENT — the admin\'s declared value wins.');
    }

    public function test_migration_is_idempotent(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $before = DB::table('venue_templates')->orderBy('slug')->get(['slug', 'visual_config'])->pluck('visual_config', 'slug')->all();

        $this->migration()->up();
        $this->migration()->up();

        $after = DB::table('venue_templates')->orderBy('slug')->get(['slug', 'visual_config'])->pluck('visual_config', 'slug')->all();

        foreach ($before as $slug => $json) {
            $this->assertSame($json, $after[$slug], "[{$slug}] re-running up() over declared rows changes nothing.");
        }
    }

    public function test_migration_down_removes_only_its_own_values(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $this->migration()->down();

        foreach (['white-cube', 'infinite-void', 'industrial-loft', 'dark-museum'] as $slug) {
            $this->assertArrayNotHasKey('environment', $this->visualConfig($slug),
                "[{$slug}] down() removes the key while it still equals the added value.");
        }

        // Re-add, then prove a FOREIGN value survives down().
        $this->migration()->up();
        $vc  = $this->visualConfig('dark-museum');
        $vc['environment'] = 'studio';
        DB::table('venue_templates')->where('slug', 'dark-museum')->update(['visual_config' => json_encode($vc)]);
        $this->migration()->down();

        $this->assertSame('studio', $this->visualConfig('dark-museum')['environment'] ?? null,
            'down() never removes an admin\'s different declaration.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. The editor contract
    // ─────────────────────────────────────────────────────────────────────

    public function test_venue_template_request_validates_the_environment_vocabulary(): void
    {
        $rules = (new \App\Http\Requests\SuperAdmin\VenueTemplateRequest())->rules();

        $base = [
            'name' => 'Env Probe', 'slug' => 'env-probe', 'category' => 'modern',
            'plan_required' => 'free', 'description' => 'probe',
        ];

        foreach (['studio', 'rural_evening', 'night', 'none'] as $declared) {
            $v = \Illuminate\Support\Facades\Validator::make($base + [
                'visual_config' => ['environment' => $declared],
            ], $rules);
            $this->assertFalse($v->fails(), "environment '{$declared}' passes: ".json_encode($v->errors()->all()));
        }

        $v = \Illuminate\Support\Facades\Validator::make($base + [
            'visual_config' => ['environment' => 'sunny_day'],
        ], $rules);
        $this->assertTrue($v->fails(), 'An unknown environment name is rejected at the form — a typo must not silently pick a sky.');

        $this->assertSame(
            ['studio', 'rural_evening', 'night', 'none'],
            VenueTemplate::ENVIRONMENTS,
            'The model vocabulary is the single source the request + editor select mirror.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────

    private function venueRow(string $slug): ?object
    {
        return DB::table('venue_templates')->where('slug', $slug)->first();
    }

    private function jsonCol(string $slug, string $col): array
    {
        $row = $this->venueRow($slug);
        if (!$row) {
            return [];
        }

        return json_decode((string) $row->$col, true) ?: [];
    }

    private function visualConfig(string $slug): array
    {
        return $this->jsonCol($slug, 'visual_config');
    }
}
