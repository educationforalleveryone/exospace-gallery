<?php

declare(strict_types=1);

/**
 * ITERATION 5 — sitemap events group tests.
 *
 * The events group lists /gallery/{slug}/events for galleries with at
 * least one ACTIVE UPCOMING event. Inclusion rules deliberately mirror
 * PublicEventController::index (NOT publiclyViewable): PIN and closed
 * galleries redirect (never list a redirect URL), while not-yet-open
 * galleries keep their events page public — openings are the pre-opening
 * marketing surface (Iteration-3 decision).
 *
 * Also pins:
 *   - GalleryScheduleEvent writes bump the sitemap version (observer
 *     registration — before Iteration 5, announcing an opening never
 *     invalidated the sitemap cache)
 *   - The events group participates in sitemap:warm
 *
 * Run: php artisan test --filter=SitemapEventsGroupTest
 */

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryScheduleEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapEventsGroupTest extends TestCase
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
            'user_id'    => $user->id,
            'title'      => 'Events Test Gallery',
            'slug'       => 'events-' . uniqid(),
            'description'=> 'A gallery with events.',
            'is_active'  => true,
        ], $attrs));
    }

    private function addUpcomingEvent(Gallery $gallery, array $attrs = []): GalleryScheduleEvent
    {
        return GalleryScheduleEvent::create(array_merge([
            'gallery_id' => $gallery->id,
            'title'      => 'Opening Reception',
            'type'       => 'opening',
            'starts_at'  => now()->addDays(5),
            'is_active'  => true,
        ], $attrs));
    }

    // ── Index + group content ───────────────────────────────────────────

    public function test_sitemap_index_lists_the_events_group(): void
    {
        $gallery = $this->makePublicGallery();
        $this->addUpcomingEvent($gallery);

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString('sitemap-events-1.xml', $xml, 'the events group appears in the sitemap index');
    }

    public function test_events_group_lists_gallery_events_url_for_upcoming_active_events(): void
    {
        $gallery = $this->makePublicGallery();
        $this->addUpcomingEvent($gallery);

        $xml = $this->get('/sitemap-events-1.xml')->getContent();

        $this->assertStringContainsString(
            'https://exospace.gallery/gallery/' . $gallery->slug . '/events',
            $xml,
            'the RSVP surface is discoverable by crawlers',
        );
    }

    // ── Exclusion rules (mirror PublicEventController access) ───────────

    public function test_pin_galleries_are_excluded_from_the_events_group(): void
    {
        $gallery = $this->makePublicGallery(['pin_hash' => bcrypt('1234')]);
        $this->addUpcomingEvent($gallery);

        $xml = $this->get('/sitemap-events-1.xml')->getContent();

        $this->assertStringNotContainsString($gallery->slug, $xml, 'PIN gallery events redirect to the PIN screen — never list a redirect URL');
    }

    public function test_closed_galleries_are_excluded_from_the_events_group(): void
    {
        $gallery = $this->makePublicGallery(['closes_at' => now()->subDay()]);
        $this->addUpcomingEvent($gallery);

        $xml = $this->get('/sitemap-events-1.xml')->getContent();

        $this->assertStringNotContainsString($gallery->slug, $xml, 'closed galleries redirect to their closed page');
    }

    public function test_draft_galleries_are_excluded_from_the_events_group(): void
    {
        $gallery = $this->makePublicGallery(['is_active' => false]);
        $this->addUpcomingEvent($gallery);

        $xml = $this->get('/sitemap-events-1.xml')->getContent();

        $this->assertStringNotContainsString($gallery->slug, $xml, 'unpublished galleries have no public events page');
    }

    public function test_not_yet_open_galleries_are_included_in_the_events_group(): void
    {
        // Deliberate difference from the galleries group: the events page of
        // a future-opening exhibition stays public — openings and artist
        // talks are the pre-opening marketing surface (Iteration 3).
        $gallery = $this->makePublicGallery(['opens_at' => now()->addDays(10)]);
        $this->addUpcomingEvent($gallery, ['starts_at' => now()->addDays(10)->addHours(2)]);

        $xml = $this->get('/sitemap-events-1.xml')->getContent();

        $this->assertStringContainsString(
            'https://exospace.gallery/gallery/' . $gallery->slug . '/events',
            $xml,
            'pre-opening events remain the marketing surface',
        );
    }

    public function test_galleries_with_only_past_events_self_prune_out(): void
    {
        $gallery = $this->makePublicGallery();
        $this->addUpcomingEvent($gallery, ['starts_at' => now()->subDays(2)]);

        $xml = $this->get('/sitemap-events-1.xml')->getContent();

        $this->assertStringNotContainsString($gallery->slug, $xml, 'the group is upcoming-only — galleries drop out as events pass');
    }

    public function test_inactive_events_do_not_qualify_their_gallery(): void
    {
        $gallery = $this->makePublicGallery();
        $this->addUpcomingEvent($gallery, ['is_active' => false]);

        $xml = $this->get('/sitemap-events-1.xml')->getContent();

        $this->assertStringNotContainsString($gallery->slug, $xml);
    }

    public function test_banned_owners_are_excluded_from_the_events_group(): void
    {
        $banned = User::factory()->create(['banned_at' => now()]);
        $gallery = Gallery::create([
            'user_id'    => $banned->id,
            'title'      => 'Banned Owner Gallery',
            'slug'       => 'banned-' . uniqid(),
            'description'=> 'x',
            'is_active'  => true,
        ]);
        $this->addUpcomingEvent($gallery);

        $xml = $this->get('/sitemap-events-1.xml')->getContent();

        $this->assertStringNotContainsString($gallery->slug, $xml, 'same banned-owner rule as the galleries group');
    }

    public function test_empty_events_group_is_not_listed_in_the_index(): void
    {
        // No events anywhere — but galleries/artists may exist so the index
        // itself renders.
        $gallery = $this->makePublicGallery();

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertStringNotContainsString('sitemap-events-', $xml, 'no upcoming events anywhere → group omitted from the index');
    }

    // ── Cache invalidation ──────────────────────────────────────────────

    public function test_event_writes_bump_the_sitemap_version(): void
    {
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 100);
        $before = (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version');

        $gallery = $this->makePublicGallery();
        $this->addUpcomingEvent($gallery, ['title' => 'Announced Opening']);

        $after = (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version');
        $this->assertGreaterThan($before, $after, 'announcing an event invalidates the sitemap cache (ITERATION 5 observer registration)');

        // And a non-SEO-relevant write must NOT bump (no churn rule):
        // capacity changes don't alter the URL set or the page's indexed
        // content signals — the observer's WATCHED filter skips them.
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 100);
        $event = GalleryScheduleEvent::first();
        $event->capacity = 42;
        $event->save();
        $this->assertSame(100, (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version'), 'capacity is not a watched attribute — no version churn');
    }

    // ── Warming ─────────────────────────────────────────────────────────

    public function test_sitemap_warm_covers_the_events_group(): void
    {
        $gallery = $this->makePublicGallery();
        $this->addUpcomingEvent($gallery);

        $stats = app(\App\Http\Controllers\SitemapController::class)->warmCaches(null, 5);

        $this->assertArrayHasKey('events', $stats['groups'], 'the warmer iterates GROUPS — the events group is warmed daily at 04:15');
        $this->assertSame(1, $stats['groups']['events']);

        // The warmed key actually serves (crawler request = pure cache read).
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 999);
        app(\App\Http\Controllers\SitemapController::class)->warmCaches('events', 5);
        $this->assertTrue(
            \Illuminate\Support\Facades\Cache::has('sitemap:group:events:1:v999'),
            'events group entries cached under the shared version key',
        );
    }
}
