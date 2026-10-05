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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Systematic N+1 regression sweep.
 *
 * HttpRequestEfficiencyTest pins query volume on the heaviest public pages;
 * this sweep guarantees the property that makes those fixes stick: no page
 * on the exercised surface may lazy-load a relation, and per-record query
 * cost must stay flat as data grows. Lazy loads are the mechanism through
 * which N+1s reappear, so strict mode (preventLazyLoading) catches them
 * regardless of which layer — controller, view composer, or blade — issued
 * the access.
 */
class QueryHygieneSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function seedVenue(int $galleryCount, int $imagesPerGallery = 4): array
    {
        $owner = User::factory()->create(['plan' => 'studio']);
        $venue = VenueTemplate::factory()->create(['is_draft' => false]);
        $artist = Artist::factory()->create();

        $galleries = Gallery::factory()->count($galleryCount)->create([
            'user_id' => $owner->id,
            'venue_template_id' => $venue->id,
            'is_active' => true,
        ]);

        $image = null;
        foreach ($galleries as $gallery) {
            $images = GalleryImage::factory()->count($imagesPerGallery)->create([
                'gallery_id' => $gallery->id,
                'artist_id' => $artist->id,
            ]);
            $image ??= $images->first();

            $event = GalleryScheduleEvent::create([
                'gallery_id' => $gallery->id,
                'title' => 'Opening',
                'type' => 'opening',
                'starts_at' => now()->addDay(),
                'timezone' => 'UTC',
                'is_active' => true,
            ]);
            EventRsvp::create([
                'schedule_event_id' => $event->id,
                'name' => 'Visitor',
                'email' => 'visitor@example.com',
                'confirmed_at' => now(),
            ]);
        }

        return [$owner, $venue, $artist, $galleries, $image];
    }

    private function publicUrls(array $ctx): array
    {
        [$owner, $venue, $artist, $galleries, $image] = $ctx;
        $gallery = $galleries->first();

        return ['/', '/discover', '/artists', "/artist/{$artist->slug}", '/venues',
            "/venues/{$venue->slug}", "/venues/{$venue->slug}/preview",
            "/gallery/{$gallery->slug}", "/gallery/{$gallery->slug}/artwork/{$image->id}",
            "/gallery/{$gallery->slug}/events", '/changelog', '/feed.xml', '/sitemap.xml',
            '/contact', '/pricing', '/about', '/terms', '/privacy', '/status'];
    }

    private function ownerUrls(array $ctx): array
    {
        [$owner, $venue, $artist, $galleries, $image] = $ctx;
        $gallery = $galleries->first();

        return ['/admin/dashboard', '/admin/galleries', "/admin/galleries/{$gallery->id}",
            "/admin/galleries/{$gallery->id}/edit", "/admin/galleries/{$gallery->id}/analytics",
            "/admin/galleries/{$gallery->id}/events", "/admin/galleries/{$gallery->id}/preview",
            '/admin/artists', "/admin/artists/{$artist->id}", '/admin/teams',
            '/profile', '/billing'];
    }

    private function superAdminUrls(array $ctx): array
    {
        [$owner, $venue, $artist, $galleries, $image] = $ctx;

        return ['/master-control', "/master-control/users/{$owner->id}/galleries",
            '/master-control/venues', '/master-control/featured', '/master-control/seo',
            '/master-control/pending-upgrades', '/master-control/billing',
            '/master-control/webhooks', '/master-control/feedback', '/master-control/nps',
            '/master-control/affiliates'];
    }

    private function publicApiUrls(array $ctx): array
    {
        [$owner, $venue, $artist, $galleries, $image] = $ctx;
        $gallery = $galleries->first();

        return ['/api/v1/galleries', "/api/v1/galleries/{$gallery->slug}",
            "/api/v1/galleries/{$gallery->slug}/images", '/api/v1/artists',
            "/api/v1/artists/{$artist->slug}", "/api/v1/artists/{$artist->slug}/galleries"];
    }

    /**
     * Hit every URL with strict lazy-loading enabled and report violations.
     *
     * @return list<string> violation summaries, empty when the surface is clean
     */
    private function sweep(array $urls, string $as): array
    {
        Model::preventLazyLoading(true);

        if ($as === 'owner') {
            $this->actingAs(User::where('plan', 'studio')->firstOrFail());
        } elseif ($as === 'super') {
            $admin = User::factory()->withMfa()->create([
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]);
            $this->actingAs($admin)->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
            ]);
        }

        $violations = [];
        foreach ($urls as $url) {
            Cache::flush();
            try {
                $this->get($url);
            } catch (\Illuminate\Database\Eloquent\LazyLoadingViolationException $e) {
                $first = $e->getViolations()[0];
                $violations[] = sprintf(
                    '%s lazy-loads %s::%s (%d access(es))',
                    $url,
                    $first->getModel(),
                    $first->getRelation(),
                    count($e->getViolations()),
                );
            }
        }

        Model::preventLazyLoading(false);

        return $violations;
    }

    /**
     * Same sweep for JSON endpoints. When $bearer is provided, requests go
     * out authenticated (Sanctum personal access token with read ability).
     *
     * @param  list<string>  $urls
     * @return list<string> violation summaries, empty when the surface is clean
     */
    private function sweepApi(array $urls, ?string $bearer = null): array
    {
        Model::preventLazyLoading(true);

        $headers = $bearer !== null ? ['Authorization' => 'Bearer '.$bearer] : [];

        $violations = [];
        foreach ($urls as $url) {
            Cache::flush();
            try {
                $this->getJson($url, $headers);
            } catch (\Illuminate\Database\Eloquent\LazyLoadingViolationException $e) {
                $first = $e->getViolations()[0];
                $violations[] = sprintf(
                    '%s lazy-loads %s::%s (%d access(es))',
                    $url,
                    $first->getModel(),
                    $first->getRelation(),
                    count($e->getViolations()),
                );
            }
        }

        Model::preventLazyLoading(false);

        return $violations;
    }

    public function test_public_surface_has_no_lazy_loading(): void
    {
        $ctx = $this->seedVenue(galleryCount: 4);

        $violations = $this->sweep($this->publicUrls($ctx), 'guest');

        $this->assertSame([], $violations, sprintf(
            "Public pages contain lazy-loaded relations (N+1s):\n%s",
            implode("\n", $violations),
        ));
    }

    public function test_authenticated_surfaces_have_no_lazy_loading(): void
    {
        $ctx = $this->seedVenue(galleryCount: 4);

        $violations = array_merge(
            $this->sweep($this->ownerUrls($ctx), 'owner'),
            $this->sweep($this->superAdminUrls($ctx), 'super'),
        );

        $this->assertSame([], $violations, sprintf(
            "Authenticated pages contain lazy-loaded relations (N+1s):\n%s",
            implode("\n", $violations),
        ));
    }

    public function test_api_surface_has_no_lazy_loading(): void
    {
        $ctx = $this->seedVenue(galleryCount: 4);
        [$owner] = $ctx;

        $token = $owner->createToken('hygiene-sweep', ['read'])->plainTextToken;

        $violations = array_merge(
            $this->sweepApi($this->publicApiUrls($ctx)),
            $this->sweepApi(['/api/v1/me', '/api/v1/me/galleries', '/api/v1/tokens'], $token),
        );

        $this->assertSame([], $violations, sprintf(
            "API endpoints contain lazy-loaded relations (N+1s):\n%s",
            implode("\n", $violations),
        ));
    }

    public function test_api_pagination_contract_keeps_image_count_accurate(): void
    {
        // The image_count N+1 fix (withCount) must not change the contract value.
        $ctx = $this->seedVenue(galleryCount: 2, imagesPerGallery: 3);
        [, , , $galleries] = $ctx;
        $gallery = $galleries->first();

        $list = $this->getJson('/api/v1/galleries');
        $list->assertOk();

        $row = collect($list->json('data'))->firstWhere('slug', $gallery->slug);
        $this->assertNotNull($row);
        $this->assertSame(3, $row['image_count'], 'withCount must preserve the image_count contract value.');

        $detail = $this->getJson("/api/v1/galleries/{$gallery->slug}");
        $detail->assertOk();
        $this->assertSame(3, $detail->json('data.image_count'));
    }

    public function test_hot_endpoint_query_volume_is_flat_from_3_to_25_records(): void
    {
        $probe = function (int $n): array {
            $ctx = $this->seedVenue(galleryCount: $n);
            [$owner, $venue, $artist, $galleries, $image] = $ctx;
            $gallery = $galleries->first();

            $counts = [];

            $run = function (string $label, callable $request) use (&$counts) {
                Cache::flush();
                DB::flushQueryLog();
                DB::enableQueryLog();
                $request();
                $counts[$label] = count(DB::getQueryLog());
                DB::disableQueryLog();
            };

            $run('artist-profile', fn () => $this->get("/artist/{$artist->slug}"));
            $run('gallery-view', fn () => $this->get("/gallery/{$gallery->slug}"));
            $run('api-galleries', fn () => $this->getJson('/api/v1/galleries'));
            $run('api-artists', fn () => $this->getJson('/api/v1/artists'));

            $apiToken = $owner->createToken('hygiene-probe', ['read'])->plainTextToken;
            $run('api-me-galleries', fn () => $this->getJson('/api/v1/me/galleries', [
                'Authorization' => 'Bearer '.$apiToken,
            ]));

            $this->actingAs($owner);
            $run('admin-dashboard', fn () => $this->get('/admin/dashboard'));
            $run('admin-galleries', fn () => $this->get('/admin/galleries'));
            $run('admin-teams', fn () => $this->get('/admin/teams'));
            $run('billing', fn () => $this->get('/billing'));

            $admin = User::factory()->withMfa()->create([
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ]);
            $this->actingAs($admin)->withSession([
                'mfa_verified' => true,
                'mfa_verified_at' => now()->timestamp,
            ]);
            $run('master-control', fn () => $this->get('/master-control'));
            $run('user-galleries', fn () => $this->get("/master-control/users/{$owner->id}/galleries"));
            $run('feedback', fn () => $this->get('/master-control/feedback'));

            return $counts;
        };

        $small = $probe(3);
        $large = $probe(25);

        $message = '';
        $worst = 0;
        foreach ($small as $label => $smallCount) {
            $delta = $large[$label] - $smallCount;
            $worst = max($worst, $delta);
            $message .= sprintf("%s: %d → %d (Δ%d)\n", $label, $smallCount, $large[$label], $delta);
        }

        // A page whose query cost grew with record count would show a delta
        // proportional to the 22 added galleries. Bounded fixed-cost extras
        // (pagination counts, per-page aggregates) stay far below this.
        $this->assertLessThanOrEqual(6, $worst, sprintf(
            "Query volume scales with record count somewhere — per-record queries are back.\n%s",
            $message,
        ));
    }
}
