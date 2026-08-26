<?php

declare(strict_types=1);

/**
 * SEO OPERATING SYSTEM — Iteration 1 (foundation) tests.
 *
 * Covers:
 *   - SeoData value object behaviour (with/override, robots resolution)
 *   - CanonicalUrl normalizer (tracking params, pagination, unknown params)
 *   - Breadcrumb trail + JSON-LD generation
 *   - SeoManager title templates, description limits, real-data fallbacks,
 *     and seo_profiles override layering
 *   - <x-seo> v2 emission (robots, og:image metadata, canonical cleaning)
 *   - seo_profiles migration + HasSeoProfile relation on Gallery/Artist
 *
 * Run: php artisan test --filter=SeoFoundationTest
 */

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\SeoProfile;
use App\Models\User;
use App\Support\Seo\Breadcrumb;
use App\Support\Seo\CanonicalUrl;
use App\Support\Seo\SeoData;
use App\Support\Seo\SeoManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoFoundationTest extends TestCase
{
    use RefreshDatabase;

    private SeoManager $seo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seo = app(SeoManager::class);
        // ITERATION-1 FIX: several assertions compare absolute URLs built
        // by url() — force the root so they aren't rendered as localhost.
        config(['app.url' => 'https://exospace.gallery']);
        \Illuminate\Support\Facades\URL::forceRootUrl('https://exospace.gallery');
        \Illuminate\Support\Facades\URL::forceScheme('https');
    }

    // ── SeoData ─────────────────────────────────────────────────────────

    public function test_seo_data_with_layers_overrides_without_mutating(): void
    {
        $base = new SeoData(title: 'Original', description: 'Base description');
        $next = $base->with(['title' => 'Override']);

        $this->assertSame('Original', $base->title, 'SeoData must be immutable.');
        $this->assertSame('Override', $next->title);
        $this->assertSame('Base description', $next->description, 'Unchanged props carry over.');
    }

    public function test_seo_data_with_ignores_null_changes(): void
    {
        $base = new SeoData(title: 'Original');
        $next = $base->with(['title' => null]);

        $this->assertSame('Original', $next->title, 'null in with() means "no change", not "clear".');
    }

    public function test_seo_data_robots_defaults_to_index_follow(): void
    {
        $data = new SeoData();

        $this->assertSame('index,follow', $data->robotsDirective());
        $this->assertTrue($data->isIndexable());

        $blocked = new SeoData(robots: 'noindex,follow');
        $this->assertFalse($blocked->isIndexable());
    }

    // ── CanonicalUrl ────────────────────────────────────────────────────

    public function test_canonical_url_strips_tracking_params(): void
    {
        $url = 'https://exospace.gallery/discover?utm_source=x&utm_medium=y&gclid=abc&sort=views';

        $clean = CanonicalUrl::clean($url);

        $this->assertSame('https://exospace.gallery/discover', $clean);
    }

    public function test_canonical_url_strips_embed_and_artwork_display_params(): void
    {
        $url = 'https://exospace.gallery/gallery/echoes?embed=1&artwork=42';

        $this->assertSame(
            'https://exospace.gallery/gallery/echoes',
            CanonicalUrl::clean($url),
        );
    }

    public function test_canonical_url_keeps_pagination_only_when_allowed(): void
    {
        $url = 'https://exospace.gallery/discover?page=3&utm_source=mail';

        $this->assertSame(
            'https://exospace.gallery/discover',
            CanonicalUrl::clean($url),
            'Pagination is dropped when allowPagination is false.',
        );
        $this->assertSame(
            'https://exospace.gallery/discover?page=3',
            CanonicalUrl::clean($url, allowPagination: true),
        );
    }

    public function test_canonical_url_page_one_is_not_meaningful_pagination(): void
    {
        $url = 'https://exospace.gallery/discover?page=1';

        $this->assertSame(
            'https://exospace.gallery/discover',
            CanonicalUrl::clean($url, allowPagination: true),
            'page=1 duplicates the clean URL and must canonicalize to it.',
        );
    }

    public function test_canonical_url_pagination_links(): void
    {
        $links = CanonicalUrl::paginationLinks('https://exospace.gallery/discover', 3, true);

        $this->assertSame('https://exospace.gallery/discover?page=2', $links['prev']);
        $this->assertSame('https://exospace.gallery/discover?page=4', $links['next']);

        // Page 2's prev must be the clean URL, not ?page=1
        $links2 = CanonicalUrl::paginationLinks('https://exospace.gallery/discover', 2, true);
        $this->assertSame('https://exospace.gallery/discover', $links2['prev']);

        // Last page has no next
        $links3 = CanonicalUrl::paginationLinks('https://exospace.gallery/discover', 5, false);
        $this->assertNull($links3['next']);
    }

    public function test_canonical_url_handles_urls_without_query(): void
    {
        $this->assertSame(
            'https://exospace.gallery/pricing',
            CanonicalUrl::clean('https://exospace.gallery/pricing'),
        );
    }

    // ── Breadcrumb ──────────────────────────────────────────────────────

    public function test_breadcrumb_trail_marks_last_as_current_page(): void
    {
        $crumbs = Breadcrumb::trail([
            ['Discover', 'https://exospace.gallery/discover'],
            ['Echoes of the Void'],
        ]);

        $this->assertCount(2, $crumbs);
        $this->assertSame('https://exospace.gallery/discover', $crumbs[0]->url);
        $this->assertNull($crumbs[1]->url, 'Last crumb (current page) must not link.');
    }

    public function test_breadcrumb_json_ld_shape(): void
    {
        $jsonLd = Breadcrumb::toJsonLd(
            Breadcrumb::trail([
                ['Discover', 'https://exospace.gallery/discover'],
                ['Echoes of the Void'],
            ]),
        );

        $this->assertSame('BreadcrumbList', $jsonLd['@type']);
        $this->assertCount(2, $jsonLd['itemListElement']);
        $this->assertSame(1, $jsonLd['itemListElement'][0]['position']);
        $this->assertSame('Discover', $jsonLd['itemListElement'][0]['name']);
        $this->assertSame('https://exospace.gallery/discover', $jsonLd['itemListElement'][0]['item']);
        $this->assertArrayNotHasKey('item', $jsonLd['itemListElement'][1], 'Current page ListItem has no item URL.');
    }

    // ── SeoManager ──────────────────────────────────────────────────────

    public function test_gallery_seo_uses_title_template_and_custom_domain_canonical(): void
    {
        config(['app.url' => 'https://exospace.gallery']);

        $user = User::factory()->create();
        $gallery = Gallery::create([
            'user_id' => $user->id,
            'title' => 'Echoes of the Void',
            'description' => 'A survey of new digital works exploring light and space.',
            'is_active' => true,
        ]);

        $seo = $this->seo->forGallery($gallery);

        $this->assertSame('Echoes of the Void — 3D Virtual Exhibition', $seo->title);
        $this->assertSame('https://exospace.gallery/gallery/' . $gallery->slug, $seo->canonicalUrl);
        $this->assertStringContainsString('/og-image', $seo->ogImage);
    }

    public function test_gallery_seo_generates_factual_fallback_description(): void
    {
        config(['app.url' => 'https://exospace.gallery']);

        $user = User::factory()->create();
        $gallery = Gallery::create([
            'user_id' => $user->id,
            'title' => 'Untitled Feelings',
            'description' => null, // curator left it empty
            'is_active' => true,
        ]);

        $seo = $this->seo->forGallery($gallery);

        $this->assertNotSame('', (string) $seo->description, 'Empty curator description must produce a generated fallback, not an empty meta tag.');
        $this->assertStringContainsString('3D virtual exhibition', $seo->description);
    }

    public function test_gallery_description_is_length_managed(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::create([
            'user_id' => $user->id,
            'title' => 'Long',
            'description' => str_repeat('word ', 100),
            'is_active' => true,
        ]);

        $seo = $this->seo->forGallery($gallery);

        $this->assertLessThanOrEqual(160, mb_strlen($seo->description));
    }

    public function test_artist_seo_builds_profile_from_real_data(): void
    {
        config(['app.url' => 'https://exospace.gallery']);

        $artist = Artist::create([
            'name' => 'Maya Chen',
            'bio' => 'Berlin-based artist working with light and space.',
            'location' => 'Berlin, Germany',
        ]);

        $seo = $this->seo->forArtist($artist, publicWorkCount: 12, exhibitionCount: 3);

        $this->assertSame('Maya Chen — Artist Profile & 3D Exhibitions', $seo->title);
        $this->assertSame('https://exospace.gallery/artist/maya-chen', $seo->canonicalUrl);
        $this->assertSame('profile', $seo->ogType);
        $this->assertStringContainsString('Berlin-based artist', $seo->description);
    }

    public function test_artist_seo_fallback_description_counts_real_works(): void
    {
        $artist = Artist::create(['name' => 'New Artist']);

        $seo = $this->seo->forArtist($artist, publicWorkCount: 5, exhibitionCount: 2);

        $this->assertStringContainsString('2 3D exhibitions', $seo->description);
    }

    public function test_seo_profile_overrides_layer_over_generated_metadata(): void
    {
        config(['app.url' => 'https://exospace.gallery']);

        $user = User::factory()->create();
        $gallery = Gallery::create([
            'user_id' => $user->id,
            'title' => 'Auto Title',
            'description' => 'Auto description.',
            'is_active' => true,
        ]);

        $gallery->seoProfile()->create([
            'title_override' => 'Manual Title',
            'description_override' => 'Manual description.',
            'robots_directive' => 'noindex,follow',
        ]);

        $seo = $this->seo->forGallery($gallery->fresh(['seoProfile']));

        $this->assertSame('Manual Title', $seo->title, 'Profile override must replace generated title.');
        $this->assertSame('Manual description.', $seo->description);
        $this->assertSame('noindex,follow', $seo->robots);
    }

    public function test_seo_profile_partial_override_keeps_rest_automatic(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::create([
            'user_id' => $user->id,
            'title' => 'Auto Title',
            'description' => 'Auto description.',
            'is_active' => true,
        ]);

        $gallery->seoProfile()->create([
            'description_override' => 'Only description overridden.',
        ]);

        $seo = $this->seo->forGallery($gallery->fresh(['seoProfile']));

        $this->assertSame('Auto Title — 3D Virtual Exhibition', $seo->title, 'Title stays automatic.');
        $this->assertSame('Only description overridden.', $seo->description);
        $this->assertNull($seo->robots, 'Robots stays automatic (null).');
    }

    public function test_has_seo_profile_resolvers_respect_null_semantics(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::create([
            'user_id' => $user->id,
            'title' => 'T',
            'is_active' => true,
        ]);

        // No profile: automatic decisions pass through.
        $this->assertSame('noindex', $gallery->effectiveRobotsDirective('noindex'));
        $this->assertFalse($gallery->effectiveSitemapInclusion(false));
        $this->assertTrue($gallery->effectiveSitemapInclusion(true));

        // Profile with forced values.
        $gallery->seoProfile()->create([
            'robots_directive' => 'index,follow',
            'sitemap_include' => true,
        ]);
        $gallery = $gallery->fresh(['seoProfile']);

        $this->assertSame('index,follow', $gallery->effectiveRobotsDirective('noindex'), 'Admin override forces indexing.');
        $this->assertTrue($gallery->effectiveSitemapInclusion(false), 'Admin override forces sitemap inclusion.');

        // Profile with NULL values falls back to automatic.
        $gallery->seoProfile()->update([
            'robots_directive' => null,
            'sitemap_include' => null,
        ]);
        $gallery = $gallery->fresh(['seoProfile']);

        $this->assertSame('noindex', $gallery->effectiveRobotsDirective('noindex'));
    }

    // ── <x-seo> v2 emission ─────────────────────────────────────────────

    public function test_x_seo_v2_renders_full_meta_layer_from_seo_data(): void
    {
        config(['app.url' => 'https://exospace.gallery']);

        $seo = new SeoData(
            title: 'Page Title',
            description: 'Page description.',
            canonicalUrl: 'https://exospace.gallery/page',
            robots: 'noindex,follow',
            ogImage: 'https://exospace.gallery/img/og.png',
            ogImageAlt: 'Alt text',
            prevUrl: 'https://exospace.gallery/page?page=1',
            nextUrl: 'https://exospace.gallery/page?page=3',
            jsonLd: [['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'Page Title']],
        );

        $html = view('components.seo', ['seo' => $seo])->render();

        $this->assertStringContainsString('<title>Page Title</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Page description.">', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex,follow">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/page">', $html);
        $this->assertStringContainsString('<link rel="prev" href="https://exospace.gallery/page?page=1">', $html);
        $this->assertStringContainsString('<link rel="next" href="https://exospace.gallery/page?page=3">', $html);
        $this->assertStringContainsString('<meta property="og:image:alt" content="Alt text">', $html);
        $this->assertStringContainsString('<meta property="og:locale" content="en_US">', $html);
        $this->assertStringContainsString('"@type":"WebPage"', $html, 'JSON-LD graphs render from SeoData.');
    }

    public function test_x_seo_legacy_mode_strips_tracking_params_from_default_canonical(): void
    {
        config(['app.url' => 'https://exospace.gallery']);

        // Simulate a request that carried tracking params.
        $this->app->instance(
            'request',
            \Illuminate\Http\Request::create('https://exospace.gallery/pricing?utm_source=newsletter&utm_campaign=launch'),
        );

        $html = view('components.seo', [
            'title' => 'Pricing',
            'description' => 'Pricing page.',
            // canonical intentionally omitted → component falls back to cleaned current URL
        ])->render();

        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/pricing">', $html);
        $this->assertStringNotContainsString('utm_source', $html, 'Tracking params must never leak into canonicals.');
    }

    public function test_x_seo_omits_robots_tag_when_indexable(): void
    {
        $html = view('components.seo', ['title' => 'T', 'description' => 'D'])->render();

        $this->assertStringNotContainsString('name="robots"', $html, 'Indexable pages emit no robots tag (default).');
    }

    // ── Public layout integration ───────────────────────────────────────

    public function test_public_layout_accepts_seo_data_object(): void
    {
        config(['app.url' => 'https://exospace.gallery']);
        $this->withoutVite(); // no built frontend in CI/test env

        $seo = new SeoData(
            title: 'Object Title',
            description: 'From controller.',
            canonicalUrl: 'https://exospace.gallery/obj',
        );

        $html = view('layouts.public', [
            'seoData' => $seo,
            'content' => '<div>content</div>',
        ])->render();

        $this->assertStringContainsString('<title>Object Title</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/obj">', $html);
    }

    // ── Migration sanity ────────────────────────────────────────────────

    public function test_seo_profiles_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Schema::hasTable('seo_profiles'));
        foreach ([
            'subject_type', 'subject_id', 'title_override', 'description_override',
            'canonical_override', 'robots_directive', 'og_image_path',
            'sitemap_include', 'structured_data_enabled', 'updated_by',
        ] as $column) {
            $this->assertTrue(
                \Schema::hasColumn('seo_profiles', $column),
                "seo_profiles is missing column {$column}",
            );
        }
    }

    public function test_seo_profile_belongs_to_subject_polymorphically(): void
    {
        $user = User::factory()->create();
        $artist = Artist::create(['name' => 'Poly Artist']);

        $profile = SeoProfile::create([
            'subject_type' => Artist::class,
            'subject_id' => $artist->id,
            'title_override' => 'X',
        ]);

        $this->assertTrue($profile->subject->is($artist));
    }
}
