<?php

declare(strict_types=1);

/**
 * SEO OPERATING SYSTEM — Iteration 2 (public entity SEO) tests.
 *
 * Covers:
 *   - Artist profiles: indexable (no more noindex from guest layout), unique
 *     title/canonical, Person JSON-LD, breadcrumbs, noindex when no public works
 *   - Artist directory /artists: hub renders, canonical + pagination prev/next
 *   - Artwork pages: quality gate (indexable vs noindex), VisualArtwork schema,
 *     canonical URL shape, 404 on cross-gallery artwork access
 *   - Gallery view: canonical link present (audit C3), semantic layer in DOM,
 *     embed noindex, empty-gallery noindex
 *   - Venue pages: hub + detail, noindex for venue without exhibitions
 *   - Events page: no longer inside guest layout, noindex when empty
 *   - Discover: canonical policy (filtered → clean canonical + noindex,follow;
 *     paginated → self canonical with page param; default → clean)
 *   - /gallery/demo never leaks PIN-protected or scheduled galleries
 *
 * Run: php artisan test --filter=SeoEntityPagesTest
 */

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use App\Models\VenueTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeoEntityPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://exospace.gallery']);
        // ITERATION-1 FIX: config('app.url') alone does not change what
        // url() generates in feature tests — the UrlGenerator uses the
        // request root (http://localhost) unless the root is forced.
        // Without this, every canonical/OG assertion below compared the
        // rendered localhost URLs against exospace.gallery and failed.
        \Illuminate\Support\Facades\URL::forceRootUrl('https://exospace.gallery');
        \Illuminate\Support\Facades\URL::forceScheme('https');
    }

    private function makePublicGallery(array $attrs = []): Gallery
    {
        $user = User::factory()->create();

        return Gallery::create(array_merge([
            'user_id'    => $user->id,
            'title'      => 'Echoes of the Void',
            'slug'       => 'echoes-of-the-void',
            'description'=> 'A survey of new digital works exploring light and space.',
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

    // ── Artist profiles (audit C1) ──────────────────────────────────────

    public function test_artist_profile_is_indexable_with_unique_title_and_canonical(): void
    {
        $artist = Artist::create([
            'name' => 'Maya Chen',
            'bio'  => 'Berlin-based artist working with light and space.',
            'location' => 'Berlin, Germany',
        ]);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $artist->id, 'title' => 'Light Study']);

        $response = $this->get('/artist/maya-chen');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('noindex', $html, 'Artist profiles must NOT be noindexed (audit C1).');
        $this->assertStringContainsString('<title>Maya Chen — Artist Profile &amp; 3D Exhibitions</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/artist/maya-chen">', $html);
        $this->assertStringContainsString('"@type":"Person"', $html, 'Person JSON-LD must be present.');
        $this->assertStringContainsString('Maya Chen', $html);
        $this->assertStringContainsString('/artist/maya-chen/og-image', $html, 'Artist OG image endpoint referenced.');
    }

    public function test_artist_profile_without_public_works_is_noindex(): void
    {
        $artist = Artist::create(['name' => 'Empty Artist']);

        $response = $this->get('/artist/empty-artist');

        $response->assertOk();
        $this->assertStringContainsString('noindex,follow', $response->getContent());
    }

    public function test_artist_profile_does_not_expose_private_works(): void
    {
        $artist = Artist::create(['name' => 'Mixed Artist']);
        $public = $this->makePublicGallery();
        $this->addArtwork($public, ['artist_id' => $artist->id, 'title' => 'Public Work']);

        $pinGallery = $this->makePublicGallery([
            'slug' => 'pin-gallery', 'title' => 'Secret Show',
            'pin_hash' => \Illuminate\Support\Facades\Hash::make('1234'),
        ]);
        $this->addArtwork($pinGallery, ['artist_id' => $artist->id, 'title' => 'Private Work']);

        $response = $this->get('/artist/mixed-artist');
        $html = $response->getContent();

        $this->assertStringContainsString('Public Work', $html);
        $this->assertStringNotContainsString('Private Work', $html, 'PIN-protected works must not appear on artist profiles.');
        $this->assertStringNotContainsString('Secret Show', $html);
    }

    // ── Artist directory ────────────────────────────────────────────────

    public function test_artists_directory_renders_with_hub_metadata(): void
    {
        $artist = Artist::create(['name' => 'Directory Artist', 'location' => 'Oslo']);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $artist->id]);

        $response = $this->get('/artists');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Directory Artist', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/artists">', $html);
        $this->assertStringContainsString('href="https://exospace.gallery/artist/directory-artist"', $html);
    }

    public function test_artists_directory_page_two_self_canonicalizes(): void
    {
        $response = $this->get('/artists?page=2');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('href="https://exospace.gallery/artists?page=2"', $html);
        $this->assertStringContainsString('rel="prev"', $html);
    }

    // ── Artwork pages ───────────────────────────────────────────────────

    public function test_artwork_page_passing_quality_gate_is_indexable(): void
    {
        $artist = Artist::create(['name' => 'Maya Chen']);
        $gallery = $this->makePublicGallery();
        $artwork = $this->addArtwork($gallery, [
            'artist_id'   => $artist->id,
            'title'       => 'Light Study #4',
            'description' => 'An exploration of light through layered glass panels, part of an ongoing series.',
            'medium'      => 'Mixed media on glass',
            'year'        => 2024,
            'dimensions'  => '120 × 80 cm',
        ]);

        $response = $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}");

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('noindex', $html);
        $this->assertStringContainsString('<title>Light Study #4 by Maya Chen</title>', $html);
        $this->assertStringContainsString(
            '<link rel="canonical" href="https://exospace.gallery/gallery/echoes-of-the-void/artwork/' . $artwork->id . '">',
            $html,
        );
        $this->assertStringContainsString('"@type":"VisualArtwork"', $html);
        $this->assertStringContainsString('"artMedium":"Mixed media on glass"', $html);
        $this->assertStringContainsString('"size":"120 × 80 cm"', $html);
        $this->assertStringContainsString('href="https://exospace.gallery/artist/maya-chen"', $html, 'Artwork links to artist profile.');
        $this->assertStringContainsString('Maya Chen', $html);
    }

    public function test_artwork_page_failing_quality_gate_is_noindex(): void
    {
        // No artist, no medium, no year, no description → thin page.
        $gallery = $this->makePublicGallery();
        $artwork = $this->addArtwork($gallery, ['title' => 'Bare Work']);

        $response = $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}");

        $response->assertOk();
        $this->assertStringContainsString('noindex,follow', $response->getContent());
    }

    public function test_artwork_page_404s_when_artwork_belongs_to_another_gallery(): void
    {
        $galleryA = $this->makePublicGallery();
        $galleryB = $this->makePublicGallery(['slug' => 'other-show', 'title' => 'Other Show']);
        $artworkInB = $this->addArtwork($galleryB, ['title' => 'Not Here', 'artist_id' => Artist::create(['name' => 'X'])->id]);

        $response = $this->get("/gallery/{$galleryA->slug}/artwork/{$artworkInB->id}");

        $response->assertNotFound();
    }

    public function test_artwork_page_in_pin_gallery_redirects_to_pin_screen(): void
    {
        // ITERATION-3: the artwork page previously rendered the full
        // artwork with only a noindex tag — noindex stops crawlers, not
        // humans. It now enforces the same PIN gate as the gallery view.
        $gallery = $this->makePublicGallery([
            'slug' => 'locked-show', 'title' => 'Locked Show',
            'pin_hash' => \Illuminate\Support\Facades\Hash::make('1234'),
        ]);
        $artwork = $this->addArtwork($gallery, ['title' => 'Hidden', 'artist_id' => Artist::create(['name' => 'Y'])->id]);

        $response = $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}");

        $response->assertRedirect(route('gallery.pin', $gallery->slug));
    }

    // ── Gallery view canonical + semantic layer ─────────────────────────

    public function test_gallery_view_has_canonical_and_semantic_layer(): void
    {
        $artist = Artist::create(['name' => 'Maya Chen']);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['title' => 'Work One', 'artist_id' => $artist->id]);

        $response = $this->get("/gallery/{$gallery->slug}");

        $response->assertOk();
        $html = $response->getContent();

        // Audit C3: canonical link must exist.
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/gallery/echoes-of-the-void">', $html);
        // Audit H1: semantic layer.
        $this->assertStringContainsString('id="exhibition-details"', $html);
        $this->assertStringContainsString('Artworks in this exhibition', $html);
        $this->assertStringContainsString('Work One', $html);
        $this->assertStringContainsString('href="https://exospace.gallery/artist/maya-chen"', $html, 'Crawlable artist link in semantic layer.');
        $this->assertStringContainsString("/artwork/", $html, 'Crawlable artwork links in semantic layer.');
    }

    public function test_gallery_view_embed_mode_is_noindex(): void
    {
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['title' => 'Work One']);

        $response = $this->get("/gallery/{$gallery->slug}?embed=1");

        $response->assertOk();
        $this->assertStringContainsString('noindex,nofollow', $response->getContent());
        // Semantic layer suppressed in embed mode.
        $this->assertStringNotContainsString('id="exhibition-details"', $response->getContent());
    }

    public function test_empty_gallery_view_is_noindex(): void
    {
        $gallery = $this->makePublicGallery(['slug' => 'empty-show', 'title' => 'Empty Show']);
        // No artworks added.

        $response = $this->get("/gallery/{$gallery->slug}");

        $response->assertOk();
        $this->assertStringContainsString('noindex,follow', $response->getContent());
    }

    public function test_gallery_with_null_description_still_has_meta_description(): void
    {
        $gallery = $this->makePublicGallery(['slug' => 'no-desc', 'description' => null]);
        $this->addArtwork($gallery, ['title' => 'Work One']);

        $response = $this->get("/gallery/{$gallery->slug}");

        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]+"/', $html, 'Fallback description must be generated (audit M1).');
        $this->assertStringNotContainsString('<meta name="description" content="">', $html);
    }

    // ── Venue pages ─────────────────────────────────────────────────────

    public function test_venue_pages_render_with_metadata(): void
    {
        $venue = VenueTemplate::create([
            'name' => 'White Cube',
            'slug' => 'white-cube',
            'description' => 'A clean, minimal gallery space with pure white walls.',
            'category' => 'gallery',
            'is_active' => true,
            'is_draft' => false,
            // ITERATION-1 FIX: default_settings is NOT NULL in the schema.
            'default_settings' => ['wall_texture' => 'white', 'room_layout' => 'square'],
        ]);
        $gallery = $this->makePublicGallery(['venue_template_id' => $venue->id]);
        $this->addArtwork($gallery, ['title' => 'Work One']);

        $index = $this->get('/venues');
        $index->assertOk();
        $this->assertStringContainsString('White Cube', $index->getContent());
        $this->assertStringContainsString('href="https://exospace.gallery/venues/white-cube"', $index->getContent());

        $show = $this->get('/venues/white-cube');
        $show->assertOk();
        $html = $show->getContent();
        $this->assertStringContainsString('White Cube', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/venues/white-cube">', $html);
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertStringContainsString('Echoes of the Void', $html, 'Live exhibitions listed on venue page.');
    }

    public function test_venue_without_exhibitions_is_noindex(): void
    {
        VenueTemplate::create([
            'name' => 'Lonely Hall', 'slug' => 'lonely-hall',
            'description' => 'Empty venue.', 'category' => 'museum',
            'is_active' => true, 'is_draft' => false,
            // ITERATION-1 FIX: default_settings is NOT NULL in the schema.
            'default_settings' => ['wall_texture' => 'white', 'room_layout' => 'square'],
        ]);

        $response = $this->get('/venues/lonely-hall');

        $response->assertOk();
        $this->assertStringContainsString('noindex,follow', $response->getContent());
    }

    // ── Events page ─────────────────────────────────────────────────────

    public function test_events_page_has_seo_and_noindex_when_empty(): void
    {
        $gallery = $this->makePublicGallery();

        $response = $this->get("/gallery/{$gallery->slug}/events");

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringNotContainsString('x-guest-layout', $html);
        $this->assertStringContainsString('noindex,follow', $html, 'Empty events calendar is thin content.');
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/gallery/echoes-of-the-void/events">', $html);
    }

    // ── Discover canonical policy ───────────────────────────────────────

    public function test_discover_default_view_is_clean_canonical(): void
    {
        $response = $this->get('/discover');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/discover">', $html);
        $this->assertStringNotContainsString('name="robots"', $html);
    }

    public function test_discover_filtered_view_canonicalizes_to_clean_url_and_noindex(): void
    {
        $response = $this->get('/discover?sort=views&venue=3&utm_source=x');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/discover">', $html, 'Filtered views canonicalize to the clean hub URL.');
        $this->assertStringContainsString('noindex,follow', $html);
    }

    public function test_discover_paginated_view_self_canonicalizes(): void
    {
        $response = $this->get('/discover?page=2');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://exospace.gallery/discover?page=2">', $html);
        $this->assertStringContainsString('rel="prev"', $html);
        $this->assertStringNotContainsString('noindex', $html);
    }

    // ── Demo route quality (audit M9) ───────────────────────────────────

    public function test_demo_route_skips_pin_protected_galleries(): void
    {
        $pinGallery = $this->makePublicGallery([
            'slug' => 'first-pin', 'title' => 'PIN Show',
            'pin_hash' => \Illuminate\Support\Facades\Hash::make('9999'),
        ]);
        $this->addArtwork($pinGallery, ['title' => 'Locked']);

        $openGallery = $this->makePublicGallery(['slug' => 'first-open', 'title' => 'Open Show']);
        $this->addArtwork($openGallery, ['title' => 'Free']);

        $response = $this->get('/gallery/demo');

        $response->assertRedirect();
        $this->assertStringNotContainsString('first-pin', $response->headers->get('Location') ?? '');
    }

    // ── Quality gate unit-level ─────────────────────────────────────────

    public function test_quality_gate_logic(): void
    {
        $gallery = $this->makePublicGallery();

        $thin = $this->addArtwork($gallery, ['title' => 'Only Title']);
        $this->assertFalse(\App\Http\Controllers\ArtworkController::passesQualityGate($thin));

        $withArtist = $this->addArtwork($gallery, ['title' => 'With Artist', 'artist_id' => Artist::create(['name' => 'A'])->id]);
        $this->assertTrue(\App\Http\Controllers\ArtworkController::passesQualityGate($withArtist));

        $withMedium = $this->addArtwork($gallery, ['title' => 'With Medium', 'medium' => 'Oil on canvas']);
        $this->assertTrue(\App\Http\Controllers\ArtworkController::passesQualityGate($withMedium));

        $withDescription = $this->addArtwork($gallery, [
            'title' => 'With Desc',
            'description' => str_repeat('meaningful ', 10), // > 80 chars
        ]);
        $this->assertTrue(\App\Http\Controllers\ArtworkController::passesQualityGate($withDescription));
    }
}
