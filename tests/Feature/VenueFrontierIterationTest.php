<?php

declare(strict_types=1);

/**
 * Iteration 7 "FRONTIER" regression tests (3D venue roadmap, P2.4 + P3.1).
 *
 * Pins the storefront + data-driven-catalog contract:
 *
 *   P2.4 — venue pages become the SEO/conversion asset:
 *     - /venues/{slug} renders the hero still + the EMBEDDED walkthrough
 *       (click-to-load poster → preview iframe), still no-signup (DO NOT DO #10).
 *     - flag venue_previews off → no poster, no preview URL anywhere.
 *     - the sitemap gains a venues group whose inclusion rule mirrors the
 *       page's indexability EXACTLY (active + published + >= 1 public
 *       exhibition): draft / inactive / archived / exhibition-less venues
 *       are never listed; SeoProfile exclusions are honored.
 *
 *   P2.4 — the catalog decision has data:
 *     - venues:catalog-report --json rolls up adoption/demand/resonance
 *       with exact numbers (accuracy contract), conversion null-safe at
 *       zero views, and the §3.3 register coverage (the gap is always
 *       listed — since Iteration 8, that gap is grandeur alone: The
 *       Salon covered intimacy).
 *
 *   P3.1 — try-on spike behind a default-OFF flag:
 *     - flag default false; preview payload carries tryOnEnabled ONLY on
 *       the sample-only preview (never in any customer-gallery source).
 *     - TryOn.js network-surface scan: zero I/O tokens (client-side only,
 *       §14 P3.1 "nothing persisted" — structural, not procedural).
 *     - TryOn.js slug scan: zero venue identity (DoD #7).
 *
 * Run: php artisan test --filter=VenueFrontierIterationTest
 */

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\SeoProfile;
use App\Models\User;
use App\Models\VenueTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VenueFrontierIterationTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDED_SLUGS = [
        'white-cube', 'infinite-void', 'industrial-loft', 'dark-museum',
        'zen-gallery', 'crystal-cathedral', 'nebula-drift', 'luxury-penthouse',
        'cyber-gallery', 'sculpture-garden', 'mirror-lake',
        'the-salon', // Iteration 8 (P3.2) — the twelfth venue
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function venue(string $slug): VenueTemplate
    {
        return VenueTemplate::where('slug', $slug)->firstOrFail();
    }

    private function attachPublicGallery(VenueTemplate $venue, array $galleryAttrs = []): Gallery
    {
        $user = User::factory()->create();

        $gallery = Gallery::create(array_merge([
            'user_id'           => $user->id,
            'title'             => 'Show in ' . $venue->name,
            'slug'              => 'show-' . $venue->slug . '-' . uniqid(),
            'description'       => 'A public exhibition.',
            'is_active'         => true,
            'venue_template_id' => $venue->id,
        ], $galleryAttrs));

        $this->attachArtwork($gallery);

        return $gallery;
    }

    private function attachArtwork(Gallery $gallery, array $attrs = []): GalleryImage
    {
        return GalleryImage::create(array_merge([
            'gallery_id'    => $gallery->id,
            'artist_id'     => null,
            'filename'      => 'artwork.jpg',
            'original_name' => 'artwork.jpg',
            'path'          => 'artworks/artwork.jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => 1024,
            'width'         => 1200,
            'height'        => 800,
            'orientation'   => 'landscape',
        ], $attrs));
    }

    private function bustSitemapCaches(): void
    {
        // Same mechanism SeoSitemapSystemTest uses: move the version key so
        // every versioned group cache regenerates from live queries.
        Cache::put('seo:sitemap:version', random_int(1000, 999999));
    }

    private function reportJson(): array
    {
        Artisan::call('venues:catalog-report', ['--json' => true]);

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded, 'the --json mode must emit parseable JSON');

        return $decoded;
    }

    // ─────────────────────────────────────────────────────────────────────
    // P2.4 — the venue page storefront
    // ─────────────────────────────────────────────────────────────────────

    public function test_venue_page_renders_hero_still_and_embedded_walkthrough(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $venue = $this->venue('white-cube');
        $venue->update(['thumbnail_path' => 'venues/white-cube-hero.jpg']);
        $this->attachPublicGallery($venue);

        $response = $this->get(route('venues.show', 'white-cube'));

        $response->assertOk();
        $html = $response->getContent();

        // Hero still: the SAME pipeline the picker uses (P0.2 unification),
        // now on the page. Four legitimate surfaces since the SEO layer:
        // poster img + figure img + og:image + twitter:image.
        $this->assertSame(
            4,
            substr_count($html, 'venues/white-cube-hero.jpg'),
            'Hero still appears on the venue page (poster + figure + og:image + twitter:image).'
        );

        // Embedded walkthrough: click-to-load poster carrying the preview URL.
        $this->assertStringContainsString('data-walkthrough-poster', $html);
        $this->assertStringContainsString('data-preview-url="' . route('venues.preview', 'white-cube') . '"', $html);
        $this->assertStringContainsString('Walk through this venue', $html);

        // No-JS visitors keep the direct link — the funnel is never gated
        // behind JavaScript either (DO NOT DO #10, extended).
        $this->assertStringContainsString('<noscript>', $html);

        // The 3D runtime must NOT boot on page load — the iframe only ever
        // exists after an explicit user action.
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_venue_page_without_thumbnail_still_renders_embed(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $venue = $this->venue('zen-gallery');
        $this->attachPublicGallery($venue);

        $response = $this->get(route('venues.show', 'zen-gallery'));
        $response->assertOk();

        $this->assertStringContainsString('data-walkthrough-poster', $response->getContent());
    }

    public function test_venue_page_embed_hides_when_previews_flag_off(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        config(['feature_flags.flags.venue_previews' => false]);
        $venue = $this->venue('white-cube');
        $venue->update(['thumbnail_path' => 'venues/white-cube-hero.jpg']);
        $this->attachPublicGallery($venue);

        $response = $this->get(route('venues.show', 'white-cube'));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringNotContainsString('data-walkthrough-poster', $html);
        $this->assertStringNotContainsString((string) route('venues.preview', 'white-cube'), $html);
        // The hero-still figure is page content, not preview machinery — it stays.
        $this->assertStringContainsString('venues/white-cube-hero.jpg', $html);
    }

    // ─────────────────────────────────────────────────────────────────────
    // P2.4 — the sitemap venues group
    // ─────────────────────────────────────────────────────────────────────

    public function test_sitemap_index_advertises_venues_group(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->attachPublicGallery($this->venue('white-cube'));
        $this->bustSitemapCaches();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('sitemap-venues-1.xml', $xml);
    }

    public function test_venues_sitemap_lists_only_indexable_venues(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Indexable: active + published + >= 1 public exhibition.
        $this->attachPublicGallery($this->venue('white-cube'));

        // Exhibition-less venue pages render noindex,follow → never listed.
        // (zen-gallery, dark-museum etc. stay exhibition-less in this test.)

        $this->bustSitemapCaches();
        $xml = $this->get('/sitemap-venues-1.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>' . url('/venues/white-cube') . '</loc>', $xml);
        $this->assertStringNotContainsString(url('/venues/zen-gallery'), $xml);
        $this->assertStringNotContainsString(url('/venues/dark-museum'), $xml);
    }

    public function test_venues_sitemap_excludes_draft_inactive_archived_venues(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Baseline: an eligible venue that must remain listed.
        $this->attachPublicGallery($this->venue('zen-gallery'));

        // Each of these holds a live public exhibition — only its STATE
        // disqualifies it; the gate must catch every one.
        VenueTemplate::where('slug', 'dark-museum')->update(['is_draft' => true]);
        $this->attachPublicGallery($this->venue('dark-museum'));

        VenueTemplate::where('slug', 'crystal-cathedral')->update(['is_active' => false]);
        $this->attachPublicGallery($this->venue('crystal-cathedral'));

        VenueTemplate::where('slug', 'nebula-drift')->update(['archived_at' => now()]);
        $this->attachPublicGallery($this->venue('nebula-drift'));

        $this->bustSitemapCaches();
        $xml = $this->get('/sitemap-venues-1.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>' . url('/venues/zen-gallery') . '</loc>', $xml);
        $this->assertStringNotContainsString(url('/venues/dark-museum'), $xml, 'draft venues are never listed');
        $this->assertStringNotContainsString(url('/venues/crystal-cathedral'), $xml, 'paused venues are never listed');
        $this->assertStringNotContainsString(url('/venues/nebula-drift'), $xml, 'archived venues are never listed');
    }

    public function test_seo_profile_exclusion_removes_venue_from_sitemap(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $venue = $this->venue('mirror-lake');
        $this->attachPublicGallery($venue);

        SeoProfile::create([
            'subject_type'    => VenueTemplate::class,
            'subject_id'      => $venue->id,
            'sitemap_include' => false,
            'updated_by'      => null,
        ]);

        $this->bustSitemapCaches();
        $xml = $this->get('/sitemap-venues-1.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString(url('/venues/mirror-lake'), $xml);
    }

    public function test_venues_sitemap_count_matches_inclusion_rule(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);
        $this->attachPublicGallery($this->venue('white-cube'));
        $this->attachPublicGallery($this->venue('infinite-void'));

        $this->bustSitemapCaches();
        $xml = $this->get('/sitemap-venues-1.xml')->assertOk()->getContent();

        $this->assertSame(2, substr_count($xml, '<loc>'), 'exactly the two indexable venues, no others');
    }

    // ─────────────────────────────────────────────────────────────────────
    // P2.4 — the catalog rollup (accuracy contract)
    // ─────────────────────────────────────────────────────────────────────

    public function test_catalog_report_rolls_up_accurately(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $whiteCube = $this->venue('white-cube');
        // 2 galleries total, 1 public-with-artwork; 500 venue-attributed
        // views → conversion = 2 / 500 * 1000 = 4.0 per 1k.
        $this->attachPublicGallery($whiteCube, ['title' => 'Public show']);
        $privateOwner = User::factory()->create();
        Gallery::create([
            'user_id'           => $privateOwner->id,
            'title'             => 'Private show',
            'slug'              => 'private-show-' . uniqid(),
            'is_active'         => false,
            'venue_template_id' => $whiteCube->id,
        ]);
        VenueTemplate::where('slug', 'white-cube')->update(['view_count' => 500]);

        // Zero views → conversion must be null, not zero (a ratio against
        // zero is a lie, per the model's own contract).
        $this->attachPublicGallery($this->venue('zen-gallery'));

        $json = $this->reportJson();

        $whiteCubeRow = collect($json['venues'])->firstWhere('slug', 'white-cube');
        $this->assertNotNull($whiteCubeRow);
        $this->assertSame(2, $whiteCubeRow['galleries_total'], 'counts ALL galleries, not just public');
        $this->assertSame(1, $whiteCubeRow['galleries_public'], 'public = publiclyViewable with >= 1 image');
        $this->assertSame(500, $whiteCubeRow['views']);
        $this->assertSame(4.0, (float) $whiteCubeRow['conversion_per_1k'], 'conversion matches the model accessor exactly');

        $zenRow = collect($json['venues'])->firstWhere('slug', 'zen-gallery');
        $this->assertNotNull($zenRow);
        $this->assertSame(0, $zenRow['views']);
        $this->assertNull($zenRow['conversion_per_1k'], 'no views → null, never a fake 0.0');
    }

    public function test_catalog_report_register_coverage_lists_the_gap(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $json = $this->reportJson();
        $coverage = collect($json['register_coverage']);

        // Every covered register maps to a real seeded venue…
        foreach ($coverage->where('status', 'covered') as $row) {
            $this->assertNotNull($row['venue']);
            $this->assertContains($row['venue'], self::SEEDED_SLUGS, "{$row['register']} maps to a seeded venue");
        }

        // …intimacy is covered since Iteration 8 (The Salon)…
        $intimacy = $coverage->firstWhere('register', 'intimacy');
        $this->assertNotNull($intimacy);
        $this->assertSame('covered', $intimacy['status'], 'The Salon (P3.2) covers the intimacy register.');
        $this->assertSame('the-salon', $intimacy['venue']);

        // …and the §3.3 gap is always visible, never silently filled —
        // grandeur is the last open register.
        $uncovered = $coverage->where('status', 'uncovered')->pluck('register')->all();
        $this->assertContains('grandeur', $uncovered);
        $this->assertNotContains('intimacy', $uncovered, 'Intimacy was covered by The Salon — it must not reappear as a gap.');
    }

    public function test_catalog_report_decision_rule_is_printed(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $json = $this->reportJson();

        $this->assertArrayHasKey('decision_inputs', $json);
        $rule = implode("\n", $json['decision_inputs']);
        $this->assertStringContainsString('studio share >= 50%', $rule);
        $this->assertStringContainsString('grandeur', $rule);
        $this->assertStringContainsString('intimacy', $rule);
        $this->assertStringContainsString('NO existing venue retires', $rule, 'DO NOT DO #2 restated in the instrument itself');
    }

    // ─────────────────────────────────────────────────────────────────────
    // P3.1 — the try-on spike
    // ─────────────────────────────────────────────────────────────────────

    public function test_try_on_flag_defaults_off(): void
    {
        $this->assertFalse(
            config('feature_flags.flags.venue_try_on'),
            'A spike ships opt-in: FEATURE_FLAG_VENUE_TRY_ON defaults to false.'
        );
    }

    public function test_preview_payload_carries_try_on_only_when_flag_on(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        // Default off: payload key present but false (explicit > absent).
        $data = $this->get(route('venues.preview', 'mirror-lake'))
            ->assertOk()
            ->viewData('galleryData');
        $this->assertFalse($data['tryOnEnabled']);

        // Flag on: the spike arms on the preview payload.
        config(['feature_flags.flags.venue_try_on' => true]);
        $data = $this->get(route('venues.preview', 'mirror-lake'))
            ->assertOk()
            ->viewData('galleryData');
        $this->assertTrue($data['tryOnEnabled']);
    }

    public function test_preview_page_renders_try_on_affordance_only_when_flag_on(): void
    {
        $this->seed(\Database\Seeders\VenueTemplateSeeder::class);

        $off = $this->get(route('venues.preview', 'white-cube'))->assertOk()->getContent();
        $this->assertStringNotContainsString('tryon-input', $off);

        config(['feature_flags.flags.venue_try_on' => true]);
        $on = $this->get(route('venues.preview', 'white-cube'))->assertOk()->getContent();
        $this->assertStringContainsString('tryon-input', $on);
        $this->assertStringContainsString('Try your art', $on);
    }

    public function test_try_on_is_wired_only_into_the_preview_payload(): void
    {
        // Policy pinned structurally: among ALL top-level controllers, only
        // the sample-only preview controller may expose tryOnEnabled —
        // customer galleries can never receive the key.
        $hits = [];
        foreach (glob(app_path('Http/Controllers/*.php')) as $file) {
            $contents = file_get_contents($file);
            if (strpos($contents, 'tryOnEnabled') !== false) {
                $hits[] = basename($file);
            }
        }
        $this->assertSame(['VenuePreviewController.php'], $hits);
    }

    public function test_tryon_js_has_zero_network_surface(): void
    {
        $source = file_get_contents(resource_path('js/gallery/TryOn.js'));

        foreach ([
            'fetch(', 'XMLHttpRequest', 'WebSocket', 'sendBeacon',
            'navigator.send', '$.ajax', 'axios', 'postMessage',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "TryOn.js must not contain '{$forbidden}' — client-side only (§14 P3.1)"
            );
        }
    }

    public function test_tryon_js_carries_no_venue_identity(): void
    {
        $source = file_get_contents(resource_path('js/gallery/TryOn.js'));

        foreach (self::SEEDED_SLUGS as $slug) {
            $this->assertStringNotContainsString(
                $slug,
                $source,
                "TryOn.js must not know venue '{$slug}' (DoD #7: no slug-keyed JS)"
            );
        }
    }
}
