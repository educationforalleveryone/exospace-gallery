<?php

declare(strict_types=1);

/**
 * ITERATION-2 regression tests — the publish moment & TTFE.
 *
 * Covers:
 *   - Draft-by-default: POST /admin/galleries creates is_active=false and
 *     redirects straight to the edit page (upload step), not the index.
 *   - Publish/unpublish endpoints: empty-gallery guard, state transitions,
 *     editor authorization (team viewer gets 403), JSON responses.
 *   - Duplicate inherits the source's publish state (previously forced live).
 *   - Edit page renders the publish status bar (Draft/Live).
 *   - Dashboard resurrects the onboarding checklist mid-journey.
 *   - Edit page includes the artwork metadata editor (orphaned endpoint UI).
 *   - Trial wiring: pricing + billing CTAs for eligible free users only.
 *   - Plan-copy alignment: no "unlimited galleries" claims for Pro.
 *
 * Run: php artisan test --filter=PublishWorkflowTest
 */

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /** Valid GalleryController::store() payload (personal gallery). */
    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'title'           => 'Iteration Two Test Gallery',
            'description'     => 'A draft-first gallery.',
            'wall_texture'    => 'white',
            'frame_style'     => 'modern',
            'lighting_preset' => 'bright',
            'floor_material'  => 'wood',
            'room_layout'     => 'square',
        ], $overrides);
    }

    // ── Draft-by-default ────────────────────────────────────────────────

    public function test_store_creates_gallery_as_draft_and_redirects_to_edit_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/admin/galleries', $this->storePayload());

        $gallery = Gallery::where('user_id', $user->id)->firstOrFail();

        // ITERATION-2: draft-by-default + land on the upload/publish page.
        $this->assertFalse((bool) $gallery->is_active, 'New galleries must start as drafts.');
        $response->assertRedirect(route('admin.galleries.edit', $gallery));
        $response->assertSessionHas('status');
    }

    public function test_store_redirect_happens_even_when_coolify_domain_warning_fires(): void
    {
        // The custom-domain soft-warning path must also land on the edit
        // page (it previously went to the index).
        $user = User::factory()->create(['plan' => 'studio', 'max_galleries' => 999, 'max_images' => 500]);

        $response = $this->actingAs($user)->post('/admin/galleries', $this->storePayload([
            'custom_domain' => 'studio.example.com',
        ]));

        $gallery = Gallery::where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect(route('admin.galleries.edit', $gallery));
        $this->assertFalse((bool) $gallery->is_active);
    }

    // ── Publish endpoint ────────────────────────────────────────────────

    public function test_publish_blocked_for_empty_gallery(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        $response = $this->actingAs($user)
            ->from("/admin/galleries/{$gallery->id}/edit")
            ->post("/admin/galleries/{$gallery->id}/publish");

        $response->assertRedirect("/admin/galleries/{$gallery->id}/edit");
        $response->assertSessionHas('error');
        $this->assertFalse((bool) $gallery->fresh()->is_active, 'Empty gallery must not go live.');
    }

    public function test_publish_blocked_for_empty_gallery_json(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        $response = $this->actingAs($user)
            ->postJson("/admin/galleries/{$gallery->id}/publish");

        $response->assertStatus(422);
        $this->assertFalse((bool) $gallery->fresh()->is_active);
    }

    public function test_publish_makes_gallery_live_and_public_url_resolvable(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => false]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        // Draft: public URL 404s.
        $this->get("/gallery/{$gallery->slug}")->assertNotFound();

        $response = $this->actingAs($user)
            ->from("/admin/galleries/{$gallery->id}/edit")
            ->post("/admin/galleries/{$gallery->id}/publish");

        $response->assertRedirect("/admin/galleries/{$gallery->id}/edit");
        $response->assertSessionHas('status');
        $this->assertTrue((bool) $gallery->fresh()->is_active);

        // Live: public URL resolves.
        $this->get("/gallery/{$gallery->slug}")->assertOk();
    }

    public function test_publish_returns_json_when_requested(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => false]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->actingAs($user)
            ->postJson("/admin/galleries/{$gallery->id}/publish");

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('is_active', true);
    }

    public function test_publish_requires_editor_rights(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'viewer']);
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'team_id' => $team->id, 'is_active' => false]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($member)
            ->post("/admin/galleries/{$gallery->id}/publish")
            ->assertForbidden();

        $this->assertFalse((bool) $gallery->fresh()->is_active);
    }

    // ── Unpublish endpoint ──────────────────────────────────────────────

    public function test_unpublish_returns_live_gallery_to_draft(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->actingAs($user)
            ->from("/admin/galleries/{$gallery->id}/edit")
            ->post("/admin/galleries/{$gallery->id}/unpublish");

        $response->assertRedirect("/admin/galleries/{$gallery->id}/edit");
        $this->assertFalse((bool) $gallery->fresh()->is_active);

        // Public URL is closed again.
        $this->get("/gallery/{$gallery->slug}")->assertNotFound();
    }

    public function test_unpublish_requires_editor_rights(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($member->id, ['role' => 'viewer']);
        $gallery = Gallery::factory()->create(['user_id' => $owner->id, 'team_id' => $team->id, 'is_active' => true]);

        $this->actingAs($member)
            ->post("/admin/galleries/{$gallery->id}/unpublish")
            ->assertForbidden();

        $this->assertTrue((bool) $gallery->fresh()->is_active);
    }

    // ── Duplicate inherits publish state ────────────────────────────────

    public function test_duplicate_of_draft_stays_draft(): void
    {
        $user = User::factory()->pro()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        // duplicate() copies image FILES on disk — the factory points at
        // nonexistent paths, so put two real bytes on the public disk.
        Storage::fake('public');
        foreach (['artwork-one.jpg', 'artwork-two.jpg'] as $i => $filename) {
            $path = "gallery-images/{$filename}";
            Storage::disk('public')->put($path, 'fake-jpeg-bytes');
            GalleryImage::factory()->create([
                'gallery_id' => $gallery->id,
                'path'       => $path,
                'filename'   => $filename,
            ]);
        }

        $this->actingAs($user)
            ->post("/admin/galleries/{$gallery->id}/duplicate");

        $clone = Gallery::where('user_id', $user->id)
            ->where('id', '!=', $gallery->id)
            ->firstOrFail();

        // Pre-ITERATION-2 the clone was forced live even when the source
        // was a draft — instantly exposing an unreviewed copy.
        $this->assertFalse((bool) $clone->is_active, 'Clones must inherit the source publish state.');
        $this->assertSame(2, $clone->images()->count());
    }

    public function test_duplicate_of_live_gallery_stays_live(): void
    {
        $user = User::factory()->pro()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($user)
            ->post("/admin/galleries/{$gallery->id}/duplicate");

        $clone = Gallery::where('user_id', $user->id)
            ->where('id', '!=', $gallery->id)
            ->firstOrFail();

        $this->assertTrue((bool) $clone->is_active);
    }

    // ── Edit page publish status bar ────────────────────────────────────

    public function test_edit_page_shows_draft_status_bar_for_draft(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        $response = $this->actingAs($user)->get("/admin/galleries/{$gallery->id}/edit");

        $response->assertOk()
            ->assertSee('Draft')
            ->assertSee('Publish')
            ->assertSee('Preview')
            ->assertSee('Upload at least one artwork to enable publishing');
    }

    public function test_edit_page_shows_live_status_bar_for_published(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->actingAs($user)->get("/admin/galleries/{$gallery->id}/edit");

        $response->assertOk()
            ->assertSee('Live')
            ->assertSee('Unpublish')
            ->assertSee('Copy link')
            ->assertSee(route('gallery.view', $gallery->slug));
    }

    // ── Artwork metadata editor UI ──────────────────────────────────────

    public function test_edit_page_includes_metadata_editor_for_images(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => false]);
        $image = GalleryImage::factory()->create([
            'gallery_id' => $gallery->id,
            'title'      => 'Blue Composition No. 4',
        ]);

        $response = $this->actingAs($user)->get("/admin/galleries/{$gallery->id}/edit");

        // Modal + per-card edit button + the curated title in the caption.
        $response->assertOk()
            ->assertSee('Artwork details')
            ->assertSee('id="metadata-form"', false)
            ->assertSee('editMetadata')
            ->assertSee('Blue Composition No. 4')
            ->assertSee('For sale');
    }

    // ── Dashboard onboarding checklist resurrection ─────────────────────

    public function test_dashboard_shows_checklist_mid_journey(): void
    {
        $user = User::factory()->create();
        // Gallery exists but journey incomplete: draft + no images.
        Gallery::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk()->assertSee('Get started with Exospace');
    }

    public function test_dashboard_hides_checklist_when_journey_complete(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        GalleryImage::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->actingAs($user)->get('/admin/dashboard');

        $response->assertOk()->assertDontSee('Get started with Exospace');
    }

    // ── Trial wiring ────────────────────────────────────────────────────

    public function test_pricing_offers_trial_to_eligible_free_user(): void
    {
        $user = User::factory()->create(); // free, never trialed

        $response = $this->actingAs($user)->get('/pricing');

        $response->assertOk()->assertSee('try Pro free for 14 days');
    }

    public function test_pricing_hides_trial_for_users_who_already_used_one(): void
    {
        $user = User::factory()->create(['trial_ends_at' => now()->subDays(20)]);

        $response = $this->actingAs($user)->get('/pricing');

        $response->assertOk()->assertDontSee('try Pro free for 14 days');
    }

    public function test_pricing_shows_register_hint_for_guests_instead_of_trial_form(): void
    {
        $response = $this->get('/pricing');

        $response->assertOk()
            ->assertSee('Create a free account')
            ->assertDontSee('try Pro free for 14 days');
    }

    public function test_billing_index_offers_trial_cta_to_eligible_free_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/billing');

        $response->assertOk()->assertSee('Start 14-day Pro trial');
    }

    public function test_billing_index_hides_trial_cta_after_use(): void
    {
        $user = User::factory()->create(['trial_ends_at' => now()->addDays(5)]);

        $response = $this->actingAs($user)->get('/billing');

        $response->assertOk()->assertDontSee('Start 14-day Pro trial');
    }

    // ── Plan-copy alignment ─────────────────────────────────────────────

    public function test_pricing_copy_states_real_pro_entitlements(): void
    {
        $response = $this->get('/pricing');

        $response->assertOk()
            ->assertSee('5 galleries')
            ->assertSee('100 images total');

        // "Unlimited galleries" is only ever a STUDIO claim now.
        $this->assertStringNotContainsStringIgnoringCase(
            'Upgrade to Pro for unlimited galleries',
            $response->getContent(),
        );
    }

    public function test_galleries_index_upgrade_copy_states_real_pro_limits(): void
    {
        $user = User::factory()->create();
        // At the limit so the upgrade banner renders.
        Gallery::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        $response = $this->actingAs($user)->get('/admin/galleries');

        $response->assertOk()
            ->assertSee('Upgrade to Pro for ' . config('plans.limits.pro.max_galleries') . ' galleries')
            ->assertDontSee('Upgrade to Pro for unlimited galleries');
    }
}
