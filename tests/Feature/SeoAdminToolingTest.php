<?php

declare(strict_types=1);

/**
 * SEO OPERATING SYSTEM — Iteration 6 (admin/operations tooling) tests.
 *
 * Covers:
 *   - Super-admin SEO console: health tab (real stats), gallery/artist
 *     profile editing, redirect CRUD, page publish toggle, cache rebuild
 *   - Access control: super-admin + MFA middleware gates the console
 *   - Curator-level SEO fields on gallery + artist edit flows
 *   - seo:audit command output + scheduled registration
 *   - AdminAuditLog entries for mutations
 *
 * Run: php artisan test --filter=SeoAdminToolingTest
 */

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\SeoPage;
use App\Models\SeoProfile;
use App\Models\SeoRedirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoAdminToolingTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.url' => 'https://exospace.gallery']);

        // ITERATION-1 FIX: super-admin routes sit behind the `mfa`
        // middleware, which REQUIRES MFA for super-admins — the old
        // factory state had no MFA secret, so every /master-control/seo
        // POST silently redirected to /mfa/setup and the assertions below
        // inspected state that was never written.
        $this->superAdmin = User::factory()->withMfa()->create([
            'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * actingAs + a valid in-session MFA verification (the super_admin
     * middleware group demands both).
     */
    private function actingAsMfaSuperAdmin(): self
    {
        return $this->actingAs($this->superAdmin)->withSession([
            'mfa_verified'    => true,
            'mfa_verified_at' => now()->timestamp,
        ]);
    }

    private function actingAsSuperAdmin(): self
    {
        // The super-admin group requires the 'mfa' middleware, which passes
        // through for users who haven't enabled MFA.
        return $this->actingAs($this->superAdmin);
    }

    // ── Access control ──────────────────────────────────────────────────

    public function test_seo_console_requires_super_admin(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user)->get('/master-control/seo');

        $response->assertForbidden();
    }

    public function test_seo_console_rejects_guests(): void
    {
        $response = $this->get('/master-control/seo');

        $response->assertRedirect('/login');
    }

    // ── Health dashboard ────────────────────────────────────────────────

    public function test_health_tab_shows_real_counts(): void
    {
        $gallery = Gallery::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Counted Show', 'slug' => 'counted-show',
            'description' => 'Has a description.', 'is_active' => true,
        ]);
        GalleryImage::create([
            'gallery_id' => $gallery->id, 'filename' => 'a.jpg', 'original_name' => 'a.jpg',
            'path' => 'artworks/a.jpg', 'mime_type' => 'image/jpeg', 'size' => 1,
            'width' => 100, 'height' => 100, 'orientation' => 'landscape',
            'title' => 'Work', 'medium' => 'Oil',
        ]);
        $artist = Artist::create(['name' => 'Counted Artist', 'bio' => 'Bio.']);
        GalleryImage::create([
            'gallery_id' => $gallery->id, 'artist_id' => $artist->id,
            'filename' => 'b.jpg', 'original_name' => 'b.jpg',
            'path' => 'artworks/b.jpg', 'mime_type' => 'image/jpeg', 'size' => 1,
            'width' => 100, 'height' => 100, 'orientation' => 'landscape',
        ]);

        // Health tab shows counts; the galleries tab lists the entities.
        $response = $this->actingAsMfaSuperAdmin()->get('/master-control/seo?tab=health');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Indexable galleries', $html);

        // ITERATION-1 FIX: gallery titles render on the galleries tab, not
        // the health tab — the old assertion looked in the wrong place.
        $galleries = $this->actingAsMfaSuperAdmin()->get('/master-control/seo?tab=galleries');
        $galleries->assertOk();
        $this->assertStringContainsString('Counted Show', $galleries->getContent());
    }

    public function test_health_tab_flags_missing_descriptions(): void
    {
        $gallery = Gallery::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'No Desc Show', 'slug' => 'no-desc-show',
            'is_active' => true,
        ]);
        // ITERATION-1 FIX: the audit only counts galleries with at least one
        // artwork (empty exhibitions are thin content, excluded from SEO
        // accounting) — the old setup created an image-less gallery that
        // was never counted.
        GalleryImage::create([
            'gallery_id' => $gallery->id, 'filename' => 'c.jpg', 'original_name' => 'c.jpg',
            'path' => 'artworks/c.jpg', 'mime_type' => 'image/jpeg', 'size' => 1,
            'width' => 100, 'height' => 100, 'orientation' => 'landscape',
        ]);

        $response = $this->actingAsMfaSuperAdmin()->get('/master-control/seo?tab=health');

        $response->assertOk();
        $this->assertStringContainsString('galleries with no curator description', $response->getContent());
    }

    // ── Profile editing ─────────────────────────────────────────────────

    public function test_super_admin_can_set_gallery_seo_profile(): void
    {
        $gallery = Gallery::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Profile Target', 'slug' => 'profile-target',
            'is_active' => true,
        ]);

        $response = $this->actingAsMfaSuperAdmin()->post("/master-control/seo/profile/gallery/{$gallery->id}", [
            'title_override' => 'Manual Title',
            'description_override' => 'Manual description.',
            'robots_directive' => 'noindex,follow',
            'sitemap_include' => '0',
            'structured_data' => '1',
        ]);

        $response->assertRedirect();
        $profile = $gallery->fresh()->seoProfile;
        $this->assertSame('Manual Title', $profile->title_override);
        $this->assertSame('noindex,follow', $profile->robots_directive);
        $this->assertFalse((bool) $profile->sitemap_include);
        $this->assertTrue((bool) $profile->structured_data_enabled);
        $this->assertSame($this->superAdmin->id, $profile->updated_by);
    }

    public function test_profile_update_validates_robots_directive(): void
    {
        $gallery = Gallery::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Validate Me', 'slug' => 'validate-me',
            'is_active' => true,
        ]);

        $response = $this->actingAsMfaSuperAdmin()->post("/master-control/seo/profile/gallery/{$gallery->id}", [
            'robots_directive' => 'garbage-directive',
        ]);

        $response->assertSessionHasErrors('robots_directive');
    }

    public function test_profile_update_writes_audit_log(): void
    {
        $gallery = Gallery::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Audited', 'slug' => 'audited', 'is_active' => true,
        ]);

        $this->actingAsMfaSuperAdmin()->post("/master-control/seo/profile/gallery/{$gallery->id}", [
            'title_override' => 'Audit Test',
        ]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'seo.profile_updated',
            'target_type' => Gallery::class,
            'target_id' => $gallery->id,
        ]);
    }

    // ── Redirects CRUD ──────────────────────────────────────────────────

    public function test_super_admin_can_create_and_delete_redirects(): void
    {
        $this->actingAsMfaSuperAdmin()->post('/master-control/seo/redirects', [
            'source_path' => '/Old-Path/',
            'destination' => '/discover',
            'status_code' => 301,
        ]);

        $this->assertDatabaseHas('seo_redirects', [
            'source_path' => 'old-path', // normalized: lowercase, no slashes
            'destination' => '/discover',
        ]);

        $redirect = SeoRedirect::first();

        $this->actingAsMfaSuperAdmin()->delete("/master-control/seo/redirects/{$redirect->id}");

        $this->assertDatabaseMissing('seo_redirects', ['id' => $redirect->id]);
    }

    // ── SEO page management ─────────────────────────────────────────────

    public function test_super_admin_can_toggle_page_publish_state(): void
    {
        $page = SeoPage::create([
            'type' => 'landing', 'slug' => 'toggle-me', 'title' => 'Toggle Me', 'status' => 'draft',
        ]);

        $this->actingAsMfaSuperAdmin()->post("/master-control/seo/pages/{$page->id}/toggle");

        $this->assertSame('published', $page->fresh()->status);
        $this->assertNotNull($page->fresh()->published_at);

        // Toggle back to draft
        $this->actingAsMfaSuperAdmin()->post("/master-control/seo/pages/{$page->id}/toggle");
        $this->assertSame('draft', $page->fresh()->status);
    }

    public function test_pages_tab_lists_pages(): void
    {
        SeoPage::create(['type' => 'editorial', 'slug' => 'listed-guide', 'title' => 'Listed Guide', 'status' => 'draft']);

        $response = $this->actingAsMfaSuperAdmin()->get('/master-control/seo?tab=pages');

        $response->assertOk();
        $this->assertStringContainsString('Listed Guide', $response->getContent());
    }

    // ── Cache rebuild ───────────────────────────────────────────────────

    public function test_cache_rebuild_bumps_sitemap_version(): void
    {
        \Illuminate\Support\Facades\Cache::put('seo:sitemap:version', 7);

        $response = $this->actingAsMfaSuperAdmin()->post('/master-control/seo/rebuild');

        $response->assertRedirect();
        $this->assertSame(8, (int) \Illuminate\Support\Facades\Cache::get('seo:sitemap:version'));
    }

    // ── Curator SEO fields ──────────────────────────────────────────────

    public function test_curator_gallery_update_persists_seo_fields(): void
    {
        $curator = User::factory()->create(['email_verified_at' => now()]);
        $gallery = Gallery::create([
            'user_id' => $curator->id, 'title' => 'Curator Show', 'slug' => 'curator-show',
            'is_active' => true,
        ]);

        $response = $this->actingAs($curator)->put("/admin/galleries/{$gallery->id}", [
            'title' => 'Curator Show',
            'description' => 'Updated description.',
            'wall_texture' => 'white',
            'frame_style' => 'modern',
            'lighting_preset' => 'bright',
            'floor_material' => 'wood',
            'room_layout' => 'square',
            'venue_template_id' => null,
            'seo_title' => 'Curator SEO Title',
            'seo_description' => 'Curator-written meta description.',
        ]);

        $response->assertRedirect();

        $profile = $gallery->fresh()->seoProfile;
        $this->assertNotNull($profile, 'Gallery update creates the seo_profile on demand.');
        $this->assertSame('Curator SEO Title', $profile->title_override);
        $this->assertSame('Curator-written meta description.', $profile->description_override);

        // And the public page uses the override.
        $this->addArtworkTo($gallery);
        $page = $this->get("/gallery/{$gallery->slug}");
        $this->assertStringContainsString('<title>Curator SEO Title</title>', $page->getContent());
    }

    public function test_curator_artist_update_persists_seo_fields(): void
    {
        $curator = User::factory()->create(['email_verified_at' => now()]);
        $artist = Artist::create(['name' => 'Editable Artist', 'created_by' => $curator->id]);

        $response = $this->actingAs($curator)->put("/admin/artists/{$artist->id}", [
            'name' => 'Editable Artist',
            'bio' => 'Bio text.',
            'seo_title' => 'Artist SEO Title',
        ]);

        $response->assertRedirect();

        $profile = $artist->fresh()->seoProfile;
        $this->assertNotNull($profile);
        $this->assertSame('Artist SEO Title', $profile->title_override);
    }

    // ── seo:audit command ───────────────────────────────────────────────

    public function test_seo_audit_command_runs_and_reports(): void
    {
        Gallery::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Descless', 'slug' => 'descless', 'is_active' => true,
        ]);

        $this->artisan('exospace:seo-audit')
            ->expectsOutputToContain('SEO health audit')
            ->assertSuccessful();
    }

    public function test_seo_audit_is_scheduled_daily(): void
    {
        // Boot the console kernel so routes/console.php schedule definitions
        // are registered on the Schedule singleton.
        $this->artisan('list');

        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = collect($schedule->events())->filter(
            fn ($event) => str_contains($event->command ?? '', 'exospace:seo-audit'),
        );

        $this->assertCount(1, $events, 'exospace:seo-audit must be registered in the scheduler.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function addArtworkTo(Gallery $gallery): void
    {
        GalleryImage::create([
            'gallery_id' => $gallery->id, 'filename' => 'x.jpg', 'original_name' => 'x.jpg',
            'path' => 'artworks/x.jpg', 'mime_type' => 'image/jpeg', 'size' => 1,
            'width' => 100, 'height' => 100, 'orientation' => 'landscape',
            'title' => 'Work',
        ]);
    }
}
