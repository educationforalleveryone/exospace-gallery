<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\IncrementGalleryViews;
use App\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GalleryViewDedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * afterResponse() registers an app terminating() callback and the test
     * container lives across requests, so a callback from request 1 would run
     * again at request 2's terminate phase and double-count in the queue fake.
     * Production terminates the process once per request — mirror that here.
     */
    private function forgetTerminatingCallbacks(): void
    {
        $property = new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'terminatingCallbacks');
        $property->setValue($this->app, []);
    }

    public function test_repeat_visit_by_the_same_visitor_counts_one_view(): void
    {
        Queue::fake();

        $gallery = Gallery::factory()->create(['is_active' => true]);

        $first = $this->get(route('gallery.view', $gallery->slug));
        $first->assertOk();

        $this->forgetTerminatingCallbacks();

        $this->withUnencryptedCookie($this->sessionCookieName(), $this->sessionCookieValue($first))
            ->get(route('gallery.view', $gallery->slug))
            ->assertOk();

        Queue::assertPushed(IncrementGalleryViews::class, 1);
    }

    public function test_view_is_counted_again_once_the_dedup_window_has_passed(): void
    {
        Queue::fake();

        $gallery = Gallery::factory()->create(['is_active' => true]);

        $first = $this->get(route('gallery.view', $gallery->slug));
        $first->assertOk();

        $this->travel(90)->minutes();
        $this->forgetTerminatingCallbacks();

        $this->withUnencryptedCookie($this->sessionCookieName(), $this->sessionCookieValue($first))
            ->get(route('gallery.view', $gallery->slug))
            ->assertOk();

        Queue::assertPushed(IncrementGalleryViews::class, 2);
    }

    public function test_dedup_is_tracked_per_gallery(): void
    {
        Queue::fake();

        $galleryA = Gallery::factory()->create(['is_active' => true]);
        $galleryB = Gallery::factory()->create(['is_active' => true]);

        $first = $this->get(route('gallery.view', $galleryA->slug));
        $first->assertOk();

        $this->forgetTerminatingCallbacks();

        $this->withUnencryptedCookie($this->sessionCookieName(), $this->sessionCookieValue($first))
            ->get(route('gallery.view', $galleryB->slug))
            ->assertOk();

        Queue::assertPushed(IncrementGalleryViews::class, 2);
    }

    public function test_dedup_map_is_capped_so_the_session_stays_small(): void
    {
        Queue::fake();

        $gallery = Gallery::factory()->create(['is_active' => true]);
        $now = now()->timestamp;

        $seeded = [];
        for ($id = 100000; $id < 100060; $id++) {
            $seeded[$id] = $now - 60; // all inside the window
        }

        $response = $this->withSession(['counted_gallery_views' => $seeded])
            ->get(route('gallery.view', $gallery->slug));

        $response->assertOk();

        $tracked = session('counted_gallery_views');
        $this->assertLessThanOrEqual(50, count($tracked), 'The dedup map must stay capped.');
        $this->assertArrayHasKey($gallery->id, $tracked, 'The current view must be tracked.');
    }

    private function sessionCookieName(): string
    {
        return config('session.cookie');
    }

    private function sessionCookieValue($response): string
    {
        $cookie = $response->getCookie($this->sessionCookieName(), decrypt: false);

        return $cookie->getValue();
    }
}
