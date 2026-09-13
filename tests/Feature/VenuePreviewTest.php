<?php

declare(strict_types=1);

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

class VenuePreviewTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDED_SLUGS = [
        'white-cube', 'infinite-void', 'industrial-loft', 'dark-museum',
        'zen-gallery', 'crystal-cathedral', 'nebula-drift', 'luxury-penthouse',
        'cyber-gallery', 'sculpture-garden', 'mirror-lake',
    ];

    public function test_preview_renders_for_every_seeded_venue_as_guest(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

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
            foreach (['id', 'title', 'venue_slug', 'venueConfig', 'images',
                      'wall_texture', 'floor_material', 'frame_style',
                      'lighting_preset', 'room_layout', 'imageCount'] as $key) {
                if (!array_key_exists($key, $data)) {
                    return false;
                }
            }

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

    public function test_preview_is_noindex(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $response = $this->get(route('venues.preview', 'white-cube'));
        $response->assertOk();

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_preview_is_rate_limited(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        for ($i = 0; $i < 20; $i++) {
            $this->get(route('venues.preview', 'white-cube'))->assertOk();
        }

        $this->get(route('venues.preview', 'white-cube'))
            ->assertStatus(429, 'The 21st preview request in one minute must be throttled.');
    }

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

        $raw = $exporter->forVenue($venue);
        $this->assertCount(3, $raw['decorations']);

        // Pro venue preview: free + pro visible, studio still hidden.
        $venue->plan_required = 'pro';
        $proConfig = $exporter->forVenuePreview($venue);
        $this->assertSame(['bench', 'neon'], array_column($proConfig['decorations'], 'type'));
    }

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
