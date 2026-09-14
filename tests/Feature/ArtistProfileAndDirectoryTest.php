<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ArtistProfileAndDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://exospace.gallery']);
        \Illuminate\Support\Facades\URL::forceRootUrl('https://exospace.gallery');
        \Illuminate\Support\Facades\URL::forceScheme('https');
    }

    private function makePublicGallery(array $attrs = []): Gallery
    {
        $user = User::factory()->create();

        return Gallery::create(array_merge([
            'user_id'     => $user->id,
            'title'       => 'Public Show',
            'slug'        => 'public-show',
            'description' => 'An open exhibition.',
            'is_active'   => true,
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

    // ── Slug resolution ──────────────────────────────────────────────────

    public function test_second_artist_with_identical_name_gets_a_distinct_resolvable_slug(): void
    {
        $first = Artist::create(['name' => 'Jordan Lee']);
        $second = Artist::create(['name' => 'Jordan Lee']);

        $this->assertNotSame($first->slug, $second->slug);

        $this->get("/artist/{$first->slug}")->assertOk();
        $this->get("/artist/{$second->slug}")->assertOk();
    }

    public function test_non_ascii_artist_name_gets_a_resolvable_slug(): void
    {
        $artist = Artist::create(['name' => '李明']);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $artist->id]);

        $this->assertNotSame('', $artist->slug);

        $response = $this->get("/artist/{$artist->slug}");
        $response->assertOk();
        $response->assertSee('李明');
    }

    public function test_cleared_slug_is_regenerated_and_profile_stays_resolvable(): void
    {
        $artist = Artist::create(['name' => 'Casey Ray', 'slug' => 'custom-slug']);

        $artist->update(['slug' => null]);

        $this->assertNotSame('', (string) $artist->slug);
        $this->assertSame('casey-ray', $artist->slug);

        $this->get('/artist/casey-ray')->assertOk();
        $this->get('/artist/custom-slug')->assertNotFound();
    }

    public function test_admin_creating_an_artist_with_a_taken_slug_sees_a_validation_error(): void
    {
        Artist::create(['name' => 'Existing Artist', 'slug' => 'taken-slug']);
        $user = User::factory()->create();
        $user->markEmailAsVerified();

        $response = $this->actingAs($user)->post('/admin/artists', [
            'name' => 'New Artist',
            'slug' => 'taken-slug',
        ]);

        $response->assertSessionHasErrors('slug');
        $this->assertSame(1, Artist::where('slug', 'taken-slug')->count());
    }

    public function test_unknown_and_malformed_slugs_return_404(): void
    {
        $this->get('/artist/does-not-exist')->assertNotFound();
        $this->get('/artist/%3Cscript%3E')->assertNotFound();
    }

    // ── Public rendering ─────────────────────────────────────────────────

    public function test_social_handles_render_as_readable_labels(): void
    {
        $artist = Artist::create([
            'name'      => 'Handle Artist',
            'instagram' => 'maya.chen',
            'twitter'   => 'mayachen',
        ]);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $artist->id]);

        $html = $this->get("/artist/{$artist->slug}")->getContent();

        $this->assertStringContainsString('@maya.chen', $html);
        $this->assertStringContainsString('@mayachen', $html);
        $this->assertStringNotContainsString('{{ $artist->instagram }}', $html);
    }

    public function test_multibyte_initials_render_correctly(): void
    {
        $artist = Artist::create(['name' => 'Élodie Bernard']);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $artist->id]);

        $html = $this->get("/artist/{$artist->slug}")->getContent();

        $this->assertStringContainsString('ÉB', $html);
    }

    public function test_closed_exhibitions_are_excluded_from_profile(): void
    {
        $artist = Artist::create(['name' => 'Schedule Artist']);
        $open = $this->makePublicGallery(['slug' => 'open-show', 'title' => 'Open Show']);
        $this->addArtwork($open, ['artist_id' => $artist->id, 'title' => 'Visible Work']);

        $closed = $this->makePublicGallery([
            'slug'      => 'closed-show', 'title' => 'Closed Show',
            'closes_at' => now()->subDay(),
        ]);
        $this->addArtwork($closed, ['artist_id' => $artist->id, 'title' => 'Expired Work']);

        $html = $this->get("/artist/{$artist->slug}")->getContent();

        $this->assertStringContainsString('Visible Work', $html);
        $this->assertStringNotContainsString('Expired Work', $html);
        $this->assertStringNotContainsString('Closed Show', $html);
    }

    // ── Script-context safety of structured data ─────────────────────────

    public function test_artist_name_cannot_break_out_of_json_ld_script_tag(): void
    {
        $artist = Artist::create(['name' => 'Maya</script><script>alert(1)</script>']);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $artist->id]);

        $html = $this->get("/artist/{$artist->slug}")->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertStringContainsString('\u003C/script\u003E', $html);
    }

    public function test_non_http_website_scheme_is_not_rendered_as_a_link(): void
    {
        $artist = Artist::create([
            'name'    => 'Scheme Artist',
            'website' => 'javascript://%0Aalert(1)',
        ]);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $artist->id]);

        $html = $this->get("/artist/{$artist->slug}")->getContent();

        $this->assertStringNotContainsString('javascript://', $html);
        $this->assertStringNotContainsString('Website', $html);
    }

    // ── Directory ────────────────────────────────────────────────────────

    public function test_directory_lists_only_artists_with_public_works(): void
    {
        $visible = Artist::create(['name' => 'Visible Artist']);
        $hidden = Artist::create(['name' => 'Hidden Artist']);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $visible->id]);

        $html = $this->get('/artists')->getContent();

        $this->assertStringContainsString('Visible Artist', $html);
        $this->assertStringNotContainsString('Hidden Artist', $html);
    }

    public function test_directory_pagination_boundaries(): void
    {
        $gallery = $this->makePublicGallery();

        for ($i = 1; $i <= 25; $i++) {
            $artist = Artist::create(['name' => sprintf('Artist %02d', $i)]);
            $this->addArtwork($gallery, ['artist_id' => $artist->id]);
        }

        $pageOne = $this->get('/artists')->getContent();
        $this->assertStringContainsString('Artist 01', $pageOne);
        $this->assertStringContainsString('Artist 24', $pageOne);
        $this->assertStringNotContainsString('Artist 25', $pageOne);

        $pageTwo = $this->get('/artists?page=2')->getContent();
        $this->assertStringContainsString('Artist 25', $pageTwo);
        $this->assertStringNotContainsString('Artist 01', $pageTwo);

        $this->get('/artists?page=abc')->assertOk();
        $this->get('/artists?page=99999')->assertOk();
        $this->get('/artists?page=-4')->assertOk();
    }

    public function test_directory_links_resolve_to_the_correct_artist(): void
    {
        $artist = Artist::create(['name' => 'Linked Artist']);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $artist->id]);

        $html = $this->get('/artists')->getContent();

        $this->assertStringContainsString('href="https://exospace.gallery/artist/' . $artist->slug . '"', $html);
        $this->get("/artist/{$artist->slug}")->assertOk();
    }

    // ── Public API ───────────────────────────────────────────────────────

    public function test_artists_api_matches_public_eligibility_and_survives_malformed_per_page(): void
    {
        $visible = Artist::create(['name' => 'Api Visible']);
        $hidden = Artist::create(['name' => 'Api Hidden']);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $visible->id]);

        $this->getJson('/api/v1/artists')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Api Visible'])
            ->assertJsonMissing(['name' => 'Api Hidden']);

        $this->getJson('/api/v1/artists?per_page=0')->assertOk();
        $this->getJson('/api/v1/artists?per_page=-5')->assertOk();

        // The single-artist endpoint mirrors the public profile page: any
        // existing artist entity is resolvable, unknown slugs 404.
        $this->getJson("/api/v1/artists/{$visible->slug}")->assertOk();
        $this->getJson("/api/v1/artists/{$hidden->slug}")->assertOk();
        $this->getJson('/api/v1/artists/no-such-artist')->assertNotFound();
    }

    // ── Cache rotation ───────────────────────────────────────────────────

    public function test_seo_rebuild_rotates_related_content_cache_keys(): void
    {
        $artist = Artist::create(['name' => 'Cache Artist']);
        $gallery = $this->makePublicGallery();
        $this->addArtwork($gallery, ['artist_id' => $artist->id]);

        app(\App\Services\Seo\InternalLinkingService::class)->relatedArtists($artist);
        $this->get("/artist/{$artist->slug}")->assertOk();

        $this->artisan('seo:rebuild')->assertSuccessful();

        $this->assertGreaterThanOrEqual(2, (int) Cache::get('seo:related:version', 1));
    }
}
