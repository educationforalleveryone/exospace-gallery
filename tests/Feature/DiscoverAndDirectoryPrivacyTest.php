<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DiscoverAndDirectoryPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeGallery(User $user, array $attrs = []): Gallery
    {
        return Gallery::create(array_merge([
            'user_id' => $user->id,
            'title' => 'A Show',
            'slug' => 'a-show-'.uniqid(),
            'is_active' => true,
        ], $attrs));
    }

    private function addArtwork(Gallery $gallery, array $attrs = []): GalleryImage
    {
        return GalleryImage::create(array_merge([
            'gallery_id' => $gallery->id,
            'filename' => 'artwork.jpg',
            'original_name' => 'artwork.jpg',
            'path' => 'artworks/artwork.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'width' => 1200,
            'height' => 800,
            'orientation' => 'landscape',
        ], $attrs));
    }

    #[Test]
    public function pin_protected_galleries_never_appear_on_discover(): void
    {
        $open = $this->makeGallery(User::factory()->create(), ['title' => 'Open Show']);
        $this->addArtwork($open);

        $pinned = $this->makeGallery(User::factory()->create(), [
            'title' => 'Secret Show',
            'pin_hash' => 'hash',
        ]);
        $this->addArtwork($pinned);

        $html = $this->get('/discover')->getContent();

        $this->assertStringContainsString('Open Show', $html);
        $this->assertStringNotContainsString('Secret Show', $html);
    }

    #[Test]
    public function galleries_of_banned_owners_are_hidden_from_discover(): void
    {
        $visible = $this->makeGallery(User::factory()->create(), ['title' => 'Visible Show']);
        $this->addArtwork($visible);

        $bannedOwner = User::factory()->create(['banned_at' => now(), 'ban_reason' => 'spam']);
        $hidden = $this->makeGallery($bannedOwner, ['title' => 'Banned Owner Show']);
        $this->addArtwork($hidden);

        $html = $this->get('/discover')->getContent();

        $this->assertStringContainsString('Visible Show', $html);
        $this->assertStringNotContainsString('Banned Owner Show', $html);
    }

    #[Test]
    public function discover_venue_filter_limits_results_to_that_venue(): void
    {
        $venueA = \App\Models\VenueTemplate::factory()->create(['is_draft' => false, 'name' => 'White Cube']);
        $venueB = \App\Models\VenueTemplate::factory()->create(['is_draft' => false, 'name' => 'Dark Museum']);

        $inVenue = $this->makeGallery(User::factory()->create(), ['title' => 'Venue A Show', 'venue_template_id' => $venueA->id]);
        $this->addArtwork($inVenue);

        $otherVenue = $this->makeGallery(User::factory()->create(), ['title' => 'Venue B Show', 'venue_template_id' => $venueB->id]);
        $this->addArtwork($otherVenue);

        $html = $this->get('/discover?venue='.$venueA->id)->getContent();

        $this->assertStringContainsString('Venue A Show', $html);
        $this->assertStringNotContainsString('Venue B Show', $html);
    }

    #[Test]
    public function discover_sorting_by_newest_puts_latest_gallery_first(): void
    {
        $older = $this->makeGallery(User::factory()->create(), ['title' => 'Older Show']);
        $older->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        $this->addArtwork($older);

        $newer = $this->makeGallery(User::factory()->create(), ['title' => 'Newer Show']);
        $newer->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        $this->addArtwork($newer);

        $html = $this->get('/discover?sort=newest')->getContent();

        $this->assertStringContainsString('Newer Show', $html);
        $this->assertStringContainsString('Older Show', $html);
        $this->assertTrue(
            mb_strpos($html, 'Newer Show') < mb_strpos($html, 'Older Show'),
            'Newest sort must list the newest gallery first.'
        );
    }

    #[Test]
    public function discover_empty_state_renders_when_nothing_is_public(): void
    {
        $response = $this->get('/discover');

        $response->assertOk();
        $response->assertSee('No exhibitions match');
        $response->assertSee(route('register'), false);
    }

    #[Test]
    public function discover_handles_malformed_filters_without_errors(): void
    {
        $response = $this->get('/discover?venue=not-a-number&sort=weird&page=-3');

        $response->assertOk();
    }

    #[Test]
    public function banned_owner_works_do_not_leak_through_artist_profile(): void
    {
        $artist = Artist::create(['name' => 'Cross Surface Artist']);

        $bannedOwner = User::factory()->create(['banned_at' => now(), 'ban_reason' => 'spam']);
        $bannedGallery = $this->makeGallery($bannedOwner, ['title' => 'Banned Owner Show']);
        $this->addArtwork($bannedGallery, ['artist_id' => $artist->id, 'title' => 'Banned Owner Work']);

        $openGallery = $this->makeGallery(User::factory()->create(), ['title' => 'Open Show']);
        $this->addArtwork($openGallery, ['artist_id' => $artist->id, 'title' => 'Open Work']);

        $html = $this->get("/artist/{$artist->slug}")->getContent();

        $this->assertStringContainsString('Open Work', $html);
        $this->assertStringNotContainsString('Banned Owner Work', $html);
        $this->assertStringNotContainsString('Banned Owner Show', $html);
    }

    #[Test]
    public function banned_owner_works_do_not_inflate_the_directory_or_its_counts(): void
    {
        $artist = Artist::create(['name' => 'Counted Artist']);

        $bannedOwner = User::factory()->create(['banned_at' => now(), 'ban_reason' => 'spam']);
        $bannedGallery = $this->makeGallery($bannedOwner, ['title' => 'Banned Owner Show']);
        $this->addArtwork($bannedGallery, ['artist_id' => $artist->id]);

        $openGallery = $this->makeGallery(User::factory()->create(), ['title' => 'Open Show']);
        $this->addArtwork($openGallery, ['artist_id' => $artist->id]);

        $html = $this->get('/artists')->getContent();

        $this->assertStringContainsString('Counted Artist', $html, 'The artist still has one public work.');
        $this->assertStringContainsString('1 artwork', $html, 'Only the public work may count toward the directory.');

        // The banned owner's gallery must not be listed through the artist API either.
        $api = $this->getJson('/api/v1/artists');
        $api->assertOk();
        $api->assertJsonCount(1, 'data');
    }

    #[Test]
    public function artist_directory_empty_state_offers_discover_alternative(): void
    {
        $response = $this->get('/artists');

        $response->assertOk();
        $response->assertSee('No artists are on public display yet.');
        $response->assertSee(route('discover'), false);
    }

    #[Test]
    public function artist_profile_with_only_hidden_works_stays_inaccessible_to_directory_but_renders_noindex(): void
    {
        $artist = Artist::create(['name' => 'Only Hidden Works']);

        $pinned = $this->makeGallery(User::factory()->create(), ['pin_hash' => 'hash', 'title' => 'Pinned Show']);
        $this->addArtwork($pinned, ['artist_id' => $artist->id]);

        // The artist must not appear in the directory.
        $directory = $this->get('/artists')->getContent();
        $this->assertStringNotContainsString('Only Hidden Works', $directory);

        // The direct profile renders as an empty, non-indexable page.
        $response = $this->get("/artist/{$artist->slug}");
        $response->assertOk();
        $this->assertStringContainsString('No public artworks yet', $response->getContent());
        $this->assertStringContainsString('noindex', $response->getContent());
    }

    #[Test]
    public function artists_api_gallery_listing_excludes_banned_owner_galleries(): void
    {
        $artist = Artist::create(['name' => 'Api Surface Artist']);

        $bannedOwner = User::factory()->create(['banned_at' => now(), 'ban_reason' => 'spam']);
        $bannedGallery = $this->makeGallery($bannedOwner, ['title' => 'Banned Owner Show']);
        $this->addArtwork($bannedGallery, ['artist_id' => $artist->id]);

        $openGallery = $this->makeGallery(User::factory()->create(), ['title' => 'Open Show']);
        $this->addArtwork($openGallery, ['artist_id' => $artist->id]);

        $response = $this->getJson("/api/v1/artists/{$artist->slug}/galleries");

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertContains('Open Show', $titles);
        $this->assertNotContains('Banned Owner Show', $titles);
    }
}
