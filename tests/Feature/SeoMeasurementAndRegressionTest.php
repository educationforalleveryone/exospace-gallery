<?php

declare(strict_types=1);

/**
 * SEO OPERATING SYSTEM — Iteration 7 (measurement + final hardening) tests.
 *
 * Covers:
 *   - Acquisition capture: channel classification, first-touch-only
 *     semantics, signup persistence, report aggregation
 *   - LCP preload hooks (artwork + artist pages)
 *   - FINAL REGRESSION: every public route emits a complete meta layer
 *     (title, meta description, canonical, og:title, og:image, og:url,
 *     twitter card) — the SEO operating system's acceptance test.
 *
 * Run: php artisan test --filter=SeoMeasurementAndRegressionTest
 */

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use App\Services\Seo\OrganicAcquisitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoMeasurementAndRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://exospace.gallery']);
        // ITERATION-1 FIX: canonical assertions require https URLs — force
        // the generator root (config alone doesn't change url() output).
        \Illuminate\Support\Facades\URL::forceRootUrl('https://exospace.gallery');
        \Illuminate\Support\Facades\URL::forceScheme('https');
    }

    // ── Acquisition capture ─────────────────────────────────────────────

    public function test_first_touch_acquisition_is_captured_in_session(): void
    {
        $response = $this->get('/discover', ['HTTP_REFERER' => 'https://www.google.com/search?q=3d+art+gallery']);

        $response->assertOk();
        $acquisition = session('acquisition');
        $this->assertNotNull($acquisition, 'First page view captures acquisition context.');
        $this->assertSame('organic', $acquisition['channel']);
        $this->assertSame('/discover', $acquisition['landing_page']);
        $this->assertSame('https://www.google.com/search?q=3d+art+gallery', $acquisition['referrer']);
    }

    public function test_acquisition_channel_classification(): void
    {
        $cases = [
            ['https://www.google.com/search?q=x', null, 'organic'],
            ['https://bing.com/search?q=x', null, 'organic'],
            ['https://duckduckgo.com/', null, 'organic'],
            ['https://www.facebook.com/page', null, 'social'],
            ['https://t.co/abc123', null, 'social'],
            ['https://some-blog.com/post-about-art', null, 'referral'],
            [null, null, 'direct'],
        ];

        foreach ($cases as [$referrer, $utm, $expected]) {
            session()->forget('acquisition');
            $this->get('/discover', $referrer ? ['HTTP_REFERER' => $referrer] : []);
            $this->assertSame(
                $expected,
                session('acquisition')['channel'],
                "Referrer [{$referrer}] should classify as {$expected}",
            );
        }
    }

    public function test_utm_campaign_takes_precedence(): void
    {
        $this->get('/discover?utm_source=newsletter&utm_campaign=launch', [
            'HTTP_REFERER' => 'https://www.google.com/search?q=x',
        ]);

        $this->assertSame('campaign', session('acquisition')['channel']);
        $this->assertSame('newsletter', session('acquisition')['utm']['utm_source']);
    }

    public function test_first_touch_is_sticky(): void
    {
        $this->get('/discover', ['HTTP_REFERER' => 'https://www.google.com/search?q=x']);
        $this->get('/pricing', ['HTTP_REFERER' => 'https://some-blog.com/link']);

        // Second view must NOT overwrite the first-touch referrer.
        $this->assertSame('organic', session('acquisition')['channel']);
        $this->assertSame('/discover', session('acquisition')['landing_page']);
    }

    public function test_signup_persists_acquisition_context(): void
    {
        // Arrive from Google on a gallery page, then register.
        $this->get('/discover', ['HTTP_REFERER' => 'https://www.google.com/search?q=virtual+gallery']);

        $user = User::create([
            'name' => 'Organic Visitor',
            'email' => 'organic@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->assertSame('organic', $user->fresh()->acquisition_channel);
        $this->assertSame('/discover', $user->fresh()->acquisition_landing_page);
        $this->assertNotNull($user->fresh()->acquisition_captured_at);
    }

    public function test_acquisition_report_aggregates_channels(): void
    {
        User::create(['name' => 'A', 'email' => 'a@x.com', 'password' => bcrypt('x'), 'acquisition_channel' => 'organic']);
        User::create(['name' => 'B', 'email' => 'b@x.com', 'password' => bcrypt('x'), 'acquisition_channel' => 'organic']);
        User::create(['name' => 'C', 'email' => 'c@x.com', 'password' => bcrypt('x'), 'acquisition_channel' => 'direct']);

        $curator = User::create([
            'name' => 'D', 'email' => 'd@x.com', 'password' => bcrypt('x'),
            'acquisition_channel' => 'organic',
            'acquisition_landing_page' => '/artist/someone',
        ]);
        Gallery::create([
            'user_id' => $curator->id, 'title' => 'Organic User Gallery',
            'slug' => 'organic-user-gallery', 'is_active' => true,
        ]);

        $report = app(OrganicAcquisitionService::class)->report();

        $this->assertSame(3, $report['signups_by_channel']['organic']);
        $this->assertSame(1, $report['signups_by_channel']['direct']);
        $this->assertSame(75.0, $report['organic_share']);
        $this->assertSame(1, $report['organic_galleries']['galleries'], 'Gallery created by organic user counts.');
        $this->assertSame(1, $report['organic_galleries']['users_with_galleries']);
        $this->assertSame('/artist/someone', $report['top_landing_pages']->first()['landing_page']);
    }

    public function test_seo_console_acquisition_tab_renders(): void
    {
        // ITERATION-1 FIX: master-control requires MFA for super-admins.
        $superAdmin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($superAdmin)
            ->withSession(['mfa_verified' => true, 'mfa_verified_at' => now()->timestamp])
            ->get('/master-control/seo?tab=acquisition');

        $response->assertOk();
        $this->assertStringContainsString('Organic acquisition', $response->getContent());
        $this->assertStringContainsString('Signups by channel', $response->getContent());
    }

    // ── LCP preloads ────────────────────────────────────────────────────

    public function test_artwork_page_preloads_lcp_image(): void
    {
        $gallery = $this->makePublicGallery();
        $artwork = $this->addArtwork($gallery, ['title' => 'Preloaded', 'medium' => 'Oil']);

        $response = $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}");

        $this->assertStringContainsString(
            '<link rel="preload" as="image"',
            $response->getContent(),
            'Artwork page preloads its main image (LCP).',
        );
    }

    // ── FINAL REGRESSION: meta layer on every public surface ────────────

    public function test_every_public_surface_has_the_complete_meta_layer(): void
    {
        $artist = Artist::create(['name' => 'Regression Artist', 'bio' => 'Bio.']);
        $gallery = $this->makePublicGallery(['title' => 'Regression Show']);
        $artwork = $this->addArtwork($gallery, ['title' => 'Regression Work', 'artist_id' => $artist->id, 'medium' => 'Oil']);

        $surfaces = [
            '/',

            '/discover',
            '/artists',
            '/venues',
            '/pricing',
            '/about',
            '/contact',
            '/privacy',
            '/terms',
            '/refund-policy',
            '/payment-security',
            '/changelog',
            '/status',

            '/artist/regression-artist',
            "/gallery/{$gallery->slug}",
            "/gallery/{$gallery->slug}/artwork/{$artwork->id}",
            "/gallery/{$gallery->slug}/events",
        ];

        foreach ($surfaces as $url) {
            $response = $this->get($url);
            $html = $response->getContent();

            $this->assertTrue(
                $response->isOk(),
                "Surface {$url} must return 200 — got {$response->getStatusCode()}.",
            );

            foreach ([
                ['<title>', 'title tag'],
                ['<meta name="description" content="', 'meta description'],
                ['<link rel="canonical" href="', 'canonical'],
                ['<meta property="og:title" content="', 'og:title'],
                ['<meta property="og:description" content="', 'og:description'],
                ['<meta property="og:image" content="', 'og:image'],
                ['<meta property="og:url" content="', 'og:url'],
                ['<meta name="twitter:card" content="', 'twitter card'],
                ['<meta property="og:locale" content="', 'og:locale'],
            ] as [$needle, $label]) {
                $this->assertStringContainsString(
                    $needle,
                    $html,
                    "Surface {$url} is missing {$label}.",
                );
            }

            // Canonical must be absolute and https.
            preg_match('/<link rel="canonical" href="([^"]+)"/', $html, $m);
            $this->assertMatchesRegularExpression(
                '#^https://#',
                $m[1] ?? '',
                "Surface {$url} canonical must be absolute https.",
            );
            $this->assertStringNotContainsString(
                'utm_',
                $m[1] ?? '',
                "Surface {$url} canonical must not carry tracking params.",
            );
        }
    }

    public function test_tracking_params_never_reach_any_canonical(): void
    {
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['title' => 'W']);

        foreach ([
            "/gallery/{$gallery->slug}?utm_source=mail&utm_campaign=boom",
            '/discover?gclid=abc123',
            '/artists?fbclid=x',
        ] as $url) {
            $html = $this->get($url)->getContent();
            preg_match('/<link rel="canonical" href="([^"]+)"/', $html, $m);

            $this->assertStringNotContainsString('utm_', $m[1] ?? '', "Canonical for {$url} must drop utm params.");
            $this->assertStringNotContainsString('gclid', $m[1] ?? '', "Canonical for {$url} must drop gclid.");
            $this->assertStringNotContainsString('fbclid', $m[1] ?? '', "Canonical for {$url} must drop fbclid.");
        }
    }

    public function test_no_public_surface_is_unintentionally_noindex(): void
    {
        $artist = Artist::create(['name' => 'Indexable Artist', 'bio' => 'Bio.']);
        $gallery = $this->makePublicGallery(['title' => 'Indexable Show']);
        $this->addArtwork($gallery, ['title' => 'Work', 'artist_id' => $artist->id, 'medium' => 'Oil']);

        foreach ([
            '/',
            '/discover',
            '/artists',
            '/venues',
            '/pricing',
            '/artist/indexable-artist',
            "/gallery/{$gallery->slug}",
        ] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringNotContainsString(
                'noindex',
                $html,
                "Surface {$url} must be indexable (healthy public content).",
            );
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function makePublicGallery(array $attrs = []): Gallery
    {
        return Gallery::create(array_merge([
            'user_id'    => User::factory()->create()->id,
            'title'      => 'Regression Gallery',
            'slug'       => 'regression-' . uniqid(),
            'description'=> 'A description.',
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
}
