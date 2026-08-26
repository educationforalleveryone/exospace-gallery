<?php

declare(strict_types=1);

/**
 * ITERATION-3 — Public-experience hardening: access-control tests.
 *
 * The artwork page and the public events page previously leaked gated
 * exhibition content with only a noindex meta tag as mitigation:
 *   - artwork pages rendered in FULL for PIN-protected, unpublished and
 *     not-yet-opened galleries (noindex stops crawlers, not humans);
 *   - the events page + RSVP form were fully public for PIN galleries.
 *
 * These tests pin the gating matrix that now mirrors the gallery view's
 * own visibility rules:
 *
 *   gallery state            artwork page        events page
 *   ───────────────────      ──────────────      ─────────────
 *   draft / unpublished      404                 404 (is_active query)
 *   not yet open             redirect → gallery  public (RSVP surface)
 *   closed                   redirect → gallery  redirect → gallery
 *   PIN, session unverified  redirect → PIN      redirect → PIN
 *   PIN, session verified    full page noindex   full page
 *
 * Run: php artisan test --filter=PublicAccessControlTest
 */

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\GalleryScheduleEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicAccessControlTest extends TestCase
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
            'title'      => 'Gated Show',
            'slug'       => 'gated-show-' . uniqid(),
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
            'title'         => 'Hidden Masterpiece',
            'description'   => 'A richly documented work with enough descriptive depth to pass the quality gate.',
        ], $attrs));
    }

    private function pinGallery(array $attrs = []): Gallery
    {
        return $this->makeGallery(array_merge([
            'pin_hash' => Hash::make('1234'),
        ], $attrs));
    }

    // ── Artwork page: state matrix ───────────────────────────────────────

    public function test_artwork_page_is_public_for_open_gallery(): void
    {
        $gallery = $this->makeGallery();
        $artwork = $this->addArtwork($gallery);

        $response = $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}");

        $response->assertOk();
        $response->assertSee('Hidden Masterpiece', false);
    }

    public function test_artwork_page_404s_for_draft_gallery(): void
    {
        $gallery = $this->makeGallery(['is_active' => false]);
        $artwork = $this->addArtwork($gallery);

        $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}")
            ->assertNotFound();
    }

    public function test_artwork_page_404s_after_unpublish(): void
    {
        // The Iteration-2 promise: unpublishing must withdraw the public
        // URL. Before Iteration-3 the gallery page 404'd but the artwork
        // deep link kept serving the content.
        $gallery = $this->makeGallery();
        $artwork = $this->addArtwork($gallery);

        $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}")->assertOk();

        $gallery->forceFill(['is_active' => false])->save();

        $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}")
            ->assertNotFound();
    }

    public function test_artwork_page_redirects_to_gallery_when_not_yet_open(): void
    {
        $gallery = $this->makeGallery(['opens_at' => now()->addDays(7)]);
        $artwork = $this->addArtwork($gallery);

        $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}")
            ->assertRedirect(route('gallery.view', $gallery->slug));
    }

    public function test_artwork_page_redirects_to_gallery_when_closed(): void
    {
        $gallery = $this->makeGallery(['closes_at' => now()->subDay()]);
        $artwork = $this->addArtwork($gallery);

        $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}")
            ->assertRedirect(route('gallery.view', $gallery->slug));
    }

    // ── Artwork page: PIN gating ─────────────────────────────────────────

    public function test_artwork_page_redirects_to_pin_screen_for_pin_gallery(): void
    {
        $gallery = $this->pinGallery();
        $artwork = $this->addArtwork($gallery);

        $response = $this->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}");

        $response->assertRedirect(route('gallery.pin', $gallery->slug));
        $this->assertStringNotContainsString('Hidden Masterpiece', $response->getContent());
    }

    public function test_artwork_page_renders_after_pin_verification_but_stays_noindex(): void
    {
        $gallery = $this->pinGallery();
        $artwork = $this->addArtwork($gallery);

        $response = $this
            ->withSession(["pin_verified_{$gallery->id}" => true])
            ->get("/gallery/{$gallery->slug}/artwork/{$artwork->id}");

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Hidden Masterpiece', $html, 'PIN-verified visitor must see the artwork.');
        $this->assertStringContainsString('noindex,nofollow', $html, 'PIN galleries are never publiclyViewable — stay out of the index even post-unlock.');
        // Structured data would describe gated content to machines — a
        // second leak of what the PIN protects.
        $this->assertStringNotContainsString('"@type":"VisualArtwork"', $html);
    }

    // ── Events page: PIN + time gating ───────────────────────────────────

    public function test_events_page_redirects_to_pin_screen_for_pin_gallery(): void
    {
        $gallery = $this->pinGallery();
        GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title'      => 'Private Vernissage',
            'type'       => 'opening',
            'starts_at'  => now()->addDays(3),
            'is_active'  => true,
        ]);

        $response = $this->get("/gallery/{$gallery->slug}/events");

        $response->assertRedirect(route('gallery.pin', $gallery->slug));
        $this->assertStringNotContainsString('Private Vernissage', $response->getContent());
    }

    public function test_events_page_renders_for_pin_gallery_after_verification(): void
    {
        $gallery = $this->pinGallery();
        GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title'      => 'Private Vernissage',
            'type'       => 'opening',
            'starts_at'  => now()->addDays(3),
            'is_active'  => true,
        ]);

        $this
            ->withSession(["pin_verified_{$gallery->id}" => true])
            ->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->assertSee('Private Vernissage');
    }

    public function test_events_page_redirects_to_gallery_when_closed(): void
    {
        $gallery = $this->makeGallery(['closes_at' => now()->subDay()]);

        $this->get("/gallery/{$gallery->slug}/events")
            ->assertRedirect(route('gallery.view', $gallery->slug));
    }

    public function test_events_page_stays_public_before_opening(): void
    {
        // Deliberate: openings and artist talks are the pre-opening
        // marketing surface — that is what RSVPs are for.
        $gallery = $this->makeGallery(['opens_at' => now()->addDays(7)]);
        GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title'      => 'Opening Night',
            'type'       => 'opening',
            'starts_at'  => now()->addDays(6),
            'is_active'  => true,
        ]);

        $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->assertSee('Opening Night');
    }

    // ── RSVP write path: PIN gating ──────────────────────────────────────

    public function test_rsvp_cannot_be_submitted_to_pin_gallery_without_pin(): void
    {
        Mail::fake();
        $gallery = $this->pinGallery();
        $event = GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title'      => 'Private Vernissage',
            'type'       => 'opening',
            'starts_at'  => now()->addDays(3),
            'is_active'  => true,
        ]);

        $response = $this->post("/gallery/{$gallery->slug}/events/{$event->id}/rsvp", [
            'name'  => 'Sneaky Visitor',
            'email' => 'sneaky@example.com',
        ]);

        $response->assertRedirect(route('gallery.pin', $gallery->slug));
        $this->assertDatabaseMissing('event_rsvps', ['email' => 'sneaky@example.com']);
    }

    public function test_rsvp_works_for_pin_gallery_after_pin_verification(): void
    {
        Mail::fake();
        $gallery = $this->pinGallery();
        $event = GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title'      => 'Private Vernissage',
            'type'       => 'opening',
            'starts_at'  => now()->addDays(3),
            'is_active'  => true,
        ]);

        $this
            ->withSession(["pin_verified_{$gallery->id}" => true])
            ->post("/gallery/{$gallery->slug}/events/{$event->id}/rsvp", [
                'name'  => 'Invited Guest',
                'email' => 'guest@example.com',
            ]);

        $this->assertDatabaseHas('event_rsvps', [
            'schedule_event_id' => $event->id,
            'email'             => 'guest@example.com',
        ]);
    }
}
