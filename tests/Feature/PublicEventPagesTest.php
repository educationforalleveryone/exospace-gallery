<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\EventRsvpNotification;
use App\Models\Gallery;
use App\Models\GalleryScheduleEvent;
use App\Models\EventRsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicEventPagesTest extends TestCase
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
            'title'      => 'Scheduled Show',
            'slug'       => 'scheduled-show-' . uniqid(),
            'description'=> 'A gallery with a public events schedule.',
            'is_active'  => true,
        ], $attrs));
    }

    private function addEvent(Gallery $gallery, array $attrs = []): GalleryScheduleEvent
    {
        return GalleryScheduleEvent::create(array_merge([
            'gallery_id' => $gallery->id,
            'title'      => 'Opening Reception',
            'type'       => 'opening',
            'starts_at'  => now()->addDays(5),
            'is_active'  => true,
        ], $attrs));
    }

    // ── Route resolution ────────────────────────────────────────────────

    public function test_public_event_page_renders_for_direct_entry(): void
    {
        $gallery = $this->makeGallery();
        $this->addEvent($gallery, ['title' => 'Vernissage Night']);

        $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->assertSee('Vernissage Night')
            ->assertSee('Scheduled Show')
            ->assertSee('Enter 3D gallery');
    }

    public function test_unknown_gallery_slug_returns_404(): void
    {
        $this->get('/gallery/no-such-exhibition/events')->assertNotFound();
    }

    public function test_malformed_event_identifier_on_rsvp_returns_404(): void
    {
        $gallery = $this->makeGallery();

        $this->post("/gallery/{$gallery->slug}/events/not-an-event/rsvp", [
            'name'  => 'Visitor',
            'email' => 'visitor@example.com',
        ])->assertNotFound();
    }

    public function test_rsvp_to_another_gallerys_event_returns_404(): void
    {
        Mail::fake();
        $galleryA = $this->makeGallery();
        $galleryB = $this->makeGallery();
        $event = $this->addEvent($galleryA);

        $this->post("/gallery/{$galleryB->slug}/events/{$event->id}/rsvp", [
            'name'  => 'Visitor',
            'email' => 'visitor@example.com',
        ])->assertNotFound();

        $this->assertDatabaseMissing('event_rsvps', ['schedule_event_id' => $event->id]);
    }

    // ── Visibility ──────────────────────────────────────────────────────

    public function test_inactive_events_are_not_listed(): void
    {
        $gallery = $this->makeGallery();
        $this->addEvent($gallery, ['title' => 'Live Opening']);
        $this->addEvent($gallery, ['title' => 'Unpublished Rehearsal', 'is_active' => false]);

        $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->assertSee('Live Opening')
            ->assertDontSee('Unpublished Rehearsal');
    }

    public function test_banned_owner_events_page_is_404(): void
    {
        $banned = User::factory()->create(['banned_at' => now()]);
        $gallery = Gallery::create([
            'user_id'    => $banned->id,
            'title'      => 'Banned Owner Show',
            'slug'       => 'banned-owner-show-' . uniqid(),
            'description'=> 'x',
            'is_active'  => true,
        ]);
        $event = $this->addEvent($gallery);

        $this->get("/gallery/{$gallery->slug}/events")->assertNotFound();

        $this->post("/gallery/{$gallery->slug}/events/{$event->id}/rsvp", [
            'name'  => 'Visitor',
            'email' => 'visitor@example.com',
        ])->assertNotFound();
        $this->assertDatabaseMissing('event_rsvps', ['schedule_event_id' => $event->id]);
    }

    public function test_rsvp_rejected_for_inactive_events(): void
    {
        Mail::fake();
        $gallery = $this->makeGallery();
        $event = $this->addEvent($gallery, ['is_active' => false]);

        $this->post("/gallery/{$gallery->slug}/events/{$event->id}/rsvp", [
            'name'  => 'Visitor',
            'email' => 'visitor@example.com',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('event_rsvps', ['schedule_event_id' => $event->id]);
        Mail::assertNothingQueued();
    }

    public function test_rsvp_rejected_for_past_events(): void
    {
        Mail::fake();
        $gallery = $this->makeGallery();
        $event = $this->addEvent($gallery, ['starts_at' => now()->subDay()]);

        $this->post("/gallery/{$gallery->slug}/events/{$event->id}/rsvp", [
            'name'  => 'Visitor',
            'email' => 'visitor@example.com',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseMissing('event_rsvps', ['schedule_event_id' => $event->id]);
        Mail::assertNothingQueued();
    }

    // ── Grouping and ordering ───────────────────────────────────────────

    public function test_past_events_show_most_recent_first_and_are_capped(): void
    {
        $gallery = $this->makeGallery();

        foreach (range(1, 7) as $daysAgo) {
            $this->addEvent($gallery, [
                'title'     => "Reception {$daysAgo} Days Ago",
                'type'      => 'event',
                'starts_at' => now()->subDays($daysAgo),
            ]);
        }

        $html = $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Reception 1 Days Ago', $html, 'the newest past event is shown');
        $this->assertStringContainsString('Reception 5 Days Ago', $html, 'the cap keeps the five most recent');
        $this->assertStringNotContainsString('Reception 6 Days Ago', $html, 'older runs never crowd out recent history');
        // Reception 1 is the newest; newest-first means it appears first.
        $this->assertLessThan(
            strpos($html, 'Reception 2 Days Ago'),
            strpos($html, 'Reception 1 Days Ago'),
            'history reads newest-first',
        );
    }

    public function test_event_page_with_no_events_shows_empty_state(): void
    {
        $gallery = $this->makeGallery();

        $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->assertSee('No upcoming events scheduled.');
    }

    // ── Timezone handling ───────────────────────────────────────────────

    public function test_event_times_render_in_the_declared_timezone(): void
    {
        $gallery = $this->makeGallery();
        // Stored instants are UTC: 22:00 UTC on 2030-07-01 is 18:00 EDT on the
        // same day in New York, so the end (01:00 UTC) stays on July 1 there.
        $this->addEvent($gallery, [
            'title'     => 'Gallery Hour',
            'starts_at' => '2030-07-01 22:00:00',
            'ends_at'   => '2030-07-02 01:00:00',
            'timezone'  => 'America/New_York',
        ]);

        $html = $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('6:00 PM EDT', $html, 'the wall-clock matches the declared timezone');
        $this->assertStringContainsString('July 1', $html, 'the calendar date matches the declared timezone');
        $this->assertStringNotContainsString('July 2', $html, 'a 9:00 PM EDT end stays on the same calendar day');
    }

    public function test_multi_day_events_repeat_the_end_day(): void
    {
        $gallery = $this->makeGallery();
        // 03:00 UTC on 2030-07-02 is 11:00 PM EDT on July 1; 04:00 UTC on
        // 2030-07-03 is 12:00 AM EDT on July 3 — a two-calendar-day range.
        $this->addEvent($gallery, [
            'title'     => 'Overnight Workshop',
            'starts_at' => '2030-07-02 03:00:00',
            'ends_at'   => '2030-07-03 04:00:00',
            'timezone'  => 'America/New_York',
        ]);

        $html = $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Monday, July 1, 2030 at 11:00 PM EDT', $html);
        $this->assertStringContainsString('– Wednesday, July 3, 2030 at 12:00 AM EDT', $html, 'the end day is spelled out instead of a bare 12:00 AM');
    }

    // ── Location links ──────────────────────────────────────────────────

    public function test_location_url_renders_as_a_safe_external_link(): void
    {
        $gallery = $this->makeGallery();
        $this->addEvent($gallery, [
            'title'        => 'Virtual Artist Talk',
            'location_url' => 'https://zoom.us/j/1234567890',
        ]);

        $html = $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="https://zoom.us/j/1234567890"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_location_url_without_a_name_falls_back_to_a_generic_label(): void
    {
        $gallery = $this->makeGallery();
        $this->addEvent($gallery, [
            'title'        => 'Streamed Walkthrough',
            'location_url' => 'https://meet.example.com/room',
        ]);

        $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->assertSee('Join online');
    }

    public function test_non_http_location_schemes_never_render_as_links(): void
    {
        $gallery = $this->makeGallery();
        $this->addEvent($gallery, [
            'title'        => 'Crafty Talk',
            'location_url' => 'javascript:alert(1)',
        ]);

        $html = $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('javascript:', $html);
    }

    // ── Content robustness ──────────────────────────────────────────────

    public function test_event_content_is_escaped(): void
    {
        $gallery = $this->makeGallery();
        $this->addEvent($gallery, [
            'title' => 'Special <script>alert(1)</script> Show',
        ]);

        $html = $this->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── Gated SEO surface ───────────────────────────────────────────────

    public function test_pin_verified_events_page_is_noindex(): void
    {
        $gallery = $this->makeGallery(['pin_hash' => bcrypt('1234')]);
        $this->addEvent($gallery, ['title' => 'Private Vernissage']);

        $html = $this
            ->withSession(["pin_verified_{$gallery->id}" => true])
            ->get("/gallery/{$gallery->slug}/events")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Private Vernissage', $html);
        $this->assertStringContainsString('noindex,nofollow', $html, 'crawlers hit the PIN screen — the gated page must not present as indexable');
    }

    // ── RSVP flow ───────────────────────────────────────────────────────

    public function test_rsvp_creates_row_and_queues_curator_notification(): void
    {
        Mail::fake();
        $gallery = $this->makeGallery();
        $event = $this->addEvent($gallery);

        $this->post("/gallery/{$gallery->slug}/events/{$event->id}/rsvp", [
            'name'  => 'Curious Visitor',
            'email' => 'visitor@example.com',
        ])->assertRedirect();

        $this->assertDatabaseHas('event_rsvps', [
            'schedule_event_id' => $event->id,
            'email'             => 'visitor@example.com',
            'name'              => 'Curious Visitor',
        ]);
        Mail::assertQueued(EventRsvpNotification::class, 1);
    }

    public function test_rsvp_is_idempotent_for_duplicate_submissions(): void
    {
        Mail::fake();
        $gallery = $this->makeGallery();
        $event = $this->addEvent($gallery);
        $payload = ['name' => 'Repeat Visitor', 'email' => 'repeat@example.com'];

        $this->post("/gallery/{$gallery->slug}/events/{$event->id}/rsvp", $payload)->assertRedirect();
        $this->post("/gallery/{$gallery->slug}/events/{$event->id}/rsvp", $payload)->assertRedirect();

        $this->assertDatabaseCount('event_rsvps', 1);
    }

    public function test_rsvp_honors_capacity(): void
    {
        Mail::fake();
        $gallery = $this->makeGallery();
        $event = $this->addEvent($gallery, ['capacity' => 1]);

        EventRsvp::create([
            'schedule_event_id' => $event->id,
            'name'              => 'First Guest',
            'email'             => 'first@example.com',
            'confirmed_at'      => now(),
        ]);

        $this->post("/gallery/{$gallery->slug}/events/{$event->id}/rsvp", [
            'name'  => 'Second Guest',
            'email' => 'second@example.com',
        ])->assertRedirect()->assertSessionHas('error', 'This event has reached capacity.');

        $this->assertDatabaseMissing('event_rsvps', ['email' => 'second@example.com']);
        Mail::assertNothingQueued();
    }
}
