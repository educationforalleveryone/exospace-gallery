<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ITERATION 4 — sitemap cache warming.
 *
 * Before this iteration, every sitemap cache key rebuilt lazily INSIDE the
 * crawler's request (Cache::flexible's cold path computes inline with no
 * lock). These tests pin the warmer contract:
 *   - sitemap:warm populates the versioned keys a crawler would read
 *   - the daily 04:15 schedule exists
 *   - seo:rebuild now actually warms (its docblock always claimed it did)
 *   - warmed keys serve without re-querying (cold-path never fires)
 */
class SitemapWarmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://exospace.gallery']);
        Cache::forget('seo:sitemap:version');
    }

    private function seedPublicGallery(): Gallery
    {
        $gallery = Gallery::create([
            'user_id'     => User::factory()->create()->id,
            'title'       => 'Warmable Show',
            'slug'        => 'warmable-show',
            'description' => 'A description long enough to pass the artwork quality gate comfortably.',
            'is_active'   => true,
        ]);
        GalleryImage::create([
            'gallery_id'  => $gallery->id,
            'path'        => 'artworks/warm.jpg',
            'original_name' => 'warm.jpg',
            'filename'       => 'warm.jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => 12345,
            'width'        => 800,
            'height'       => 600,
            'orientation'  => 'landscape',
            'title'       => 'Warm Artwork',
            'description' => str_repeat('Substantive description. ', 10),
            'position_order' => 0,
        ]);

        return $gallery;
    }

    public function test_warm_command_populates_the_keys_crawlers_read(): void
    {
        $this->seedPublicGallery();
        Cache::flush();

        $exit = Artisan::call('sitemap:warm');
        $this->assertSame(0, $exit);

        $version = (int) Cache::get('seo:sitemap:version', 1);
        // Same key expressions the request path uses (single source of
        // truth — cacheGroupEntries / cacheIndexEntries):
        $this->assertTrue(Cache::has("sitemap:index:v{$version}"));
        $this->assertTrue(Cache::has('sitemap:count:galleries:v' . $version));
        $this->assertTrue(Cache::has('sitemap:lastmod:galleries:v' . $version));
        $this->assertTrue(Cache::has("sitemap:group:galleries:1:v{$version}"));
        $this->assertTrue(Cache::has('sitemap:group:static:1:v' . $version));
        $this->assertTrue(Cache::has('feed:galleries:v' . $version));

        // And the warmed content actually serves:
        $this->get('/sitemap-galleries-1.xml')->assertOk()->assertSee('warmable-show');
        $this->get('/sitemap.xml')->assertOk()->assertSee('sitemap-galleries-1.xml');
    }

    public function test_warm_command_rejects_unknown_group(): void
    {
        $this->assertSame(1, Artisan::call('sitemap:warm', ['--group' => 'nonsense']));
    }

    /**
     * ITERATION-6 FIX (Iteration-5 regression): the events group was added
     * to SitemapController::GROUPS but missing from the command's --group
     * allowlist — targeted warming of the events sitemap failed with
     * "Unknown group".
     */
    public function test_warm_command_accepts_the_events_group(): void
    {
        // Warm an empty events group — exit 0 proves the allowlist accepts
        // it (the empty group simply yields a 0-page warm).
        $this->assertSame(0, Artisan::call('sitemap:warm', ['--group' => 'events']));
    }

    public function test_warm_command_supports_single_group(): void
    {
        $this->seedPublicGallery();
        Cache::flush();

        Artisan::call('sitemap:warm', ['--group' => 'galleries']);

        $version = (int) Cache::get('seo:sitemap:version', 1);
        $this->assertTrue(Cache::has("sitemap:group:galleries:1:v{$version}"));
        $this->assertFalse(Cache::has("sitemap:group:static:1:v{$version}"), 'other groups stay lazy');
    }

    public function test_sitemap_warm_is_scheduled_daily_at_0415(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $warm = $events->first(fn ($e) => str_contains((string) $e->command, 'sitemap:warm'));

        $this->assertNotNull($warm, 'sitemap:warm must be scheduled');
        $this->assertSame('15 4 * * *', $warm->expression ?? null, 'warm runs daily at 04:15 — after reconcile (04:10), before seo:audit (04:30)');
    }

    public function test_seo_rebuild_now_warms_the_fresh_version(): void
    {
        $this->seedPublicGallery();
        Cache::flush();
        Cache::put('seo:sitemap:version', 7);

        Artisan::call('seo:rebuild');

        // Version bumped atomically…
        $this->assertSame(8, (int) Cache::get('seo:sitemap:version'));
        // …and the NEW version's keys are pre-populated (the docblock has
        // claimed "eagerly warms" since SEO OS Iteration 4 — now true).
        $this->assertTrue(Cache::has('sitemap:group:galleries:1:v8'));
        $this->assertTrue(Cache::has('sitemap:index:v8'));

        $this->get('/sitemap-galleries-1.xml')->assertOk()->assertSee('warmable-show');
    }

    public function test_observer_bump_is_atomic_and_invalidates_warmed_keys(): void
    {
        $this->seedPublicGallery();
        Cache::flush();

        Artisan::call('sitemap:warm');
        $v1 = (int) Cache::get('seo:sitemap:version', 1);
        $this->assertTrue(Cache::has("sitemap:group:galleries:1:v{$v1}"));

        // An entity write bumps the version atomically (seed-then-increment)
        // and the old version's keys stop being served.
        GalleryImage::create([
            'gallery_id'    => Gallery::first()->id,
            'path'          => 'artworks/warm2.jpg',
            'original_name' => 'warm2.jpg',
            'filename'       => 'warm2.jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => 12345,
            'width'        => 800,
            'height'       => 600,
            'orientation'  => 'landscape',
            'title'         => 'Second Artwork',
            'description'   => str_repeat('Another substantive description. ', 8),
            'position_order' => 1,
        ]);

        $v2 = (int) Cache::get('seo:sitemap:version', 1);
        $this->assertSame($v1 + 1, $v2, 'seed-then-increment must net exactly +1 per bump');
        $this->assertTrue(Cache::has("sitemap:group:galleries:1:v{$v2}") === false, 'new version starts cold until warmed');
    }
}
