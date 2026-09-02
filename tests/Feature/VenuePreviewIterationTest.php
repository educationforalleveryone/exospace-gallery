<?php

declare(strict_types=1);

/**
 * Iteration 1 "THE REHEARSAL" regression tests (3D venue roadmap, P1.1).
 *
 * Pins the walkable-venue-preview contract so the chooser test (every venue
 * walkable pre-commit) can never silently regress:
 *
 *   - Preview route renders for ALL 11 seeded venues, guest-accessible
 *     (DO NOT DO #10: previews are the funnel — never signup-gated).
 *   - Draft/inactive/unknown venues 404 (Iteration 0's selection-integrity
 *     contract extends to previews).
 *   - NOINDEX: X-Robots-Tag header + meta robots on the preview page.
 *   - Rate-limited: 21st request within a minute from one IP → 429.
 *   - Feature flag off → 404 route + no "Walk through" affordances.
 *   - SAMPLE-DATA ISOLATION: a preview can never contain real gallery or
 *     artwork data; artwork ids are namespaced sample-*.
 *   - forVenuePreview() filters decorations by the VENUE's plan_required
 *     (preview honesty: render the venue at the tier that unlocks it).
 *   - Sample exhibitions cover every seeded venue with 6–8 art-type-matched
 *     works, all resolvable in the shared collection.
 *
 * Run: php artisan test --filter=VenuePreviewIterationTest
 */

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use App\Models\VenueTemplate;
use App\Services\SampleExhibitionService;
use App\Services\VenueConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VenuePreviewIterationTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDED_SLUGS = [
        'white-cube', 'infinite-void', 'industrial-loft', 'dark-museum',
        'zen-gallery', 'crystal-cathedral', 'nebula-drift', 'luxury-penthouse',
        'cyber-gallery', 'sculpture-garden', 'mirror-lake',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // The chooser test, as CI — every seeded venue walkable pre-commit
    // ─────────────────────────────────────────────────────────────────────

    public function test_preview_renders_for_every_seeded_venue_as_guest(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // NOTE: no actingAs() — the preview must work signed out.
        foreach (self::SEEDED_SLUGS as $slug) {
            $response = $this->get(route('venues.preview', $slug));

            $response->assertOk();
            $response->assertViewHas('galleryData', function ($data) use ($slug) {
                return ($data['venue_slug'] ?? null) === $slug
                    && ($data['isPreview'] ?? false) === true
                    && count($data['images']) >= 6
                    && count($data['images']) <= 8
                    // Determinism: stable string id → PRNG seed `{slug}:preview`
                    && ($data['id'] ?? null) === 'preview'
                    // The venue's own config ships with the payload
                    && ($data['venueConfig']['slug'] ?? null) === $slug;
            });
        }
    }

    public function test_preview_payload_contract_matches_gallery_viewer_shape(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $response = $this->get(route('venues.preview', 'mirror-lake'));
        $response->assertOk();

        $response->assertViewHas('galleryData', function ($data) {
            // Fields the 3D runtime REQUIRES (RoomBuilder/AssetLoader/
            // GalleryScene read these unconditionally or with null-fallbacks
            // that must at least be present for the legacy-switch path).
            foreach (['id', 'title', 'venue_slug', 'venueConfig', 'images',
                      'wall_texture', 'floor_material', 'frame_style',
                      'lighting_preset', 'room_layout', 'imageCount'] as $key) {
                if (!array_key_exists($key, $data)) {
                    return false;
                }
            }

            // Each sample artwork must carry the texture-tier map the
            // loader's pickTextureUrl() expects (plus url fallback).
            foreach ($data['images'] as $img) {
                if (!isset($img['id'], $img['url'], $img['textures']['large'], $img['aspectRatio'])) {
                    return false;
                }
                if (!str_starts_with((string) $img['id'], 'sample-')) {
                    return false;
                }
            }

            return $data['imageCount'] === count($data['images']);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Selection integrity — drafts/inactive/unknown are not walkable
    // ─────────────────────────────────────────────────────────────────────

    public function test_draft_and_inactive_venues_are_not_walkable(): void
    {
        VenueTemplate::factory()->create([
            'slug' => 'preview-draft', 'is_active' => true, 'is_draft' => true,
        ]);
        VenueTemplate::factory()->create([
            'slug' => 'preview-inactive', 'is_active' => false, 'is_draft' => false,
        ]);

        $this->get(route('venues.preview', 'preview-draft'))->assertNotFound();
        $this->get(route('venues.preview', 'preview-inactive'))->assertNotFound();
    }

    public function test_unknown_venue_404s(): void
    {
        $this->get(route('venues.preview', 'not-a-real-venue'))->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────
    // NOINDEX — previews must never compete with /venues/{slug} in search
    // ─────────────────────────────────────────────────────────────────────

    public function test_preview_is_noindex(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $response = $this->get(route('venues.preview', 'white-cube'));
        $response->assertOk();

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rate limiting — throttle:20,1 at the route layer
    // ─────────────────────────────────────────────────────────────────────

    public function test_preview_is_rate_limited(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        for ($i = 0; $i < 20; $i++) {
            $this->get(route('venues.preview', 'white-cube'))->assertOk();
        }

        $this->get(route('venues.preview', 'white-cube'))
            ->assertStatus(429, 'The 21st preview request in one minute must be throttled.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Feature flag — the rollback path ("route stays harmless")
    // ─────────────────────────────────────────────────────────────────────

    public function test_flag_off_404s_the_route_and_hides_walkthrough_affordances(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        config(['feature_flags.flags.venue_previews' => false]);

        $this->get(route('venues.preview', 'white-cube'))->assertNotFound();

        // The venue page must not render the "Walk through" CTA either.
        $show = $this->get(route('venues.show', 'white-cube'));
        $show->assertOk();
        $show->assertDontSee('Walk through this venue');

        // …and the flag back on restores everything (the default is on).
        config(['feature_flags.flags.venue_previews' => true]);
        $this->get(route('venues.preview', 'white-cube'))->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Sample-data isolation — no user data can reach a preview payload
    // ─────────────────────────────────────────────────────────────────────

    public function test_preview_never_contains_real_gallery_or_artwork_data(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $owner = User::factory()->create(['plan' => 'studio']);
        $venue = VenueTemplate::where('slug', 'white-cube')->firstOrFail();

        $privateGallery = Gallery::factory()->create([
            'user_id'          => $owner->id,
            'venue_template_id' => $venue->id,
            'title'            => 'SECRETS-HOLDER-7Q2',
            'description'      => 'A private description that must never leak.',
            'slug'             => 'secrets-holder-7q2',
        ]);
        GalleryImage::factory()->create([
            'gallery_id'    => $privateGallery->id,
            'title'         => 'PRIVATE-ARTWORK-9X4',
            'original_name' => 'private-artwork-9x4.jpg',
        ]);

        $response = $this->get(route('venues.preview', 'white-cube'));
        $response->assertOk();

        $html = $response->getContent();
        foreach (['SECRETS-HOLDER-7Q2', 'secrets-holder-7q2', 'PRIVATE-ARTWORK-9X4',
                  'A private description that must never leak.'] as $secret) {
            $this->assertStringNotContainsString($secret, $html,
                "Preview for white-cube leaked user data: {$secret}");
        }

        $response->assertViewHas('galleryData', function ($data) {
            foreach ($data['images'] as $img) {
                // Namespaced ids only — a numeric id would mean a real
                // images-table row reached the preview.
                if (!str_starts_with((string) $img['id'], 'sample-')) {
                    return false;
                }
                if (!empty($img['artist']) || !empty($img['price']) || !empty($img['forSale'])) {
                    return false; // samples are never attributed or for sale
                }
            }
            return true;
        });
    }

    public function test_preview_never_sets_analytics_track_url(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $html = $this->get(route('venues.preview', 'zen-gallery'))->getContent();

        $this->assertStringNotContainsString('EXOSPACE_TRACK_URL', $html,
            'Preview pages must never enable the analytics pipeline.');
        $this->assertStringNotContainsString('gallery/track', $html,
            'Preview pages must never reference the tracking endpoint.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // forVenuePreview() — the preview honesty rule (tier-correct rendering)
    // ─────────────────────────────────────────────────────────────────────

    public function test_for_venue_preview_filters_decorations_to_the_venue_plan(): void
    {
        $exporter = app(VenueConfigExporter::class);

        $venue = VenueTemplate::factory()->make([
            'slug'         => 'deco-test',
            'plan_required' => 'free',
            'decorations'  => [
                ['type' => 'bench',  'plan_required' => 'free'],
                ['type' => 'neon',   'plan_required' => 'pro'],
                ['type' => 'skyline','plan_required' => 'studio'],
            ],
        ]);

        $config = $exporter->forVenuePreview($venue);

        $types = array_column($config['decorations'], 'type');
        $this->assertSame(['bench'], $types,
            'A free-tier venue preview must render free decorations only — '
            .'showing studio props would promise what the tier cannot deliver.');

        // The standalone admin export intentionally does NOT filter —
        // document the difference so it cannot be "fixed" by accident.
        $raw = $exporter->forVenue($venue);
        $this->assertCount(3, $raw['decorations']);

        // Pro venue preview: free + pro visible, studio still hidden.
        $venue->plan_required = 'pro';
        $proConfig = $exporter->forVenuePreview($venue);
        $this->assertSame(['bench', 'neon'], array_column($proConfig['decorations'], 'type'));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Curation coverage — the chooser test needs a real hang everywhere
    // ─────────────────────────────────────────────────────────────────────

    public function test_sample_exhibitions_cover_every_seeded_venue_with_6_to_8_works(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $config   = config('sample_exhibitions');
        $artworks = $config['collection']['artworks'];
        $service  = app(SampleExhibitionService::class);

        foreach (self::SEEDED_SLUGS as $slug) {
            $this->assertArrayHasKey($slug, $config['venues'],
                "[{$slug}] needs a curated sample hang in config/sample_exhibitions.php.");

            $selection = $config['venues'][$slug]['selection'];
            $this->assertGreaterThanOrEqual(6, count($selection), "[{$slug}] hang too sparse.");
            $this->assertLessThanOrEqual(8, count($selection), "[{$slug}] hang too heavy.");
            $this->assertCount(count($selection), array_unique($selection),
                "[{$slug}] hang repeats a work.");

            foreach ($selection as $key) {
                $this->assertArrayHasKey($key, $artworks,
                    "[{$slug}] references unknown sample artwork [{$key}].");
                $file = $artworks[$key]['file'];
                $this->assertFileExists(
                    public_path('assets/sample/artworks/' . $file),
                    "[{$slug}] sample artwork file missing: {$file}"
                );
            }

            $venue  = VenueTemplate::where('slug', $slug)->firstOrFail();
            $images = $service->forVenue($venue);
            $this->assertCount(count($selection), $images);
        }
    }

    public function test_unknown_venue_slug_still_gets_a_walkable_fallback_hang(): void
    {
        $service = app(SampleExhibitionService::class);

        $adminVenue = VenueTemplate::factory()->make([
            'slug' => 'admin-created-venue-without-curation',
        ]);

        $images = $service->forVenue($adminVenue);

        $this->assertGreaterThanOrEqual(6, count($images),
            'Admin-created venues must be walkable too — fallback hang required.');
    }

    public function test_preview_composition_is_deterministic_per_venue(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $service = app(SampleExhibitionService::class);
        $venue   = VenueTemplate::where('slug', 'crystal-cathedral')->firstOrFail();

        $first  = $service->forVenue($venue);
        $second = $service->forVenue($venue);

        $this->assertSame(
            array_column($first, 'id', 'title'),
            array_column($second, 'id', 'title'),
            'Sample hangs must be deterministic (same venue → same order).'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // The route must never read/write Gallery rows for a preview
    // ─────────────────────────────────────────────────────────────────────

    public function test_preview_issues_no_gallery_queries(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $this->get(route('venues.preview', 'mirror-lake'))->assertOk();

        $leaks = [];
        foreach ($queries as $sql) {
            foreach (['galleries', 'images', 'users'] as $table) {
                if (preg_match('/\b' . $table . '\b/i', $sql)) {
                    $leaks[] = $sql;
                    break;
                }
            }
        }

        $this->assertCount(0, $leaks,
            'Preview must not query gallery/image/user tables. Offending: '
            . implode(' | ', array_slice($leaks, 0, 3))
            . (($n = count($leaks)) > 3 ? " … (+{$n} more)" : ''));
    }
}
