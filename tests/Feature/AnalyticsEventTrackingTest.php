<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsEventTrackingTest extends TestCase
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
            'user_id'   => $user->id,
            'title'     => 'Analytics Specimen',
            'slug'      => 'analytics-specimen-' . uniqid(),
            'is_active' => true,
        ], $attrs));
    }

    private function trackPayload(array $overrides = []): array
    {
        return array_merge([
            'event'         => 'view',
            'session_token' => 'visitor-session-uuid',
        ], $overrides);
    }

    private function postTrack(Gallery $gallery, array $payload)
    {
        return $this->post("/gallery/{$gallery->id}/track", $payload, [
            'Accept' => 'application/json',
        ]);
    }

    public function test_valid_view_event_is_ingested_with_hashed_session_token(): void
    {
        $gallery = $this->makeGallery();

        $this->post("/gallery/{$gallery->id}/track", $this->trackPayload(), [
            'Accept'  => 'application/json',
            'Referer' => 'https://www.instagram.com/p/abc123/',
        ])->assertOk();

        $event = AnalyticsEvent::where('gallery_id', $gallery->id)->first();

        $this->assertNotNull($event);
        $this->assertSame('view', $event->event);
        $this->assertSame(hash('sha256', 'visitor-session-uuid'), $event->session_token);
        $this->assertStringNotContainsString('visitor-session-uuid', $event->session_token);
        $this->assertSame('instagram.com', $event->referrer);
    }

    public function test_referrer_is_reduced_to_the_host_domain(): void
    {
        $gallery = $this->makeGallery();

        $this->post("/gallery/{$gallery->id}/track", $this->trackPayload(), [
            'Accept'  => 'application/json',
            'Referer' => 'https://google.com/search?q=private+query+string',
        ])->assertOk();

        $this->assertSame('google.com', AnalyticsEvent::value('referrer'));
    }

    public function test_malformed_event_payload_is_rejected_without_storing(): void
    {
        $gallery = $this->makeGallery();

        $this->postTrack($gallery, $this->trackPayload(['event' => 'page_load']))
            ->assertStatus(422);

        $this->postTrack($gallery, ['event' => 'view'])
            ->assertStatus(422);

        $this->postTrack($gallery, $this->trackPayload(['dwell_seconds' => 999999]))
            ->assertStatus(422);

        $this->assertSame(0, AnalyticsEvent::count());
    }

    public function test_focus_event_with_another_gallerys_artwork_is_stored_unlinked(): void
    {
        $gallery = $this->makeGallery();
        $other = $this->makeGallery();
        $foreign = GalleryImage::create([
            'gallery_id'    => $other->id,
            'filename'      => 'foreign.jpg',
            'original_name' => 'foreign.jpg',
            'path'          => 'artworks/foreign.jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => 1024,
            'width'         => 100,
            'height'        => 100,
            'orientation'   => 'landscape',
        ]);

        $this->postTrack($gallery, $this->trackPayload([
            'event'    => 'focus',
            'image_id' => $foreign->id,
        ]))->assertOk();

        $this->assertSame(1, AnalyticsEvent::where('gallery_id', $gallery->id)->count());
        $this->assertNull(AnalyticsEvent::where('gallery_id', $gallery->id)->value('image_id'));
    }

    public function test_focus_event_on_deleted_artwork_is_stored_unlinked(): void
    {
        $gallery = $this->makeGallery();
        $image = GalleryImage::create([
            'gallery_id'    => $gallery->id,
            'filename'      => 'artwork.jpg',
            'original_name' => 'artwork.jpg',
            'path'          => 'artworks/artwork.jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => 1024,
            'width'         => 100,
            'height'        => 100,
            'orientation'   => 'landscape',
        ]);

        $image->delete();

        $this->postTrack($gallery, $this->trackPayload([
            'event'    => 'focus',
            'image_id' => $image->id,
        ]))->assertOk();

        $this->assertNull(AnalyticsEvent::value('image_id'));
    }

    public function test_dwell_updates_the_most_recent_view_of_the_session(): void
    {
        $gallery = $this->makeGallery();
        $token = hash('sha256', 'visitor-session-uuid');

        AnalyticsEvent::create([
            'gallery_id'    => $gallery->id,
            'event'         => 'view',
            'session_token' => $token,
            'created_at'    => now()->subMinutes(10),
        ]);
        AnalyticsEvent::create([
            'gallery_id'    => $gallery->id,
            'event'         => 'view',
            'session_token' => $token,
            'created_at'    => now(),
        ]);

        $this->postTrack($gallery, $this->trackPayload([
            'event'         => 'dwell',
            'dwell_seconds' => 45,
        ]))->assertOk();

        $views = AnalyticsEvent::where('event', 'view')->orderBy('created_at')->get();
        $this->assertNull($views->first()->dwell_seconds);
        $this->assertSame(45, $views->last()->dwell_seconds);
    }

    public function test_perf_beacon_keeps_only_schema_fields(): void
    {
        $gallery = $this->makeGallery();

        $this->postTrack($gallery, $this->trackPayload([
            'event' => 'perf',
            'perf'  => [
                'tier'   => 'high',
                'fps'    => 58,
                'fps_min' => 31,
                'heap'   => 210,
                'a_very' => 'long unvalidated string '.str_repeat('x', 2048),
                'another'=> ['nested' => 'payload'],
            ],
        ]))->assertOk();

        $stored = AnalyticsEvent::where('event', 'perf')->value('perf_data');

        $this->assertIsArray($stored);
        $this->assertSame([
            'tier'    => 'high',
            'fps'     => 58,
            'fps_min' => 31,
            'heap'    => 210,
        ], $stored);
    }

    public function test_declined_consent_suppresses_ingestion(): void
    {
        $gallery = $this->makeGallery();

        // A real browser writes the consent cookie with document.cookie, so it
        // arrives unencrypted — disable the test client's auto-encryption to
        // simulate that.
        $this->disableCookieEncryption()
            ->withCookie('exospace_cookie_consent', 'declined')
            ->postTrack($gallery, $this->trackPayload())
            ->assertOk();

        $this->assertSame(0, AnalyticsEvent::count());
    }

    public function test_banned_owner_exhibition_accepts_no_tracking_events(): void
    {
        $gallery = $this->makeGallery();
        $gallery->user->forceFill(['banned_at' => now()])->save();

        $this->postTrack($gallery, $this->trackPayload())->assertOk();

        $this->assertSame(0, AnalyticsEvent::count());
    }

    public function test_unopened_exhibition_accepts_no_tracking_events(): void
    {
        $gallery = $this->makeGallery(['opens_at' => now()->addDays(3)]);

        $this->postTrack($gallery, $this->trackPayload())->assertOk();

        $this->assertSame(0, AnalyticsEvent::count());
    }

    public function test_closed_exhibition_accepts_no_tracking_events(): void
    {
        $gallery = $this->makeGallery(['closes_at' => now()->subDay()]);

        $this->postTrack($gallery, $this->trackPayload())->assertOk();

        $this->assertSame(0, AnalyticsEvent::count());
    }

    public function test_pin_protected_exhibition_requires_pin_verification(): void
    {
        $gallery = $this->makeGallery([
            'pin_hash' => \Illuminate\Support\Facades\Hash::make('1234'),
        ]);

        $this->postTrack($gallery, $this->trackPayload())->assertOk();
        $this->assertSame(0, AnalyticsEvent::count());

        $this->withSession(["pin_verified_{$gallery->id}" => true])
            ->postTrack($gallery, $this->trackPayload())
            ->assertOk();
        $this->assertSame(1, AnalyticsEvent::count());
    }

    public function test_tracking_bursts_are_rate_limited(): void
    {
        $gallery = $this->makeGallery();

        for ($i = 0; $i < 30; $i++) {
            $this->postTrack($gallery, $this->trackPayload([
                'session_token' => "session-{$i}",
            ]))->assertOk();
        }

        $this->postTrack($gallery, $this->trackPayload(['session_token' => 'session-30']))
            ->assertStatus(429);

        $this->assertSame(30, AnalyticsEvent::count());
    }

    public function test_gallery_analytics_seven_day_windows_do_not_overlap(): void
    {
        $owner = User::factory()->create();
        $gallery = Gallery::create([
            'user_id'   => $owner->id,
            'title'     => 'Window Specimen',
            'slug'      => 'window-specimen-' . uniqid(),
            'is_active' => true,
        ]);

        $rollup = [
            [1, 1], [2, 1], [3, 1], [4, 1], [5, 1], [6, 1],
            [7, 100],
            [8, 1], [9, 1], [10, 1], [11, 1], [12, 1], [13, 1],
            [14, 100],
        ];
        foreach ($rollup as [$ago, $views]) {
            DB::table('analytics_daily')->insert([
                'gallery_id' => $gallery->id,
                'date'       => now()->subDays($ago)->toDateString(),
                'views'      => $views,
            ]);
        }

        AnalyticsEvent::create([
            'gallery_id'    => $gallery->id,
            'event'         => 'view',
            'session_token' => hash('sha256', 's1'),
            'created_at'    => now(),
        ]);
        AnalyticsEvent::create([
            'gallery_id'    => $gallery->id,
            'event'         => 'view',
            'session_token' => hash('sha256', 's2'),
            'created_at'    => now(),
        ]);

        $response = $this->actingAs($owner)
            ->get(route('admin.galleries.analytics', $gallery));

        $response->assertOk();
        $response->assertViewHas('views7', 8);
        // viewsPrev7 is not passed to the view — the trend derives from it:
        // round((8 − 106) / 106 × 100) = −92.
        $response->assertViewHas('viewsTrend', -92);
    }

    public function test_dashboard_seven_day_windows_do_not_overlap(): void
    {
        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create([
            'user_id'   => $owner->id,
            'team_id'   => null,
            'is_active' => true,
        ]);

        $rollup = [
            [1, 1], [2, 1], [3, 1], [4, 1], [5, 1], [6, 1],
            [7, 100],
            [8, 1], [9, 1], [10, 1], [11, 1], [12, 1], [13, 1],
            [14, 100],
        ];
        foreach ($rollup as [$ago, $views]) {
            DB::table('analytics_daily')->insert([
                'gallery_id' => $gallery->id,
                'date'       => now()->subDays($ago)->toDateString(),
                'views'      => $views,
            ]);
        }

        AnalyticsEvent::create([
            'gallery_id'    => $gallery->id,
            'event'         => 'view',
            'session_token' => hash('sha256', 's1'),
            'created_at'    => now(),
        ]);
        AnalyticsEvent::create([
            'gallery_id'    => $gallery->id,
            'event'         => 'view',
            'session_token' => hash('sha256', 's2'),
            'created_at'    => now(),
        ]);

        $response = $this->actingAs($owner)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertViewHas('viewsToday', 2);
        $response->assertViewHas('views7', 8);
        // viewsPrev7 itself is not passed to the view — the trend derives
        // from it: round((8 − 106) / 106 × 100) = −92.
        $response->assertViewHas('viewsTrend', -92);
    }

    public function test_today_views_are_counted_from_raw_events_before_rollup(): void
    {
        $owner = User::factory()->create();
        $gallery = Gallery::factory()->create([
            'user_id'   => $owner->id,
            'team_id'   => null,
            'is_active' => true,
        ]);

        $today = now()->toDateString();
        DB::table('analytics_daily')->insert([
            'gallery_id' => $gallery->id,
            'date'       => $today,
            'views'      => 50,
        ]);

        $response = $this->actingAs($owner)->get(route('admin.galleries.analytics', $gallery));

        $response->assertOk();
        // Today exists in the rollup table but must not be double-counted:
        // today's figures come from raw events only.
        $response->assertViewHas('totalViews', 0);
        $response->assertViewHas('uniqueVisitors', 0);
    }
}
