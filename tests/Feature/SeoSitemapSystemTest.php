<?php

declare(strict_types=1);

/**
 * SEO OPERATING SYSTEM — Iteration 4 (sitemaps / robots / redirects) tests.
 *
 * Covers:
 *   - Grouped sitemap index (static, galleries, artists, artworks listed)
 *   - Gallery group: public galleries in, PIN/closed/scheduled/banned out,
 *     cover image extension, custom-domain URLs as loc
 *   - Artist group: only artists with public works
 *   - Artwork group: quality-gated artworks only
 *   - Legacy /sitemap-{page}.xml → 301 to /sitemap-galleries-{page}.xml
 *   - Out-of-range sitemap pages → 404 (Search Console hygiene)
 *   - Dynamic robots.txt: primary host rules + sitemap; custom-domain rules
 *   - SeoRedirects middleware: 301/302 application, unknown paths pass through
 *   - Sitemap version bumps on gallery edits (cache invalidation)
 *   - ?artwork= deep link canonicalizes to the artwork page
 *
 * Run: php artisan test --filter=SeoSitemapSystemTest
 */

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\SeoProfile;
use App\Models\SeoRedirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SeoSitemapSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://exospace.gallery']);
        // ITERATION-1 FIX: force the URL generator root so url()-built
        // canonicals match the asserted absolute URLs (see SeoEntityPagesTest).
        \Illuminate\Support\Facades\URL::forceRootUrl('https://exospace.gallery');
        \Illuminate\Support\Facades\URL::forceScheme('https');
    }

    private function makePublicGallery(array $attrs = []): Gallery
    {
        $user = User::factory()->create();

        return Gallery::create(array_merge([
            'user_id'    => $user->id,
            'title'      => 'Echoes of the Void',
            'slug'       => 'echoes-' . uniqid(),
            'description'=> 'A survey of new digital works.',
            'is_active'  => true,
        ], $attrs));
    }

    private function addArtwork(Gallery $gallery, array $attrs = []): GalleryImage
    {
        return GalleryImage::create(array_merge([
            'gallery_id'    => $gallery->id,
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

    // ── Sitemap index ───────────────────────────────────────────────────

    public function test_sitemap_index_lists_all_groups(): void
    {
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['title' => 'Work']);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('sitemap-static-1.xml', $xml);
        $this->assertStringContainsString('sitemap-galleries-1.xml', $xml);
        $this->assertStringContainsString('sitemap-artists-1.xml', $xml);
        $this->assertStringContainsString('sitemap-artworks-1.xml', $xml);
        // content group omitted when empty (no seo_pages table data yet)
    }

    public function test_sitemap_index_has_real_lastmod(): void
    {
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['title' => 'Work']);
        $gallery->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();

        // Reset caches so the sub-3-days lastmod is picked up.
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 42);

        $response = $this->get('/sitemap.xml');
        $xml = $response->getContent();

        $this->assertStringContainsString('<lastmod>', $xml);
        $this->assertStringContainsString(now()->subDays(3)->format('Y-m-d'), $xml, 'lastmod reflects the real max(updated_at), not now().');
    }

    // ── Gallery group ───────────────────────────────────────────────────

    public function test_gallery_sitemap_includes_public_and_excludes_private(): void
    {
        $public = $this->makePublicGallery(['title' => 'Public Show']);
        $this->addArtwork($public, ['title' => 'Work']);

        $pin = $this->makePublicGallery([
            'title' => 'PIN Show', 'pin_hash' => \Illuminate\Support\Facades\Hash::make('1'),
        ]);
        $this->addArtwork($pin, ['title' => 'Work']);

        $closed = $this->makePublicGallery([
            'title' => 'Closed Show', 'closes_at' => now()->subDay(),
        ]);
        $this->addArtwork($closed, ['title' => 'Work']);

        $empty = $this->makePublicGallery(['title' => 'Empty Show']);

        $response = $this->get('/sitemap-galleries-1.xml');
        $xml = $response->getContent();

        $this->assertStringContainsString("gallery/{$public->slug}", $xml);
        $this->assertStringNotContainsString("gallery/{$pin->slug}", $xml, 'PIN galleries never in sitemap');
        $this->assertStringNotContainsString("gallery/{$closed->slug}", $xml, 'Closed galleries excluded');
        $this->assertStringNotContainsString("gallery/{$empty->slug}", $xml, 'Empty galleries excluded (thin content)');
    }

    public function test_gallery_sitemap_uses_custom_domain_as_loc(): void
    {
        $gallery = $this->makePublicGallery([
            'title' => 'White Label Show',
            'custom_domain' => 'gallery.janedoe.com',
            'custom_domain_verified_at' => now(),
        ]);
        $this->addArtwork($gallery, ['title' => 'Work']);

        $response = $this->get('/sitemap-galleries-1.xml');

        $this->assertStringContainsString('<loc>https://gallery.janedoe.com</loc>', $response->getContent());
    }

    public function test_seo_profile_can_exclude_gallery_from_sitemap(): void
    {
        $gallery = $this->makePublicGallery(['title' => 'Excluded Show']);
        $this->addArtwork($gallery, ['title' => 'Work']);
        $gallery->seoProfile()->create(['sitemap_include' => false]);

        // Bump version so the sitemap cache regenerates
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 100);

        $response = $this->get('/sitemap-galleries-1.xml');

        $this->assertStringNotContainsString("gallery/{$gallery->slug}", $response->getContent(), 'Profile sitemap_include=false forces exclusion.');
    }

    public function test_banned_users_galleries_excluded(): void
    {
        $bannedUser = User::factory()->create(['banned_at' => now()]);
        $gallery = Gallery::create([
            'user_id' => $bannedUser->id,
            'title' => 'Banned Show',
            'slug' => 'banned-show',
            'is_active' => true,
        ]);
        $this->addArtwork($gallery, ['title' => 'Work']);

        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 100);

        $response = $this->get('/sitemap-galleries-1.xml');

        $this->assertStringNotContainsString('banned-show', $response->getContent());
    }

    // ── Artist group ────────────────────────────────────────────────────

    public function test_artist_sitemap_only_lists_artists_with_public_works(): void
    {
        $withWork = Artist::create(['name' => 'Listed Artist']);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $withWork->id]);

        Artist::create(['name' => 'Invisible Artist']);

        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 100);

        $response = $this->get('/sitemap-artists-1.xml');
        $xml = $response->getContent();

        $this->assertStringContainsString('/artist/listed-artist', $xml);
        $this->assertStringNotContainsString('invisible-artist', $xml);
    }

    // ── Artwork group ───────────────────────────────────────────────────

    public function test_artwork_sitemap_applies_quality_gate(): void
    {
        $artist = Artist::create(['name' => 'Gate Artist']);
        $gallery = $this->makePublicGallery();

        $rich = $this->addArtwork($gallery, ['title' => 'Rich Work', 'artist_id' => $artist->id, 'medium' => 'Oil']);
        $thin = $this->addArtwork($gallery, ['title' => 'Thin Work']);

        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 100);

        $response = $this->get('/sitemap-artworks-1.xml');
        $xml = $response->getContent();

        $this->assertStringContainsString("/artwork/{$rich->id}", $xml);
        $this->assertStringNotContainsString("/artwork/{$thin->id}", $xml, 'Thin artworks stay out of the sitemap.');
    }

    // ── Legacy + bounds ─────────────────────────────────────────────────

    public function test_legacy_sitemap_route_redirects_to_galleries_group(): void
    {
        $response = $this->get('/sitemap-1.xml');

        $response->assertStatus(301);
        $this->assertStringEndsWith('/sitemap-galleries-1.xml', (string) $response->headers->get('Location'));
    }

    public function test_out_of_range_sitemap_page_404s(): void
    {
        $response = $this->get('/sitemap-galleries-99.xml');

        $response->assertNotFound();
    }

    public function test_unknown_group_404s(): void
    {
        $response = $this->get('/sitemap-private-1.xml');

        $response->assertNotFound();
    }

    // ── robots.txt ──────────────────────────────────────────────────────

    public function test_robots_txt_serves_primary_host_rules(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('User-agent: *', $body);
        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringContainsString('Disallow: /gallery/*/pin', $body);
        $this->assertStringContainsString('Disallow: /unsubscribe', $body);
        $this->assertStringContainsString('Disallow: /*?embed=', $body);
        $this->assertStringContainsString('Sitemap: https://exospace.gallery/sitemap.xml', $body);
    }

    public function test_robots_txt_on_custom_domain_references_host_local_sitemap(): void
    {
        $gallery = $this->makePublicGallery([
            'title' => 'Domain Gallery',
            'custom_domain' => 'show.janedoe.com',
            'custom_domain_verified_at' => now(),
        ]);

        // Simulate the DetectCustomDomain middleware resolution by calling
        // the controller with a request carrying the resolved gallery.
        $request = Request::create('https://show.janedoe.com/robots.txt', 'GET');
        $request->attributes->set('resolved_gallery', $gallery->fresh());

        $controller = new \App\Http\Controllers\RobotsController();
        $response = $controller($request);

        $body = $response->getContent();
        $this->assertStringContainsString('Sitemap: https://show.janedoe.com/sitemap.xml', $body);
        $this->assertStringNotContainsString('exospace.gallery/sitemap.xml', $body, 'Cross-host sitemap reference is invalid — must not appear.');
    }

    public function test_custom_domain_sitemap_lists_only_that_gallery(): void
    {
        $mine = $this->makePublicGallery(['title' => 'Mine']);
        $this->addArtwork($mine, ['title' => 'W']);

        $other = $this->makePublicGallery(['title' => 'Other Show']);
        $this->addArtwork($other, ['title' => 'W']);

        $request = Request::create('https://show.janedoe.com/sitemap.xml', 'GET');
        $request->attributes->set('resolved_gallery', $mine->fresh());

        $controller = new \App\Http\Controllers\SitemapController();
        $response = $controller->index($request);

        $xml = $response->getContent();
        $this->assertStringContainsString('gallery/' . $mine->slug, $xml);
        $this->assertStringNotContainsString('gallery/' . $other->slug, $xml, 'Custom-domain sitemap lists ONLY the resolved gallery.');
    }

    // ── Redirects ───────────────────────────────────────────────────────

    public function test_seo_redirect_301_applies(): void
    {
        SeoRedirect::create([
            'source_path' => 'old-exhibition',
            'destination' => '/discover',
            'status_code' => 301,
        ]);
        SeoRedirect::clearMapCache();

        $response = $this->get('/old-exhibition');

        $response->assertStatus(301);
        $this->assertStringEndsWith('/discover', (string) $response->headers->get('Location'));
    }

    public function test_seo_redirect_supports_302_and_absolute_urls(): void
    {
        SeoRedirect::create([
            'source_path' => 'temp-page',
            'destination' => 'https://example.org/elsewhere',
            'status_code' => 302,
        ]);
        SeoRedirect::clearMapCache();

        $response = $this->get('/temp-page');

        $response->assertStatus(302);
        $this->assertSame('https://example.org/elsewhere', $response->headers->get('Location'));
    }

    public function test_seo_redirect_inactive_does_not_apply(): void
    {
        SeoRedirect::create([
            'source_path' => 'inactive-redirect',
            'destination' => '/discover',
            'status_code' => 301,
            'is_active' => false,
        ]);
        SeoRedirect::clearMapCache();

        $response = $this->get('/inactive-redirect');

        $response->assertNotFound();
    }

    public function test_seo_redirect_query_string_ignored(): void
    {
        SeoRedirect::create([
            'source_path' => 'tracked-old-page',
            'destination' => '/pricing',
            'status_code' => 301,
        ]);
        SeoRedirect::clearMapCache();

        $response = $this->get('/tracked-old-page?utm_source=x');

        $response->assertStatus(301);
    }

    public function test_redirects_do_not_apply_to_post(): void
    {
        SeoRedirect::create([
            'source_path' => 'posted-path',
            'destination' => '/discover',
            'status_code' => 301,
        ]);
        SeoRedirect::clearMapCache();

        $response = $this->post('/posted-path');

        $this->assertNotSame(301, $response->getStatusCode(), 'POST must not redirect via SEO map.');
    }

    // ── Cache invalidation ──────────────────────────────────────────────

    public function test_gallery_edit_bumps_sitemap_version(): void
    {
        $gallery = $this->makePublicGallery();
        // ITERATION-1 FIX: creating a public gallery (a new sitemap URL)
        // legitimately bumps the version — reset the counter AFTER setup
        // so the assertion measures only the edit's effect.
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 5);

        $gallery->update(['title' => 'Renamed Show']);

        $this->assertSame(6, (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version'), 'Relevant edit bumps version.');
    }

    public function test_view_count_change_does_not_bump_sitemap_version(): void
    {
        $gallery = $this->makePublicGallery();
        // ITERATION-1 FIX: same reset-after-setup pattern as above.
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 5);

        $gallery->update(['view_count' => 99999]);

        $this->assertSame(5, (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version'), 'Counter updates must not invalidate sitemaps.');
    }

    public function test_gallery_deletion_bumps_version(): void
    {
        $gallery = $this->makePublicGallery();
        // ITERATION-1 FIX: creation bumps once (new URL) — reset AFTER setup
        // so the assertion measures only the deletion's effect.
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 5);

        $gallery->delete();

        $this->assertSame(6, (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version'));
    }

    // ── Deep-link canonical ─────────────────────────────────────────────

    public function test_artwork_deep_link_canonicalizes_to_artwork_page(): void
    {
        $artist = Artist::create(['name' => 'DL']);
        $gallery = $this->makePublicGallery();
        $artwork = $this->addArtwork($gallery, ['title' => 'Deep Work', 'artist_id' => $artist->id]);

        $response = $this->get("/gallery/{$gallery->slug}?artwork={$artwork->id}");

        $html = $response->getContent();
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://exospace.gallery/gallery/' . $gallery->slug . '/artwork/' . $artwork->id . '">',
            $html,
            'Deep links canonicalize to the artwork landing page.',
        );
    }
}
