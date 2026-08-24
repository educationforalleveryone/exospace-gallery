<?php

declare(strict_types=1);

/**
 * SEO OPERATING SYSTEM — Iteration 3 (structured data + internal linking) tests.
 *
 * Covers:
 *   - SchemaBuilder: every schema type built from real entity data; no
 *     fabricated properties; correct types
 *   - InternalLinkingService: related galleries (shared-artist relevance,
 *     excludes private/empty/self, caps at limit), related artists
 *     (shared exhibitions), related artworks (same artist, other galleries)
 *   - Wiring: gallery page emits builder graphs (ExhibitionEvent for dated,
 *     CollectionPage for undated; ItemList with artwork URLs), artist page
 *     Person + ItemList, artwork page VisualArtwork, discover CollectionPage
 *   - Welcome page: real featured galleries render + WebSite schema
 *
 * Run: php artisan test --filter=SeoSchemaAndLinkingTest
 */

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use App\Services\Seo\InternalLinkingService;
use App\Services\Seo\SchemaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoSchemaAndLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://exospace.gallery']);
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

    // ── SchemaBuilder ───────────────────────────────────────────────────

    public function test_organization_schema_is_real_and_complete(): void
    {
        $schema = app(SchemaBuilder::class)->organization();

        $this->assertSame('Organization', $schema['@type']);
        $this->assertSame('Exospace', $schema['name']);
        $this->assertSame('https://exospace.gallery', $schema['url']);
        $this->assertArrayNotHasKey('sameAs', $schema, 'No fabricated social profiles.');
    }

    public function test_organization_schema_reads_sameas_from_config(): void
    {
        config(['seo.organization.same_as' => ['https://twitter.com/exospace', ' ']]);

        $schema = app(SchemaBuilder::class)->organization();

        $this->assertSame(['https://twitter.com/exospace'], $schema['sameAs'], 'Blank entries filtered.');
    }

    public function test_website_schema_has_no_search_action(): void
    {
        $schema = app(SchemaBuilder::class)->webSite();

        $this->assertSame('WebSite', $schema['@type']);
        $this->assertArrayNotHasKey('potentialAction', $schema, 'No SearchAction — no site search exists (no fabricated endpoints).');
    }

    public function test_person_schema_maps_real_artist_columns(): void
    {
        $artist = Artist::create([
            'name'     => 'Maya Chen',
            'bio'      => 'Berlin-based artist.',
            'location' => 'Berlin, Germany',
            'website'  => 'https://mayachen.example.com',
        ]);

        $schema = app(SchemaBuilder::class)->person($artist);

        $this->assertSame('Person', $schema['@type']);
        $this->assertSame('Maya Chen', $schema['name']);
        $this->assertSame('Berlin-based artist.', $schema['description']);
        $this->assertSame('Berlin, Germany', $schema['homeLocation']['name']);
        $this->assertSame(['https://mayachen.example.com'], $schema['sameAs']);
        $this->assertSame('https://exospace.gallery/artist/maya-chen', $schema['url']);
    }

    public function test_person_schema_omits_missing_data(): void
    {
        $artist = Artist::create(['name' => 'Minimal']);

        $schema = app(SchemaBuilder::class)->person($artist);

        $this->assertArrayNotHasKey('description', $schema);
        $this->assertArrayNotHasKey('homeLocation', $schema);
        $this->assertArrayNotHasKey('sameAs', $schema);
        $this->assertArrayNotHasKey('image', $schema);
    }

    public function test_exhibition_event_schema_for_dated_gallery(): void
    {
        $gallery = $this->makePublicGallery([
            'opens_at'  => now()->addDays(7),
            'closes_at' => now()->addDays(37),
        ]);

        $schema = app(SchemaBuilder::class)->exhibitionEvent($gallery);

        $this->assertSame('ExhibitionEvent', $schema['@type']);
        $this->assertSame('https://schema.org/EventScheduled', $schema['eventStatus']);
        $this->assertArrayHasKey('startDate', $schema);
        $this->assertArrayHasKey('endDate', $schema);
        $this->assertSame('VirtualLocation', $schema['location']['@type']);
    }

    public function test_visual_artwork_schema_uses_size_not_artwork_surface(): void
    {
        $artist = Artist::create(['name' => 'M']);
        $gallery = $this->makePublicGallery();
        $artwork = $this->addArtwork($gallery, [
            'artist_id'  => $artist->id,
            'title'      => 'Light Study',
            'medium'     => 'Oil on canvas',
            'year'       => 2024,
            'dimensions' => '120 × 80 cm',
            'for_sale'   => true,
            'price'      => 2500.00,
            'currency'   => 'USD',
        ]);

        $schema = app(SchemaBuilder::class)->visualArtwork($artwork, $gallery);

        $this->assertSame('VisualArtwork', $schema['@type']);
        $this->assertSame('120 × 80 cm', $schema['size']);
        $this->assertArrayNotHasKey('artworkSurface', $schema, 'audit M6: dimensions map to size, not artworkSurface.');
        $this->assertSame('Oil on canvas', $schema['artMedium']);
        $this->assertSame('2024', $schema['dateCreated']);
        $this->assertSame('2500.00', $schema['offers']['price']);
        $this->assertSame('USD', $schema['offers']['priceCurrency']);
        $this->assertSame('M', $schema['creator']['name'], 'Creator maps to the real artist.');
        $this->assertStringContainsString('/artist/m', $schema['creator']['url']);
    }

    public function test_visual_artwork_without_price_has_no_offers(): void
    {
        $gallery = $this->makePublicGallery();
        $artwork = $this->addArtwork($gallery, ['title' => 'Not For Sale', 'for_sale' => false]);

        $schema = app(SchemaBuilder::class)->visualArtwork($artwork, $gallery);

        $this->assertArrayNotHasKey('offers', $schema, 'No fabricated prices — offers only when for_sale + price.');
    }

    public function test_artwork_item_list_contains_artwork_urls(): void
    {
        $gallery = $this->makePublicGallery();
        $a1 = $this->addArtwork($gallery, ['title' => 'One']);
        $a2 = $this->addArtwork($gallery, ['title' => 'Two']);

        $schema = app(SchemaBuilder::class)->artworkItemList($gallery, [$a1, $a2], 2);

        $this->assertSame('ItemList', $schema['@type']);
        $this->assertSame(2, $schema['numberOfItems']);
        $this->assertSame(
            "https://exospace.gallery/gallery/{$gallery->slug}/artwork/{$a1->id}",
            $schema['itemListElement'][0]['url'],
        );
    }

    public function test_hub_collection_page_caps_items_at_25(): void
    {
        $gallery = $this->makePublicGallery();
        $items = collect(range(1, 40))->map(fn ($i) => $this->makePublicGallery(['title' => "G{$i}"]));

        $schema = app(SchemaBuilder::class)->hubCollectionPage('Test Hub', 'https://exospace.gallery/hub', $items);

        $this->assertCount(25, $schema['mainEntity']['itemListElement']);
    }

    // ── InternalLinkingService ──────────────────────────────────────────

    public function test_related_galleries_rank_shared_artists_first(): void
    {
        $artist = Artist::create(['name' => 'Shared Artist']);
        $otherArtist = Artist::create(['name' => 'Other Artist']);

        $subject = $this->makePublicGallery(['title' => 'Subject']);
        $this->addArtwork($subject, ['artist_id' => $artist->id]);

        $shared = $this->makePublicGallery(['title' => 'Shared Show', 'view_count' => 5]);
        $this->addArtwork($shared, ['artist_id' => $artist->id]);

        $notShared = $this->makePublicGallery(['title' => 'Unrelated Show', 'view_count' => 5000]);
        $this->addArtwork($notShared, ['artist_id' => $otherArtist->id]);

        $related = app(InternalLinkingService::class)->relatedGalleries($subject, 2);

        $this->assertSame('Shared Show', $related->first()->title, 'Shared-artist gallery outranks more-viewed unrelated gallery.');
        $this->assertNotEquals($subject->id, $related->first()->id, 'Self is excluded.');
    }

    public function test_related_galleries_exclude_private_and_empty(): void
    {
        $artist = Artist::create(['name' => 'A']);
        $subject = $this->makePublicGallery(['title' => 'Subject']);
        $this->addArtwork($subject, ['artist_id' => $artist->id]);

        $pinGallery = $this->makePublicGallery([
            'title' => 'PIN Show',
            'pin_hash' => \Illuminate\Support\Facades\Hash::make('1234'),
        ]);
        $this->addArtwork($pinGallery, ['artist_id' => $artist->id]);

        $emptyGallery = $this->makePublicGallery(['title' => 'Empty Show']);

        $related = app(InternalLinkingService::class)->relatedGalleries($subject);

        $titles = $related->pluck('title');
        $this->assertFalse($titles->contains('PIN Show'), 'PIN-protected galleries are never linked.');
        $this->assertFalse($titles->contains('Empty Show'), 'Empty galleries are never linked.');
    }

    public function test_related_galleries_respects_limit(): void
    {
        $artist = Artist::create(['name' => 'Prolific']);
        $subject = $this->makePublicGallery(['title' => 'Subject']);
        $this->addArtwork($subject, ['artist_id' => $artist->id]);

        for ($i = 1; $i <= 10; $i++) {
            $g = $this->makePublicGallery(['title' => "Show {$i}"]);
            $this->addArtwork($g, ['artist_id' => $artist->id]);
        }

        $related = app(InternalLinkingService::class)->relatedGalleries($subject);

        $this->assertCount(6, $related, 'Default cap is 6 (config seo.related.galleries_max).');
    }

    public function test_related_artists_share_exhibitions(): void
    {
        $a = Artist::create(['name' => 'Artist A']);
        $b = Artist::create(['name' => 'Artist B']);
        $c = Artist::create(['name' => 'Artist C (no shared show)']);

        $sharedShow = $this->makePublicGallery(['title' => 'Shared Show']);
        $this->addArtwork($sharedShow, ['artist_id' => $a->id]);
        $this->addArtwork($sharedShow, ['artist_id' => $b->id]);

        $soloShow = $this->makePublicGallery(['title' => 'Solo Show']);
        $this->addArtwork($soloShow, ['artist_id' => $c->id]);

        $related = app(InternalLinkingService::class)->relatedArtists($a);

        $names = $related->pluck('name');
        $this->assertTrue($names->contains('Artist B'));
        $this->assertFalse($names->contains('Artist C'), 'No shared exhibition → not related.');
    }

    public function test_related_artworks_cross_galleries_only(): void
    {
        $artist = Artist::create(['name' => 'AA']);
        $galleryA = $this->makePublicGallery(['title' => 'A']);
        $galleryB = $this->makePublicGallery(['title' => 'B']);
        $pinGallery = $this->makePublicGallery([
            'title' => 'PIN',
            'pin_hash' => \Illuminate\Support\Facades\Hash::make('1'),
        ]);

        $current = $this->addArtwork($galleryA, ['artist_id' => $artist->id, 'title' => 'Current']);
        $sameGallery = $this->addArtwork($galleryA, ['artist_id' => $artist->id, 'title' => 'Same Gallery']);
        $otherPublic = $this->addArtwork($galleryB, ['artist_id' => $artist->id, 'title' => 'Other Public']);
        $inPin = $this->addArtwork($pinGallery, ['artist_id' => $artist->id, 'title' => 'In PIN']);

        $related = app(InternalLinkingService::class)->relatedArtworks($current);

        $titles = $related->pluck('title');
        $this->assertTrue($titles->contains('Other Public'));
        $this->assertFalse($titles->contains('Same Gallery'), 'Same-gallery works are covered by the siblings section.');
        $this->assertFalse($titles->contains('In PIN'), 'Works in private galleries are never linked.');
        $this->assertFalse($titles->contains('Current'));
    }

    public function test_related_artworks_without_artist_returns_empty(): void
    {
        $gallery = $this->makePublicGallery();
        $artwork = $this->addArtwork($gallery, ['title' => 'No Artist']);

        $this->assertTrue(app(InternalLinkingService::class)->relatedArtworks($artwork)->isEmpty());
    }

    // ── Controller wiring ───────────────────────────────────────────────

    public function test_gallery_page_emits_dated_and_undated_graphs_correctly(): void
    {
        // Undated gallery → CollectionPage (not a fake event)
        $undated = $this->makePublicGallery(['title' => 'Permanent Collection']);
        $a = $this->addArtwork($undated, ['title' => 'Work']);
        $response = $this->get("/gallery/{$undated->slug}");
        $html = $response->getContent();
        $this->assertStringContainsString('"@type":"CollectionPage"', $html);
        $this->assertStringNotContainsString('ExhibitionEvent', $html);

        // Dated gallery → ExhibitionEvent
        $dated = $this->makePublicGallery([
            'title' => 'Dated Show',
            'opens_at' => now()->subDays(1),
            'closes_at' => now()->addDays(30),
        ]);
        $this->addArtwork($dated, ['title' => 'Dated Work']);
        $response2 = $this->get("/gallery/{$dated->slug}");
        $html2 = $response2->getContent();
        $this->assertStringContainsString('"@type":"ExhibitionEvent"', $html2);
        $this->assertStringContainsString('"@type":"ItemList"', $html2);
        $this->assertStringContainsString("/artwork/", $html2);
    }

    public function test_gallery_page_shows_related_exhibitions(): void
    {
        $artist = Artist::create(['name' => 'R']);
        $subject = $this->makePublicGallery(['title' => 'Subject Show']);
        $this->addArtwork($subject, ['artist_id' => $artist->id]);

        $related = $this->makePublicGallery(['title' => 'Related Show']);
        $this->addArtwork($related, ['artist_id' => $artist->id]);

        $response = $this->get("/gallery/{$subject->slug}");

        $html = $response->getContent();
        $this->assertStringContainsString('Related exhibitions', $html);
        $this->assertStringContainsString('Related Show', $html);
        $this->assertStringContainsString("gallery/{$related->slug}", $html);
    }

    public function test_artist_page_shows_related_artists(): void
    {
        $a = Artist::create(['name' => 'Alpha One']);
        $b = Artist::create(['name' => 'Beta Two']);

        $show = $this->makePublicGallery(['title' => 'Together']);
        $this->addArtwork($show, ['artist_id' => $a->id]);
        $this->addArtwork($show, ['artist_id' => $b->id]);

        $response = $this->get('/artist/alpha-one');

        $html = $response->getContent();
        $this->assertStringContainsString('Beta Two', $html, 'Related artist appears on profile.');
        $this->assertStringContainsString('"@type":"Person"', $html);
    }

    public function test_artwork_page_links_cross_gallery_works(): void
    {
        $artist = Artist::create(['name' => 'Cross']);
        $galleryA = $this->makePublicGallery(['title' => 'Show A']);
        $galleryB = $this->makePublicGallery(['title' => 'Show B']);

        $current = $this->addArtwork($galleryA, ['artist_id' => $artist->id, 'title' => 'Origin']);
        $other = $this->addArtwork($galleryB, ['artist_id' => $artist->id, 'title' => 'Elsewhere']);

        $response = $this->get("/gallery/{$galleryA->slug}/artwork/{$current->id}");

        $html = $response->getContent();
        $this->assertStringContainsString('More by Cross', $html);
        $this->assertStringContainsString("gallery/{$galleryB->slug}/artwork/{$other->id}", $html);
        $this->assertStringContainsString('"@type":"VisualArtwork"', $html);
    }

    public function test_discover_first_page_emits_collection_page_schema(): void
    {
        $gallery = $this->makePublicGallery(['title' => 'Schema Test Show']);
        $this->addArtwork($gallery, ['title' => 'Work']);

        $response = $this->get('/discover');

        $html = $response->getContent();
        $this->assertStringContainsString('"@type":"CollectionPage"', $html);
        $this->assertStringContainsString('Schema Test Show', $html);
    }

    public function test_welcome_page_renders_real_featured_galleries(): void
    {
        $gallery = $this->makePublicGallery(['title' => 'Real Featured Show', 'is_featured' => true]);
        $this->addArtwork($gallery, ['title' => 'Work']);

        $response = $this->get('/');

        $html = $response->getContent();
        $this->assertStringContainsString('Real Featured Show', $html, 'Real featured gallery replaces placeholder (audit M10).');
        $this->assertStringContainsString("gallery/{$gallery->slug}", $html, 'Links point at the real gallery URL.');
        $this->assertStringContainsString('"@type":"WebSite"', $html);
        $this->assertStringContainsString('"@type":"Organization"', $html);
    }
}
