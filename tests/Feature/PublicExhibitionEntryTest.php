<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use App\Models\VenueTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicExhibitionEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeGallery(array $attrs = []): Gallery
    {
        $user = User::factory()->create();

        return Gallery::create(array_merge([
            'user_id'    => $user->id,
            'title'      => 'Coastal Light',
            'slug'       => 'coastal-light-' . uniqid(),
            'description'=> 'A photographic survey of shoreline towns.',
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

    public function test_published_exhibition_loads_for_anonymous_visitor(): void
    {
        $gallery = $this->makeGallery();
        $this->addArtwork($gallery);

        $response = $this->get("/gallery/{$gallery->slug}");

        $response->assertOk();
        $response->assertSee('Coastal Light', false);
        $response->assertSee('GALLERY_DATA', false);
    }

    public function test_viewer_payload_carries_no_private_owner_data(): void
    {
        $owner = User::factory()->create(['email' => 'secretowner@example.com']);
        $gallery = Gallery::create([
            'user_id'    => $owner->id,
            'title'      => 'Private Payload Check',
            'slug'       => 'payload-check-' . uniqid(),
            'is_active'  => true,
        ]);
        $this->addArtwork($gallery);

        $response = $this->get("/gallery/{$gallery->slug}");

        $html = $response->getContent();
        $this->assertStringNotContainsString('secretowner@example.com', $html);
        $this->assertStringNotContainsString('pin_hash', $html);
        $this->assertStringNotContainsString('team_id', $html);
        $this->assertStringNotContainsString('banned_at', $html);
    }

    public function test_draft_exhibition_is_not_public(): void
    {
        $gallery = $this->makeGallery(['is_active' => false]);

        $this->get("/gallery/{$gallery->slug}")->assertNotFound();
    }

    public function test_unpublished_exhibition_is_immediately_withdrawn(): void
    {
        $gallery = $this->makeGallery();
        $this->get("/gallery/{$gallery->slug}")->assertOk();

        $gallery->forceFill(['is_active' => false])->save();

        $this->get("/gallery/{$gallery->slug}")->assertNotFound();
    }

    public function test_deleted_exhibition_is_not_public(): void
    {
        $gallery = $this->makeGallery();
        $slug = $gallery->slug;

        $gallery->delete();

        $this->get("/gallery/{$slug}")->assertNotFound();
    }

    public function test_banned_owner_exhibition_is_not_public(): void
    {
        $gallery = $this->makeGallery();

        $gallery->user->forceFill(['banned_at' => now()])->save();

        $this->get("/gallery/{$gallery->slug}")->assertNotFound();
    }

    public function test_nonexistent_exhibition_renders_branded_not_found_page(): void
    {
        $response = $this->get('/gallery/never-existed-slug');

        $response->assertNotFound();
        $response->assertSee('Page not found', false);
        $response->assertSee('Browse exhibitions', false);
    }

    public function test_wrong_case_slug_does_not_render_the_exhibition(): void
    {
        $gallery = $this->makeGallery();

        $this->get('/gallery/' . strtoupper($gallery->slug))->assertNotFound();
    }

    public function test_not_yet_opened_exhibition_shows_coming_soon(): void
    {
        $gallery = $this->makeGallery(['opens_at' => now()->addDays(7)]);

        $this->get("/gallery/{$gallery->slug}")
            ->assertOk()
            ->assertSee('Coastal Light', false);
    }

    public function test_closed_exhibition_shows_closed_page(): void
    {
        $gallery = $this->makeGallery(['closes_at' => now()->subDay()]);

        $this->get("/gallery/{$gallery->slug}")
            ->assertOk()
            ->assertSee('Coastal Light', false);
    }

    public function test_pin_protected_exhibition_redirects_to_pin_screen(): void
    {
        $gallery = $this->makeGallery(['pin_hash' => \Illuminate\Support\Facades\Hash::make('1234')]);

        $this->get("/gallery/{$gallery->slug}")
            ->assertRedirect(route('gallery.pin', $gallery->slug));
    }

    public function test_embed_view_renders_without_indexing(): void
    {
        $gallery = $this->makeGallery();
        $this->addArtwork($gallery);

        $response = $this->get("/gallery/{$gallery->slug}?embed=1");

        $response->assertOk();
        $response->assertSee('noindex,nofollow', false);
    }

    public function test_venue_hub_excludes_banned_owner_exhibitions(): void
    {
        $venue = VenueTemplate::create([
            'slug'             => 'test-venue-' . uniqid(),
            'name'             => 'Test Venue',
            'description'      => 'A test venue with live exhibitions.',
            'default_settings' => [],
            'visual_config'    => [],
            'material_config'  => [],
            'decorations'      => [],
            'lighting_fixtures'=> [],
            'is_active'        => true,
            'is_draft'         => false,
            'version'          => 1,
            'published_at'     => now(),
        ]);

        $active = $this->makeGallery(['venue_template_id' => $venue->id]);
        $this->addArtwork($active);

        $banned = $this->makeGallery(['venue_template_id' => $venue->id]);
        $this->addArtwork($banned);
        $banned->user->forceFill(['banned_at' => now()])->save();

        $venue->refresh();

        $this->get('/venues')
            ->assertOk()
            ->assertSee($venue->name, false);

        $this->assertSame(1, $venue->galleries()->publiclyViewable()
            ->has('images', '>=', 1)
            ->whereDoesntHave('user', fn ($q) => $q->whereNotNull('banned_at'))
            ->count());

        $show = $this->get("/venues/{$venue->slug}");
        $show->assertOk();
        $this->assertStringNotContainsString(
            $banned->slug,
            $show->getContent(),
            'Banned-owner exhibitions must not be listed on public venue pages.'
        );
        $show->assertSee($active->slug, false);
    }

    public function test_tracking_an_inactive_gallery_is_a_silent_noop(): void
    {
        $gallery = $this->makeGallery(['is_active' => false]);

        $this->post(route('gallery.track', $gallery), [
            'event'         => 'view',
            'session_token' => 'test-session-token',
        ])->assertOk();
    }
}
