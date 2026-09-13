<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoImprovementsTest extends TestCase
{
    use RefreshDatabase;

    public function guest_layout_has_noindex_meta(): void
    {
        $source = file_get_contents(resource_path('views/layouts/guest.blade.php'));
        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $source, 'I-1: guest layout must have noindex,nofollow meta');
    }

    public function app_layout_has_noindex_meta(): void
    {
        $source = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $source, 'I-1: app layout must have noindex,nofollow meta');
    }

    public function json_ld_component_exists_with_supported_types(): void
    {
        $source = file_get_contents(resource_path('views/components/json-ld.blade.php'));
        $this->assertStringContainsString("'organization'", $source, 'I-2: component supports organization type');
        $this->assertStringContainsString("'product'", $source, 'I-2: component supports product type');
        $this->assertStringContainsString("'faq-page'", $source, 'I-2: component supports faq-page type');
        $this->assertStringContainsString("'item-list'", $source, 'I-2: component supports item-list type');
        $this->assertStringContainsString('application/ld+json', $source, 'I-2: renders <script type="application/ld+json">');
    }

    public function homepage_renders_organization_json_ld(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('"@type": "Organization"', false);
        $response->assertSee('application/ld+json', false);
    }

    public function pricing_page_renders_product_and_faq_json_ld(): void
    {
        $response = $this->get('/pricing');
        $response->assertStatus(200);

        // Product schema (×2: Pro + Studio)
        $response->assertSee('"@type": "Product"', false);
        $response->assertSee('"@type": "Offer"', false);

        // FAQPage schema
        $response->assertSee('"@type": "FAQPage"', false);
        $response->assertSee('"@type": "Question"', false);
        $response->assertSee('Is there a free trial for Pro?', false);
    }

    public function discover_page_renders_item_list_json_ld_when_galleries_exist(): void
    {
        $gallery = Gallery::factory()->create([
            'is_active' => true,
        ]);
        \App\Models\GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
        ]);

        $response = $this->get('/discover');
        $response->assertStatus(200);
        $response->assertSee('"@type":"ItemList"', false);
        $response->assertSee('"@type":"ListItem"', false);
    }

    public function discover_page_omits_item_list_when_no_galleries(): void
    {
        $response = $this->get('/discover');
        $response->assertStatus(200);
        $response->assertDontSee('"@type": "ItemList"', false);
    }

    public function sitemap_controller_includes_changelog_and_status(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/SitemapController.php'));
        $this->assertStringContainsString("route('changelog')", $source, 'I-3: sitemap includes changelog route');
        $this->assertStringContainsString("route('status')", $source, 'I-3: sitemap includes status route');
    }

    public function sitemap_xml_contains_changelog_and_status_urls(): void
    {
        $response = $this->get('/sitemap-static-1.xml');
        $response->assertStatus(200);
        $response->assertSee('/changelog', false);
        $response->assertSee('/status', false);
    }

    public function public_layout_has_rss_auto_discovery_link(): void
    {
        $source = file_get_contents(resource_path('views/layouts/public.blade.php'));
        $this->assertStringContainsString('rel="alternate"', $source, 'I-6: public layout has alternate link');
        $this->assertStringContainsString('application/rss+xml', $source, 'I-6: link type is application/rss+xml');
        $this->assertStringContainsString('/feed.xml', $source, 'I-6: href points at /feed.xml');
    }

    public function homepage_renders_rss_auto_discovery_link(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('rel="alternate"', false);
        $response->assertSee('application/rss+xml', false);
        $response->assertSee('/feed.xml', false);
    }
}
