<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\DetectCustomDomain;
use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\SeoPage;
use App\Models\User;
use App\Support\ResilientCache;
use App\Support\SitemapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ── QR code caches ──────────────────────────────────────────────────

    public function test_qr_rejects_unlisted_formats_instead_of_caching_them(): void
    {
        $gallery = Gallery::factory()->create();

        $this->get("/gallery/{$gallery->slug}/qr?format=bmp")->assertNotFound();

        $this->assertNull(Cache::store()->get("qr:{$gallery->slug}:app:bmp"));
    }

    public function test_qr_cache_key_tracks_the_public_url(): void
    {
        $gallery = Gallery::factory()->create();

        $this->get("/gallery/{$gallery->slug}/qr")->assertOk();
        $this->assertNotNull(Cache::store()->get("qr:{$gallery->slug}:app:png"));

        // Same slug, new address: the custom domain must land on a fresh
        // entry, not serve a code that still encodes the old URL.
        $gallery->forceFill([
            'custom_domain'             => 'gallery.example.com',
            'custom_domain_verified_at' => now(),
        ])->save();

        $this->get("/gallery/{$gallery->slug}/qr")->assertOk();
        $this->assertNotNull(Cache::store()->get("qr:{$gallery->slug}:gallery.example.com:png"));
    }

    // ── OG artwork cards ────────────────────────────────────────────────

    public function test_og_artwork_stamp_follows_artwork_edits(): void
    {
        $gallery = Gallery::factory()->create();
        $artist = Artist::factory()->create();
        $artwork = GalleryImage::factory()->create([
            'gallery_id'     => $gallery->id,
            'artist_id'      => $artist->id,
            'position_order' => 1,
        ]);

        $this->get("/gallery/{$gallery->slug}/og-image?artwork={$artwork->id}")->assertOk();

        $artwork->refresh();
        $artworkStamp = max($artwork->updated_at?->getTimestamp() ?? 0, $artist->updated_at?->getTimestamp() ?? 0);
        $oldKey = "og:image:{$gallery->slug}:artwork:{$artwork->id}:";

        $this->assertNotNull($this->ogArtworkCacheEntry($gallery, $artwork), 'OG artwork card must be cached under a key stamped with the artwork edits.');

        // An artwork edit changes the stamp, so the next render writes a new
        // generation instead of serving the old card for the whole window.
        sleep(1);
        $artwork->update(['title' => 'Renamed artwork']);

        $this->get("/gallery/{$gallery->slug}/og-image?artwork={$artwork->id}")->assertOk();

        $artwork->refresh();
        $newStamp = max($artwork->updated_at?->getTimestamp() ?? 0, $artwork->artist?->updated_at?->getTimestamp() ?? 0);
        $this->assertGreaterThan($artworkStamp, $newStamp, 'Artwork stamp must move on edit.');
        $this->assertNotNull($this->ogArtworkCacheEntry($gallery, $artwork), 'Fresh generation must be written after the edit.');
        $this->assertStringContainsString(":a{$newStamp}:v1", (string) $this->ogArtworkCacheKey($gallery, $artwork));
        $this->assertStringStartsWith($oldKey, (string) $this->ogArtworkCacheKey($gallery, $artwork));
    }

    // ── Custom-domain payload stamps ────────────────────────────────────

    public function test_custom_domain_payload_drops_when_owner_is_banned(): void
    {
        $gallery = Gallery::factory()->create([
            'custom_domain'             => 'gallery.example.com',
            'custom_domain_verified_at' => now(),
        ]);

        $this->handleCustomDomainRequest('gallery.example.com');
        $request = $this->handleCustomDomainRequest('gallery.example.com');
        $this->assertNotNull($request->attributes->get('resolved_gallery'));

        // Banning the owner must drop the domain payload immediately: the
        // ban stamp rides in the key, so the next request reads fresh data
        // instead of trusting the cached owner.
        $gallery->user->forceFill(['banned_at' => now()])->save();

        $request = $this->handleCustomDomainRequest('gallery.example.com');
        $this->assertNull($request->attributes->get('resolved_gallery'));
    }

    public function test_custom_domain_payload_drops_when_gallery_is_unpublished(): void
    {
        $gallery = Gallery::factory()->create([
            'custom_domain'             => 'gallery.example.com',
            'custom_domain_verified_at' => now(),
        ]);

        $this->handleCustomDomainRequest('gallery.example.com');
        $request = $this->handleCustomDomainRequest('gallery.example.com');
        $this->assertNotNull($request->attributes->get('resolved_gallery'));

        // Second-precision stamps: land the edit in a later second.
        sleep(1);
        $gallery->update(['is_active' => false]);

        $request = $this->handleCustomDomainRequest('gallery.example.com');
        $this->assertNull($request->attributes->get('resolved_gallery'));
    }

    // ── Public content version (sitemaps, listings, welcome) ────────────

    public function test_public_content_version_bumps_are_monotonic(): void
    {
        SitemapVersion::bump();
        SitemapVersion::bump();

        $this->assertSame('3', SitemapVersion::version());
    }

    public function test_banning_a_user_rotates_the_public_content_version(): void
    {
        $this->withoutMiddleware([
            \Illuminate\Auth\Middleware\RequirePassword::class,
            \App\Http\Middleware\RequireMfa::class,
        ]);

        $admin = User::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->create();

        $before = (int) SitemapVersion::version();

        $this->actingAs($admin)->post(route('super.banUser', $user), ['reason' => 'abuse'])->assertRedirect();
        $this->assertGreaterThan($before, (int) SitemapVersion::version(), 'Ban must rotate public listing caches.');

        $before = (int) SitemapVersion::version();
        $this->actingAs($admin)->post(route('super.unbanUser', $user))->assertRedirect();
        $this->assertGreaterThan($before, (int) SitemapVersion::version(), 'Unban must rotate public listing caches.');
    }

    public function test_mass_image_reorder_touches_the_gallery_and_bumps_the_version(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);
        $a = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'position_order' => 1]);
        $b = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'position_order' => 2]);

        $beforeVersion = (int) SitemapVersion::version();

        sleep(1);
        $this->actingAs($user)
            ->postJson(route('admin.galleries.reorder-images', $gallery), ['order' => [$b->id, $a->id]])
            ->assertOk();

        $this->assertSame(1, (int) $b->fresh()->position_order);
        $this->assertSame(2, (int) $a->fresh()->position_order);
        $this->assertGreaterThan($beforeVersion, (int) SitemapVersion::version(), 'Reorder must rotate public content caches.');
        $this->assertGreaterThan($a->created_at->getTimestamp(), $gallery->fresh()->updated_at->getTimestamp(), 'Reorder must refresh the gallery stamp that OG and payload caches key off.');
    }

    public function test_artist_deletion_touches_the_galleries_featuring_them(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create(['created_by' => $user->id]);
        $gallery = Gallery::factory()->create(['user_id' => $user->id]);
        $image = GalleryImage::factory()->create(['gallery_id' => $gallery->id, 'artist_id' => $artist->id]);

        $beforeStamp = $gallery->updated_at->getTimestamp();

        sleep(1);
        $this->actingAs($user)->delete(route('admin.artists.destroy', $artist))->assertRedirect();

        $this->assertGreaterThan($beforeStamp, $gallery->fresh()->updated_at->getTimestamp());
        $this->assertNull($image->fresh()->artist_id);
    }

    // ── Cache outage resilience ─────────────────────────────────────────

    public function test_resilient_cache_serves_uncached_when_the_store_fails(): void
    {
        Cache::shouldReceive('remember')
            ->once()
            ->andThrow(new \RuntimeException('READONLY Redis is in read-only mode'));

        $result = ResilientCache::remember('some:key', 60, fn () => 'live-value');

        $this->assertSame('live-value', $result);
    }

    public function test_resilient_cache_get_returns_default_when_the_store_fails(): void
    {
        Cache::shouldReceive('get')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $this->assertSame('fallback', ResilientCache::get('some:key', 'fallback'));
    }

    public function test_slug_map_falls_back_to_the_database_when_the_cache_fails(): void
    {
        $page = SeoPage::create([
            'type'   => 'landing',
            'slug'   => 'pricing-fallback',
            'title'  => 'Pricing fallback',
            'status' => 'published',
            'blocks' => [],
        ]);

        Cache::shouldReceive('get')->andThrow(new \RuntimeException('Redis down'));
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('Redis down'));

        $map = SeoPage::cachedSlugMap();

        $this->assertArrayHasKey(SeoPage::pathFor($page->type, $page->slug), $map);
    }

    public function test_health_endpoint_reports_coolify_outage_from_the_sync_key(): void
    {
        config([
            'services.coolify.api_token'    => 'test-token',
            'services.coolify.api_base_url' => 'https://coolify.example.com',
        ]);

        Cache::put('ops:sync:coolify-unreachable-alerted', now()->toIso8601String(), now()->addHours(2));

        $checks = $this->get('/health')->assertOk()->json('checks');

        $this->assertSame('unreachable', $checks['coolify']['status']);
    }

    // ── Tag hygiene ─────────────────────────────────────────────────────

    public function test_tag_flushes_of_never_written_tags_do_not_touch_other_entries(): void
    {
        Cache::put('untagged:key', 'value', 60);

        app(\App\Services\CacheTagService::class)->invalidateTags(['og', 'sitemap', 'gallery:1']);

        $this->assertSame('value', Cache::store()->get('untagged:key'));
    }

    // ── Scheduler heartbeat ─────────────────────────────────────────────

    public function test_scheduler_heartbeat_task_is_registered(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('scheduler-heartbeat')->assertSuccessful();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function handleCustomDomainRequest(string $host): Request
    {
        $request = Request::create("https://{$host}/", 'GET');
        app(DetectCustomDomain::class)->handle($request, fn () => new Response(''));

        return $request;
    }

    private function ogArtworkCacheKey(Gallery $gallery, GalleryImage $artwork): ?string
    {
        $gallery->refresh();
        $artwork->refresh();

        $stamp = max($gallery->updated_at?->getTimestamp() ?? 0, $gallery->coverImage?->updated_at?->getTimestamp() ?? 0);
        $artworkStamp = max($artwork->updated_at?->getTimestamp() ?? 0, $artwork->artist?->updated_at?->getTimestamp() ?? 0);

        return "og:image:{$gallery->slug}:artwork:{$artwork->id}:{$stamp}:a{$artworkStamp}:v1";
    }

    private function ogArtworkCacheEntry(Gallery $gallery, GalleryImage $artwork): mixed
    {
        return Cache::store()->get($this->ogArtworkCacheKey($gallery, $artwork));
    }
}
