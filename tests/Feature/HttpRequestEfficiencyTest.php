<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\EventRsvp;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\GalleryScheduleEvent;
use App\Models\User;
use App\Models\VenueTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HttpRequestEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function loggedQueries(): array
    {
        return DB::getQueryLog();
    }

    private function startLog(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
    }

    private function stopLog(): array
    {
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return $queries;
    }

    // ── Public listing pages ─────────────────────────────────────────────

    public function test_discover_page_query_volume_does_not_scale_with_card_count(): void
    {
        $small = $this->renderDiscoverPageWithGalleryCount(3);
        $large = $this->renderDiscoverPageWithGalleryCount(9);

        $this->assertSame($small, $large, sprintf(
            'Discover page query count scales with gallery count (3 cards=%d, 9 cards=%d) — per-card lazy loads are back.',
            $small,
            $large,
        ));
    }

    private function renderDiscoverPageWithGalleryCount(int $count): int
    {
        \Illuminate\Support\Facades\Cache::flush();
        $galleries = Gallery::factory()->count($count)->create();
        foreach ($galleries as $gallery) {
            GalleryImage::factory()->create(['gallery_id' => $gallery->id]);
        }

        $this->startLog();
        $response = $this->get('/discover');
        $queries = count($this->loggedQueries());
        $this->stopLog();

        $response->assertOk();
        $response->assertSee('artworks');

        return $queries;
    }

    public function test_venue_page_query_volume_does_not_scale_with_card_count(): void
    {
        $small = $this->renderVenuePageWithGalleryCount(3);
        $large = $this->renderVenuePageWithGalleryCount(9);

        $this->assertSame($small, $large, sprintf(
            'Venue page query count scales with gallery count (3 cards=%d, 9 cards=%d) — per-card lazy loads are back.',
            $small,
            $large,
        ));
    }

    private function renderVenuePageWithGalleryCount(int $count): int
    {
        \Illuminate\Support\Facades\Cache::flush();
        $venue = VenueTemplate::factory()->create(['is_draft' => false]);
        $galleries = Gallery::factory()->count($count)->forVenue($venue)->create();
        foreach ($galleries as $gallery) {
            GalleryImage::factory()->create(['gallery_id' => $gallery->id]);
        }

        $this->startLog();
        $response = $this->get('/venues/'.$venue->slug);
        $queries = count($this->loggedQueries());
        $this->stopLog();

        $response->assertOk();

        return $queries;
    }

    // ── Public artwork page ─────────────────────────────────────────────

    public function test_artwork_page_query_volume_is_bounded_regardless_of_gallery_size(): void
    {
        $small = $this->renderArtworkPageWithImageCount(3);
        $large = $this->renderArtworkPageWithImageCount(40);

        // A gallery forty times larger must not cost proportionally more
        // queries: siblings are a bounded LIMIT query, not full hydration.
        $this->assertLessThanOrEqual(8, abs($large - $small), sprintf(
            'Artwork page query count scales with gallery size (small=%d, large=%d).',
            $small,
            $large,
        ));
    }

    private function renderArtworkPageWithImageCount(int $count): int
    {
        \Illuminate\Support\Facades\Cache::flush();
        $gallery = Gallery::factory()->create();
        $images = GalleryImage::factory()->count($count)->create([
            'gallery_id' => $gallery->id,
            'title'      => 'Artwork',
        ]);

        $this->startLog();
        $response = $this->get("/gallery/{$gallery->slug}/artwork/{$images->first()->id}");
        $queries = count($this->loggedQueries());
        $this->stopLog();

        $response->assertOk();

        return $queries;
    }

    public function test_artwork_page_shows_at_most_eight_siblings(): void
    {
        $gallery = Gallery::factory()->create();
        $images = GalleryImage::factory()->count(15)->create([
            'gallery_id' => $gallery->id,
            'title'      => 'Artwork',
        ]);

        $response = $this->get("/gallery/{$gallery->slug}/artwork/{$images->first()->id}");

        $response->assertOk();
        // 14 siblings exist; the controller must hand the view a bounded slice.
        $siblings = $response->viewData('siblings');
        $this->assertLessThanOrEqual(8, $siblings->count());
    }

    // ── Related exhibitions ──────────────────────────────────────────────

    public function test_related_galleries_candidate_pool_is_capped(): void
    {
        $gallery = Gallery::factory()->create();
        GalleryImage::factory()->count(2)->create(['gallery_id' => $gallery->id]);
        Gallery::factory()->count(3)->create()->each(function (Gallery $other) {
            GalleryImage::factory()->count(2)->create(['gallery_id' => $other->id]);
        });

        \Illuminate\Support\Facades\Cache::flush();
        $service = app(\App\Services\Seo\InternalLinkingService::class);

        $this->startLog();
        $related = $service->relatedGalleries($gallery);
        $queries = $this->loggedQueries();
        $this->stopLog();

        $this->assertLessThanOrEqual(6, $related->count());

        // The candidate query must carry an explicit LIMIT — an unbounded
        // SELECT of every publicly-viewable gallery is a production hazard.
        $candidateQuery = collect($queries)->first(function ($q) {
            return str_contains(strtolower($q['query']), 'from "galleries"');
        });
        $this->assertNotNull($candidateQuery, 'No galleries candidate query ran.');
        $this->assertStringContainsString('limit', strtolower($candidateQuery['query']));
    }

    // ── Artist directory ─────────────────────────────────────────────────

    public function test_artist_directory_covers_pick_one_work_per_artist(): void
    {
        $artist = Artist::factory()->create();
        $gallery = Gallery::factory()->create();
        GalleryImage::factory()->count(6)->create([
            'gallery_id' => $gallery->id,
            'artist_id'  => $artist->id,
        ]);

        $this->startLog();
        $response = $this->get('/artists');
        $queries = $this->loggedQueries();
        $this->stopLog();

        $response->assertOk();

        // The cover query groups by artist — the bounded latest-per-artist
        // pattern. No query may hydrate every work of every page artist.
        $groupedCovers = collect($queries)->filter(function ($q) {
            return str_contains(strtolower($q['query']), 'group by "artist_id"');
        })->count();
        $this->assertSame(1, $groupedCovers, 'Artist directory must fetch covers with a single grouped query.');
    }

    // ── Navigation (every authenticated page) ────────────────────────────

    public function test_navigation_team_switcher_does_not_query_roles_per_team(): void
    {
        $user = User::factory()->create();

        $owned = \App\Models\Team::factory()->create(['owner_id' => $user->id]);
        $owned->members()->attach($user->id, ['role' => 'owner']);

        $others = User::factory()->count(2)->create();
        foreach ($others as $member) {
            $team = \App\Models\Team::factory()->create(['owner_id' => $member->id]);
            $team->members()->attach($user->id, ['role' => 'viewer']);
        }

        $this->actingAs($user);

        $this->startLog();
        $response = $this->get(route('profile.edit'));
        $queries = $this->loggedQueries();
        $this->stopLog();

        $response->assertOk();

        // memberRole() probes the pivot with a per-team LIMIT 1 lookup; the
        // switcher must read the hydrated pivot role instead.
        $perTeamRoleProbes = collect($queries)->filter(function ($q) {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'team_user')
                && str_contains($sql, 'where "team_user"."team_id" =')
                && str_contains($sql, 'limit 1');
        })->count();
        $this->assertSame(0, $perTeamRoleProbes, 'Team switcher must not run a role lookup per team.');
    }

    // ── Admin gallery events ─────────────────────────────────────────────

    public function test_admin_events_page_does_not_lazy_load_rsvp_collections(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);

        foreach ([1, 2, 3] as $i) {
            $event = GalleryScheduleEvent::create([
                'gallery_id' => $gallery->id,
                'title'      => "Opening {$i}",
                'type'       => 'opening',
                'starts_at'  => now()->addDays($i),
                'timezone'   => 'UTC',
                'is_active'  => true,
            ]);
            EventRsvp::create([
                'schedule_event_id' => $event->id,
                'name'              => 'Visitor',
                'email'             => "visitor{$i}@example.com",
                'confirmed_at'      => now(),
            ]);
        }

        $this->actingAs($user);

        $this->startLog();
        $response = $this->get(route('admin.galleries.events.index', $gallery));
        $queries = $this->loggedQueries();
        $this->stopLog();

        $response->assertOk();

        $rsvpLazyLoads = collect($queries)->filter(function ($q) {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'from "event_rsvps"')
                && ! str_contains($sql, 'count(*)');
        })->count();
        $this->assertSame(0, $rsvpLazyLoads, 'Events list must use rsvps_count, not lazy-loaded RSVP collections.');
    }

    public function test_admin_rsvp_list_is_paginated(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);
        $event = GalleryScheduleEvent::create([
            'gallery_id' => $gallery->id,
            'title'      => 'Opening',
            'type'       => 'opening',
            'starts_at'  => now()->addDay(),
            'timezone'   => 'UTC',
            'is_active'  => true,
        ]);

        for ($i = 0; $i < 130; $i++) {
            EventRsvp::create([
                'schedule_event_id' => $event->id,
                'name'              => 'Visitor',
                'email'             => "visitor{$i}@example.com",
                'confirmed_at'      => now(),
            ]);
        }

        $this->actingAs($user);

        $response = $this->get(route('admin.galleries.events.rsvps', [$gallery, $event]));

        $response->assertOk();
        $response->assertViewHas('rsvps', function ($rsvps) {
            return $rsvps instanceof LengthAwarePaginator
                && $rsvps->count() <= 100
                && $rsvps->total() === 130;
        });
    }

    // ── Gallery view payload ─────────────────────────────────────────────

    public function test_gallery_view_does_not_run_seo_profile_lookup_per_page(): void
    {
        $gallery = Gallery::factory()->create();
        GalleryImage::factory()->count(2)->create(['gallery_id' => $gallery->id]);

        \Illuminate\Support\Facades\Cache::flush();

        $this->startLog();
        $response = $this->get('/gallery/'.$gallery->slug);
        $queries = $this->loggedQueries();
        $this->stopLog();

        $response->assertOk();

        $profileFirstLookups = collect($queries)->filter(function ($q) {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'seo_profiles') && str_contains($sql, 'limit 1');
        })->count();
        $this->assertSame(0, $profileFirstLookups, 'seoProfile must be eager-loaded for the gallery view.');
    }

    // ── Quota semantics preserved ────────────────────────────────────────

    public function test_gallery_creation_quota_check_keeps_its_write_lock(): void
    {
        $source = file_get_contents(base_path('app/Models/User.php'));

        $this->assertStringContainsString('lockForUpdate', $source,
            'canCreateGallery() must keep serializing concurrent creations.');
    }

    public function test_free_plan_nav_quota_banner_matches_creation_scope(): void
    {
        $user = User::factory()->create(['max_galleries' => 1]);
        Gallery::factory()->count(2)->create(['user_id' => $user->id]);

        $this->actingAs($user);

        $response = $this->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Gallery limit reached');
    }
}
