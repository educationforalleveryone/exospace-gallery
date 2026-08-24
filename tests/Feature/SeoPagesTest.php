<?php

declare(strict_types=1);

/**
 * SEO OPERATING SYSTEM — Iteration 5 (SEO pages) tests.
 *
 * Covers:
 *   - Fallback routing: published landing pages at /{slug}, editorial at
 *     /resources/{slug}; real routes always win
 *   - Draft pages 404 publicly; preview token renders with noindex
 *   - Scheduled pages 404 until due
 *   - Block allow-list: unknown block types dropped
 *   - Live-content blocks render real exhibitions
 *   - FAQ blocks emit FAQPage schema; editorial pages emit Article schema
 *   - Page SEO: unique title/canonical, noindex flag honored
 *   - Sitemap content group lists published pages only
 *
 * Run: php artisan test --filter=SeoPagesTest
 */

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\SeoPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://exospace.gallery']);
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 1);
    }

    private function makePage(array $attrs = []): SeoPage
    {
        return SeoPage::create(array_merge([
            'type'   => 'landing',
            'slug'   => 'test-page-' . uniqid(),
            'title'  => 'Test Landing Page',
            'status' => 'published',
            'blocks' => [
                ['type' => 'hero', 'data' => ['title' => 'Hero Title', 'subtitle' => 'Hero subtitle text.']],
                ['type' => 'text', 'data' => ['heading' => 'About', 'body' => "First paragraph.\n\nSecond paragraph."]],
                ['type' => 'cta', 'data' => ['title' => 'Go', 'button_text' => 'Start', 'button_url' => '/register']],
            ],
        ], $attrs));
    }

    public function test_published_landing_page_renders_at_root_slug(): void
    {
        $page = $this->makePage(['slug' => 'virtual-galleries', 'title' => 'Virtual Galleries']);

        $response = $this->get('/virtual-galleries');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Virtual Galleries', $html);
        $this->assertStringContainsString('Hero Title', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/virtual-galleries">', $html);
        $this->assertStringNotContainsString('noindex', $html);
    }

    public function test_editorial_page_renders_under_resources_prefix(): void
    {
        $page = $this->makePage([
            'type'   => 'editorial',
            'slug'   => 'curating-guide',
            'title'  => 'How to Curate a Virtual Exhibition',
        ]);

        $response = $this->get('/resources/curating-guide');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('How to Curate a Virtual Exhibition', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/resources/curating-guide">', $html);
        $this->assertStringContainsString('"@type":"Article"', $html, 'Editorial pages emit Article schema.');
    }

    public function test_real_routes_always_win_over_seo_pages(): void
    {
        // A page trying to shadow an existing route slug.
        $this->makePage(['slug' => 'pricing', 'title' => 'Fake Pricing']);

        $response = $this->get('/pricing');

        $response->assertOk();
        $this->assertStringNotContainsString('Fake Pricing', $response->getContent(), 'Fallback route must not shadow real routes.');
    }

    public function test_unknown_path_still_404s(): void
    {
        $response = $this->get('/this-page-does-not-exist');

        $response->assertNotFound();
    }

    public function test_draft_page_404s_publicly(): void
    {
        $page = $this->makePage(['slug' => 'draft-page', 'status' => 'draft']);

        $response = $this->get('/draft-page');

        $response->assertNotFound();
    }

    public function test_draft_page_preview_renders_with_noindex(): void
    {
        $page = $this->makePage(['slug' => 'previewable-page', 'status' => 'draft']);
        $token = $page->previewToken();

        $response = $this->get("/previewable-page?preview={$token}");

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('noindex', $html, 'Previews are always noindex.');
        $this->assertStringContainsString('Preview', $html, 'Preview banner shown.');
    }

    public function test_invalid_preview_token_404s_draft(): void
    {
        $this->makePage(['slug' => 'locked-page', 'status' => 'draft']);

        $response = $this->get('/locked-page?preview=wrong-token');

        $response->assertNotFound();
    }

    public function test_scheduled_page_404s_until_due(): void
    {
        $this->makePage([
            'slug'         => 'future-page',
            'status'       => 'published',
            'published_at' => now()->addDays(7),
        ]);

        $response = $this->get('/future-page');

        $response->assertNotFound();
    }

    public function test_noindex_flag_honored(): void
    {
        $this->makePage(['slug' => 'noindex-page', 'noindex' => true]);

        $response = $this->get('/noindex-page');

        $response->assertOk();
        $this->assertStringContainsString('noindex,follow', $response->getContent());
    }

    public function test_unknown_block_types_are_dropped(): void
    {
        $this->makePage([
            'slug' => 'weird-blocks',
            'blocks' => [
                ['type' => 'hero', 'data' => ['title' => 'Real Hero']],
                ['type' => 'evil-raw-html', 'data' => ['body' => '<script>alert(1)</script>']],
            ],
        ]);

        $response = $this->get('/weird-blocks');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Real Hero', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'Non-allowlisted block types never render.');
        $this->assertStringNotContainsString('evil-raw-html', $html);
    }

    public function test_block_content_is_escaped(): void
    {
        $this->makePage([
            'slug' => 'xss-test',
            'blocks' => [
                ['type' => 'text', 'data' => ['heading' => '<b>Bold</b>', 'body' => '<img src=x onerror=alert(1)>']],
            ],
        ]);

        $response = $this->get('/xss-test');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringNotContainsString('<img src=x onerror', $html, 'Block data is escaped.');
    }

    public function test_live_exhibitions_block_renders_real_galleries(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::create([
            'user_id' => $user->id, 'title' => 'Real Live Show', 'slug' => 'real-live-show',
            'is_active' => true,
        ]);
        GalleryImage::create([
            'gallery_id' => $gallery->id, 'filename' => 'a.jpg', 'original_name' => 'a.jpg',
            'path' => 'artworks/a.jpg', 'mime_type' => 'image/jpeg', 'size' => 1,
            'width' => 100, 'height' => 100, 'orientation' => 'landscape',
        ]);

        $this->makePage([
            'slug' => 'live-content',
            'blocks' => [
                ['type' => 'exhibitions', 'data' => ['heading' => 'Live exhibitions']],
            ],
        ]);

        $response = $this->get('/live-content');

        $html = $response->getContent();
        $this->assertStringContainsString('Real Live Show', $html, 'Live block renders real gallery.');
        $this->assertStringContainsString('gallery/real-live-show', $html);
    }

    public function test_faq_block_emits_faqpage_schema(): void
    {
        $this->makePage([
            'slug' => 'faq-page-test',
            'blocks' => [
                ['type' => 'faq', 'data' => ['items' => [
                    ['question' => 'Is it free?', 'answer' => 'Yes, the starter plan is free.'],
                ]]],
            ],
        ]);

        $response = $this->get('/faq-page-test');

        $html = $response->getContent();
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('Is it free?', $html);
        $this->assertStringContainsString('Yes, the starter plan is free.', $html);
    }

    public function test_seo_title_and_meta_description_override(): void
    {
        $this->makePage([
            'slug'              => 'meta-test',
            'title'             => 'Display Title',
            'seo_title'         => 'Search Title',
            'meta_description'  => 'Custom meta description for search.',
        ]);

        $response = $this->get('/meta-test');

        $html = $response->getContent();
        $this->assertStringContainsString('<title>Search Title</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Custom meta description for search.">', $html);
        $this->assertStringContainsString('Display Title', $html, 'Visible heading keeps display title.');
    }

    public function test_canonical_override_applies(): void
    {
        $this->makePage([
            'slug'               => 'canonical-test',
            'canonical_override' => 'https://exospace.gallery/other-canonical',
        ]);

        $response = $this->get('/canonical-test');

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://exospace.gallery/other-canonical">',
            $response->getContent(),
        );
    }

    public function test_sitemap_content_group_lists_published_pages_only(): void
    {
        $published = $this->makePage(['slug' => 'sitemap-listed']);
        $this->makePage(['slug' => 'sitemap-draft', 'status' => 'draft']);

        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 50);

        $index = $this->get('/sitemap.xml');
        $this->assertStringContainsString('sitemap-content-1.xml', $index->getContent(), 'Content group appears once pages exist.');

        $response = $this->get('/sitemap-content-1.xml');
        $xml = $response->getContent();
        $this->assertStringContainsString('/sitemap-listed', $xml);
        $this->assertStringNotContainsString('/sitemap-draft', $xml, 'Drafts never appear in sitemaps.');
    }

    public function test_make_page_command_creates_draft(): void
    {
        $this->artisan('seo:make-page', ['slug' => 'command-made', '--type' => 'landing', '--title' => 'Command Made'])
            ->assertSuccessful();

        $page = SeoPage::where('slug', 'command-made')->first();

        $this->assertNotNull($page);
        $this->assertSame('draft', $page->status);
        $this->assertSame('landing', $page->type);
        $this->assertNotEmpty($page->blocks);
    }
}
